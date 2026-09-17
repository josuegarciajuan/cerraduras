/**
 * scanner-relay-prod-12v-robusto-lowpower.ino — SKETCH PRODUCTIVO (DEFINITIVO)
 * Producción QR + Relé 12V (LOW-POWER / ANTI-BROWNOUT).
 *
 * Este es el único sketch que debe flashearse en producción. Nace de
 * scanner-relay-prod-12v-robusto.ino con mitigaciones SOLO-SOFTWARE para el
 * brownout cuando la ESP32 comparte fuente con el lector USB y el módulo
 * relé/solenoide. El resto de `scanner-*.ino` del directorio es legado/pruebas.
 *
 * F47 (2026-09-17):
 *   - Instrumentación `[QR] Encolado→POST: <ms>` (del callback USB al POST) junto
 *     al ya existente `[QR] Validación HTTP <code> (<ms>)`.
 *   - Prioridad del QR: con un QR pendiente (hasPending) no se inician
 *     health/heartbeat/announce ni se encadenan peticiones TLS de mantenimiento.
 *   - NO se habilita reuso de TLS (historial de cuelgues).
 *
 * ⚠️ Reflasheo: clearNvsIfNewFirmware() borra las claves WiFi del namespace
 * `cerraduras` en cada build nuevo y reinicia → hay que reprovisionar el WiFi por
 * el portal `Cerraduras-Setup-<chipId>`. La credencial `device-cred` se conserva.
 *
 * Objetivo: evitar que los picos de corriente se solapen y bajarlos, porque el
 * pico dominante (WiFi TX a máxima potencia + handshake TLS) sigue existiendo
 * aunque se escalone. Cambios respecto a robusto:
 *   1) Radio de bajo consumo: WiFi.setTxPower(8.5 dBm, o 2 dBm si el arranque
 *      anterior fue BROWNOUT) + WiFi.setSleep(false) por estabilidad TLS.
 *      CPU a 80 MHz DESACTIVADO por defecto (ver toggles LOWPOWER_*).
 *   2) Arranque escalonado: delay() de asentamiento de la rail SIN radio antes de
 *      conectar WiFi, y usb.begin() (inrush del GM65) AL FINAL, después de
 *      WiFi/NTP/health.
 *   3) Serialización relé ↔ red: el heartbeat LOCK se difiere hasta que el relé
 *      vuelve a reposo; heartbeat periódico, SCANNER y announce se saltan mientras
 *      relayPulsing (el QR ya no hace TLS con la bobina energizada).
 *   4) Modo adaptativo: esp_reset_reason() → si el arranque anterior fue BROWNOUT,
 *      baja a 2 dBm, retrasa USB y omite el blink-light. Código de LED como
 *      diagnóstico sin monitor serie: 1 = normal, 2 = cuelgue SW (WDT/PANIC),
 *      3 = brownout. Contador de reinicios en RTC_NOINIT.
 *   5) Anti-bucle: el WiFi watchdog reintenta sin ESP.restart() (cada reinicio es
 *      un pico que realimenta el brownout).
 *   6) DISABLE_BROWNOUT (0 por defecto): último recurso. Poner a 1 desactiva el
 *      detector de brownout; evita el reset pero puede corromper NVS/flash.
 *
 * v2 (corrección de cuelgue TASK_WDT observado en v1):
 *   A) Toggles LOWPOWER_CPU_80MHZ=0 y LOWPOWER_WIFI_SLEEP=0 (se sospechan
 *      culpables del cuelgue; apagados para bisecar).
 *   B) TWDT arreglado: en core 3.x esp_task_wdt_init() falla por "already
 *      initialized"; se llama esp_task_wdt_reconfigure() para fijar 60 s de verdad.
 *   C) TLS: WiFiClientSecure.setHandshakeTimeout(10) (el default ~120 s supera el
 *      TWDT y reiniciaba).
 *   C2) F47 (2026-09-17): HTTPClient keep-alive ON con salvaguardas (timeout 4 s,
 *       reset de socket si hueco > 60 s, reintento en QR, reuse OFF automático
 *       tras 3 fallos). El servidor Apache usa KeepAliveTimeout 75 s. Evita el
 *       handshake (~1,8 s) en cada petición.
 *   D) v2.1: isJsonResponse() detecta respuestas 200 con HTML (un gate/proxy como
 *      panel-gate delante de la API). Un 200 con HTML NO se da por bueno: se
 *      avisa por serial y, en el announce, se reintenta.
 *
 * Hereda de robusto/no-bootclick:
 *   A) relayOff() como PRIMERA línea de setup().
 *   B) Provisioning a prueba de fallos (portal siempre si la red guardada falla).
 *   C) wifiConnectedFeedback() y QR rechazado NO accionan el relé.
 * El relé solo se acciona por apertura real (relayPulse) o verificación manual
 * (checkLock). Resto idéntico (WiFi NVS, WiFiManager, heartbeat 30 s, F33
 * command-queue, announce F40, watchdog, factory reset por GPIO4).
 *
 * RELÉ (configurable con RELAY_ACTIVE_LOW): por defecto ACTIVE-LOW tri-state para
 * el relé recuperado SONGLE SRD-12VDC-SL-C (LOW = ON, reposo FLOAT/INPUT). El
 * módulo ACTIVE-HIGH original se soporta poniendo RELAY_ACTIVE_LOW a 0.
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
 *   4b. Lectura descartada por el pre-filtro (dots≠2 o len<QR_MIN_LEN) → POST
 *       /api/v1/devices/qr-rejected con len/dots/hex (diagnóstico sin serie).
 *   5. Botón mírame (GPIO4): al pulsar → POST /api/v1/devices/identify.
 *   6. Heartbeat cada 30s.
 *
 * Hardware:
 *   - ESP32-S3-USB-OTG (Board: ESP32-S3-USB-OTG / USB Mode: USB-OTG)
 *   - Lector QR 2D USB conectado al puerto USB-Host
 *   - Relé SONGLE SRD-12VDC-SL-C en GPIO16 (ACTIVE-LOW tri-state; ver RELAY_ACTIVE_LOW)
 *   - Pulsador N.O. en GPIO4 (a GND, con INPUT_PULLUP)
 *
 * LÓGICA DEL RELÉ (verificada con test-relay-open-5s.ino):
 *   relayOn()  = OUTPUT + LOW  → relé ON → abierto
 *   relayOff() = INPUT (FLOAT) → relé OFF → cerrado (HIGH haría zumbar el relé)
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
#include "esp_attr.h"      // RTC_NOINIT_ATTR (contador de reinicios en RTC)
#include "soc/soc.h"             // último recurso: registro del brownout detector
#include "soc/rtc_cntl_reg.h"    // RTC_CNTL_BROWN_OUT_REG

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

// ── Relay ──────────────────────────────────────────────────────────────────
// Relé recuperado (SONGLE SRD-12VDC-SL-C sobre módulo ACTIVE-LOW): LOW = ON y
// reposo FLOAT (INPUT). El nivel HIGH deja la entrada en zona indeterminada y
// hace zumbar el relé, por eso NUNCA se usa como reposo.
// Poner RELAY_ACTIVE_LOW a 0 para el módulo ACTIVE-HIGH (idle LOW).
#define RELAY_ACTIVE_LOW  1
#define RELAY_PIN         16    // GPIO16 (probado con test-relay-open-5s.ino)
#define OPEN_DURATION_MS  3000   // 3 segundos de pulso de apertura
#define RELAY_CLICK_MS    150    // pulso corto para feedback acústico (no abre)
#define LOCK_CHECK_US     5000   // F33: pulso de test de cerradura (5ms, no activa solenoide)

// FIX #1: longitud mínima de un token QR válido. El token real ronda los 150
// chars; 80 evita descartar lecturas parciales leves (que ahora llegan a la API
// y quedan auditadas como 403) sin aceptar basura corta.
#define QR_MIN_LEN        80

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

// ── LOW-POWER / ANTI-BROWNOUT (solo software) ───────────────────────────────
// El pico de corriente dominante es el TX de WiFi a máxima potencia (~19.5 dBm).
// Bajarlo + escalonar cargas + no solapar el relé con TLS es la mejor defensa sin
// tocar hardware. WIFI_TX_POWER se usa en arranque normal; SAFE_TX_POWER si el
// arranque anterior terminó en BROWNOUT (auto-throttle).
#define WIFI_TX_POWER          WIFI_POWER_8_5dBm
#define SAFE_TX_POWER          WIFI_POWER_2dBm
#define BOOT_SETTLE_MS         4000          // quiet time con la radio apagada
#define USB_BOOT_DELAY_MS      500           // respiro antes de encender el host USB
#define USB_BOOT_DELAY_BROWNOUT_MS 6000      // extra si venimos de brownout
#define HTTP_GAP_MS            200           // separación entre peticiones TLS
#define WIFI_RETRY_MS          30000         // reintento de conexión (sin reinicio)

// v2 (bisect): los dos ajustes que se sospechan culpables del cuelgue/TASK_WDT
// quedan APAGADOS por defecto. Poner a 1 solo para probar en aislamiento.
//   LOWPOWER_CPU_80MHZ=1 → setCpuFrequencyMhz(80)  (puede romper timing WiFi/TLS)
//   LOWPOWER_WIFI_SLEEP=1 → WiFi.setSleep(true)    (modem-sleep puede colgar TLS)
// Con 0 se usa CPU por defecto y WiFi.setSleep(false) por estabilidad.
#define LOWPOWER_CPU_80MHZ     0
#define LOWPOWER_WIFI_SLEEP    0
#define CPU_FREQ_MHZ           80            // solo se aplica si LOWPOWER_CPU_80MHZ

// 1 = desactivar el brownout detector (ÚLTIMO RECURSO: riesgo de corrupción de
// NVS/flash si la rail cae). 0 = dejarlo activo (recomendado).
#define DISABLE_BROWNOUT       0

// ── Globales ──────────────────────────────────────────────────────────────
EspUsbHost usb;
String     scanned;
String     pendingQr;
bool       hasPending = false;
// F47 (RF-56.1): instante (millis) en que el callback USB encoló el QR, para
// medir el tramo encolado→POST e identificar espera de bucle vs. handshake TLS.
unsigned long qrEnqueuedAt = 0;
unsigned long lastBeat = 0;
bool       scannerConnected = false;  // F33: set true on USB connect, false on disconnect
// F33: el callback USB corre en la tarea del host USB. NUNCA debe usar el
// WiFiClientSecure/HTTPClient compartidos (panic por uso concurrente). El
// heartbeat de SCANNER se pide aquí y lo envía loop() de forma secuencial.
volatile bool scannerBeatPending = false;

// FIX #1: telemetría de rechazos QR diferida. El callback USB NUNCA debe usar
// WiFiClientSecure/HTTPClient (panic por uso concurrente); marca el flag y
// loop() envía la telemetría con el cliente TLS compartido (y sin solapar el
// relé). Sin esto, una lectura descartada es invisible fuera del monitor serie.
volatile bool qrRejectPending = false;
volatile int  qrRejectLen     = 0;
volatile int  qrRejectDots    = 0;
String        qrRejectText;

// ── Fase 1: Non-blocking relay state ──
unsigned long relayOffAt = 0;
bool          relayPulsing = false;

// ── LOW-POWER: LOCK heartbeat diferido (no solapar TLS con el relé) ──
volatile bool lockHeartbeatPending = false;

// ── LOW-POWER: motivo del arranque (auto-throttle) ──
esp_reset_reason_t bootResetReason = ESP_RST_UNKNOWN;
bool               bootFromBrownout = false;
bool               bootFromWdt = false;   // v2: cuelgue SW (TASK_WDT/PANIC/WDT)
// Contador de reinicios en RTC (sobrevive reinicios; se limpia en power-on).
RTC_NOINIT_ATTR uint32_t rtcBootCount;

// ── Fase 1: Non-blocking LED + WiFi watchdog state ──
unsigned long ledOffAt = 0;
unsigned long wifiDownSince = 0;

// ── Fase 39: scheduler de anuncio integrado (efímero por arranque) ─────────
bool          factoryAnnouncementEnabled = true;
bool          factoryAnnouncementInFlight = false;
unsigned long factoryNextAttemptAt = 0;
String        deviceFactoryKey;

// ── LOW-POWER: helpers de arranque ─────────────────────────────────────────
const char* resetReasonName(esp_reset_reason_t r) {
  switch (r) {
    case ESP_RST_POWERON:   return "POWERON";
    case ESP_RST_EXT:       return "EXT_RESET";
    case ESP_RST_SW:        return "SW_RESET";
    case ESP_RST_PANIC:     return "PANIC";
    case ESP_RST_INT_WDT:   return "INT_WDT";
    case ESP_RST_TASK_WDT:  return "TASK_WDT";
    case ESP_RST_WDT:       return "WDT";
    case ESP_RST_BROWNOUT:  return "BROWNOUT";
    case ESP_RST_DEEPSLEEP: return "DEEPSLEEP";
    case ESP_RST_SDIO:      return "SDIO";
    default:                return "UNKNOWN";
  }
}

// Radio de bajo consumo. Llamar SIEMPRE tras WiFi.mode() y antes de begin()/AP.
// Si el arranque anterior fue BROWNOUT, usa la potencia segura (2 dBm).
void configureRadio() {
  WiFi.setTxPower(bootFromBrownout ? SAFE_TX_POWER : WIFI_TX_POWER);
#if LOWPOWER_WIFI_SLEEP
  WiFi.setSleep(true);
#else
  WiFi.setSleep(false);  // v2: modem-sleep OFF → handshakes TLS estables
#endif
}

// v2: código de LED según el motivo del arranque anterior, para diagnóstico sin
// monitor serie (crítico cuando la placa va a la fuente).
//   1 = normal | 2 = cuelgue software (WDT/PANIC) | 3 = brownout (eléctrico)
int ledCodeForResetReason(esp_reset_reason_t r) {
  switch (r) {
    case ESP_RST_BROWNOUT:  return 3;
    case ESP_RST_TASK_WDT:
    case ESP_RST_INT_WDT:
    case ESP_RST_WDT:
    case ESP_RST_PANIC:     return 2;
    default:                return 1;
  }
}

// Diagnóstico sin monitor serie: parpadea el LED incorporado N veces.
void ledBlinkDiagnostic(int times) {
  pinMode(LED_PIN, OUTPUT);
  for (int i = 0; i < times; i++) {
    digitalWrite(LED_PIN, HIGH);
    delay(250);
    digitalWrite(LED_PIN, LOW);
    delay(250);
  }
}

// ── F47: keep-alive TLS con salvaguardas ──────────────────────────────────
// El handshake completo cuesta ~1,8 s en cada petición (medido 2026-09-17). Con
// reutilización de conexión baja a ~0,1-0,2 s. Salvaguardas anti-cuelgue:
//   - timeout corto (4 s) → nunca espera indefinida.
//   - hueco > HTTP_IDLE_RESET_MS → conexión nueva (evita reutilizar socket muerto).
//   - tras HTTP_REUSE_FAIL_LIMIT fallos → reuse OFF automático (modo seguro).
// El cliente SOLO se usa desde loop() (nunca desde callbacks USB).
bool          httpReuseEnabled  = true;
int           httpReuseFailures = 0;
unsigned long lastHttpAt        = 0;
const unsigned long HTTP_IDLE_RESET_MS   = 60000;  // > keep-alive del servidor
const int           HTTP_REUSE_FAIL_LIMIT = 3;

WiFiClientSecure &apiTlsClient() {
  static WiFiClientSecure client;
  static bool configured = false;
  if (!configured) {
    client.setCACert(CERRADURAS_API_CA_PEM);
    // v2: el handshake por defecto puede bloquear hasta ~120 s (>> TWDT) y
    // provocar el reinicio por watchdog. Se acota a 10 s para fallar/retentar.
    client.setHandshakeTimeout(10);
    client.setTimeout(4);   // F47: timeout de socket corto (anti-bloqueo)
    configured = true;
  }
  return client;
}

// F47: el destructor de un HTTPClient LOCAL llama _client->stop() y cierra
// nuestro WiFiClientSecure compartido, rompiendo el keep-alive. Una sesión
// persistente (static, nunca destruida en runtime) evita ese stop.
HTTPClient &apiHttpSession() {
  static HTTPClient s_httpSession;
  return s_httpSession;
}

void beginApiRequest(HTTPClient &http, const String &url) {
  WiFiClientSecure &cli = apiTlsClient();
  // F47: si el hueco es grande, el socket puede estar cerrado por el servidor o
  // por NAT; cerrar evita reutilizar una conexión muerta.
  if (lastHttpAt != 0 && (millis() - lastHttpAt) > HTTP_IDLE_RESET_MS) {
    cli.stop();
  }
  http.setReuse(httpReuseEnabled);
  http.begin(cli, url);
}

// F47: registra el resultado de una petición. Ante fallos repetidos con
// keep-alive, lo desactiva en caliente (vuelve al modo seguro anterior).
void noteHttpResult(bool ok) {
  lastHttpAt = millis();
  if (ok) {
    httpReuseFailures = 0;
    return;
  }
  if (!httpReuseEnabled) return;
  httpReuseFailures++;
  if (httpReuseFailures >= HTTP_REUSE_FAIL_LIMIT) {
    httpReuseEnabled = false;
    Serial.println("[HTTP] ⚠ fallos con keep-alive → reuse DESACTIVADO (modo seguro)");
    apiTlsClient().stop();
  }
}

// v2.1: detecta respuestas que NO son JSON. Un 200 con HTML significa que hay
// un gate/proxy/página de login delante de la API (p. ej. panel-gate): el HTTP
// es 200 pero el cuerpo no es la API, así que NO debe darse por bueno.
bool isJsonResponse(const String &body) {
  int i = 0;
  while (i < (int)body.length() &&
         (body[i] == ' ' || body[i] == '\t' || body[i] == '\r' || body[i] == '\n')) {
    i++;
  }
  return i < (int)body.length() && body[i] == '{';
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
      WiFi.status() != WL_CONNECTED || now < factoryNextAttemptAt ||
      hasPending) {   // F47 (RF-56.2): no iniciar TLS de anuncio con un QR pendiente
    return;
  }

  factoryAnnouncementInFlight = true;
  factoryNextAttemptAt = now + FACTORY_ANNOUNCE_RETRY_MS;

  String body;
  body.reserve(140);
  body = "{\"chip_id\":\"" + chipId() + "\",\"factory_key\":\"" + deviceFactoryKey + "\"}";

  HTTPClient &http = apiHttpSession();
  beginApiRequest(http, String(API_BASE_URL) + "/api/v1/factory-devices/announce");
  http.addHeader("Content-Type", "application/json");
  http.setTimeout(FACTORY_ANNOUNCE_TIMEOUT_MS);

  unsigned long startedAt = millis();
  int code = http.POST(body);
  String response = http.getString();
  http.end();
  factoryAnnouncementInFlight = false;

  // v2.1: un 2xx con HTML no es la API (gate/proxy delante). No interpretar como
  // válido; se reintenta.
  if (code >= 200 && code < 300 && !isJsonResponse(response)) {
    Serial.printf("[FACTORY] ⚠ HTTP %d pero NO-JSON (¿gate/proxy delante de la API?); reintento\n", code);
    return;
  }

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

// ── Relé ───────────────────────────────────────────────────────────────────
// ACTIVE-LOW (recuperado):  ON = OUTPUT + LOW   | OFF = INPUT (FLOAT, apagado limpio)
// ACTIVE-HIGH (módulo 12V): ON = OUTPUT + HIGH  | OFF = OUTPUT + LOW
void relayOn() {
#if RELAY_ACTIVE_LOW
  pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, LOW);
#else
  pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, HIGH);
#endif
}
void relayOff() {
#if RELAY_ACTIVE_LOW
  pinMode(RELAY_PIN, INPUT);   // tri-state: reposo limpio (evita el zumbido)
#else
  pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, LOW);
#endif
}

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
#if RELAY_ACTIVE_LOW
  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, LOW);          // activa relay (active-low)
  delayMicroseconds(LOCK_CHECK_US);      // 5ms = 5000µs
  pinMode(RELAY_PIN, INPUT);             // vuelve a reposo (FLOAT)
#else
  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, HIGH);         // activa relay (active-high)
  delayMicroseconds(LOCK_CHECK_US);      // 5ms = 5000µs
  digitalWrite(RELAY_PIN, LOW);          // desactiva relay
#endif
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
  configureRadio();   // LOW-POWER: TX reducido antes de asociar
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
  configureRadio();  // LOW-POWER: también baja la potencia en modo AP
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
  WiFi.mode(WIFI_STA);
  configureRadio();   // LOW-POWER: TX reducido también en reconexión
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
  HTTPClient &http = apiHttpSession();
  beginApiRequest(http, String(API_BASE_URL) + "/api/v1/devices/identify");
  http.addHeader("Content-Type", "application/json");
  addDeviceAuth(http);
  http.setTimeout(1500);
  int code = http.POST(body);
  String resp = http.getString();
  http.end();
  Serial.printf("[IDENTIFY] state=%s → HTTP %d %s\n", state ? "true" : "false", code, resp.c_str());

  if (code == 200) {
    HTTPClient &hb = apiHttpSession();
    beginApiRequest(hb, String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
    hb.addHeader("Content-Type", "application/json");
    addDeviceAuth(hb);
    hb.setTimeout(2000);
    hb.POST("{\"external_id\":\"" + id + "\",\"sub_kind\":\"RPI\"}");
    hb.end();
  }
}

// ── FIX #1: telemetría de lecturas QR rechazadas por el pre-filtro ────────
// Sin monitor serie, una lectura parcial USB-HID que el firmware descarta es
// invisible: la API nunca recibe la petición. Enviamos len/dots/hex para poder
// diagnosticar desde el servidor si fue truncamiento, puntos extra o basura.
void reportQrRejected(int qrLen, int qrDots, const String &text) {
  if (!ensureWiFi()) return;

  char buf[4];
  String hexHead;
  hexHead.reserve(220);
  int headLen = text.length() < 60 ? text.length() : 60;
  for (int i = 0; i < headLen; i++) {
    snprintf(buf, sizeof(buf), "%02X ", (uint8_t) text[i]);
    hexHead += buf;
  }
  String hexTail;
  if (text.length() > 60) {
    hexTail.reserve(80);
    for (int i = text.length() - 20; i < (int) text.length(); i++) {
      snprintf(buf, sizeof(buf), "%02X ", (uint8_t) text[i]);
      hexTail += buf;
    }
  }

  String id = chipId();
  String body;
  body.reserve(500);
  body = "{\"external_id\":\"" + id + "\",\"len\":" + String(qrLen) +
         ",\"dots\":" + String(qrDots) +
         ",\"hex_head\":\"" + hexHead + "\",\"hex_tail\":\"" + hexTail + "\"}";

  HTTPClient &http = apiHttpSession();
  beginApiRequest(http, String(API_BASE_URL) + "/api/v1/devices/qr-rejected");
  http.addHeader("Content-Type", "application/json");
  addDeviceAuth(http);
  http.setTimeout(2500);
  int code = http.POST(body);
  http.getString();
  http.end();
  Serial.printf("[QR] telemetría rechazo → HTTP %d (len=%d dots=%d)\n", code, qrLen, qrDots);
}

// ── setup ─────────────────────────────────────────────────────────────────
void setup() {
  // Relé OFF desde el PRIMER instante del sketch. Antes se hacía tras
  // Serial.begin+delay(3000): en esos ~3 s GPIO16 quedaba sin control y, con la
  // cerradura (solenoide) conectada a la fuente real, podía dispararse en el
  // arranque → brownout → reinicio en bucle sin llegar nunca al AP/heartbeat.
  relayOff();

#if DISABLE_BROWNOUT
  // ÚLTIMO RECURSO (ver DISABLE_BROWNOUT arriba): desactivar el detector de
  // brownout evita el reinicio, pero si la rail realmente cae puede corromper
  // NVS/flash. Solo tiene sentido si 1..5 no bastan.
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0);
#endif

  Serial.begin(115200);
  delay(3000);

  // ── LOW-POWER: motivo del arranque + auto-throttle ──
  bootResetReason  = esp_reset_reason();
  bootFromBrownout = (bootResetReason == ESP_RST_BROWNOUT);
  bootFromWdt = (bootResetReason == ESP_RST_TASK_WDT) ||
                (bootResetReason == ESP_RST_INT_WDT)  ||
                (bootResetReason == ESP_RST_WDT)      ||
                (bootResetReason == ESP_RST_PANIC);
  if (bootResetReason == ESP_RST_POWERON) rtcBootCount = 0;  // nuevo power-on
  rtcBootCount++;
#if LOWPOWER_CPU_80MHZ
  setCpuFrequencyMhz(CPU_FREQ_MHZ);  // v2: apagado por defecto (ver define)
#endif

  Serial.println();
  Serial.printf("[BOOT] reset_reason=%s (%d)  boot_count=%lu  CPU=%u MHz%s\n",
                resetReasonName(bootResetReason), (int)bootResetReason,
                (unsigned long)rtcBootCount, (unsigned)getCpuFrequencyMhz(),
                bootFromBrownout ? "  → MODO SEGURO (TX 2 dBm, USB tardío)"
                                 : (bootFromWdt ? "  → cuelgue SW previo" : ""));
  // Diagnóstico sin monitor serie (clave en la fuente): 1 = normal,
  // 2 = cuelgue software (WDT/PANIC), 3 = brownout.
  ledBlinkDiagnostic(ledCodeForResetReason(bootResetReason));

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
  Serial.println(" ESP32-S3 QR Reader + Relay 12V (v6-12v — ACTIVE-LOW tri-state)");
  Serial.println(" Device ID: " + id);
  Serial.println(" Relay pin: GPIO" + String(RELAY_PIN) + " (ACTIVE-LOW tri-state, SRD-12VDC)");
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
  //
  // v2: en core 3.x el TWDT ya viene inicializado por el core, así que
  // esp_task_wdt_init() devuelve ESP_ERR_INVALID_STATE ("already initialized") y
  // el timeout de 60 s NUNCA se aplicaba. Si falla, se usa esp_task_wdt_reconfigure
  // para fijar de verdad el timeout y evitar reinicios por stalls transitorios.
#if ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t wdt_cfg = {
      .timeout_ms     = 60000,   // RF-1.1: 60s
      .idle_core_mask = 0,       // no vigilar idle tasks
      .trigger_panic  = true,    // panic/reinicio al expirar
  };
  esp_err_t wdtErr = esp_task_wdt_init(&wdt_cfg);
  if (wdtErr != ESP_OK) {
    esp_task_wdt_reconfigure(&wdt_cfg);  // ya inicializado: reconfigurar
  }
#else
  esp_task_wdt_init(60, true);   // Arduino core 2.x (IDF 4.x)
#endif

  // ── LOW-POWER: asentamiento de la rail SIN radio antes de encender WiFi ──
  // Deja que los condensadores de entrada se carguen y evita que el pico de
  // asociación WiFi coincida con el inrush de arranque.
  Serial.printf("[BOOT] Asentando alimentación %d ms (radio apagada)...\n", BOOT_SETTLE_MS);
  delay(BOOT_SETTLE_MS);

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
        // Requisitos: exactamente 2 puntos (token JWT-like) y >= QR_MIN_LEN.
        if (dots != 2 || scanned.length() < QR_MIN_LEN) {
          Serial.printf("[QR] RECHAZADO — dots=%d (necesita 2), len=%d (necesita >=%d)\n",
                        dots, scanned.length(), QR_MIN_LEN);
          if (scanned.length() < QR_MIN_LEN)
            Serial.println("[QR]   → posible lectura parcial o token truncado");
          if (dots > 2)
            Serial.println("[QR]   → scanner añade puntos extra (prefijo/sufijo?)");
          if (dots < 2)
            Serial.println("[QR]   → faltan puntos — token malformado o scanner omite '.'");
          // FIX #1: encolar telemetría (sin HTTP en el callback USB). Sin
          // relayClick(): el relé solo se acciona por apertura real.
          qrRejectText    = scanned;
          qrRejectLen     = (int) scanned.length();
          qrRejectDots    = dots;
          qrRejectPending = true;
          scanned = "";
          return;
        }

        // ── Formato OK → encolar para POST a la API ──────────────
        Serial.println("[QR] Formato OK → encolando para validación API");
        pendingQr  = scanned;
        hasPending = true;
        qrEnqueuedAt = millis();   // F47: marca el inicio del tramo encolado→POST
        scanned    = "";
      }
      return;
    }
    // Acumular solo caracteres imprimibles (excluir DEL 0x7F)
    if (event.ascii >= 0x20 && event.ascii != 0x7F) scanned += (char)event.ascii;
  });

  // LOW-POWER: el registro de callbacks de arriba no consume; el arranque real
  // del host (usb.begin) se difiere al FINAL de setup() para que el inrush del
  // lector no coincida con el pico de WiFi/NTP/health. No poner usb.begin() aquí.


  // ── Sincronizar reloj antes de cualquier HTTPS (crítico para TLS) ──
  if (ensureWiFi()) {
    syncSystemTime();
  }

  // ── Health check + blink (solo en WiFi NUEVA) ──────────────────────
  if (ensureWiFi()) {
    HTTPClient &h = apiHttpSession();
    beginApiRequest(h, String(API_BASE_URL) + "/api/v1/health");
    h.setTimeout(4000);
    int c = h.GET();
    String healthResp = h.getString();
    h.end();
    Serial.printf("[BOOT] API health: HTTP %d — %s\n", c, healthResp.c_str());
    // v2.1: detecta 200 con HTML (gate/proxy delante de la API).
    if (c == 200 && !isJsonResponse(healthResp)) {
      Serial.println("[BOOT] ⚠ health 200 NO-JSON → hay un gate/proxy delante de la API");
    }

    // Solo parpadea el switch si la red WiFi es NUEVA (no reconexión a red conocida)
    Preferences prefs;
    prefs.begin("cerraduras", false);
    String currentSsid = WiFi.SSID();
    String lastSsid    = prefs.getString("last_ssid", "");
    prefs.end();

    // LOW-POWER: si venimos de brownout, no disparamos el blink-light (evita
    // una petición TLS con timeout de 15 s en pleno arranque marginal).
    if (currentSsid.length() > 0 && currentSsid != lastSsid && !bootFromBrownout) {
      Serial.printf("[BOOT] WiFi NUEVA: '%s' (anterior: '%s') → parpadeo\n",
                    currentSsid.c_str(),
                    lastSsid.length() ? lastSsid.c_str() : "ninguna");

      String blinkBody;
      blinkBody.reserve(120);  // Fase 1: pre-allocate
      blinkBody = "{\"external_id\":\"" + chipId() + "\"}";
      HTTPClient &h2 = apiHttpSession();
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

  // ── LOW-POWER: USB Host AL FINAL, con respiro antes de encenderlo ──
  // El host USB (lector GM65) produce un inrush; encenderlo tras asentar
  // WiFi/NTP/health evita sumar cargas en la ventana crítica del arranque.
  delay(bootFromBrownout ? USB_BOOT_DELAY_BROWNOUT_MS : USB_BOOT_DELAY_MS);
  if (!usb.begin()) {
    Serial.printf("usb.begin() fallo: %s\n", usb.lastErrorName());
  } else {
    Serial.println("[USB] Host iniciado correctamente");
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

    // F47 (RF-56.1): separa espera de bucle (maintenance TLS en curso) del POST.
    Serial.printf("[QR] Encolado→POST: %lu ms\n",
                  qrEnqueuedAt ? (millis() - qrEnqueuedAt) : 0);

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

    // F47: una sola pasada + reintento con conexión nueva si la reutilizada falló.
    int code = -1;
    unsigned long qrElapsed = 0;
    String resp;
    for (int attempt = 1; attempt <= 2; attempt++) {
      bool reused = apiTlsClient().connected();
      esp_task_wdt_reset();  // defensa: feed antes de HTTP bloqueante
      HTTPClient &http = apiHttpSession();
      beginApiRequest(http, String(API_BASE_URL) + "/api/v1/qr/validate");
      http.addHeader("Content-Type", "application/json");
      addDeviceAuth(http);
      http.setTimeout(4000);

      unsigned long qrStart = millis();
      code = http.POST(body);
      qrElapsed = millis() - qrStart;
      resp = http.getString();
      http.end();
      esp_task_wdt_reset();  // defensa: feed tras HTTP bloqueante
      yield();

      Serial.printf("[QR] Validación HTTP %d (%lu ms, reuse=%d) → %s\n",
                    code, qrElapsed, reused ? 1 : 0, resp.c_str());
      noteHttpResult(code > 0);

      if (code > 0 || attempt == 2) break;
      Serial.println("[QR] fallo de conexión → reintento con conexión nueva");
      apiTlsClient().stop();
    }

    if (code == 200) {
      Serial.println(">> ACCESO PERMITIDO — Abriendo relé...");
      relayPulse();
      // LOW-POWER: NO hacer el heartbeat LOCK aquí. Antes se lanzaba un POST TLS
      // con la bobina/solenoide aún energizada (OPEN_DURATION_MS) → pico WiFi TX
      // + inrush del solenoide a la vez. loop() lo enviará cuando el relé vuelva
      // a reposo (ver bloque "LOCK heartbeat diferido").
      lockHeartbeatPending = true;
    } else {
      Serial.println(">> ACCESO DENEGADO");
    }
  }

  // ── FIX #1: telemetría de rechazo QR diferida (nunca HTTP en el callback) ──
  // LOW-POWER: no enviar con el relé activo (no solapar TLS con la bobina).
  if (qrRejectPending && !relayPulsing) {
    qrRejectPending = false;
    esp_task_wdt_reset();
    delay(HTTP_GAP_MS);          // respiro de rail antes de la petición TLS
    reportQrRejected(qrRejectLen, qrRejectDots, qrRejectText);
    esp_task_wdt_reset();
  }

  // ── F33: heartbeat de SCANNER diferido ─────────────────────────────
  // El callback onDeviceConnected solo marca el flag; aquí, en la tarea
  // principal, se envía con el cliente TLS compartido (secuencial).
  // LOW-POWER: saltar si el relé está activo (no solapar TLS con la bobina).
  if (scannerBeatPending && !relayPulsing && !hasPending) {   // F47: no competir con el QR
    if (ensureWiFi()) {
      scannerBeatPending = false;
      esp_task_wdt_reset();  // feed antes de HTTP bloqueante
      HTTPClient &hScan = apiHttpSession();
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
  // LOW-POWER: si el relé está activo, NO se envía nada por red en esta vuelta;
  // se pospone (lastBeat no se actualiza) hasta que la bobina vuelva a reposo.
  unsigned long now = millis();
  if (now - lastBeat > 30000 && !relayPulsing && !hasPending) {   // F47: prioriza el QR
    lastBeat = now;

    // ── Fase 1: Heap monitor ──
    unsigned long heap = ESP.getFreeHeap();
    Serial.printf("[HB] Free heap: %lu bytes\n", heap);
    if (heap < 20480) {
      Serial.printf("[HB] ⚠️ ADVERTENCIA: heap bajo (%lu bytes) — posible fuga de memoria\n", heap);
    }

    if (ensureWiFi()) {
      esp_task_wdt_reset();  // defensa: feed antes de cadena HTTP bloqueante
      HTTPClient &h = apiHttpSession();
      beginApiRequest(h, String(API_BASE_URL) + "/api/v1/health");
      h.setTimeout(4000);
      int hc = h.GET();
      String hr = h.getString();
      h.end();
      noteHttpResult(hc > 0);   // F47: alimenta la autoprotección del keep-alive
      esp_task_wdt_reset();  // defensa: feed entre HTTP
      yield();
      Serial.printf("[HB] Health check: HTTP %d — %s\n", hc, hr.c_str());
      // v2.1: detecta 200 con HTML (gate/proxy delante de la API).
      if (hc == 200 && !isJsonResponse(hr)) {
        Serial.println("[HB] ⚠ health 200 NO-JSON → hay un gate/proxy delante de la API");
      }

      if (hasPending) return;   // F47 (RF-56.2): cede el turno al QR pendiente
      delay(HTTP_GAP_MS);  // LOW-POWER: respiro de rail entre peticiones TLS

      // F33: send heartbeat with batch sub_kinds for all ESP32 sub-devices
      String hbBody;
      hbBody.reserve(250);  // Fase 1: pre-allocate
      hbBody = "{\"external_id\":\"" + chipId() + "\",\"sub_kinds\":[\"RPI\",\"SCANNER\",\"LOCK\"]}";
      HTTPClient &hb = apiHttpSession();
      beginApiRequest(hb, String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
      hb.addHeader("Content-Type", "application/json");
      addDeviceAuth(hb);
      hb.setTimeout(3000);
      int hbCode = hb.POST(hbBody);
      String hbResp = hb.getString();
      hb.end();
      noteHttpResult(hbCode > 0);   // F47: alimenta la autoprotección del keep-alive
      esp_task_wdt_reset();  // defensa: feed entre HTTP
      yield();
      Serial.printf("[HB] Heartbeat [HTTP %d] %s\n", hbCode, hbResp.c_str());
      // v2.1: un 200 con HTML no confirma el latido en la API.
      if (hbCode == 200 && !isJsonResponse(hbResp)) {
        Serial.println("[HB] ⚠ heartbeat 200 NO-JSON → el latido NO llegó a la API");
      }

      // F33: If API reports pending commands, poll and execute them
      if (hbCode == 200 && hbResp.indexOf("\"has_pending_commands\":true") > 0) {
        Serial.println("[F33] Polling pending commands...");
        if (hasPending) return;   // F47 (RF-56.2): cede el turno al QR pendiente
        esp_task_wdt_reset();  // defensa: feed antes de HTTP bloqueante
        delay(HTTP_GAP_MS);    // LOW-POWER: respiro de rail antes del poll
        HTTPClient &cmdPoll = apiHttpSession();
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

              HTTPClient &cmdResult = apiHttpSession();
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

  // ── LOW-POWER: LOCK heartbeat diferido ──
  // Se envía SOLO con el relé ya en reposo, para que el pico de WiFi TX no
  // coincida con la bobina/solenoide energizada.
  if (lockHeartbeatPending && !relayPulsing && !hasPending) {   // F47: prioriza el QR
    lockHeartbeatPending = false;
    if (ensureWiFi()) {
      if (hasPending) return;   // F47 (RF-56.2): cede el turno al QR pendiente
      esp_task_wdt_reset();
      delay(HTTP_GAP_MS);  // respiro de rail antes de la petición TLS
      HTTPClient &hLock = apiHttpSession();
      beginApiRequest(hLock, String(API_BASE_URL) + "/dashboard-api/device-heartbeat");
      hLock.addHeader("Content-Type", "application/json");
      addDeviceAuth(hLock);
      hLock.setTimeout(3000);
      int lc = hLock.POST("{\"external_id\":\"" + chipId() + "\",\"sub_kind\":\"LOCK\"}");
      String lr = hLock.getString();
      hLock.end();
      esp_task_wdt_reset();
      yield();
      Serial.printf("[QR] LOCK heartbeat (diferido) → HTTP %d %s\n", lc, lr.c_str());
    } else {
      lockHeartbeatPending = true;  // reintentar cuando haya WiFi
    }
  }

  // ── Fase 1: LED feedback no bloqueante ──
  if (ledOffAt && millis() > ledOffAt) {
    digitalWrite(LED_PIN, LOW);
    ledOffAt = 0;
    Serial.println("[OK] LED apagado");
  }

  // ── LOW-POWER: WiFi watchdog SIN reinicio ──
  // Antes: ESP.restart() a los 120 s. En una placa marginal cada arranque es un
  // pico de inrush + asociación WiFi que realimenta el brownout (bucle). Ahora se
  // reintenta la conexión; si la red vuelve, la placa se recupera sin reiniciar.
  if (WiFi.status() != WL_CONNECTED) {
    if (wifiDownSince == 0) {
      wifiDownSince = millis();
      Serial.println("[WIFI] Conexión perdida — reintentando sin ESP.restart");
    } else if (millis() - wifiDownSince > WIFI_RETRY_MS) {
      wifiDownSince = millis();
      Serial.println("[WIFI] Reintento de conexión (sin reinicio)");
      WiFi.disconnect();
      delay(200);
      WiFi.reconnect();
    }
  } else {
    wifiDownSince = 0;
  }

  // ── Fase 39: inventario auxiliar, después de QR/USB/heartbeat/identify ──
  // HTTPClient usa el timeout corto mínimo disponible; no hay delay ni bucle de espera.
  // LOW-POWER: no anunciar con el relé activo; y dejar respiro de rail SOLO cuando
  // el announce realmente va a emitir (no delay por vuelta, para no ralentizar loop).
  esp_task_wdt_reset();  // defensa: feed antes del posible HTTP de announce
  if (!relayPulsing) {
    bool announceDue = factoryAnnouncementEnabled && !factoryAnnouncementInFlight &&
                       (now >= factoryNextAttemptAt) && (WiFi.status() == WL_CONNECTED);
    if (announceDue) {
      delay(HTTP_GAP_MS);
    }
    announceFactoryDevice(now);
  }
  esp_task_wdt_reset();  // defensa: feed tras el posible HTTP de announce
  yield();
}
