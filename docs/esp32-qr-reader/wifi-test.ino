#include <WiFi.h>

// ── WiFi ──
const char* WIFI_SSID = "Xiaomi_B04D";
const char* WIFI_PASS = "bakcAse4#@";

// ── Server (via Apache reverse proxy, port 80) ──
const char* SERVER_HOST = "92.113.151.136";
const int   SERVER_PORT = 80;
const char* HEALTH_PATH = "/cerraduras/api/health";

// ── UART pins ──
#define RX2 16
#define TX2 17

void setup() {
  Serial.begin(115200);
  delay(1000);

  // Device ID
  uint64_t mac = ESP.getEfuseMac();
  char chip[13];
  snprintf(chip, sizeof(chip), "%02x%02x%02x%02x%02x%02x",
    (uint8_t)(mac>>40), (uint8_t)(mac>>32), (uint8_t)(mac>>24),
    (uint8_t)(mac>>16), (uint8_t)(mac>>8),  (uint8_t)(mac));
  String DEVICE_ID = String(chip);

  Serial.println("\n========== WIFI + SERVER TEST ==========");
  Serial.println("Device ID : " + DEVICE_ID);
  Serial.println("WiFi SSID : " + String(WIFI_SSID));
  Serial.println("Server    : http://" + String(SERVER_HOST) + HEALTH_PATH);

  // ── Conectar WiFi ──
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.print("Connecting");
  int t = 0;
  while (WiFi.status() != WL_CONNECTED && t < 40) {
    delay(500); Serial.print("."); t++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[OK] WiFi CONNECTED");
    Serial.print("      IP  : "); Serial.println(WiFi.localIP());
    Serial.print("      RSSI: "); Serial.print(WiFi.RSSI()); Serial.println(" dBm");
  } else {
    Serial.println("\n[FAIL] WiFi — status: " + String(WiFi.status()));
    Serial.println("       1=SSID not found  4=wrong password  6=disconnected");
    return;
  }

  // ── Ping server ──
  Serial.print("\nPinging " + String(SERVER_HOST) + ":" + String(SERVER_PORT) + " ... ");
  WiFiClient client;
  if (client.connect(SERVER_HOST, SERVER_PORT)) {
    client.print("GET " + String(HEALTH_PATH) + " HTTP/1.1\r\nHost: ");
    client.print(SERVER_HOST); client.print("\r\nConnection: close\r\n\r\n");

    unsigned long timeout = millis() + 5000;
    while (!client.available() && millis() < timeout) { delay(10); }

    if (client.available()) {
      Serial.println("[OK] Server responds:");
      while (client.available()) {
        String line = client.readStringUntil('\n');
        if (line.length() > 0) { Serial.print("      "); Serial.println(line); }
      }
    } else {
      Serial.println("[WARN] Connected but no response");
    }
    client.stop();
  } else {
    Serial.println("[FAIL] Cannot reach server");
    Serial.println("      Check: Apache proxy enabled? a2enconf cerraduras-proxy");
  }

  Serial.println("\n========== TEST COMPLETE ==========");
}

void loop() { delay(1000); }
