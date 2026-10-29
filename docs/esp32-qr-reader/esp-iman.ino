/**
 * esp-iman.ino — SKETCH PRODUCTIVO ESP-IMÁN (BASE NUEVA R35D-B)
 * Producción QR + electroimán 12V sobre ESP32-S3-USB-OTG.
 *
 * Variante F97: mismo hardware y misma base que ESP-cerradura, pero el
 * actuador es un imán. En reposo se mantiene la retención con corriente
 * pulsada (100 ms ON / 100 ms OFF, ajustable). Al autorizar un QR se corta la
 * corriente y NO se rearma hasta que el sensor Zigbee del pack confirma el
 * cierre físico de la puerta. La puerta puede quedar abierta indefinidamente.
 *
 * El estado de puerta llega por push desde /dashboard-api/door-events
 * (Zigbee2MQTT → API → SSE). No hay polling a Tuya ni /get al sensor.
 *
 * Base: scanner-relay-prod-r35d.ino (ESP-cerradura).
 *
 * Nace del sketch productivo de la base prototipo:
 *   scanner-relay-prod-12v-robusto-lowpower.ino
 * y conserva TODA su lógica de producción:
 *   WiFiManager/NVS, credenciales de fábrica, TLS/CA, /api/v1/qr/validate,
 *   health, heartbeat, command-queue (check), identify, announce, watchdog,
 *   mitigaciones de brownout, prioridad del QR y cooldown.
 *
 * Cambios de hardware respecto a la base prototipo:
 *   - Lector: GM65 USB-HID → R35D-B por RS-485 (UART1 9600 8N1).
 *     RX=GPIO18, TX=GPIO17, DIR=GPIO7 (LOW=lectura, HIGH=escritura).
 *     Texto ASCII plano terminado en CRLF; NO es MODBUS.
 *   - Cerradura: GPIO16 activo-bajo tri-state → GPIO15 activo-alto con la
 *     secuencia del proveedor validada (≈800 ms).
 *   - LEDs externos: rojo GPIO1, verde GPIO2. Rojo ON = cerrado; verde ON
 *     durante 3 s tras una apertura. El LED verde integrado de la placa está
 *     en GPIO15 (macro LED_GREEN del variant): parpadea con el relé y NO es el
 *     LED verde externo de GPIO2.
 *   - Pulsador: GPIO4 → GPIO9 (INPUT_PULLUP, LOW = pulsado).
 *   - Sin host USB: no se incluye ni inicializa el host USB. El variant
 *     esp32s3usbotg deja GPIO17/18 en LOW en initVariant(); Serial1 se
 *     inicializa en setup(), después de ese arranque.
 *
 * Secuencia de apertura (validada en la placa nueva):
 *   250 ms HIGH +
 *   125 × (1 ms HIGH / 1 ms LOW) +
 *   30 × (5 ms HIGH / 5 ms LOW) +
 *   LOW final.
 * Se ejecuta en una tarea FreeRTOS dedicada para no bloquear loop()/TLS y
 * para que el watchdog siga alimentado. El relé nunca se queda energizado:
 * el reposo es LOW (activo-alto).
 *
 * Flujo normal:
 *   1. Lee una trama del R35D-B por UART1.
 *   2. Pre-filtra solo ruido (no vacío, ASCII imprimible, longitud mínima).
 *      No exige puntos ni un formato JWT concreto: el backend valida.
 *   3. Encola el texto y hace POST /api/v1/qr/validate con device_id=chipId.
 *   4. Solo con HTTP 200 inicia la secuencia de GPIO15 y los LEDs de apertura.
 *   5. Botón GPIO9: identify; mantenido al arrancar: factory reset de WiFi.
 *   6. Heartbeat cada 30 s + command-queue + announce de fábrica.
 *
 * Provisioning:
 *   - Credenciales WiFi en NVS (Preferences).
 *   - Cada build nuevo borra solo las claves WiFi y fuerza provisioning.
 *   - Portal cautivo "Cerraduras-Setup-<chipId>".
 *   - GPIO9 mantenido durante el arranque: borra WiFi y reabre el portal.
 *
 * TLS:
 *   - CA embebida, NTP obligatorio, handshake acotado, keep-alive con
 *     salvaguardas y sin reuso si falla.
 *
 * Monitor serie: 115200, DTR/RTS off (el puerto COM/CH343 puede retener la
 * placa en reset si se activan).
 *
 * Placa: esp32:esp32:esp32s3usbotg (ESP32-S3-USB-OTG).
 * Librerías: WiFiManager (tzapu) + Preferences (core ESP32).
 * No flashear este sketch en la placa prototipo antigua.
 */

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
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>

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
const char* API_HOST     = "cerraduras.josue.ink";
const int   API_PORT     = 443;

// ── F97: variante y parámetros del imán ────────────────────────────────────
#define IMAN_VARIANT                 "IMAN"
#define IMAN_VERSION                 "2.0-iman"
#define IMAN_HOLD_ON_MS              100   // corriente 100 ms
#define IMAN_HOLD_OFF_MS             100   // reposo 100 ms (ajustar en banco)
#define IMAN_OPEN_WAIT_MS            4000  // espera de apertura tras el QR
#define IMAN_CLOSE_STABLE_MS         300   // cierre estable antes de rearmar
#define IMAN_LINK_GRACE_MS           5000  // tolerancia de reconexión SSE
#define IMAN_SSE_READ_TIMEOUT_MS     20000
#define IMAN_SSE_RECONNECT_MS        1000

// ── Cerradura (GPIO15, ACTIVO-ALTO) ────────────────────────────────────────
// Base nueva: HIGH = activado, LOW = reposo. NO usar la lógica activo-bajo
// tri-state del prototipo. La secuencia del proveedor está validada en la placa.
#define LOCK_PIN                 15
#define LOCK_ACTIVE_HIGH         1
#define LOCK_SEQ_HOLD_MS         250   // 250 ms HIGH
#define LOCK_SEQ_FAST_CYCLES     125   // 125 × (1 ms ON / 1 ms OFF)
#define LOCK_SEQ_FAST_MS         1
#define LOCK_SEQ_SLOW_CYCLES     30    // 30 × (5 ms ON / 5 ms OFF)
#define LOCK_SEQ_SLOW_MS         5
#define LOCK_CHECK_US            5000  // F33: 5 ms (no acciona el solenoide)

// Ventana de apertura visible: verde ON / rojo OFF durante 3 s.
#define OPEN_LED_MS              3000

// ── Lector R35D-B por RS-485 (UART1, texto ASCII + CRLF) ───────────────────
// El variant esp32s3usbotg usa GPIO17/18 para el host USB y los deja LOW en
// initVariant(). Este sketch NO habilita el host USB e inicializa Serial1 en
// setup(), después de ese arranque. En operación normal solo se lee.
#define R35D_BAUD                9600
#define R35D_RX_PIN              18
#define R35D_TX_PIN              17
#define R35D_DIR_PIN             7     // LOW = lectura, HIGH = escritura
#define R35D_BUF_MAX             512
#define R35D_GAP_MS              120   // fin de trama si no llega CRLF
#define R35D_RX_BUFFER           1024  // el driver debe aguantar un JWT de ~200 B

// Pre-filtro: el R35D-B entrega el texto tal cual. No exigir puntos ni un
// formato JWT: el backend es la autoridad. Solo se descarta ruido/vacío/short.
// 8 permite que un token corto llegue a la API y quede auditado como 403.
#define QR_MIN_LEN               8

// Cooldown/debounce del mismo token (el lector tiene ~1 s entre lecturas).
#define SAME_TOKEN_COOLDOWN_MS   2000

// ── LEDs externos (rojo GPIO1 / verde GPIO2) ───────────────────────────────
// Rojo ON/verde OFF = cerrado. Verde ON/rojo OFF = apertura (3 s).
// El LED verde integrado de la placa (GPIO15) NO se usa como indicador: es el
// pin de la cerradura y parpadea con la secuencia.
#define LED_RED_PIN              1
#define LED_GREEN_PIN            2

// ── Identify button ("mírame") + factory reset de provisioning ─────────────
// GPIO9, pulsador N.O. a GND con INPUT_PULLUP: LOW = pulsado.
// Mantenerlo al arrancar borra las credenciales WiFi (igual que GPIO4 antes).
#define IDENTIFY_PIN             9
#define IDENTIFY_DEBOUNCE_MS     50

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
String     pendingQr;
bool       hasPending = false;
// F47 (RF-56.1): instante (millis) en que se encoló el QR, para medir el tramo
// encolado→POST e identificar espera de bucle vs. handshake TLS.
unsigned long qrEnqueuedAt = 0;
unsigned long lastBeat = 0;
// El R35D-B va soldado a la placa: UART1 lista = SCANNER disponible. No hay
// detección hot-plug como en el lector USB anterior.
bool       scannerConnected = false;
volatile bool scannerBeatPending = false;

// FIX #1: telemetría de rechazos QR diferida. Nunca se hace HTTP desde el
// parser de UART; se marca el flag y loop() envía con el cliente TLS compartido.
volatile bool qrRejectPending = false;
volatile int  qrRejectLen     = 0;
volatile int  qrRejectDots    = 0;
String        qrRejectText;

// ── Lector R35D-B: buffer de trama ──
uint8_t       r35dBuf[R35D_BUF_MAX];
size_t        r35dLen = 0;
unsigned long r35dLastByteAt = 0;

// ── Cooldown/debounce del mismo token ──
String        lastQrText;
unsigned long lastQrAt = 0;

// ── Cerradura: secuencia no bloqueante en tarea FreeRTOS ──
// relayPulsing = secuencia en curso; se usa para no solapar TLS con la bobina.
volatile bool relayPulsing = false;
volatile bool lockSequenceBusy = false;
TaskHandle_t  lockTaskHandle = nullptr;
unsigned long greenUntil = 0;   // fin de la ventana verde/rojo de apertura

// ── LOW-POWER: LOCK heartbeat diferido (no solapar TLS con el relé) ──
volatile bool lockHeartbeatPending = false;

// ── F97: máquina de estados del imán y canal de puerta ─────────────────────
enum ImanState {
  IMAN_UNSYNCED = 0,   // sin estado fiable → salida OFF
  IMAN_HOLDING,        // puerta cerrada → corriente pulsada
  IMAN_WAIT_OPEN,      // QR autorizado → sin corriente, espera apertura
  IMAN_OPEN,           // puerta abierta → sin corriente indefinidamente
  IMAN_CLOSING,        // cierre detectado → confirmación antes de rearmar
};
volatile int      imanState             = IMAN_UNSYNCED;
volatile bool     imanReleaseRequest    = false;
volatile bool     imanOutputOn          = false;
volatile bool     imanDoorKnown         = false;
volatile bool     imanDoorOpen          = false;
volatile bool     imanNeedFreshEvent    = true;
volatile uint32_t imanLastEventId       = 0;
volatile uint32_t imanDoorEventSeq      = 0;
volatile bool     imanLinkConnected     = false;
volatile unsigned long imanLinkHealthyAt = 0;
TaskHandle_t imanTaskHandle  = nullptr;
TaskHandle_t doorTaskHandle  = nullptr;

// ── LOW-POWER: motivo del arranque (auto-throttle) ──
esp_reset_reason_t bootResetReason = ESP_RST_UNKNOWN;
bool               bootFromBrownout = false;
bool               bootFromWdt = false;   // v2: cuelgue SW (TASK_WDT/PANIC/WDT)
// Contador de reinicios en RTC (sobrevive reinicios; se limpia en power-on).
RTC_NOINIT_ATTR uint32_t rtcBootCount;

// ── Fase 1: Non-blocking LED + WiFi watchdog state ──
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

// ── LEDs externos ──────────────────────────────────────────────────────────
// Rojo ON / verde OFF = cerrado (estado por defecto). Verde ON / rojo OFF =
// apertura durante OPEN_LED_MS. El verde integrado de GPIO15 no se toca aquí:
// pertenece a la cerradura y parpadea con la secuencia.
void setLockedLeds() {
  digitalWrite(LED_GREEN_PIN, LOW);
  digitalWrite(LED_RED_PIN, HIGH);
}

void setOpenLeds() {
  digitalWrite(LED_RED_PIN, LOW);
  digitalWrite(LED_GREEN_PIN, HIGH);
}

void initLeds() {
  pinMode(LED_RED_PIN, OUTPUT);
  pinMode(LED_GREEN_PIN, OUTPUT);
  setLockedLeds();
}

// Diagnóstico sin monitor serie. Usa el LED rojo externo (el verde significa
// apertura): N parpadeos y vuelve a rojo fijo = cerrado. No confundir con la
// ventana de apertura.
//   1 = normal | 2 = cuelgue software (WDT/PANIC) | 3 = brownout (eléctrico)
void ledBlinkDiagnostic(int times) {
  pinMode(LED_RED_PIN, OUTPUT);
  digitalWrite(LED_RED_PIN, LOW);
  for (int i = 0; i < times; i++) {
    digitalWrite(LED_RED_PIN, HIGH);
    delay(250);
    digitalWrite(LED_RED_PIN, LOW);
    delay(250);
  }
  setLockedLeds();
}

// ── F47: keep-alive TLS con salvaguardas ──────────────────────────────────
// El handshake completo cuesta ~1,8 s en cada petición (medido 2026-09-17). Con
// reutilización de conexión baja a ~0,1-0,2 s. Salvaguardas anti-cuelgue:
//   - timeout corto (4 s) → nunca espera indefinida.
//   - hueco > HTTP_IDLE_RESET_MS → conexión nueva (evita reutilizar socket muerto).
//   - tras HTTP_REUSE_FAIL_LIMIT fallos → reuse OFF automático (modo seguro).
// El cliente SOLO se usa desde loop() (nunca desde la tarea de la cerradura).
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

// ── Feedback visual en conexión WiFi OK ───────────────────────────────────
// NO se acciona la cerradura en el arranque/conexión. El LED verde externo
// significa apertura, así que WiFi no lo usa: se mantiene rojo fijo (cerrado).
void wifiConnectedFeedback() {
  Serial.println("[OK] ¡WiFi conectado! IP: " + WiFi.localIP().toString());
  setLockedLeds();
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
    // F97: ESP-imán conserva WiFi y credenciales al actualizar el sketch.
    // El flasheo debe dejar el equipo conectado; el reset de provisioning
    // sigue disponible manteniendo GPIO9 al arrancar.
    Serial.printf("[FW] Fingerprint cambiado (%s) — conservando WiFi/NVS\n", fingerprint.c_str());
    prefs.putString("build_marker", fingerprint);
    prefs.end();
    return false;
  }

  prefs.end();
  Serial.printf("[FW] Fingerprint sin cambios (MD5: %s)\n", currentMd5.c_str());
  return false;
}

// ── F97: salida del imán + máquina de estados ──────────────────────────────
// GPIO15 activo-alto. Un único controlador escribe la salida; ninguna petición
// HTTP ni diagnóstico la toca directamente.
static void imanOutput(bool on) {
  digitalWrite(LOCK_PIN, on ? HIGH : LOW);
  imanOutputOn = on;
}

static const char* imanStateName(int state) {
  switch (state) {
    case IMAN_HOLDING:   return "HOLDING";
    case IMAN_WAIT_OPEN: return "WAIT_OPEN";
    case IMAN_OPEN:      return "OPEN";
    case IMAN_CLOSING:   return "CLOSING";
    default:             return "UNSYNCED";
  }
}

static void imanEnter(int next) {
  if (next == imanState) return;
  imanState = next;
  if (next != IMAN_HOLDING) imanOutput(false);
  Serial.printf("[IMAN] estado → %s\n", imanStateName(next));
}

static bool imanLinkFresh() {
  if (!imanLinkConnected || imanLinkHealthyAt == 0) return false;
  return (millis() - imanLinkHealthyAt) <= IMAN_LINK_GRACE_MS;
}

static void imanControlTask(void *arg) {
  (void) arg;
  bool holdPhaseOn = true;
  unsigned long lastPulse = 0;
  unsigned long waitStarted = 0;
  unsigned long closeStarted = 0;

  for (;;) {
    const bool linkFresh = imanLinkFresh();
    const bool known = imanDoorKnown;
    const bool open = imanDoorOpen;
    const unsigned long now = millis();

    if (!linkFresh) {
      imanEnter(IMAN_UNSYNCED);
      imanOutput(false);
      holdPhaseOn = false;
      vTaskDelay(pdMS_TO_TICKS(10));
      continue;
    }

    if (imanReleaseRequest) {
      imanReleaseRequest = false;
      imanOutput(false);
      imanEnter(IMAN_WAIT_OPEN);
      waitStarted = now;
      holdPhaseOn = false;
      setOpenLeds();
      greenUntil = now + OPEN_LED_MS;
    }

    switch (imanState) {
      case IMAN_UNSYNCED:
        imanOutput(false);
        if (known && !imanNeedFreshEvent) {
          if (open) {
            imanEnter(IMAN_OPEN);
          } else {
            imanEnter(IMAN_HOLDING);
            holdPhaseOn = true;
            lastPulse = now;
            imanOutput(true);
          }
        }
        break;

      case IMAN_HOLDING:
        if (!known || imanNeedFreshEvent) {
          imanEnter(IMAN_UNSYNCED);
          imanOutput(false);
        } else if (open) {
          imanEnter(IMAN_OPEN);
          imanOutput(false);
        } else {
          // Corriente pulsada para no sobrecalentar la fuente/conversor.
          const unsigned long interval = holdPhaseOn ? IMAN_HOLD_ON_MS : IMAN_HOLD_OFF_MS;
          if ((now - lastPulse) >= interval) {
            holdPhaseOn = !holdPhaseOn;
            lastPulse = now;
            imanOutput(holdPhaseOn);
          }
        }
        break;

      case IMAN_WAIT_OPEN:
        imanOutput(false);
        if (!known || imanNeedFreshEvent) {
          imanEnter(IMAN_UNSYNCED);
        } else if (open) {
          imanEnter(IMAN_OPEN);
        } else if ((now - waitStarted) >= IMAN_OPEN_WAIT_MS) {
          imanEnter(IMAN_HOLDING);
          holdPhaseOn = true;
          lastPulse = now;
          imanOutput(true);
        }
        break;

      case IMAN_OPEN:
        imanOutput(false);
        if (!known || imanNeedFreshEvent) {
          imanEnter(IMAN_UNSYNCED);
        } else if (!open) {
          imanEnter(IMAN_CLOSING);
          closeStarted = now;
        }
        break;

      case IMAN_CLOSING:
        imanOutput(false);
        if (!known || imanNeedFreshEvent) {
          imanEnter(IMAN_UNSYNCED);
        } else if (open) {
          imanEnter(IMAN_OPEN);   // se reabrió durante la confirmación
        } else if ((now - closeStarted) >= IMAN_CLOSE_STABLE_MS) {
          imanEnter(IMAN_HOLDING);
          holdPhaseOn = true;
          lastPulse = now;
          imanOutput(true);
        }
        break;
    }

    vTaskDelay(pdMS_TO_TICKS(5));
  }
}

void startImanTask() {
  imanOutput(false);
  xTaskCreatePinnedToCore(imanControlTask, "iman-state", 4096, nullptr, 2,
                          &imanTaskHandle, 1);
  if (imanTaskHandle == nullptr) {
    Serial.println("[IMAN] ⚠ no se pudo crear la tarea de control");
  }
}

// El QR autorizado solo solicita la liberación; la tarea decide la secuencia.
bool startLockSequence() {
  if (imanReleaseRequest) {
    Serial.println("[IMAN] liberación ya solicitada — ignorando duplicado");
    return false;
  }
  imanOutput(false);
  imanReleaseRequest = true;
  Serial.println("[IMAN] QR OK → corte de corriente y espera de apertura");
  return true;
}

// ── F97: persistencia mínima del canal de puerta ───────────────────────────
// Último id de evento aplicado y si hace falta una transición nueva. Se guarda
// en NVS para que un reinicio no pueda rearmar el imán con un cierre cacheado.
static void saveImanPersistedState() {
  Preferences prefs;
  prefs.begin("iman-state", false);
  prefs.putULong("last_event_id", (uint32_t) imanLastEventId);
  prefs.putBool("need_fresh", (bool) imanNeedFreshEvent);
  prefs.end();
}

static void loadImanPersistedState() {
  Preferences prefs;
  prefs.begin("iman-state", true);
  imanLastEventId    = prefs.getULong("last_event_id", 0);
  imanNeedFreshEvent = prefs.getBool("need_fresh", true);
  prefs.end();
  Serial.printf("[IMAN] since_id=%lu need_fresh=%d\n",
                (unsigned long) imanLastEventId, imanNeedFreshEvent ? 1 : 0);
}

static String imanJsonString(const String &src, const char *key) {
  String pat = String("\"") + key + "\"";
  int k = src.indexOf(pat);
  if (k < 0) return "";
  int c = src.indexOf(':', k + pat.length());
  if (c < 0) return "";
  int q1 = src.indexOf('"', c + 1);
  if (q1 < 0) return "";
  int q2 = src.indexOf('"', q1 + 1);
  if (q2 < 0) return "";
  return src.substring(q1 + 1, q2);
}

static long imanJsonLong(const String &src, const char *key) {
  String pat = String("\"") + key + "\"";
  int k = src.indexOf(pat);
  if (k < 0) return 0;
  int c = src.indexOf(':', k + pat.length());
  if (c < 0) return 0;
  int end = c + 1;
  while (end < (int) src.length()
         && src[end] != ',' && src[end] != '}' && src[end] != ']') {
    end++;
  }
  return src.substring(c + 1, end).toInt();
}

static bool imanJsonTrue(const String &src, const char *key) {
  return src.indexOf(String("\"") + key + "\":true") >= 0;
}

static void imanHandleSnapshot(const String &data) {
  const uint32_t lastId = (uint32_t) imanJsonLong(data, "last_event_id");
  const bool contiguous = imanJsonTrue(data, "contiguous");
  const String state = imanJsonString(data, "door_state");

  if (lastId != imanLastEventId) {
    imanLastEventId = lastId;
  }
  if (!contiguous) {
    imanNeedFreshEvent = true;
    imanDoorKnown = false;
  } else if (state == "OPEN" || state == "CLOSED") {
    // Snapshot contiguo: no se perdió ningún evento aplicado desde since_id,
    // por lo que el estado autoritativo del servidor es confiable.
    imanNeedFreshEvent = false;
    imanDoorKnown = true;
    imanDoorOpen = (state == "OPEN");
  } else {
    imanNeedFreshEvent = true;
    imanDoorKnown = false;
  }
  saveImanPersistedState();
  Serial.printf("[IMAN] snapshot state=%s last=%lu contiguous=%d need_fresh=%d\n",
                state.c_str(), (unsigned long) lastId, contiguous ? 1 : 0,
                imanNeedFreshEvent ? 1 : 0);
}

static void imanHandleDoor(const String &data) {
  const uint32_t id = (uint32_t) imanJsonLong(data, "event_id");
  const String state = imanJsonString(data, "state");
  if (state != "OPEN" && state != "CLOSED") return;
  if (id > imanLastEventId) {
    imanLastEventId = id;
  }
  imanNeedFreshEvent = false;
  imanDoorKnown = true;
  imanDoorOpen = (state == "OPEN");
  imanDoorEventSeq++;
  saveImanPersistedState();
  Serial.printf("[IMAN] puerta %s (event=%lu)\n", state.c_str(), (unsigned long) id);
}

// ── F97: cliente SSE dedicado (no comparte TLS con loop()) ─────────────────
// La tarea solo lee el canal de puerta. El control eléctrico vive en su propia
// tarea, así que una petición TLS lenta nunca bloquea los pulsos del imán.
static void imanDoorEventTask(void *arg) {
  (void) arg;
  WiFiClientSecure *client = new WiFiClientSecure();
  if (client == nullptr) {
    Serial.println("[IMAN] ⚠ sin memoria para el cliente SSE");
    vTaskDelete(nullptr);
    return;
  }
  client->setCACert(CERRADURAS_API_CA_PEM);
  client->setHandshakeTimeout(10);
  // Timeout corto de lectura: la tarea es dedicada y no debe quedar dormida.
  client->setTimeout(2);

  for (;;) {
    if (WiFi.status() != WL_CONNECTED) {
      imanLinkConnected = false;
      vTaskDelay(pdMS_TO_TICKS(2000));
      continue;
    }

    if (!client->connect(API_HOST, API_PORT)) {
      imanLinkConnected = false;
      Serial.println("[IMAN] SSE sin conexión TLS; reintento");
      vTaskDelay(pdMS_TO_TICKS(IMAN_SSE_RECONNECT_MS));
      continue;
    }

    const String path = String("/dashboard-api/door-events?external_id=") + chipId()
                      + "&since_id=" + String((unsigned long) imanLastEventId);
    client->print(String("GET ") + path + " HTTP/1.1\r\n");
    client->print(String("Host: ") + API_HOST + "\r\n");
    client->print("Accept: text/event-stream\r\n");
    client->print(String("X-API-Key: ") + deviceFactoryKey + "\r\n");
    client->print("Connection: keep-alive\r\n\r\n");

    imanLinkConnected = true;
    imanLinkHealthyAt = millis();
    unsigned long lastData = millis();
    String currentEvent;
    String currentData;
    String lineBuf;
    lineBuf.reserve(256);
    bool sawData = false;
    Serial.printf("[IMAN] SSE conectado (%lu ms)\n", millis());

    while (client->connected()) {
      int ch = client->read();
      if (ch < 0) {
        if ((millis() - lastData) > IMAN_SSE_READ_TIMEOUT_MS) {
          Serial.println("[IMAN] SSE sin datos; reconexión");
          break;
        }
        vTaskDelay(pdMS_TO_TICKS(10));
        continue;
      }
      lastData = millis();
      imanLinkHealthyAt = lastData;
      if (!sawData) {
        sawData = true;
        Serial.println("[IMAN] SSE primer byte recibido");
      }
      if (ch == '\r') continue;
      if (ch != '\n') {
        if (lineBuf.length() < 1024) lineBuf += (char) ch;
        continue;
      }

      String line = lineBuf;
      lineBuf = "";
      line.trim();
      if (line.startsWith("event:")) {
        currentEvent = line.substring(6);
        currentEvent.trim();
      } else if (line.startsWith("data:")) {
        currentData = line.substring(5);
        currentData.trim();
      } else if (line.length() == 0 && currentData.length() > 0) {
        if (currentEvent == "snapshot") {
          imanHandleSnapshot(currentData);
        } else if (currentEvent == "door") {
          imanHandleDoor(currentData);
        } else if (currentEvent == "close") {
          Serial.println("[IMAN] SSE close (reconexión)");
        }
        currentEvent = "";
        currentData = "";
      }
    }

    imanLinkConnected = false;
    client->stop();
    vTaskDelay(pdMS_TO_TICKS(IMAN_SSE_RECONNECT_MS));
  }
}

void startDoorEventTask() {
  xTaskCreatePinnedToCore(imanDoorEventTask, "iman-door-sse", 16384, nullptr, 1,
                          &doorTaskHandle, 0);
  if (doorTaskHandle == nullptr) {
    Serial.println("[IMAN] ⚠ no se pudo crear la tarea SSE");
  }
}

// F33: Test seguro de cerradura — 5 ms HIGH (no acciona el solenoide).
// Verifica GPIO → transistor → relé → bobina. Nunca se solapa con una apertura.
bool checkLock() {
  // F97: el diagnóstico NUNCA pulsa el imán (podría liberar la puerta).
  Serial.println("[LOCK-CHECK] omitido: actuador IMAN (sin pulso de prueba)");
  return true;
}

// ── R35D-B: lectura de tramas ASCII + CRLF por UART1 ──────────────────────
// No hay callbacks: todo corre en loop(). El driver guarda hasta R35D_RX_BUFFER
// bytes mientras una petición TLS bloquea el loop.
static bool r35dIsTrim(uint8_t b) {
  return b == ' ' || b == '\r' || b == '\n' || b == '\t';
}

static void processQrFrame(const uint8_t *data, size_t n) {
  size_t start = 0, end = n;
  while (start < end && r35dIsTrim(data[start])) start++;
  while (end > start && r35dIsTrim(data[end - 1])) end--;
  // El arranque del lector puede dejar un NUL residual delante de la trama.
  while (start < end && data[start] < 0x20) start++;

  int dots = 0;
  bool printable = true;
  for (size_t i = start; i < end; i++) {
    uint8_t b = data[i];
    if (b < 0x20 || b > 0x7E) printable = false;
    if (b == '.') dots++;
  }
  size_t cleanLen = end - start;

  Serial.printf("\n[QR-R35D] frame=%u clean=%u dots=%d\n",
                (unsigned) n, (unsigned) cleanLen, dots);
  if (cleanLen == 0) {
    Serial.println("[QR-R35D] NOISE: trama vacía");
    return;
  }

  String text;
  text.reserve(cleanLen);
  for (size_t i = start; i < end; i++) text += (char) data[i];

  // El texto puede ser un JWT de ~200 bytes; no se exigen 2 puntos. Solo se
  // descarta ruido no imprimible o demasiado corto. La API decide.
  if (!printable || cleanLen < QR_MIN_LEN) {
    Serial.printf("[QR-R35D] RECHAZADO — printable=%d len=%u (min %d)\n",
                  printable ? 1 : 0, (unsigned) cleanLen, QR_MIN_LEN);
    qrRejectText    = text;
    qrRejectLen     = (int) cleanLen;
    qrRejectDots    = dots;
    qrRejectPending = true;
    return;
  }

  // Cooldown/debounce del mismo token: el lector puede reenviar en ~1 s.
  if (lastQrText == text && lastQrAt != 0 &&
      (millis() - lastQrAt) < SAME_TOKEN_COOLDOWN_MS) {
    Serial.printf("[QR-R35D] DUP mismo token (%lu ms) — ignorado\n",
                  (unsigned long)(millis() - lastQrAt));
    return;
  }

  if (hasPending) {
    Serial.println("[QR-R35D] ya hay un QR pendiente — lectura ignorada");
    return;
  }

  lastQrText = text;
  lastQrAt   = millis();
  pendingQr  = text;
  hasPending = true;
  qrEnqueuedAt = millis();

  Serial.printf("[QR-R35D] len=%u dots=%d → encolado para /api/v1/qr/validate\n",
                (unsigned) cleanLen, dots);
  // Volcado corto para diagnóstico (sin imprimir un JWT completo).
  Serial.print("[QR-R35D] HEX: ");
  size_t dumpLen = cleanLen < 40 ? cleanLen : 40;
  for (size_t i = 0; i < dumpLen; i++) Serial.printf("%02X ", data[start + i]);
  if (cleanLen > dumpLen) Serial.print("...");
  Serial.println();
}

// Lee bytes disponibles y cierra la trama con LF o por silencio (GAP).
void pollR35d() {
  while (Serial1.available()) {
    uint8_t b = (uint8_t) Serial1.read();
    r35dLastByteAt = millis();
    if (b == '\n') {
      if (r35dLen > 0) processQrFrame(r35dBuf, r35dLen);
      r35dLen = 0;
    } else {
      if (r35dLen < sizeof(r35dBuf)) {
        r35dBuf[r35dLen++] = b;
      } else {
        Serial.println("[QR-R35D] WARN buffer lleno; se procesa y reinicia");
        processQrFrame(r35dBuf, r35dLen);
        r35dLen = 0;
      }
    }
  }
  if (r35dLen > 0 && (millis() - r35dLastByteAt) >= R35D_GAP_MS) {
    processQrFrame(r35dBuf, r35dLen);
    r35dLen = 0;
  }
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
// Sin monitor serie, una lectura descartada es invisible: la API nunca recibe
// la petición. Enviamos len/dots/hex para diagnosticar truncamiento, ruido o
// basura desde el servidor. En la base R35D-B el texto llega ASCII + CRLF.
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
         ",\"hex_head\":\"" + hexHead + "\",\"hex_tail\":\"" + hexTail +
         "\",\"fw\":\"r35d-prod\"}";

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
  // Cerradura en reposo desde el PRIMER instante: GPIO15 activo-alto, LOW = off.
  // Los LEDs también se fijan de inmediato (rojo ON = cerrado).
  pinMode(LOCK_PIN, OUTPUT);
  digitalWrite(LOCK_PIN, LOW);
  initLeds();

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
                bootFromBrownout ? "  → MODO SEGURO (TX 2 dBm)"
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
  Serial.println(" ESP32-S3 QR Reader R35D-B + Cerradura 12V");
  Serial.println(" Device ID: " + id);
  Serial.printf(" Lector: UART1 %d 8N1 RX=%d TX=%d DIR=%d (LOW=lectura)\n",
                R35D_BAUD, R35D_RX_PIN, R35D_TX_PIN, R35D_DIR_PIN);
  Serial.println(" Cerradura: GPIO" + String(LOCK_PIN) + " (ACTIVO-ALTO, secuencia proveedor)");
  Serial.println(" LEDs: rojo GPIO" + String(LED_RED_PIN) + " / verde GPIO" + String(LED_GREEN_PIN));
  Serial.println(" Boton: GPIO" + String(IDENTIFY_PIN) + " (identify + factory reset al arrancar)");
  Serial.println("==============================================");
  Serial.println("");
  Serial.println("  🔧 PRIMER INICIO: si no hay WiFi guardada,");
  Serial.println("     este dispositivo creara la red:");
  Serial.println("     >> Cerraduras-Setup-" + id + " <<");
  Serial.println("");
  Serial.println("  🏷️  PEGATINA: Cerraduras-Setup-" + id);
  Serial.println("");

  pendingQr.reserve(512);
  lastQrText.reserve(512);

  // ── R35D-B por RS-485: inicializar DESPUÉS del initVariant del variant ──
  // El variant esp32s3usbotg deja GPIO17/18 LOW para el host USB. Aquí no hay
  // host USB: se reutilizan como UART1. DIR LOW = escucha (nunca se escribe en
  // operación normal). El buffer del driver cubre un JWT de ~200 B.
  pinMode(R35D_DIR_PIN, OUTPUT);
  digitalWrite(R35D_DIR_PIN, LOW);
  Serial1.setRxBufferSize(R35D_RX_BUFFER);
  Serial1.begin(R35D_BAUD, SERIAL_8N1, R35D_RX_PIN, R35D_TX_PIN);
  scannerConnected = true;      // UART lista: SCANNER disponible para F33
  scannerBeatPending = true;    // primer latido de SCANNER diferido a loop()
  r35dLen = 0;
  r35dLastByteAt = millis();
  Serial.println("[R35D] UART1 lista (9600 8N1, ASCII + CRLF, sin MODBUS)");

  // ── F97: cargar estado persistente y arrancar control del imán + SSE ──
  loadImanPersistedState();
  startImanTask();
  startDoorEventTask();

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

  Serial.println("[BOOT] listo: R35D-B escuchando, cerradura en reposo");
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

  // Leer el R35D-B lo antes posible. El driver acumula hasta R35D_RX_BUFFER
  // bytes mientras una petición TLS bloquea este loop.
  pollR35d();

  // Procesar QR pendiente. Si la cerradura está en secuencia, se espera a que
  // termine: no se solapa el POST/TLS con la bobina (prioridad QR intacta).
  if (hasPending && !relayPulsing) {
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
    // Cooldown desde el fin del POST: una relectura del mismo token que llegue
    // durante el TLS (1-4 s) tampoco genera una segunda apertura.
    lastQrAt = millis();

    if (code == 200) {
      Serial.println(">> ACCESO PERMITIDO — iniciando secuencia de cerradura...");
      startLockSequence();
      // LOW-POWER: NO hacer el heartbeat LOCK aquí. El POST TLS con la bobina
      // aún energizada sumaría pico WiFi TX + inrush del solenoide. loop() lo
      // enviará cuando relayPulsing vuelva a false.
      lockHeartbeatPending = true;
    } else {
      Serial.println(">> ACCESO DENEGADO");
    }
  }

  // ── FIX #1: telemetría de rechazo QR diferida (nunca HTTP en el parser) ──
  // LOW-POWER: no enviar con la cerradura activa (no solapar TLS con la bobina).
  if (qrRejectPending && !relayPulsing) {
    qrRejectPending = false;
    esp_task_wdt_reset();
    delay(HTTP_GAP_MS);          // respiro de rail antes de la petición TLS
    reportQrRejected(qrRejectLen, qrRejectDots, qrRejectText);
    esp_task_wdt_reset();
  }

  // ── F33: heartbeat de SCANNER diferido ─────────────────────────────
  // La UART del R35D-B marca scannerBeatPending al inicializarse; aquí se
  // envía con el cliente TLS compartido (secuencial).
  // LOW-POWER: saltar si la cerradura está activa (no solapar TLS con la bobina).
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
      Serial.printf("[R35D] SCANNER heartbeat → HTTP %d %s\n", sc, sr.c_str());
    } else {
      static unsigned long lastScannerWarn = 0;
      if (millis() - lastScannerWarn > 5000) {
        Serial.println("[R35D] SCANNER pendiente de heartbeat pero sin WiFi — reintentando...");
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
      hbBody = "{\"external_id\":\"" + chipId() + "\",\"sub_kinds\":[\"RPI\",\"SCANNER\",\"LOCK\"],\"firmware_variant\":\"" IMAN_VARIANT "\",\"firmware_version\":\"" IMAN_VERSION "\"}";
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
      if (hbResp.indexOf("\"variant_mismatch\":true") >= 0) {
        Serial.println("[IMAN] ⚠ variante configurada en el panel != sketch IMAN");
      }
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
              // SCANNER = UART1 del R35D-B inicializada.
              bool scannerOk = scannerConnected;

              // LOCK = pulso de 5 ms en GPIO15 (no acciona el solenoide).
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

  // Diferir identify mientras la cerradura está activa: evita solapar el pico
  // de WiFi TX con la bobina/solenoide (la secuencia corre en su propia tarea).
  if (identifyPending && !relayPulsing && !hasPending) {
    identifyPending = false;
    sendIdentify(pendingState);
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

  // ── LEDs: fin de la ventana de apertura (verde → rojo) ──
  // La secuencia de la cerradura dura ~800 ms; el verde se mantiene OPEN_LED_MS.
  if (greenUntil != 0 && (int32_t)(millis() - greenUntil) >= 0) {
    greenUntil = 0;
    setLockedLeds();
    Serial.println("[LED] ventana de apertura terminada: rojo ON, verde OFF");
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

  // ── Fase 39: inventario auxiliar, después de QR/UART/heartbeat/identify ──
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
