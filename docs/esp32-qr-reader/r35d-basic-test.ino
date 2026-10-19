/**
 * r35d-basic-test.ino — Pruebas básicas de la nueva placa definitiva.
 *
 * Hardware:
 *   - ESP32-S3-USB-OTG (placa completa, puerto COM/CH343).
 *   - Lector R35D-B por RS-485: UART1 RX=GPIO18, TX=GPIO17,
 *     dirección GPIO7 (LOW=lectura, HIGH=escritura), 9600 8N1.
 *   - Relé/cerradura: GPIO15, activo-alto.
 *   - LEDs externos: GPIO1 rojo, GPIO2 verde.
 *
 * Protocolo confirmado (2026-10-19):
 *   - Texto ASCII plano terminado en CRLF (0D 0A). No es MODBUS.
 *   - Token de prueba: 95l8bpUfThEF.
 *
 * Comportamiento:
 *   - Matcher exacto del token de prueba.
 *   - Al coincidir: secuencia del proveedor en GPIO15 y LEDs verde/rojo.
 *   - Por defecto: rojo ON, verde OFF.
 *   - Ventana de apertura / LED verde: 3 s. Cooldown: 2 s.
 *
 * Limitaciones:
 *   - Es una prueba de hardware, NO el firmware productivo.
 *   - Token de prueba hardcodeado; sin WiFi/API; pulso bloqueante (~800 ms).
 *   - No flashear en la placa prototipo antigua.
 */

#include <Arduino.h>

static const uint32_t R35D_BAUD = 9600;
static const uint32_t R35D_CONFIG = SERIAL_8N1;
static const int PIN_RX = 18;
static const int PIN_TX = 17;
static const int PIN_DIR = 7;
static const int PIN_LOCK = 15;
static const int PIN_LED_RED = 1;
static const int PIN_LED_GREEN = 2;
static const char *EXPECTED = "95l8bpUfThEF";
static const size_t BUF_MAX = 512;
static const uint32_t GAP_MS = 120;
static const uint32_t ALIVE_MS = 15000;
static const uint32_t DUP_MS = 2000;
static const uint32_t OPEN_LED_MS = 3000;

static uint8_t buf[BUF_MAX];
static size_t len = 0;
static uint32_t lastByteMs = 0;
static uint32_t lastAliveMs = 0;
static uint32_t lastMatchMs = 0;
static uint32_t greenUntil = 0;

static bool isTrim(uint8_t b) {
  return b == ' ' || b == '\r' || b == '\n' || b == '\t';
}

static bool isPrintable(uint8_t b) {
  return b >= 0x20 && b <= 0x7E;
}

static void printHex(const uint8_t *data, size_t n) {
  for (size_t i = 0; i < n; ++i) {
    if (i) Serial.print(' ');
    if (data[i] < 0x10) Serial.print('0');
    Serial.print(data[i], HEX);
  }
}

static void printAscii(const uint8_t *data, size_t n) {
  for (size_t i = 0; i < n; ++i) {
    Serial.print(isPrintable(data[i]) ? (char)data[i] : '.');
  }
}

// Secuencia del proveedor: GPIO15 activo-alto, ~800 ms.
static void openLock() {
  digitalWrite(PIN_LOCK, HIGH);
  delay(250);

  for (int i = 0; i < 125; ++i) {
    digitalWrite(PIN_LOCK, HIGH);
    delay(1);
    digitalWrite(PIN_LOCK, LOW);
    delay(1);
  }

  for (int i = 0; i < 30; ++i) {
    digitalWrite(PIN_LOCK, HIGH);
    delay(5);
    digitalWrite(PIN_LOCK, LOW);
    delay(5);
  }

  digitalWrite(PIN_LOCK, LOW);  // reposo garantizado
}

static void setLockedLeds() {
  digitalWrite(PIN_LED_GREEN, LOW);
  digitalWrite(PIN_LED_RED, HIGH);
}

static void setOpenLeds() {
  digitalWrite(PIN_LED_RED, LOW);
  digitalWrite(PIN_LED_GREEN, HIGH);
}

static void processFrame(uint8_t *data, size_t n) {
  size_t start = 0, end = n;
  while (start < end && isTrim(data[start])) start++;
  while (end > start && isTrim(data[end - 1])) end--;
  size_t m = end - start;

  Serial.printf("\n[FRAME] %u bytes (clean %u) at %lu ms\n",
                (unsigned)n, (unsigned)m, (unsigned long)millis());
  Serial.print("  HEX:   "); printHex(data, n); Serial.println();
  Serial.print("  ASCII: "); printAscii(data, n); Serial.println();
  Serial.print("  CLEAN: ");
  for (size_t i = start; i < end; ++i) {
    Serial.print(isPrintable(data[i]) ? (char)data[i] : '.');
  }
  Serial.println();

  if (m < 4) {
    Serial.println("  [NOISE] frame too short");
    return;
  }

  bool match = (m == strlen(EXPECTED)) && (memcmp(data + start, EXPECTED, m) == 0);
  if (!match) {
    Serial.println("  [NO MATCH]");
    return;
  }

  if (lastMatchMs != 0 && (millis() - lastMatchMs) < DUP_MS) {
    Serial.println("  [DUP] match ignored (cooldown)");
    return;
  }

  lastMatchMs = millis();
  greenUntil = millis() + OPEN_LED_MS;

  Serial.println("  [MATCH] 95l8bpUfThEF");
  Serial.println("  [LED] rojo OFF, verde ON");
  setOpenLeds();

  Serial.println("  [LOCK] abriendo...");
  openLock();
  Serial.println("  [LOCK] fin del pulso");
}

void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.println();
  Serial.println("=== R35D MATCHER + RELÉ + LEDS ===");
  Serial.printf("UART1 %lu baud, %s, RX=%d TX=%d DIR=%d\n",
                (unsigned long)R35D_BAUD,
                R35D_CONFIG == SERIAL_8N1 ? "8N1" :
                R35D_CONFIG == SERIAL_8E1 ? "8E1" :
                R35D_CONFIG == SERIAL_7E1 ? "7E1" : "?",
                PIN_RX, PIN_TX, PIN_DIR);
  Serial.printf("Expected: \"%s\"\n", EXPECTED);
  Serial.printf("LOCK=%d (HIGH=activado), LED_R=%d, LED_G=%d, cooldown=%lu ms, openLED=%lu ms\n",
                PIN_LOCK, PIN_LED_RED, PIN_LED_GREEN,
                (unsigned long)DUP_MS, (unsigned long)OPEN_LED_MS);

  pinMode(PIN_DIR, OUTPUT);
  digitalWrite(PIN_DIR, LOW);  // recepción

  pinMode(PIN_LOCK, OUTPUT);
  digitalWrite(PIN_LOCK, LOW);  // cerradura en reposo

  pinMode(PIN_LED_RED, OUTPUT);
  pinMode(PIN_LED_GREEN, OUTPUT);
  setLockedLeds();  // por defecto: rojo ON, verde OFF

  Serial1.begin(R35D_BAUD, R35D_CONFIG, PIN_RX, PIN_TX);

  Serial.println("Estado inicial: rojo ON, verde OFF.");
  Serial.println("Escanea 95l8bpUfThEF.");
}

void loop() {
  while (Serial1.available()) {
    uint8_t b = (uint8_t)Serial1.read();

    if (b == '\n') {
      if (len > 0) {
        if (len < BUF_MAX) buf[len++] = b;
        processFrame(buf, len);
        len = 0;
      }
    } else {
      if (len < BUF_MAX) {
        buf[len++] = b;
      } else {
        Serial.println("[WARN] buffer lleno; volcando");
        processFrame(buf, len);
        len = 0;
      }
    }
    lastByteMs = millis();
  }

  if (len > 0 && (millis() - lastByteMs) >= GAP_MS) {
    processFrame(buf, len);
    len = 0;
  }

  if (greenUntil != 0 && (int32_t)(millis() - greenUntil) >= 0) {
    greenUntil = 0;
    setLockedLeds();
    Serial.println("[LED] ventana abierta terminada: rojo ON, verde OFF");
  }

  if (millis() - lastAliveMs >= ALIVE_MS) {
    lastAliveMs = millis();
    Serial.printf("[ALIVE] esperando QR, %u bytes en buffer\n", (unsigned)len);
  }
}
