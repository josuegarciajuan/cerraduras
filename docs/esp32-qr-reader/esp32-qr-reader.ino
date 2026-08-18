/**
 * esp32-qr-reader.ino — Hotel QR Door Reader (ESP32)
 *
 * Hardware: ESP32 Dev Board + GM65 QR scanner (via level shifter 5V→3.3V)
 *
 * Flow:
 *   1. Connects to WiFi
 *   2. Reads QR text from GM65 via UART2 (GPIO16 RX, GPIO17 TX)
 *   3. POSTs to /api/v1/qr/validate with chip ID as device_id
 *   4. Logs response to Serial Monitor
 *
 * device_id: uses ESP32 eFuse MAC (hex, lowercase, no separators)
 *   - Example: "92f57630"
 *   - This matches devices.external_id in the API database
 */

#include <WiFi.h>
#include <HTTPClient.h>

// ⚠️ CONFIGURE THESE
const char* WIFI_SSID     = "TU_WIFI";
const char* WIFI_PASS     = "TU_CONTRASEÑA";
const char* API_BASE_URL  = "http://92.113.151.136/cerraduras/api";  // via Apache reverse proxy (port 80)
const char* API_KEY       = "8974517de1cfb1c3e6e8f2473c5f34a4cbd0252cb52ff6e1"; // RPI-DEV

// UART2 pins for GM65
#define RX2 16
#define TX2 17

// ---------------------------------------------------------------------------
// chipId()
// Returns the ESP32's unique chip ID as a hex string (lowercase, no colons).
// Example: "92f57630"
// ---------------------------------------------------------------------------
String chipId() {
  uint64_t mac = ESP.getEfuseMac();
  char buf[13];
  snprintf(buf, sizeof(buf), "%02x%02x%02x%02x%02x%02x",
           (uint8_t)(mac >> 40),
           (uint8_t)(mac >> 32),
           (uint8_t)(mac >> 24),
           (uint8_t)(mac >> 16),
           (uint8_t)(mac >> 8),
           (uint8_t)(mac));
  return String(buf);
}

// ---------------------------------------------------------------------------
// setup()
// ---------------------------------------------------------------------------
void setup() {
  Serial.begin(115200);
  Serial2.begin(9600, SERIAL_8N1, RX2, TX2);

  String id = chipId();
  Serial.println("\n=== HOTEL QR READER - ESP32 ===");
  Serial.println("Device ID: " + id);

  // Wi-Fi connect
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.print("Connecting to WiFi");
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 40) {
    delay(500);
    Serial.print(".");
    attempts++;
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWiFi OK — IP: " + WiFi.localIP().toString());
  } else {
    Serial.println("\nWiFi FAILED — check credentials");
    return;
  }

  // Quick health check
  HTTPClient http;
  http.begin(String(API_BASE_URL) + "/api/v1/health");
  int code = http.GET();
  Serial.println("API health: HTTP " + String(code));
  http.end();

  Serial.println("\nReady. Scan a QR code...");
}

// ---------------------------------------------------------------------------
// loop()
// Reads QR text from GM65 and sends it to the API for validation.
// ---------------------------------------------------------------------------
void loop() {
  if (!Serial2.available()) {
    delay(50);
    return;
  }

  String qr = Serial2.readStringUntil('\n');
  qr.trim();

  if (qr.length() < 20) {
    Serial.println("[WARN] QR too short, ignoring: " + qr);
    return;
  }

  String id  = chipId();
  Serial.println("\n--- QR Scanned ---");
  Serial.println("Device: " + id);
  Serial.println("QR:     " + qr.substring(0, 70) + "...");

  HTTPClient http;
  http.begin(String(API_BASE_URL) + "/api/v1/qr/validate");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  String body = "{\"qr_text\":\"" + qr + "\",\"device_id\":\"" + id + "\"}";
  int    code = http.POST(body);
  String resp = http.getString();
  http.end();

  Serial.println("API HTTP " + String(code));
  Serial.println("Response: " + resp);
}
