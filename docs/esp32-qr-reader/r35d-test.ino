/**
 * r35d-test.ino — R35D-B BOX Debug Sketch (ESP32)
 *
 * Prints EVERY byte received from the R35D-B to Serial Monitor in real time,
 * with millisecond timestamps, hex dumps, and system stats. Still POSTs to
 * the API for server-side logging. Copy-paste the Serial output to diagnose
 * the protocol.
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoOTA.h>

// ── Baud rate ─────────────────────────────────────────────────────────────
const unsigned long BAUD = 115200;

// ── WiFi ──────────────────────────────────────────────────────────────────
const char* WIFI_SSID     = "Xiaomi_B04D";
const char* WIFI_PASS     = "bakcAse4#@";

// ── API ───────────────────────────────────────────────────────────────────
const char* API_BASE_URL  = "http://92.113.151.136:8080";
const char* API_KEY       = "49446d9cfc9d91a674a20128f093a8c561437cf5242208c3";

// ── UART2 pins ────────────────────────────────────────────────────────────
#define RX2 16
#define TX2 17

// ── Timers ────────────────────────────────────────────────────────────────
unsigned long lastHeartbeat = 0;
unsigned long lastPulse     = 0;
unsigned long lastStats     = 0;
unsigned long totalRxBytes  = 0;
unsigned long totalRxEvents = 0;

// ── Helpers ───────────────────────────────────────────────────────────────

String chipId() {
  uint64_t mac = ESP.getEfuseMac();
  char buf[13];
  snprintf(buf, sizeof(buf), "%02x%02x%02x%02x%02x%02x",
           (uint8_t)(mac >> 40), (uint8_t)(mac >> 32),
           (uint8_t)(mac >> 24), (uint8_t)(mac >> 16),
           (uint8_t)(mac >> 8),  (uint8_t)(mac));
  return String(buf);
}

String ms() {
  char buf[20];
  snprintf(buf, sizeof(buf), "[%lu]", millis());
  return String(buf);
}

// ── WiFi ensure ───────────────────────────────────────────────────────────

bool ensureWiFi() {
  if (WiFi.status() == WL_CONNECTED) return true;
  Serial.println(ms() + " WARN WiFi lost — reconnecting...");
  WiFi.disconnect();
  delay(500);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500); attempts++;
  }
  bool ok = (WiFi.status() == WL_CONNECTED);
  Serial.println(ms() + " " + String(ok ? "WiFi OK" : "WiFi FAILED"));
  return ok;
}

// ── HTTP wrappers ─────────────────────────────────────────────────────────

int httpGet(const char* path) {
  if (!ensureWiFi()) return -1;
  HTTPClient h;
  h.begin(String(API_BASE_URL) + path);
  h.setTimeout(5000);
  int code = h.GET();
  h.end();
  return code;
}

int httpPost(const char* path, const String& body) {
  if (!ensureWiFi()) return -1;
  HTTPClient h;
  h.begin(String(API_BASE_URL) + path);
  h.addHeader("Content-Type", "application/json");
  h.addHeader("X-API-Key", API_KEY);
  h.addHeader("Idempotency-Key", chipId() + "-" + String(millis()));
  h.setTimeout(5000);
  int code = h.POST(body);
  h.end();
  return code;
}

// ── Hex helpers ───────────────────────────────────────────────────────────

String byteToHex(uint8_t b) {
  char buf[4];
  snprintf(buf, sizeof(buf), "%02X", b);
  return String(buf);
}

String hexDump(const uint8_t* data, size_t len) {
  String s = "";
  for (size_t i = 0; i < len; i++) {
    s += byteToHex(data[i]);
    if ((i + 1) % 32 == 0 && i < len - 1) s += "\n    ";
    else if (i < len - 1) s += " ";
  }
  return s;
}

String asciiDump(const uint8_t* data, size_t len) {
  String s = "";
  for (size_t i = 0; i < len; i++) {
    char c = data[i];
    s += (c >= 0x20 && c <= 0x7E) ? String(c) : ".";
  }
  return s;
}

// ── CRC32 (standard Ethernet / zlib polynomial) ────────────────────────────

uint32_t crc32(const uint8_t* data, size_t len) {
  uint32_t crc = 0xFFFFFFFF;
  for (size_t i = 0; i < len; i++) {
    crc ^= data[i];
    for (int b = 0; b < 8; b++) {
      if (crc & 1)
        crc = (crc >> 1) ^ 0xEDB88320;
      else
        crc >>= 1;
    }
  }
  return crc ^ 0xFFFFFFFF;
}

// ── setup ─────────────────────────────────────────────────────────────────

void setup() {
  Serial.begin(115200);
  delay(1000);

  String id = chipId();
  Serial.println();
  Serial.println("============================================================");
  Serial.println("  R35D-B DEBUG SKETCH");
  Serial.println("============================================================");
  Serial.println("  Chip:    " + id);
  Serial.println("  Baud:    " + String(BAUD) + " bps (7E1)");
  Serial.println("  UART:    GPIO" + String(RX2) + " (RX) / GPIO" + String(TX2) + " (TX)");
  Serial.println("  Server:  " + String(API_BASE_URL));
  Serial.println("============================================================");

  Serial2.begin(BAUD, SERIAL_8E1, RX2, TX2);

  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  Serial.print("  WiFi:    connecting");
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 40) {
    delay(500); Serial.print("."); attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println(" OK");
    Serial.println("  IP:      " + WiFi.localIP().toString());
    Serial.println("  RSSI:    " + String(WiFi.RSSI()) + " dBm");
  } else {
    Serial.println(" FAILED — continuing without WiFi");
  }
  Serial.println("  Heap:    " + String(ESP.getFreeHeap()) + " bytes free");
  Serial.println("============================================================");

  int hc = httpGet("/api/v1/health");
  Serial.println(ms() + " API health: " + String(hc));

  ArduinoOTA.setHostname("r35d-debug");
  ArduinoOTA.begin();

  Serial.println();
  Serial.println("READY. Scan a QR or RFID card now...");
  Serial.println("(Pulse '.' = loop alive, '!' = heartbeat, '#' = stats)");
  Serial.println("============================================================\n");
}

// ── loop ──────────────────────────────────────────────────────────────────

void loop() {
  ArduinoOTA.handle();
  unsigned long now = millis();

  // ── Heartbeat every 60s ──────────────────────────────────────────────
  if (now - lastHeartbeat > 60000) {
    lastHeartbeat = now;
    String path = "/api/v1/health?h=1&r=" + String(WiFi.RSSI()) + "&m=" + String(ESP.getFreeHeap());
    int code = httpGet(path.c_str());
    Serial.println(ms() + " ! heartbeat " + String(code) +
                   "  rssi=" + String(WiFi.RSSI()) +
                   "dBm  heap=" + String(ESP.getFreeHeap()));
  }

  // ── Pulse every 10s (loop alive confirmation) ────────────────────────
  if (now - lastPulse > 10000) {
    lastPulse = now;
    Serial.print(".");
  }

  // ── Stats every 30s ──────────────────────────────────────────────────
  if (now - lastStats > 30000) {
    lastStats = now;
    Serial.println();
    Serial.println(ms() + " # STATS  rx_events=" + String(totalRxEvents) +
                   "  rx_bytes=" + String(totalRxBytes) +
                   "  heap=" + String(ESP.getFreeHeap()) +
                   "  rssi=" + String(WiFi.RSSI()) + "dBm");
  }

  // ── Serial2 reading ──────────────────────────────────────────────────
  if (!Serial2.available()) {
    delay(50);
    return;
  }

  // Buffer for raw bytes
  uint8_t buf[2048];
  size_t  len = 0;
  unsigned long rxStart = millis();
  unsigned long firstGap = 0;
  unsigned long totalGaps = 0;

  Serial.println();
  Serial.println(ms() + " >>> RX START (event #" + String(totalRxEvents + 1) + ")");

  // Read with inter-byte gap detection
  unsigned long lastByteTime = millis();
  unsigned long timeout = millis() + 500;
  while (millis() < timeout && len < sizeof(buf) - 1) {
    while (Serial2.available() && len < sizeof(buf) - 1) {
      uint8_t b = (uint8_t)Serial2.read();
      unsigned long gap = millis() - lastByteTime;
      lastByteTime = millis();
      timeout = millis() + 200;

      if (len == 0) {
        firstGap = gap;
      } else if (len < 30 || gap > 5) {
        // Show timing for first 30 bytes + any significant gap
        Serial.print("    ");
        Serial.print(ms());
        Serial.print(" byte[" + String(len) + "] gap=" + String(gap) + "ms  val=0x");
        Serial.println(byteToHex(b));
      }
      totalGaps += gap;
      buf[len++] = b;
    }
    delay(5);
  }

  unsigned long rxDuration = millis() - rxStart;

  // Ignore single noise bytes
  if (len < 4) {
    Serial.println(ms() + " <<< IGNORED (" + String(len) + " byte noise)");
    return;
  }

  totalRxEvents++;
  totalRxBytes += len;

  // Full hex + ASCII dump
  uint32_t crc = crc32(buf, len);
  Serial.println("    ──────────────────────────────────────────");
  Serial.println("    RX Duration: " + String(rxDuration) + " ms");
  Serial.println("    Bytes:       " + String(len));
  Serial.println("    First gap:   " + String(firstGap) + " ms");
  Serial.println("    Avg gap:     " + String(totalGaps / max((unsigned long)1, (unsigned long)len)) + " ms");
  Serial.println("    HEX:");
  Serial.print("    "); Serial.println(hexDump(buf, len));
  Serial.println("    ASCII:");
  Serial.print("    "); Serial.println(asciiDump(buf, len));
  Serial.println("    CRC32:      " + String(crc, HEX));
  Serial.println("    ──────────────────────────────────────────");
  Serial.println(ms() + " <<< RX END");

  // Convert to String for POST
  String hexStr = "";
  String ascStr = "";
  for (size_t i = 0; i < len; i++) {
    hexStr += byteToHex(buf[i]);
    if (i < len - 1) hexStr += " ";
    char ch = buf[i];
    ascStr += (ch >= 0x20 && ch <= 0x7E) ? String(ch) : ".";
  }

  String id = chipId();
  String body = "{\"reading\":{"
                "\"device_id\":\"" + id + "\","
                "\"baud\":" + String(BAUD) + ","
                "\"byte_len\":" + String(len) + ","
                "\"crc32\":\"" + String(crc, HEX) + "\","
                "\"hex\":\"" + hexStr + "\","
                "\"ascii\":\"" + ascStr + "\""
                "}}";

  int code = httpPost("/api/v1/_dev/echo", body);
  Serial.println(ms() + " POST → " + String(code));
}
