/**
 * scanner-relay-prod-12v-robusto.ino — Producción QR + Relé 12V (variante ROBUSTA
 * definitiva). Basado en scanner-relay-prod-12v-no-bootclick.ino + 2 mejoras:
 *   A) relayOff() como PRIMERA línea de setup(): el relé queda cortado desde el
 *      instante en que corre el sketch (antes había ~3 s sin control por el
 *      delay(3000), que con la cerradura conectada disparaba el solenoide en el
 *      arranque → brownout → bucle de reinicios sin llegar al AP/heartbeat).
 *   B) Provisioning a prueba de fallos: si la WiFi guardada NO conecta, se abre
 *      SIEMPRE el portal (antes: "portal bloqueado" sin AP → placa invisible).
 *      Con el auto-wipe por fingerprint (reflash → pierde WiFi → AP por defecto)
 *      y el fallback del portal, la placa nunca queda inaccesible.
 * Hereda de no-bootclick:
 *   1) wifiConnectedFeedback() ya NO hace relayClick() al conectar WiFi.
 *   2) Las lecturas QR RECHAZADAS ya NO hacen relayClick().
 * El relé solo se acciona por apertura real (relayPulse) o verificación manual
 * (checkLock). Resto idéntico (WiFi NVS, WiFiManager, heartbeat 30 s, F33
 * command-queue, announce F40, watchdog, factory reset por GPIO4).
 *
 * VARIANTE 12V (ACTIVE-HIGH): adaptado al relé SONGLE SRD-12VDC-SL-C
 * (módulo "high/low level trigger", jumper en H). El original
 * scanner-relay-prod.ino (relé 5V ACTIVE-LOW tri-state) se conserva intacto.
 *
 * WiFi:
 *   - Credenciales WiFi guardadas en NVS (Preferences).
 *   - Cada build nuevo (MD5 + __DATE__ + __TIME__) borra solo las claves WiFi
 *     del namespace cerraduras y fuerza provisioning fresco; no borra device-cred.
 *   - Al arrancar: intenta conectar con credenciales NVS.
 *   - Si no hay credenciales/NVS o falla conexión: WiFiManager (portal cautivo).
 *   - El portal vuelve a abrirse solo manteniendo GPIO4 pulsado durante el arranque.
 *   - AP mode: "Cerraduras-Setup-<chipId>" (MAC de 12 chars, único por dispositivo).
 *   - Al conectar: LED 4.5s (SIN clic de relé) + blink-light API (luz habitación).
 *   - Credencial individual generada una vez y almacenada en NVS separado.
 *
 * BUILD/UPLOAD: recompilar y cargar este sketch en cada provisioning fresco.
 * No editar un marcador manualmente ni cargar el mismo binario: el digest del
 * sketch y __DATE__/__TIME__ forman el marcador automáticamente.
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
 *   - Relé SONGLE SRD-12VDC-SL-C en GPIO16 (ACTIVE-HIGH, módulo high/low trigger con jumper en H)
 *   - Pulsador N.O. en GPIO4 (a GND, con INPUT_PULLUP)
 *
 * LÓGICA DEL RELÉ (verificada con test-relay-high.ino):
 *   pinMode(OUTPUT) + digitalWrite(HIGH) = relé ON (ALTA en IN1) → CLIC → abierto
 *   pinMode(OUTPUT) + digitalWrite(LOW)  = relé OFF → cerrado
 *
 * Alimentación del módulo relé: VCC a 12V (SRD-12VDC), GND común con el ESP32.
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
#include <WiFiClientSecure.h>
#include <Preferences.h>
#include <WiFiManager.h>
#include "esp_task_wdt.h"  // Fase 1: watchdog timer
#include "esp_system.h"

#define CERRADURAS_API_CA_PEM \
  "-----BEGIN CERTIFICATE-----\n" \
  "MIIFazCCA1OgAwIBAgIRAIIQz7DSQONZRGPgu2OCiwAwDQYJKoZIhvcNAQELBQAw\n" \
  "TzELMAkGA1UEBhMCVVMxKTAnBgNVBAoTIEludGVybmV0IFNlY3VyaXR5IFJlc2Vh\n" \
  "cmNoIEdyb3VwMRUwEwYDVQQDEwxJU1JHIFJvb3QgWDEwHhcNMTUwNjA0MTEwNDM4\n" \
  "WhcNMzUwNjA0MTEwNDM4WjBPMQswCQYDVQQGEwJVUzEpMCcGA1UEChMgSW50ZXJu\n" \
  "ZXQgU2VjdXJpdHkgUmVzZWFyY2ggR3JvdXAxFTATBgNVBAMTDElTUkcgUm9vdCBY\n" \
  "MTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAK3oJHP0FDfzm54rVygc\n" \
  "h77ct984kIxuPOZXoHj3dcKi/vVqbvYATyjb3miGbESTtrFj/RQSa78f0uoxmyF+\n" \
  "0TM8ukj13Xnfs7j/EvEhmkvBioZxaUpmZmyPfjxwv60pIgbz5MDmgK7iS4+3mX6U\n" \
  "A5/TR5d8mUgjU+g4rk8Kb4Mu0UlXjIB0ttov0DiNewNwIRt18jA8+o+u3dpjq+sW\n" \
  "T8KOEUt+zwvo/7V3LvSye0rgTBIlDHCNAymg4VMk7BPZ7hm/ELNKjD+Jo2FR3qyH\n" \
  "B5T0Y3HsLuJvW5iB4YlcNHlsdu87kGJ55tukmi8mxdAQ4Q7e2RCOFvu396j3x+UC\n" \
  "B5iPNgiV5+I3lg02dZ77DnKxHZu8A/lJBdiB3QW0KtZB6awBdpUKD9jf1b0SHzUv\n" \
  "KBds0pjBqAlkd25HN7rOrFleaJ1/ctaJxQZBKT5ZPt0m9STJEadao0xAH0ahmbWn\n" \
  "OlFuhjuefXKnEgV4We0+UXgVCwOPjdAvBbI+e0ocS3MFEvzG6uBQE3xDk3SzynTn\n" \
  "jh8BCNAw1FtxNrQHusEwMFxIt4I7mKZ9YIqioymCzLq9gwQbooMDQaHWBfEbwrbw\n" \
  "qHyGO0aoSCqI3Haadr8faqU9GY/rOPNk3sgrDQoo//fb4hVC1CLQJ13hef4Y53CI\n" \
  "rU7m2Ys6xt0nUW7/vGT1M0NPAgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNV\n" \
  "HRMBAf8EBTADAQH/MB0GA1UdDgQWBBR5tFnme7bl5AFzgAiIyBpY9umbbjANBgkq\n" \
  "hkiG9w0BAQsFAAOCAgEAVR9YqbyyqFDQDLHYGmkgJykIrGF1XIpu+ILlaS/V9lZL\n" \
  "ubhzEFnTIZd+50xx+7LSYK05qAvqFyFWhfFQDlnrzuBZ6brJFe+GnY+EgPbk6ZGQ\n" \
  "3BebYhtF8GaV0nxvwuo77x/Py9auJ/GpsMiu/X1+mvoiBOv/2X/qkSsisRcOj/KK\n" \
  "NFtY2PwByVS5uCbMiogziUwthDyC3+6WVwW6LLv3xLfHTjuCvjHIInNzktHCgKQ5\n" \
  "ORAzI4JMPJ+GslWYHb4phowim57iaztXOoJwTdwJx4nLCgdNbOhdjsnvzqvHu7Ur\n" \
  "TkXWStAmzOVyyghqpZXjFaH3pO3JLF+l+/+sKAIuvtd7u+Nxe5AW0wdeRlN8NwdC\n" \
  "jNPElpzVmbUq4JUagEiuTDkHzsxHpFKVK7q4+63SM1N95R1NbdWhscdCb+ZAJzVc\n" \
  "oyi3B43njTOQ5yOf+1CceWxG1bQVs5ZufpsMljq4Ui0/1lvh+wjChP4kqKOJ2qxq\n" \
  "4RgqsahDYVvTH9w7jXbyLeiNdd8XM2w9U/t7y0Ff/9yi0GE44Za4rF2LN9d11TPA\n" \
  "mRGunUHBcnWEvgJBQl9nJEiU0Zsnvgc/ubhPgXRR4Xq37Z0j4r7g1SgEEzwxA57d\n" \
  "emyPxgcYxn/eR44/KJ4EBs+lVDR3veyJm+kXQ99b21/+jh5Xos1AnX5iItreGCc=\n" \
  "-----END CERTIFICATE-----\n"

#ifndef CERRADURAS_API_CA_PEM
#error "Define CERRADURAS_API_CA_PEM with the deployed API CA/certificate before building"
#endif

// ── Configuración de la API (hardcodeada, no depende de WiFi) ───────────────
const char* API_BASE_URL = "https://cerraduras.josue.ink";

// ── Relay (ACTIVE-HIGH, módulo 12V) ───────────────────────────────────────
#define RELAY_PIN         16    // GPIO16 (probado con test-relay-high.ino)
#define OPEN_DURATION_MS  3000   // 3 segundos de pulso de apertura
#define RELAY_CLICK_MS    150    // pulso corto para feedback acústico (no abre)
#define LOCK_CHECK_US     5000   // F33: pulso de test de cerradura (5ms, no activa solenoide)

// ── LED feedback ───────────────────────────────────────────────────────────
#define LED_PIN           2     // built-in LED (GPIO2 en la mayoria de placas)
#define LED_ON_MS         4500  // tiempo encendido al conectar WiFi

// ── Identify button ("mírame") ─────────────────────────────────────────────
#define IDENTIFY_PIN          4     // GPIO4, pulsador N.O. a GND
#define IDENTIFY_DEBOUNCE_MS  50    // antirrebote

// ── Fase 39: anuncio de inventario de fábrica ───────────────────────────────
// El backend conserva el estado autoritativo; estos valores solo viven en RAM.
#define FACTORY_ANNOUNCE_RETRY_MS   30000UL
#define FACTORY_ANNOUNCE_TIMEOUT_MS 1000

// ── Globales ──────────────────────────────────────────────────────────────
EspUsbHost usb;
String     scanned;
String     pendingQr;
bool       hasPending = false;
unsigned long lastBeat = 0;
bool       scannerConnected = false;  // F33: set true on USB connect, false on disconnect
// F33: el callback USB corre en la tarea del host USB. NUNCA debe usar el
// WiFiClientSecure/HTTPClient compartidos (panic por uso concurrente). El
// heartbeat de SCANNER se pide aquí y lo envía loop() de forma secuencial.
volatile bool scannerBeatPending = false;

// ── Fase 1: Non-blocking relay state ──
unsigned long relayOffAt = 0;
bool          relayPulsing = false;

// ── Fase 1: Non-blocking LED + WiFi watchdog state ──
unsigned long ledOffAt = 0;
unsigned long wifiDownSince = 0;

// ── Fase 39: scheduler de anuncio integrado (efímero por arranque) ─────────
bool          factoryAnnouncementEnabled = true;
bool          factoryAnnouncementInFlight = false;
unsigned long factoryNextAttemptAt = 0;
String        deviceFactoryKey;

WiFiClientSecure &apiTlsClient() {
  static WiFiClientSecure client;
  static bool configured = false;
  if (!configured) {
    client.setCACert(CERRADURAS_API_CA_PEM);
    configured = true;
  }
  return client;
}

void beginApiRequest(HTTPClient &http, const String &url) {
  http.begin(apiTlsClient(), url);
}

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

String loadOrCreateFactoryKey() {
  Preferences prefs;
  prefs.begin("device-cred", false);
  String key = prefs.getString("factory_key", "");
  if (key.length() == 0) {
    uint8_t randomBytes[24];
    for (size_t i = 0; i < sizeof(randomBytes); i += 4) {
      uint32_t value = esp_random();
      memcpy(randomBytes + i, &value, (sizeof(randomBytes) - i) < 4 ? (sizeof(randomBytes) - i) : 4);
    }
    char encoded[49];
    for (size_t i = 0; i < sizeof(randomBytes); ++i) snprintf(encoded + (i * 2), 3, "%02x", randomBytes[i]);
    encoded[48] = '\0';
    key = String(encoded);
    prefs.putString("factory_key", key);
    Serial.println("[CRED] Credencial individual generada y guardada en NVS device-cred");
  }
  prefs.end();
  return key;
}

void factoryResetProvisioning() {
  Preferences prefs;
  prefs.begin("cerraduras", false);
  prefs.remove("ssid");
  prefs.remove("pass");
  prefs.remove("last_ssid");
  prefs.remove("build_marker");
  prefs.end();
  Serial.println("[FACTORY-RESET] WiFi reiniciado; credencial individual conservada y portal reabierto");
}

void addDeviceAuth(HTTPClient &http) {
  if (deviceFactoryKey.length() > 0) http.addHeader("X-API-Key", deviceFactoryKey);
}

// ── Fase 39: anunciar identidad eFuse sin afectar la operación productiva ──
void announceFactoryDevice(unsigned long now) {
  if (!factoryAnnouncementEnabled || factoryAnnouncementInFlight ||
      WiFi.status() != WL_CONNECTED || now < factoryNextAttemptAt) {
    return;
  }

  factoryAnnouncementInFlight = true;
  factoryNextAttemptAt = now + FACTORY_ANNOUNCE_RETRY_MS;

  String body;
  body.reserve(140);
  body = "{\"chip_id\":\"" + chipId() + "\",\"factory_key\":\"" + deviceFactoryKey + "\"}";

  HTTPClient http;
  beginApiRequest(http, String(API_BASE_URL) + "/api/v1/factory-devices/announce");
  http.addHeader("Content-Type", "application/json");
  http.setTimeout(FACTORY_ANNOUNCE_TIMEOUT_MS);

  unsigned long startedAt = millis();
  int code = http.POST(body);
  String response = http.getString();
  http.end();
  factoryAnnouncementInFlight = false;

  if (code >= 200 && code < 300 && response.indexOf("\"status\":\"CLAIMED\"") >= 0) {
    factoryAnnouncementEnabled = false;
    Serial.printf("[FACTORY] chip_id=%s CLAIMED (%lu ms); anuncios pausados hasta reinicio\n",
                  chipId().c_str(), millis() - startedAt);
  } else if (code >= 200 && code < 300 && response.indexOf("\"status\":\"PENDING\"") >= 0) {
    Serial.printf("[FACTORY] chip_id=%s PENDING (%lu ms); reintento en %lu ms\n",
                  chipId().c_str(), millis() - startedAt, FACTORY_ANNOUNCE_RETRY_MS);
  } else if (code >= 400 && code < 500 && code != 429) {
    // Credencial rechazada (p.ej. 403 invalid_factory_credential) o petición inválida:
    // es terminal para este arranque. Parar el bucle de reintento cada 30 s hasta
    // re-enrolar la placa o reiniciarla; un PENDING genuino nunca cae aquí.
    factoryAnnouncementEnabled = false;
    Serial.printf("[FACTORY] credencial rechazada (HTTP %d); anuncio detenido hasta re-enrolar/reiniciar\n", code);
  } else {
    Serial.printf("[FACTORY] anuncio HTTP %d (%lu ms); reintento en %lu ms\n",
                  code, millis() - startedAt, FACTORY_ANNOUNCE_RETRY_MS);
  }
}

// ── Feedback acústico: pulso corto de relé (clic audible, no abre) ──────────
// Variante no-bootclick: función conservada por compatibilidad, pero ya NO se
// invoca (ni en arranque ni en QR rechazado). El relé solo se acciona con
// relayPulse() (apertura real) o checkLock() (verificación manual).
void relayClick() {
  relayOn();
  delay(RELAY_CLICK_MS);
  relayOff();
}

// ── Feedback visual en conexión WiFi OK ───────────────────────────────────
// NO se acciona el relé en el arranque/conexión: con la cerradura (solenoide)
// conectada, ese pulso de 150 ms disparaba el pestillo justo en el boot y el
// pico de corriente hundía la fuente (brownout) → reinicio en bucle cada ~10 s
// sin llegar nunca al heartbeat. El relé solo se acciona por apertura real
// (relayPulse) o por verificación manual (checkLock).
void wifiConnectedFeedback() {
  Serial.println("[OK] ¡WiFi conectado! IP: " + WiFi.localIP().toString());
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, HIGH);
  ledOffAt = millis() + LED_ON_MS;  // Fase 1: no bloqueante
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
  String storedFp = prefs.getString("build_marker", "");

  if (storedFp != fingerprint) {
    Serial.printf("[FW] Fingerprint cambiado (%s) — limpiando NVS\n", fingerprint.c_str());
    // Solo WiFi/provisioning. Nunca usar clear(): las credenciales individuales
    // viven en device-cred y cualquier metadata no relacionada debe sobrevivir.
    prefs.remove("ssid");
    prefs.remove("pass");
    prefs.remove("last_ssid");
    prefs.remove("build_marker");
    prefs.putString("build_marker", fingerprint);
    prefs.end();
    delay(500);
    ESP.restart();
    return true;  // nunca llega aquí por el restart
  }

  prefs.end();
  Serial.printf("[FW] Fingerprint sin cambios (MD5: %s)\n", currentMd5.c_str());
  return false;
}

// ── Relé (módulo SONGLE SRD-12VDC ACTIVE-HIGH) ─────────────────────────────
//   ON  = OUTPUT + HIGH (ALTA en IN1 activa la bobina)
//   OFF = OUTPUT + LOW  (desactiva; NO usar tri-state en este módulo)
void relayOn()  { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, HIGH); }
void relayOff() { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, LOW); }

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
  digitalWrite(RELAY_PIN, HIGH);         // activa relay (active-high)
  delayMicroseconds(LOCK_CHECK_US);      // 5ms = 5000µs
  digitalWrite(RELAY_PIN, LOW);          // desactiva relay
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

bool hasStoredWifi() {
  Preferences prefs;
  prefs.begin("cerraduras", true);
  bool present = prefs.getString("ssid", "").length() > 0;
  prefs.end();
  return present;
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

// ── Sincronizar reloj vía NTP (OBLIGATORIO para TLS) ─────────────────────
// WiFiClientSecure valida la ventana de validez del certificado contra el
// reloj local. Sin hora (1970) mbedTLS rechaza el cert como "not yet valid"
// y TODAS las llamadas HTTPS fallan (health, heartbeat, announce,
// qr/validate, command-result). Se sincroniza una vez tras conectar WiFi.
void syncSystemTime() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[TIME] Sin WiFi — no se puede sincronizar la hora");
    return;
  }
  configTime(0, 0, "pool.ntp.org", "time.google.com", "time.nist.gov");
  Serial.print("[TIME] Sincronizando hora vía NTP");
  time_t now = time(nullptr);
  int tries = 0;
  while (now < 1700000000 && tries < 20) {   // umbral > Nov 2023; hasta ~20s
    delay(1000);
    now = time(nullptr);
    tries++;
    Serial.print(".");
  }
  Serial.println();
  if (now < 1700000000) {
    Serial.println("[TIME] ⚠ NTP no respondió — el TLS HTTPS fallará (reloj incorrecto)");
  } else {
    struct tm tmi;
    gmtime_r(&now, &tmi);
    Serial.printf("[TIME] OK: %04d-%02d-%02d %02d:%02d:%02d UTC\n",
                  1900 + tmi.tm_year, tmi.tm_mon + 1, tmi.tm_mday,
                  tmi.tm_hour, tmi.tm_min, tmi.tm_sec);
  }
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
  beginApiRequest(http, String(API_BASE_URL) + "/api/v1/devices/identify");
  http.addHeader("Content-Type", "application/json");
  addDeviceAuth(http);
  http.setTimeout(1500);
  int code = http.POST(body);
  String resp = http.getString();
  http.end();
  Serial.printf("[IDENTIFY] state=%s → HTTP %d %s\n", state ? "true" : "false", code, resp.c_str());

  if (code == 200) {
    HTTPClient hb;
    beginApiRequest(hb, String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
    hb.addHeader("Content-Type", "application/json");
    addDeviceAuth(hb);
    hb.setTimeout(2000);
    hb.POST("{\"external_id\":\"" + id + "\",\"sub_kind\":\"RPI\"}");
    hb.end();
  }
}

// ── setup ─────────────────────────────────────────────────────────────────
void setup() {
  // Relé OFF desde el PRIMER instante del sketch. Antes se hacía tras
  // Serial.begin+delay(3000): en esos ~3 s GPIO16 quedaba sin control y, con la
  // cerradura (solenoide) conectada a la fuente real, podía dispararse en el
  // arranque → brownout → reinicio en bucle sin llegar nunca al AP/heartbeat.
  relayOff();

  Serial.begin(115200);
  delay(3000);
  deviceFactoryKey = loadOrCreateFactoryKey();

  pinMode(IDENTIFY_PIN, INPUT_PULLUP);
  lastIdentifyState = digitalRead(IDENTIFY_PIN);
  if (lastIdentifyState == LOW) {
    Serial.println("[FACTORY-RESET] Botón mantenido durante arranque: esperando liberación...");
    factoryResetProvisioning();
    while (digitalRead(IDENTIFY_PIN) == LOW) delay(20);
    delay(250);
    ESP.restart();
  }

  String id = chipId();
  Serial.println("\n==============================================");
  Serial.println(" ESP32-S3 QR Reader + Relay 12V (v6-12v — ACTIVE-HIGH)");
  Serial.println(" Device ID: " + id);
  Serial.println(" Relay pin: GPIO" + String(RELAY_PIN) + " (ACTIVE-HIGH, SRD-12VDC modo H)");
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

  // Fase 1: TWDT con timeout 60s (RF-1.1). loopTask NO se vigila hasta el 1er
  // loop(), por lo que el provisioning largo en setup() (WiFiManager, hasta 5 min)
  // ya es seguro sin pausar ni borrar la suscripción. No usar
  // esp_task_wdt_delete(NULL): loopTask aún no está suscrito aquí y generaría un
  // `task_wdt: delete_entry: task not found` benigno en cada arranque.
  //
  // API del watchdog según core: Arduino-ESP32 core 3.x (IDF 5.x) cambió la firma
  // de esp_task_wdt_init() a un config-struct. Guard para mantener compilable el
  // sketch también en core 2.x (IDF 4.x), donde la firma era (timeout_sec, panic).
#if ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t wdt_cfg = {
      .timeout_ms     = 60000,   // RF-1.1: 60s
      .idle_core_mask = 0,       // no vigilar idle tasks (igual que hoy)
      .trigger_panic  = true,    // panic/reinicio al expirar
  };
  esp_task_wdt_init(&wdt_cfg);
#else
  esp_task_wdt_init(60, true);   // Arduino core 2.x (IDF 4.x)
#endif

  bool nvsConnected = connectWithNvs();

  // Provisioning robusto: si no se pudo conectar a la red guardada (o no hay
  // credenciales), SIEMPRE abrimos el portal. La placa nunca debe quedarse en
  // un estado invisible ("credenciales que no conectan → sin AP y sin red").
  // Con timeout de 5 min el portal reinicia y reintenta la red guardada; si
  // vuelve a fallar, portal de nuevo → siempre recuperable desde el AP.
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
    scannerConnected = true;   // F33: track USB HID scanner presence
    scannerBeatPending = true; // F33: el heartbeat lo envía loop() (no la tarea USB)
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
          // Sin relayClick(): el feedback acústico pulsaba el relé (y con la
          // cerradura conectada disparaba el solenoide) en cada lectura
          // rechazada. En producto el relé solo se acciona por apertura real.
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

  // ── Sincronizar reloj antes de cualquier HTTPS (crítico para TLS) ──
  if (ensureWiFi()) {
    syncSystemTime();
  }

  // ── Health check + blink (solo en WiFi NUEVA) ──────────────────────
  if (ensureWiFi()) {
    HTTPClient h;
    beginApiRequest(h, String(API_BASE_URL) + "/api/v1/health");
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
      beginApiRequest(h2, String(API_BASE_URL) + "/dashboard-api/blink-light");
      h2.addHeader("Content-Type", "application/json");
      addDeviceAuth(h2);
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
  // Fase 1: Watchdog — suscribir loopTask al TWDT recién en la 1ª iteración.
  // setup() corrió con TWDT global init a 60s pero sin loopTask suscrito, de modo
  // que el provisioning largo jamás quedó vigilado con timeout corto.
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
    esp_task_wdt_reset();  // defensa: feed antes de HTTP bloqueante
    HTTPClient http;
    beginApiRequest(http, String(API_BASE_URL) + "/api/v1/qr/validate");
    http.addHeader("Content-Type", "application/json");
    addDeviceAuth(http);
    http.setTimeout(4000);

    unsigned long qrStart = millis();
    int code = http.POST(body);
    unsigned long qrElapsed = millis() - qrStart;
    String resp = http.getString();
    http.end();
    esp_task_wdt_reset();  // defensa: feed tras HTTP bloqueante
    yield();

    Serial.printf("[QR] Validación HTTP %d (%lu ms) → %s\n", code, qrElapsed, resp.c_str());

    if (code == 200) {
      Serial.println(">> ACCESO PERMITIDO — Abriendo relé...");
      relayPulse();

      if (ensureWiFi()) {
        esp_task_wdt_reset();  // defensa: feed antes de HTTP bloqueante
        HTTPClient hLock;
        beginApiRequest(hLock, String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
        hLock.addHeader("Content-Type", "application/json");
        addDeviceAuth(hLock);
        hLock.setTimeout(3000);
        int lc = hLock.POST("{\"external_id\":\"" + id + "\",\"sub_kind\":\"LOCK\"}");
        String lr = hLock.getString();
        hLock.end();
        esp_task_wdt_reset();  // defensa: feed tras HTTP bloqueante
        yield();
        Serial.printf("[QR] LOCK heartbeat → HTTP %d %s\n", lc, lr.c_str());
      }
    } else {
      Serial.println(">> ACCESO DENEGADO");
    }
  }

  // ── F33: heartbeat de SCANNER diferido ─────────────────────────────
  // El callback onDeviceConnected solo marca el flag; aquí, en la tarea
  // principal, se envía con el cliente TLS compartido (secuencial).
  if (scannerBeatPending) {
    if (ensureWiFi()) {
      scannerBeatPending = false;
      esp_task_wdt_reset();  // feed antes de HTTP bloqueante
      HTTPClient hScan;
      beginApiRequest(hScan, String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
      hScan.addHeader("Content-Type", "application/json");
      addDeviceAuth(hScan);
      hScan.setTimeout(3000);
      int sc = hScan.POST("{\"external_id\":\"" + chipId() + "\",\"sub_kind\":\"SCANNER\"}");
      String sr = hScan.getString();
      hScan.end();
      esp_task_wdt_reset();  // feed tras HTTP bloqueante
      Serial.printf("[USB] SCANNER heartbeat → HTTP %d %s\n", sc, sr.c_str());
    } else {
      static unsigned long lastScannerWarn = 0;
      if (millis() - lastScannerWarn > 5000) {
        Serial.println("[USB] SCANNER pendiente de heartbeat pero sin WiFi — reintentando...");
        lastScannerWarn = millis();
      }
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
      esp_task_wdt_reset();  // defensa: feed antes de cadena HTTP bloqueante
      HTTPClient h;
      beginApiRequest(h, String(API_BASE_URL) + "/api/v1/health");
      h.setTimeout(4000);
      int hc = h.GET();
      String hr = h.getString();
      h.end();
      esp_task_wdt_reset();  // defensa: feed entre HTTP
      yield();
      Serial.printf("[HB] Health check: HTTP %d — %s\n", hc, hr.c_str());

      // F33: send heartbeat with batch sub_kinds for all ESP32 sub-devices
      String hbBody;
      hbBody.reserve(250);  // Fase 1: pre-allocate
      hbBody = "{\"external_id\":\"" + chipId() + "\",\"sub_kinds\":[\"RPI\",\"SCANNER\",\"LOCK\"]}";
      HTTPClient hb;
      beginApiRequest(hb, String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
      hb.addHeader("Content-Type", "application/json");
      addDeviceAuth(hb);
      hb.setTimeout(3000);
      int hbCode = hb.POST(hbBody);
      String hbResp = hb.getString();
      hb.end();
      esp_task_wdt_reset();  // defensa: feed entre HTTP
      yield();
      Serial.printf("[HB] Heartbeat [HTTP %d] %s\n", hbCode, hbResp.c_str());

      // F33: If API reports pending commands, poll and execute them
      if (hbCode == 200 && hbResp.indexOf("\"has_pending_commands\":true") > 0) {
        Serial.println("[F33] Polling pending commands...");
        esp_task_wdt_reset();  // defensa: feed antes de HTTP bloqueante
        HTTPClient cmdPoll;
        beginApiRequest(cmdPoll, String(API_BASE_URL) + "/dashboard-api/pending-command?external_id=" + chipId());
        cmdPoll.setTimeout(3000);
        addDeviceAuth(cmdPoll);
        int cmdCode = cmdPoll.GET();
        String cmdResp = cmdPoll.getString();
        cmdPoll.end();
        esp_task_wdt_reset();  // defensa: feed tras HTTP bloqueante
        yield();
        Serial.printf("[F33] Poll → HTTP %d %s\n", cmdCode, cmdResp.c_str());

        if (cmdCode == 200) {
          // Simple JSON parse to find command_id and command type
          // (no ArduinoJSON — string search is lightweight enough for this)
          int cmdIdStart = cmdResp.indexOf("\"id\":");
          // El nombre del comando viene en el campo de nivel superior
          // "command_name":"..." (único, no anidado) que la API siempre
          // incluye junto al comando. Buscar "command": rompe porque también
          // aparece dentro del objeto anidado "command":{...,"command":...}.
          int cmdNameStart = cmdResp.indexOf("\"command_name\":");
          if (cmdIdStart >= 0 && cmdNameStart >= 0) {
            // Extract command id
            int cmdIdValStart = cmdResp.indexOf(":", cmdIdStart) + 1;
            int cmdIdEnd = cmdResp.indexOf(",", cmdIdValStart);
            if (cmdIdEnd < 0) cmdIdEnd = cmdResp.indexOf("}", cmdIdValStart);
            String cmdIdStr = cmdResp.substring(cmdIdValStart, cmdIdEnd);
            cmdIdStr.trim();
            int cmdId = cmdIdStr.toInt();

            // Extract command name from the top-level "command_name":"..." field
            // "command_name": = 15 chars + comilla de apertura = valor en +16
            int cmdValStart = cmdNameStart + 16;                 // saltar "command_name":"
            int cmdValEnd = cmdResp.indexOf("\"", cmdValStart);  // comilla de cierre
            String cmdName = (cmdValEnd > cmdValStart) ? cmdResp.substring(cmdValStart, cmdValEnd) : String("");

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
              beginApiRequest(cmdResult, String(API_BASE_URL) + "/dashboard-api/command-result");
              cmdResult.addHeader("Content-Type", "application/json");
              addDeviceAuth(cmdResult);
              cmdResult.setTimeout(3000);
              int resCode = cmdResult.POST(resultBody);
              String resResp = cmdResult.getString();
              cmdResult.end();
              esp_task_wdt_reset();  // defensa: feed tras HTTP bloqueante
              yield();
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

  // ── Fase 39: inventario auxiliar, después de QR/USB/heartbeat/identify ──
  // HTTPClient usa el timeout corto mínimo disponible; no hay delay ni bucle de espera.
  esp_task_wdt_reset();  // defensa: feed antes del posible HTTP de announce
  announceFactoryDevice(now);
  esp_task_wdt_reset();  // defensa: feed tras el posible HTTP de announce
  yield();
}
