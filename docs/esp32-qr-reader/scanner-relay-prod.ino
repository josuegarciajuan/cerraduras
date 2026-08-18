/**
 * scanner-relay-prod.ino — Production firmware: QR Reader + Relay + WiFi + Identify
 *                          + NVS WiFi storage + WiFiManager provisioning
 *                          + FW version auto-wipe + LED/relay feedback
 *
 * WiFi:
 *   - Credenciales guardadas en NVS (Preferences).
 *   - Cada vez que se flashea (MD5 del binario cambia): wipe NVS automático.
 *   - Al arrancar: intenta conectar con credenciales NVS.
 *   - Si no hay credenciales/NVS o falla conexión: WiFiManager (portal cautivo).
 *   - AP mode: "Cerraduras-Setup-<chipId>" (MAC de 12 chars, único por dispositivo).
 *   - Al conectar: LED 4.5s + CLIC relé (feedback), + blink-light API (luz habitación).
 *
 * Flujo normal:
 *   1. Lee QR vía USB-Host (EspUsbHost) — no consume GPIO16/17.
 *   2. Conecta a WiFi y hace POST /api/v1/qr/validate con device_id=chipId.
 *   3. Si HTTP 200 → activa relé GPIO16 durante OPEN_DURATION_MS (def. 3s).
 *   4. Si HTTP ≠200 → no acciona el relé.
 *   5. Botón mírame (GPIO4): al pulsar → POST /api/v1/devices/identify.
 *   6. Heartbeat cada 30s.
 *
 * Hardware:
 *   - ESP32-S3-USB-OTG (Board: ESP32-S3-USB-OTG / USB Mode: USB-OTG)
 *   - Lector QR 2D USB conectado al puerto USB-Host
 *   - Relé SRD-05VDC-SL-C en GPIO16 (ACTIVE-LOW tri-state)
 *   - Pulsador N.O. en GPIO4 (a GND, con INPUT_PULLUP)
 *
 * LÓGICA DEL RELÉ (verificada con test-relay.ino):
 *   pinMode(OUTPUT) + digitalWrite(LOW) = relé ON (GND en IN1) → CLIC → abierto
 *   pinMode(INPUT)                     = relé OFF (alta impedancia) → cerrado
 *
 * Librerías necesarias (PlatformIO / Arduino Library Manager):
 *   - EspUsbHost (tanakamasayuki)
 *   - WiFiManager  (tzapu)         https://github.com/tzapu/WiFiManager
 *   - Preferences  (incluida en ESP32 Arduino core)
 *
 * Placa:  Board: ESP32-S3-USB-OTG / USB Mode: USB-OTG / Upload Mode: UART0
 */

#include "EspUsbHost.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <WiFiManager.h>
#include "esp_task_wdt.h"  // Fase 1: watchdog timer

// ── Configuración de la API (hardcodeada, no depende de WiFi) ───────────────
const char* API_BASE_URL = "http://92.113.151.136:8080";
const char* API_KEY      = "8974517de1cfb1c3e6e8f2473c5f34a4cbd0252cb52ff6e1"; // RPI-DEV

// ── Relay ──────────────────────────────────────────────────────────────────
#define RELAY_PIN         16    // GPIO16 (probado con test-relay.ino)
#define OPEN_DURATION_MS  3000   // 3 segundos de pulso de apertura
#define RELAY_CLICK_MS    150    // pulso corto para feedback acústico (no abre)
#define LOCK_CHECK_US     5000   // F33: pulso de test de cerradura (5ms, no activa solenoide)

// ── LED feedback ───────────────────────────────────────────────────────────
#define LED_PIN           2     // built-in LED (GPIO2 en la mayoria de placas)
#define LED_ON_MS         4500  // tiempo encendido al conectar WiFi

// ── Identify button ("mírame") ─────────────────────────────────────────────
#define IDENTIFY_PIN          4     // GPIO4, pulsador N.O. a GND
#define IDENTIFY_DEBOUNCE_MS  50    // antirrebote

// ── Globales ──────────────────────────────────────────────────────────────
EspUsbHost usb;
String     scanned;
String     pendingQr;
bool       hasPending = false;
unsigned long lastBeat = 0;
bool       scannerConnected = false;  // F33: set true on USB connect, false on disconnect

// ── Fase 1: Non-blocking relay state ──
unsigned long relayOffAt = 0;
bool          relayPulsing = false;

// ── Fase 1: Non-blocking LED + WiFi watchdog state ──
unsigned long ledOffAt = 0;
unsigned long wifiDownSince = 0;

// Identify button state
bool       lastIdentifyState = HIGH;
bool       identifyPending   = false;
bool       pendingState      = false;
unsigned long identifyDebounce  = 0;
unsigned long lastIdentifyPrint = 0;

// ── chipId: eFuse MAC en hex (sin ':', lowercase) ─────────────────────────
String chipId() {
  uint64_t mac = ESP.getEfuseMac();
  char buf[13];
  snprintf(buf, sizeof(buf), "%02x%02x%02x%02x%02x%02x",
           (uint8_t)(mac >> 40), (uint8_t)(mac >> 32), (uint8_t)(mac >> 24),
           (uint8_t)(mac >> 16), (uint8_t)(mac >> 8),  (uint8_t)(mac));
  return String(buf);
}

// ── Feedback acústico: pulso corto de relé (clic audible, no abre) ──────────
void relayClick() {
  relayOn();
  delay(RELAY_CLICK_MS);
  relayOff();
}

// ── Feedback visual + acústico en conexión WiFi OK ─────────────────────────
void wifiConnectedFeedback() {
  Serial.println("[OK] ¡WiFi conectado! IP: " + WiFi.localIP().toString());
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, HIGH);
  relayClick();
  ledOffAt = millis() + LED_ON_MS - RELAY_CLICK_MS;  // Fase 1: no bloqueante
  Serial.printf("[OK] LED se apagará en %d ms\n", LED_ON_MS);
}

// ── Limpiar NVS si el firmware es nuevo (wipe WiFi para provisioning fresco) ─
// Compara MD5 del sketch actual con el almacenado en NVS.
// Si difieren → wipe NVS + guardar nuevo MD5 + restart.
// No requiere cambiar ninguna variable manualmente: cada reflash limpia solo.
bool clearNvsIfNewFirmware() {
  // Usamos MD5 + __DATE__ + __TIME__ para que cada recompilación
  // tenga un fingerprint único. Así cada flasheo borra NVS y pide
  // provisioning WiFi desde cero.
  String currentMd5 = ESP.getSketchMD5();
  String fingerprint = currentMd5 + "-" + __DATE__ + "-" + __TIME__;
  Preferences prefs;
  prefs.begin("cerraduras", false);
  String storedFp = prefs.getString("sketch_fp", "");

  if (storedFp != fingerprint) {
    Serial.printf("[FW] Fingerprint cambiado (%s) — limpiando NVS\n", fingerprint.c_str());
    prefs.clear();  // borra TODO el namespace: ssid, pass, sketch_fp, etc.
    prefs.putString("sketch_fp", fingerprint);
    prefs.end();
    delay(500);
    ESP.restart();
    return true;  // nunca llega aquí por el restart
  }

  prefs.end();
  Serial.printf("[FW] Fingerprint sin cambios (MD5: %s)\n", currentMd5.c_str());
  return false;
}

// ── Relé ────────────────────────────────────────────────────────────────────
void relayOn()  { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, LOW); }
void relayOff() { pinMode(RELAY_PIN, INPUT); }

void relayPulse() {
  Serial.println("[RELAY] → ON (abriendo pestillo)");
  relayOn();
  relayOffAt = millis() + OPEN_DURATION_MS;
  relayPulsing = true;
  // Fase 1: relayOff() se ejecuta de forma no bloqueante en loop()
}

// F33: Test seguro de cerradura — pulso de 5ms en relay (no activa solenoide)
// Verifica integridad del circuito: GPIO → transistor → relé → bobina
// El solenoide necesita ≥50ms para moverse; 5ms es imperceptible
bool checkLock() {
  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, LOW);          // activa relay (active-low)
  delayMicroseconds(LOCK_CHECK_US);      // 5ms = 5000µs
  digitalWrite(RELAY_PIN, HIGH);         // desactiva relay
  pinMode(RELAY_PIN, INPUT);             // vuelta a tri-state
  Serial.printf("[LOCK-CHECK] pulso %dµs en GPIO%d — OK\n", LOCK_CHECK_US, RELAY_PIN);
  return true;  // si llegamos aquí, el circuito responde
}

// ── Conectar WiFi leyendo credenciales de NVS ──────────────────────────────
// Devuelve true si conectó, false si hay que pasar a WiFiManager.
bool connectWithNvs() {
  Preferences prefs;
  prefs.begin("cerraduras", true);  // read-only
  String ssid = prefs.getString("ssid", "");
  String pass = prefs.getString("pass", "");
  prefs.end();

  if (ssid.length() == 0) {
    Serial.println("[WIFI] NVS vacío — se necesita provisioning.");
    return false;
  }

  Serial.printf("[WIFI] Conectando con NVS → SSID=%s...\n", ssid.c_str());
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid.c_str(), pass.c_str());

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 30) { // ~15s timeout
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    wifiConnectedFeedback();
    return true;
  }

  Serial.println("\n[WIFI] Falló conexión con NVS.");
  return false;
}

// ── WiFiManager provisioning ────────────────────────────────────────────────
void startWiFiManager() {
  String apSsid = "Cerraduras-Setup-" + chipId();
  Serial.printf("\n[WiFiManager] Iniciando AP: %s\n", apSsid.c_str());
  Serial.println("[WiFiManager] Conectate a esta red con un movil y configura el WiFi.");

  WiFiManager wm;
  wm.setConfigPortalTimeout(300); // 5 minutos de timeout

  // autoConnect: si no hay credenciales guardadas, inicia AP
  // Si hay credenciales guardadas pero fallaron, las usa y reinicia automáticamente.
  bool connected = wm.autoConnect(apSsid.c_str());

  if (!connected) {
    Serial.println("[WiFiManager] Timeout. Reiniciando...");
    delay(1000);
    ESP.restart();
  }

  wifiConnectedFeedback();

  // Guardar credenciales en NVS desde WiFiManager
  Preferences prefs;
  prefs.begin("cerraduras", false);
  prefs.putString("ssid", WiFi.SSID());
  prefs.putString("pass", WiFi.psk());
  prefs.end();
  Serial.printf("[WiFiManager] NVS guardado: SSID=%s\n", WiFi.SSID().c_str());
}

// ── ensureWiFi: garantiza conexión WiFi usando NVS ──────────────────────────
bool ensureWiFi() {
  if (WiFi.status() == WL_CONNECTED) return true;

  Preferences prefs;
  prefs.begin("cerraduras", true);
  String ssid = prefs.getString("ssid", "");
  String pass = prefs.getString("pass", "");
  prefs.end();

  if (ssid.length() == 0) return false;  // sin credenciales

  WiFi.disconnect();
  delay(500);
  WiFi.begin(ssid.c_str(), pass.c_str());
  for (int i = 0; i < 20 && WiFi.status() != WL_CONNECTED; i++) delay(500);
  return WiFi.status() == WL_CONNECTED;
}

// ── Identify: notificar estado del botón a la API ─────────────────────────
void sendIdentify(bool state) {
  if (!ensureWiFi()) {
    Serial.println("[IDENTIFY] Sin WiFi — no se puede enviar");
    return;
  }
  String id   = chipId();
  String body;
  body.reserve(200);  // Fase 1: pre-allocate to prevent heap fragmentation
  body = "{\"external_id\":\"" + id + "\",\"state\":" + (state ? "true" : "false") + "}";
  HTTPClient http;
  http.begin(String(API_BASE_URL) + "/api/v1/devices/identify");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);
  http.setTimeout(1500);
  int code = http.POST(body);
  String resp = http.getString();
  http.end();
  Serial.printf("[IDENTIFY] state=%s → HTTP %d %s\n", state ? "true" : "false", code, resp.c_str());

  if (code == 200) {
    HTTPClient hb;
    hb.begin(String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
    hb.addHeader("Content-Type", "application/json");
    hb.setTimeout(2000);
    hb.POST("{\"external_id\":\"" + id + "\",\"sub_kind\":\"RPI\"}");
    hb.end();
  }
}

// ── setup ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(3000);

  relayOff();
  pinMode(IDENTIFY_PIN, INPUT_PULLUP);
  lastIdentifyState = digitalRead(IDENTIFY_PIN);

  String id = chipId();
  Serial.println("\n==============================================");
  Serial.println(" ESP32-S3 QR Reader + Relay (v5-dbg — QR diagnostics + hex dump)");
  Serial.println(" Device ID: " + id);
  Serial.println(" Relay pin: GPIO" + String(RELAY_PIN) + " (ACTIVE-LOW tri-state)");
  Serial.println("==============================================");
  Serial.println("");
  Serial.println("  🔧 PRIMER INICIO: si no hay WiFi guardada,");
  Serial.println("     este dispositivo creara la red:");
  Serial.println("     >> Cerraduras-Setup-" + id + " <<");
  Serial.println("");
  Serial.println("  🏷️  PEGATINA: Cerraduras-Setup-" + id);
  Serial.println("");

  scanned.reserve(512);
  pendingQr.reserve(512);

  // ── WiFi: wipe NVS si firmware nuevo → NVS → connect → WiFiManager ───
  clearNvsIfNewFirmware();    // si versión != stored → wipe NVS + restart
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);

  // Fase 1: pausar TWDT nativo durante provisioning (puede tardar hasta 5 min)
  esp_task_wdt_delete(NULL);

  bool nvsConnected = connectWithNvs();

  if (!nvsConnected) {
    startWiFiManager();
  }

  // ── USB Host callbacks ──────────────────────────────────────────────
  usb.setKeyboardLayout(ESP_USB_HOST_KEYBOARD_LAYOUT_EN_US);

  usb.onDeviceConnected([](const EspUsbHostDeviceInfo &device) {
    Serial.print("\n══════════════════════════════════════════\n");
    Serial.print("[USB] DISPOSITIVO CONECTADO:\n");
    espUsbHostPrint(device);
    Serial.print("[USB] Listo. Escanea un QR...\n");
    Serial.print("══════════════════════════════════════════\n\n");
    scannerConnected = true;  // F33: track USB HID scanner presence
    if (ensureWiFi()) {
      HTTPClient hScan;
      hScan.begin(String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
      hScan.addHeader("Content-Type", "application/json");
      hScan.setTimeout(3000);
      int sc = hScan.POST("{\"external_id\":\"" + chipId() + "\",\"sub_kind\":\"SCANNER\"}");
      String sr = hScan.getString();
      hScan.end();
      Serial.printf("[USB] SCANNER heartbeat → HTTP %d %s\n", sc, sr.c_str());
    }
  });

  usb.onDeviceDisconnected([](const EspUsbHostDeviceInfo &device) {
    Serial.print("\n[USB] desconectado: ");
    espUsbHostPrint(device);
    scannerConnected = false;  // F33: USB scanner disconnected
  });

  usb.onKeyboard([](const EspUsbHostKeyboardEvent &event) {
    if (!event.pressed) return;
    if (event.ascii == '\r' || event.ascii == '\n') {
      if (scanned.length() > 0) {
        // ── v5-dbg: diagnóstico completo ANTES de filtrar ──────────
        // Trim whitespace que el scanner GM65 pueda añadir (CR, LF, espacios)
        scanned.trim();

        int dots = 0;
        for (unsigned int i = 0; i < scanned.length(); i++) {
          if (scanned[i] == '.') dots++;
        }

        // ── VOLCADO COMPLETO del texto escaneado ─────────────────
        Serial.println("\n══════════════════════════════════════════");
        Serial.printf("[QR-SCAN] len=%d  dots=%d\n", scanned.length(), dots);
        Serial.printf("[QR-SCAN] TEXTO: %s\n", scanned.c_str());

        // Hex dump: primeros 60 bytes (3 filas de 20) para detectar
        // caracteres invisibles (BOM, STX, ETX, NUL, etc.)
        Serial.print("[QR-SCAN] HEX:   ");
        int dumpLen = scanned.length() < 60 ? scanned.length() : 60;
        for (int i = 0; i < dumpLen; i++) {
          Serial.printf("%02X ", (uint8_t)scanned[i]);
          if ((i + 1) % 20 == 0 && i + 1 < dumpLen)
            Serial.print("\n                 ");
        }
        if (scanned.length() > 60) {
          Serial.print("... (truncado, total ");
          Serial.print(scanned.length());
          Serial.print(" bytes)");
        }
        Serial.println();

        // Últimos 20 bytes (útil si el scanner añade sufijo)
        if (scanned.length() > 60) {
          Serial.print("[QR-SCAN] HEX tail: ");
          int tailStart = scanned.length() - 20;
          for (int i = tailStart; i < scanned.length(); i++) {
            Serial.printf("%02X ", (uint8_t)scanned[i]);
          }
          Serial.println();
        }
        Serial.println("══════════════════════════════════════════");

        // ── Validación de formato ────────────────────────────────
        // Requisitos: exactamente 2 puntos (token JWT-like) y >= 100 chars
        if (dots != 2 || scanned.length() < 100) {
          Serial.printf("[QR] RECHAZADO — dots=%d (necesita 2), len=%d (necesita >=100)\n",
                        dots, scanned.length());
          if (scanned.length() < 100)
            Serial.println("[QR]   → posible lectura parcial o token truncado");
          if (dots > 2)
            Serial.println("[QR]   → scanner añade puntos extra (prefijo/sufijo?)");
          if (dots < 2)
            Serial.println("[QR]   → faltan puntos — token malformado o scanner omite '.'");
          relayClick();  // feedback acústico: lectura detectada pero rechazada
          scanned = "";
          return;
        }

        // ── Formato OK → encolar para POST a la API ──────────────
        Serial.println("[QR] Formato OK → encolando para validación API");
        pendingQr  = scanned;
        hasPending = true;
        scanned    = "";
      }
      return;
    }
    // Acumular solo caracteres imprimibles (excluir DEL 0x7F)
    if (event.ascii >= 0x20 && event.ascii != 0x7F) scanned += (char)event.ascii;
  });

  if (!usb.begin()) {
    Serial.printf("usb.begin() fallo: %s\n", usb.lastErrorName());
  } else {
    Serial.println("[USB] Host iniciado correctamente");
  }

  // ── Health check + blink (solo en WiFi NUEVA) ──────────────────────
  if (ensureWiFi()) {
    HTTPClient h;
    h.begin(String(API_BASE_URL) + "/api/v1/health");
    h.setTimeout(4000);
    int c = h.GET();
    String healthResp = h.getString();
    h.end();
    Serial.printf("[BOOT] API health: HTTP %d — %s\n", c, healthResp.c_str());

    // Solo parpadea el switch si la red WiFi es NUEVA (no reconexión a red conocida)
    Preferences prefs;
    prefs.begin("cerraduras", false);
    String currentSsid = WiFi.SSID();
    String lastSsid    = prefs.getString("last_ssid", "");
    prefs.end();

    if (currentSsid.length() > 0 && currentSsid != lastSsid) {
      Serial.printf("[BOOT] WiFi NUEVA: '%s' (anterior: '%s') → parpadeo\n",
                    currentSsid.c_str(),
                    lastSsid.length() ? lastSsid.c_str() : "ninguna");

      String blinkBody;
      blinkBody.reserve(120);  // Fase 1: pre-allocate
      blinkBody = "{\"external_id\":\"" + chipId() + "\"}";
      HTTPClient h2;
      h2.begin(String(API_BASE_URL) + "/dashboard-api/blink-light");
      h2.addHeader("Content-Type", "application/json");
      h2.setTimeout(15000);
      int bc = h2.POST(blinkBody);
      String blinkResp = h2.getString();
      h2.end();
      Serial.printf("[BOOT] Blink light: HTTP %d — %s\n", bc, blinkResp.c_str());

      // Guardar nuevo SSID tras parpadeo para no repetir en próximos arranques
      prefs.begin("cerraduras", false);
      prefs.putString("last_ssid", currentSsid);
      prefs.end();
    } else {
      Serial.printf("[BOOT] WiFi conocida '%s' — sin parpadeo\n", currentSsid.c_str());
    }
  }
}

// ── loop ──────────────────────────────────────────────────────────────────
void loop() {
  // Fase 1: Watchdog — reactivar TWDT en la 1ª iteración (se pausó en setup())
  static bool wdt_ready = false;
  if (!wdt_ready) {
    esp_task_wdt_add(NULL);
    wdt_ready = true;
  }
  esp_task_wdt_reset();  // Fase 1: feed the watchdog cada iteración

  // Procesar QR pendiente
  if (hasPending) {
    if (!ensureWiFi()) {
      static unsigned long lastWifiWarn = 0;
      if (millis() - lastWifiWarn > 5000) {
        Serial.println("[QR] Pendiente pero sin WiFi — esperando reconexión...");
        lastWifiWarn = millis();
      }
      hasPending = true;  // Fase 1: reencolar para siguiente iteración (no bloqueante)
      return;
    }

    hasPending = false;
    String qr = pendingQr;
    String id = chipId();

    Serial.printf("\n══════════════════════════════════════════\n");
    Serial.printf("[QR-POST] device_id=%s\n", id.c_str());
    Serial.printf("[QR-POST] QR len=%d\n", qr.length());
    Serial.printf("[QR-POST] WiFi RSSI=%d dBm\n", WiFi.RSSI());

    // ── Escape manual del QR text para JSON (solo " y \ son peligrosos) ──
    // Base64url no contiene " ni \, pero si el scanner introduce basura
    // por error de lectura, esto evita JSON inválido.
    String qrEscaped = qr;
    qrEscaped.replace("\\", "\\\\");
    qrEscaped.replace("\"", "\\\"");

    String body;
    body.reserve(600);  // Fase 1: pre-allocate for qr_text (~400 chars) + device_id + JSON
    body = "{\"qr_text\":\"" + qrEscaped + "\",\"device_id\":\"" + id + "\"}";

    Serial.printf("[QR-POST] Body JSON (%d bytes): %s\n", body.length(), body.c_str());
    Serial.printf("══════════════════════════════════════════\n");
    HTTPClient http;
    http.begin(String(API_BASE_URL) + "/api/v1/qr/validate");
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-API-Key", API_KEY);
    http.setTimeout(4000);

    unsigned long qrStart = millis();
    int code = http.POST(body);
    unsigned long qrElapsed = millis() - qrStart;
    String resp = http.getString();
    http.end();

    Serial.printf("[QR] Validación HTTP %d (%lu ms) → %s\n", code, qrElapsed, resp.c_str());

    if (code == 200) {
      Serial.println(">> ACCESO PERMITIDO — Abriendo relé...");
      relayPulse();

      if (ensureWiFi()) {
        HTTPClient hLock;
        hLock.begin(String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
        hLock.addHeader("Content-Type", "application/json");
        hLock.setTimeout(3000);
        int lc = hLock.POST("{\"external_id\":\"" + id + "\",\"sub_kind\":\"LOCK\"}");
        String lr = hLock.getString();
        hLock.end();
        Serial.printf("[QR] LOCK heartbeat → HTTP %d %s\n", lc, lr.c_str());
      }
    } else {
      Serial.println(">> ACCESO DENEGADO");
    }
  }

  // Heartbeat cada 30s + F33 command-check
  unsigned long now = millis();
  if (now - lastBeat > 30000) {
    lastBeat = now;

    // ── Fase 1: Heap monitor ──
    unsigned long heap = ESP.getFreeHeap();
    Serial.printf("[HB] Free heap: %lu bytes\n", heap);
    if (heap < 20480) {
      Serial.printf("[HB] ⚠️ ADVERTENCIA: heap bajo (%lu bytes) — posible fuga de memoria\n", heap);
    }

    if (ensureWiFi()) {
      HTTPClient h;
      h.begin(String(API_BASE_URL) + "/api/v1/health");
      h.setTimeout(4000);
      int hc = h.GET();
      String hr = h.getString();
      h.end();
      Serial.printf("[HB] Health check: HTTP %d — %s\n", hc, hr.c_str());

      // F33: send heartbeat with batch sub_kinds for all ESP32 sub-devices
      String hbBody;
      hbBody.reserve(250);  // Fase 1: pre-allocate
      hbBody = "{\"external_id\":\"" + chipId() + "\",\"sub_kinds\":[\"RPI\",\"SCANNER\",\"LOCK\"]}";
      HTTPClient hb;
      hb.begin(String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
      hb.addHeader("Content-Type", "application/json");
      hb.setTimeout(3000);
      int hbCode = hb.POST(hbBody);
      String hbResp = hb.getString();
      hb.end();
      Serial.printf("[HB] Heartbeat [HTTP %d] %s\n", hbCode, hbResp.c_str());

      // F33: If API reports pending commands, poll and execute them
      if (hbCode == 200 && hbResp.indexOf("\"has_pending_commands\":true") > 0) {
        Serial.println("[F33] Polling pending commands...");
        HTTPClient cmdPoll;
        cmdPoll.begin(String(API_BASE_URL) + "/dashboard-api/pending-command?external_id=" + chipId());
        cmdPoll.setTimeout(3000);
        int cmdCode = cmdPoll.GET();
        String cmdResp = cmdPoll.getString();
        cmdPoll.end();
        Serial.printf("[F33] Poll → HTTP %d %s\n", cmdCode, cmdResp.c_str());

        if (cmdCode == 200) {
          // Simple JSON parse to find command_id and command type
          // (no ArduinoJSON — string search is lightweight enough for this)
          int cmdIdStart = cmdResp.indexOf("\"id\":");
          int cmdNameStart = cmdResp.indexOf("\"command\":\"");
          if (cmdIdStart > 0 && cmdNameStart > 0) {
            // Extract command id
            int cmdIdValStart = cmdResp.indexOf(":", cmdIdStart) + 1;
            int cmdIdEnd = cmdResp.indexOf(",", cmdIdValStart);
            if (cmdIdEnd < 0) cmdIdEnd = cmdResp.indexOf("}", cmdIdValStart);
            String cmdIdStr = cmdResp.substring(cmdIdValStart, cmdIdEnd);
            cmdIdStr.trim();
            int cmdId = cmdIdStr.toInt();

            // Extract command name
            int cmdValStart = cmdResp.indexOf("\"", cmdNameStart + 12) + 1;
            int cmdValEnd = cmdResp.indexOf("\"", cmdValStart);
            String cmdName = cmdResp.substring(cmdValStart, cmdValEnd);

            Serial.printf("[F33] Executing command #%d: %s\n", cmdId, cmdName.c_str());

            if (cmdName == "check") {
              // Verify SCANNER: USB HID keyboard connected?
              bool scannerOk = scannerConnected;

              // Verify LOCK: 5ms relay pulse test
              bool lockOk = checkLock();

              // RPI is always online if we're executing this
              Serial.printf("[F33] SCANNER=%s LOCK=%s RPI=true\n",
                            scannerOk ? "ok" : "offline", lockOk ? "ok" : "offline");

              // Report results
              String resultBody;
              resultBody.reserve(350);  // Fase 1: pre-allocate
              resultBody = "{\"command_id\":" + String(cmdId) +
                                   ",\"external_id\":\"" + chipId() + "\"" +
                                   ",\"results\":{" +
                                   "\"RPI\":true," +
                                   "\"SCANNER\":" + String(scannerOk ? "true" : "false") + "," +
                                   "\"LOCK\":" + String(lockOk ? "true" : "false") +
                                   "}}";

              HTTPClient cmdResult;
              cmdResult.begin(String(API_BASE_URL) + "/dashboard-api/command-result");
              cmdResult.addHeader("Content-Type", "application/json");
              cmdResult.setTimeout(3000);
              int resCode = cmdResult.POST(resultBody);
              String resResp = cmdResult.getString();
              cmdResult.end();
              Serial.printf("[F33] Command result POST → HTTP %d %s\n", resCode, resResp.c_str());
            } else {
              Serial.printf("[F33] Unknown command: %s — skipping\n", cmdName.c_str());
            }
          } else {
            Serial.println("[F33] No command data in response");
          }
        } else {
          Serial.printf("[F33] Poll failed (HTTP %d)\n", cmdCode);
        }
      }
    }
  }

  // ── Identify button ─────────────────────────────────────────────────
  bool raw = digitalRead(IDENTIFY_PIN);
  if (raw != lastIdentifyState) {
    if (now - identifyDebounce > IDENTIFY_DEBOUNCE_MS) {
      lastIdentifyState = raw;
      identifyPending   = true;
      pendingState      = (raw == LOW);
      Serial.printf("\n[IDENTIFY] flanco → state=%s\n", pendingState ? "true (pulsado)" : "false (suelto)");
    }
    identifyDebounce = now;
  }

  if (lastIdentifyState == LOW && now - lastIdentifyPrint > 500) {
    lastIdentifyPrint = now;
    Serial.printf("[IDENTIFY] PULSANDO... (%s)\n", chipId().c_str());
  }

  if (identifyPending) {
    identifyPending = false;
    sendIdentify(pendingState);
  }

  // ── Fase 1: Relé no bloqueante ──
  if (relayPulsing && millis() > relayOffAt) {
    relayOff();
    relayPulsing = false;
    Serial.println("[RELAY] → OFF (pestillo cerrado)");
  }

  // ── Fase 1: LED feedback no bloqueante ──
  if (ledOffAt && millis() > ledOffAt) {
    digitalWrite(LED_PIN, LOW);
    ledOffAt = 0;
    Serial.println("[OK] LED apagado");
  }

  // ── Fase 1: WiFi watchdog — reinicio si >2 min sin conexión ──
  if (WiFi.status() != WL_CONNECTED) {
    if (wifiDownSince == 0) {
      wifiDownSince = millis();
      Serial.println("[WIFI] Conexión perdida — contador 120s para reinicio");
    } else if (millis() - wifiDownSince > 120000) {
      Serial.println("[WIFI] 120s sin conexión — ESP.restart()");
      delay(500);
      ESP.restart();
    }
  } else {
    wifiDownSince = 0;
  }

  yield();  // Fase 1: non-blocking (antes era delay(1))
}
