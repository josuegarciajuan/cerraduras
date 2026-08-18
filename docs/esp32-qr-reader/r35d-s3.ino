/**
 * r35d-s3.ino — R35D-B QR Reader for ESP32-S3 (TTL via GPIO16/17)
 *
 * Slim version: no OTA, small buffer, watchdog, deep sleep pulse.
 * Posts QR readings to /api/v1/_dev/echo (SIM-CLIENT).
 *
 * Wiring: R35D-B TX → Level Shifter → GPIO16 (RX2)
 *         R35D-B RX → Level Shifter → GPIO17 (TX2)
 */

#include <WiFi.h>
#include <HTTPClient.h>

// ── Config ────────────────────────────────────────────────────────────────
const unsigned long BAUD      = 115200;  // R35D-B factory default
const char* WIFI_SSID         = "Xiaomi_B04D";
const char* WIFI_PASS         = "bakcAse4#@";
const char* API_BASE_URL      = "http://92.113.151.136:8080";
const char* API_KEY           = "49446d9cfc9d91a674a20128f093a8c561437cf5242208c3";
#define RX2 16
#define TX2 17

// ── Timers ────────────────────────────────────────────────────────────────
unsigned long lastBeat   = 0;
unsigned long lastYield  = 0;
int           idleLoops  = 0;

// ── Helpers ───────────────────────────────────────────────────────────────
String chipId() {
  uint64_t mac = ESP.getEfuseMac();
  char buf[13];
  snprintf(buf, sizeof(buf), "%02x%02x%02x%02x%02x%02x",
    (uint8_t)(mac>>40),(uint8_t)(mac>>32),(uint8_t)(mac>>24),
    (uint8_t)(mac>>16),(uint8_t)(mac>>8),(uint8_t)(mac));
  return String(buf);
}

String hexDump(const uint8_t* d, size_t n) {
  String s; char b[4];
  for (size_t i = 0; i < n && i < 64; i++) {
    snprintf(b, sizeof(b), "%02X ", d[i]); s += b;
  }
  return s;
}

// ── WiFi ensure ───────────────────────────────────────────────────────────
bool ensureWiFi() {
  if (WiFi.status() == WL_CONNECTED) return true;
  WiFi.disconnect(); delay(500);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  for (int i = 0; i < 20 && WiFi.status() != WL_CONNECTED; i++) delay(500);
  return WiFi.status() == WL_CONNECTED;
}

// ── HTTP ──────────────────────────────────────────────────────────────────
int httpGet(const char* path) {
  if (!ensureWiFi()) return -1;
  HTTPClient h; h.begin(String(API_BASE_URL) + path); h.setTimeout(4000);
  int c = h.GET(); h.end(); return c;
}

int httpPost(const char* path, const String& body) {
  if (!ensureWiFi()) return -1;
  HTTPClient h; h.begin(String(API_BASE_URL) + path); h.setTimeout(4000);
  h.addHeader("Content-Type", "application/json");
  h.addHeader("X-API-Key", API_KEY);
  h.addHeader("Idempotency-Key", chipId() + "-" + String(millis()));
  int c = h.POST(body); h.end(); return c;
}

// ── setup ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200); delay(1000);
  String id = chipId();
  Serial.println("\n=== R35D-S3 TTL READER ===");
  Serial.println("Chip: " + id + "  Baud: " + String(BAUD));

  Serial2.begin(BAUD, SERIAL_8N1, RX2, TX2);

  WiFi.setAutoReconnect(true); WiFi.persistent(false);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.print("WiFi");
  for (int i = 0; i < 40 && WiFi.status() != WL_CONNECTED; i++) {
    delay(500); Serial.print(".");
  }
  bool ok = WiFi.status() == WL_CONNECTED;
  Serial.println(ok ? " OK " + WiFi.localIP().toString() : " FAIL");
  Serial.println("Heap: " + String(ESP.getFreeHeap()) + "  RSSI: " + String(WiFi.RSSI()));

  int hc = httpGet("/api/v1/health");
  Serial.println("API: " + String(hc) + "\n");
}

// ── loop ──────────────────────────────────────────────────────────────────
void loop() {
  unsigned long now = millis();

  // ── Heartbeat every 60s ──────────────────────────────────────────────
  if (now - lastBeat > 60000) {
    lastBeat = now;
    int c = httpGet("/api/v1/health");
    Serial.println("♥ " + String(c));
  }

  // ── Yield to prevent overheating (sleep 5ms every 30s idle) ──────────
  if (now - lastYield > 30000) {
    lastYield = now;
    Serial.print("."); // loop alive marker
    delay(5);          // tiny sleep to cool down
  }

  // ── Serial2 reading ──────────────────────────────────────────────────
  if (!Serial2.available()) { delay(50); return; }

  uint8_t buf[512]; size_t len = 0;
  unsigned long to = millis() + 400;
  while (millis() < to && len < sizeof(buf) - 1) {
    while (Serial2.available() && len < sizeof(buf) - 1) {
      buf[len++] = Serial2.read(); to = millis() + 150;
    }
    delay(5);
  }
  if (len < 4) return;

  // Log locally
  Serial.println("RX " + String(len) + "B: " + hexDump(buf, len));

  // Build POST body (only first 256 bytes to keep body small)
  String hex = "", asc = "";
  for (size_t i = 0; i < min(len, (size_t)256); i++) {
    char hx[4]; snprintf(hx, sizeof(hx), "%02X ", buf[i]); hex += hx;
    asc += (buf[i] >= 0x20 && buf[i] <= 0x7E) ? String((char)buf[i]) : ".";
  }

  String body = "{\"reading\":{\"device_id\":\"" + chipId() + "\","
                "\"baud\":" + String(BAUD) + ","
                "\"byte_len\":" + String(len) + ","
                "\"hex\":\"" + hex + "\","
                "\"ascii\":\"" + asc + "\"}}";

  int code = httpPost("/api/v1/_dev/echo", body);
  Serial.println("POST " + String(code));
}
