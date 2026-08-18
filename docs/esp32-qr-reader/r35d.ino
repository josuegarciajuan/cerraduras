/**
 * r35d.ino — R35D-B BOX QR/RFID Door Reader (ESP32)
 *
 * Hardware: ESP32 Dev Board + R35D-B BOX (via 4ch logic level shifter 5V↔3.3V)
 *
 * Flow:
 *  1. Reads QR/RFID data from R35D-B via UART2 (GPIO16 RX / GPIO17 TX) at 9600 bps
 *  2. Connects to WiFi
 *  3. POSTs to /api/v1/qr/validate with chip ID as device_id
 *  4. API validates the QR token, opens the lock if valid, logs access events
 *
 * device_id: uses ESP32 eFuse MAC (hex, lowercase, no separators)
 *   - Example: "92f57630"
 *   - This matches devices.external_id in the API database
 */

#include <WiFi.h>
#include <HTTPClient.h>

// ── Baud rate ─────────────────────────────────────────────────────────────
// R35D-B confirmed at 9600 bps via configuration QR from manual
const unsigned long BAUD = 9600;

// ── WiFi ──────────────────────────────────────────────────────────────────
const char* WIFI_SSID     = "Xiaomi_B04D";
const char* WIFI_PASS     = "bakcAse4#@";

// ── API (direct to PHP server) ────────────────────────────────────────────
const char* API_BASE_URL  = "http://92.113.151.136:8080";
const char* API_KEY       = "8974517de1cfb1c3e6e8f2473c5f34a4cbd0252cb52ff6e1"; // RPI-DEV

// ── UART2 pins for R35D-B BOX ─────────────────────────────────────────────
#define RX2 16
#define TX2 17

// ── Helpers ────────────────────────────────────────────────────────────────

String chipId() {
  uint64_t mac = ESP.getEfuseMac();
  char buf[13];
  snprintf(buf, sizeof(buf), "%02x%02x%02x%02x%02x%02x",
           (uint8_t)(mac >> 40), (uint8_t)(mac >> 32),
           (uint8_t)(mac >> 24), (uint8_t)(mac >> 16),
           (uint8_t)(mac >> 8),  (uint8_t)(mac));
  return String(buf);
}

// ── setup ─────────────────────────────────────────────────────────────────

void setup() {
  Serial.begin(115200);
  delay(1000);

  // Open UART to R35D-B at 9600 bps
  Serial2.begin(BAUD, SERIAL_8N1, RX2, TX2);

  String id = chipId();
  Serial.println("\n=== R35D-B DOOR READER ===");
  Serial.println("Device ID: " + id);
  Serial.println("Baud:      " + String(BAUD) + " bps");

  // WiFi
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.print("Connecting to WiFi");
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 40) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("\nWiFi FAILED — check credentials");
    return;
  }

  Serial.println("\nWiFi OK — IP: " + WiFi.localIP().toString());

  // API health check
  HTTPClient http;
  http.begin(String(API_BASE_URL) + "/api/v1/health");
  int code = http.GET();
  Serial.println("API health: HTTP " + String(code));
  http.end();

  Serial.println("\nReady — scan a QR or RFID card...");
}

// ── loop ──────────────────────────────────────────────────────────────────

void loop() {
  if (!Serial2.available()) {
    delay(50);
    return;
  }

  // Read whatever the R35D-B sends (QR text or RFID UID)
  String raw = "";
  unsigned long timeout = millis() + 500;
  while (millis() < timeout) {
    while (Serial2.available()) {
      raw += (char)Serial2.read();
      timeout = millis() + 200;
    }
    delay(10);
  }

  raw.trim();
  if (raw.length() < 4) return; // ignore noise

  String id = chipId();

  Serial.println("\n--- Reading (" + String(raw.length()) + " bytes) ---");
  Serial.println("Text: " + raw);

  // POST to QR validate endpoint
  HTTPClient http;
  http.begin(String(API_BASE_URL) + "/api/v1/qr/validate");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  String body = "{\"qr_text\":\"" + raw + "\",\"device_id\":\"" + id + "\"}";
  int    code = http.POST(body);
  String resp = http.getString();
  http.end();

  Serial.println("API HTTP " + String(code));
  Serial.println("Response: " + resp);
}
