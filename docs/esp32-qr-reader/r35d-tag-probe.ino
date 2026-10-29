/**
 * r35d-tag-probe.ino — Diagnóstico de lectura del R35D-B (QR y llavero EM)
 *
 * Objetivo:
 *   Saber si el R35D-B entrega algo por RS-485 al presentar un llavero EM y
 *   ver el contenido exacto de cada trama. NO valida contra la API, NO abre
 *   la cerradura y NO usa WiFi.
 *
 * Hardware (base ESP32-S3-USB-OTG):
 *   - R35D-B por RS-485: UART1 9600 8N1, RX=GPIO18, TX=GPIO17.
 *   - Dirección RS-485: GPIO7 LOW = lectura (nunca se pone HIGH).
 *   - Cerradura: GPIO15 se deja en LOW al arrancar y NO se toca.
 *   - LEDs externos: rojo GPIO1 (reposo), verde GPIO2 (destello al leer).
 *   - Pulsador GPIO9: no se usa.
 *   - Monitor serie USB/UART0: 115200, DTR/RTS off.
 *
 * Comportamiento:
 *   1. Acumula todos los bytes de UART1, incluso binarios o muy cortos.
 *   2. Cierra una trama al recibir LF o tras R35D_GAP_MS de silencio.
 *   3. Imprime por serie: longitud, terminador, HEX y ASCII escapado.
 *   4. Emite [ALIVE] cada 5 s para distinguir "no llega nada" de un monitor
 *      desconectado.
 *   5. Marca una pista orientativa: UID EM (8/10 hex), QR probable u otra.
 *
 * Este sketch es SOLO de diagnóstico. No es el firmware productivo. Para
 * restaurar producción hay que volver a cargar el binario respaldado o el
 * sketch `scanner-relay-prod-r35d.ino`; este último puede exigir rehacer el
 * provisioning WiFi porque su marcador de build cambia.
 *
 * Placa: esp32:esp32:esp32s3usbotg
 * No flashear en la base antigua (GM65 / GPIO16).
 */

#include <Arduino.h>

// ── R35D-B (RS-485) ───────────────────────────────────────────────────────
#define R35D_BAUD             9600
#define R35D_RX_PIN           18
#define R35D_TX_PIN           17
#define R35D_DIR_PIN          7       // LOW = lectura, HIGH = escritura
#define R35D_RX_BUFFER        1024
#define R35D_FRAME_MAX        1024
#define R35D_GAP_MS           120     // fin de trama si no llega LF

// ── Indicadores y seguridad ───────────────────────────────────────────────
#define LOCK_PIN              15      // se deja en LOW; nunca se activa
#define LED_RED               1
#define LED_GREEN             2
#define GREEN_MS              400
#define ALIVE_MS              5000

static uint8_t  frame[R35D_FRAME_MAX];
static size_t   frameLen       = 0;
static bool     frameHadLf     = false;
static bool     frameHadCr     = false;
static unsigned long lastByteAt = 0;
static unsigned long lastAliveAt = 0;
static unsigned long greenUntil = 0;
static uint32_t frameSeq       = 0;
static uint32_t totalBytes     = 0;
static uint32_t totalFrames    = 0;

static bool printable(uint8_t b) {
  return b >= 0x20 && b <= 0x7E;
}

/**
 * Imprime la trama acumulada. `hadLf` indica si se cerró por LF; si no, se
 * cerró por silencio. Se recortan CR/LF finales solo para la vista, pero el
 * HEX se imprime completo para no ocultar bytes reales.
 */
static void emitFrame(bool hadLf) {
  if (frameLen == 0) return;

  frameSeq++;
  totalFrames++;

  const char *term = frameHadCr && hadLf ? "CRLF"
                    : frameHadCr         ? "CR"
                    : hadLf              ? "LF"
                                         : "NONE";

  Serial.printf("\n[TAG] seq=%lu len=%u term=%s gap_ms=%lu\n",
                (unsigned long) frameSeq,
                (unsigned) frameLen,
                term,
                (unsigned long) (millis() - lastByteAt));

  Serial.print("[TAG] HEX:");
  for (size_t i = 0; i < frameLen; i++) {
    Serial.printf(" %02X", frame[i]);
  }
  Serial.println();

  Serial.print("[TAG] ASCII: ");
  for (size_t i = 0; i < frameLen; i++) {
    uint8_t b = frame[i];
    if (printable(b)) {
      Serial.write(b);
    } else {
      Serial.printf("\\x%02X", b);
    }
  }
  Serial.println();

  // Vista sin terminadores, útil para copiar el UID.
  size_t end = frameLen;
  while (end > 0 && (frame[end - 1] == '\r' || frame[end - 1] == '\n')) end--;
  size_t start = 0;
  while (start < end && frame[start] == 0x00) start++;

  bool onlyHex = (end > start);
  for (size_t i = start; i < end; i++) {
    uint8_t b = frame[i];
    bool hex = (b >= '0' && b <= '9') ||
               (b >= 'A' && b <= 'F') ||
               (b >= 'a' && b <= 'f');
    if (!hex) { onlyHex = false; break; }
  }

  Serial.print("[TAG] content=\"");
  for (size_t i = start; i < end; i++) {
    uint8_t b = frame[i];
    if (printable(b) && b != '"' && b != '\\') Serial.write(b);
    else Serial.printf("\\x%02X", b);
  }
  Serial.println("\"");

  if (onlyHex && (end - start == 8 || end - start == 10)) {
    Serial.println("[TAG] hint=possible-EM-UID (8/10 hex)");
  } else if (end - start >= 20) {
    Serial.println("[TAG] hint=possible-QR/text");
  } else {
    Serial.println("[TAG] hint=unknown");
  }

  // Feedback visual local, sin tocar la cerradura.
  digitalWrite(LED_RED, LOW);
  digitalWrite(LED_GREEN, HIGH);
  greenUntil = millis() + GREEN_MS;
}

static void pollR35d() {
  while (Serial1.available()) {
    uint8_t b = (uint8_t) Serial1.read();
    totalBytes++;
    lastByteAt = millis();

    if (b == '\n') {
      frameHadLf = true;
      emitFrame(true);
      frameLen = 0;
      frameHadLf = false;
      frameHadCr = false;
      continue;
    }

    if (b == '\r') frameHadCr = true;

    if (frameLen < sizeof(frame)) {
      frame[frameLen++] = b;
    } else {
      Serial.println("[TAG] WARN buffer lleno; se emite y reinicia");
      emitFrame(false);
      frameLen = 0;
      frameHadLf = false;
      frameHadCr = false;
    }
  }

  if (frameLen > 0 && (millis() - lastByteAt) >= R35D_GAP_MS) {
    emitFrame(false);
    frameLen = 0;
    frameHadLf = false;
    frameHadCr = false;
  }
}

void setup() {
  // Cerradura en reposo antes de cualquier otra cosa.
  pinMode(LOCK_PIN, OUTPUT);
  digitalWrite(LOCK_PIN, LOW);

  pinMode(LED_RED, OUTPUT);
  pinMode(LED_GREEN, OUTPUT);
  digitalWrite(LED_RED, HIGH);
  digitalWrite(LED_GREEN, LOW);

  Serial.begin(115200);
  delay(1200);

  Serial.println();
  Serial.println("============================================================");
  Serial.println(" R35D-B TAG PROBE (solo lectura, sin WiFi, sin API)");
  Serial.println(" UART1 9600 8N1  RX=18 TX=17 DIR=7(LOW=lectura)");
  Serial.println(" Cerradura GPIO15 = LOW (deshabilitada durante la prueba)");
  Serial.println(" Presenta un QR de control y luego el llavero EM.");
  Serial.println("============================================================");

  pinMode(R35D_DIR_PIN, OUTPUT);
  digitalWrite(R35D_DIR_PIN, LOW);     // lectura; no se pone HIGH nunca

  Serial1.setRxBufferSize(R35D_RX_BUFFER);
  Serial1.begin(R35D_BAUD, SERIAL_8N1, R35D_RX_PIN, R35D_TX_PIN);

  lastAliveAt = millis();
  Serial.println("[READY] esperando tramas del R35D-B...");
}

void loop() {
  pollR35d();

  if (greenUntil != 0 && millis() >= greenUntil) {
    digitalWrite(LED_GREEN, LOW);
    digitalWrite(LED_RED, HIGH);
    greenUntil = 0;
  }

  if (millis() - lastAliveAt >= ALIVE_MS) {
    lastAliveAt = millis();
    Serial.printf("[ALIVE] uptime=%lus frames=%lu bytes=%lu heap=%lu\n",
                  (unsigned long) (millis() / 1000),
                  (unsigned long) totalFrames,
                  (unsigned long) totalBytes,
                  (unsigned long) ESP.getFreeHeap());
  }

  delay(10);
}
