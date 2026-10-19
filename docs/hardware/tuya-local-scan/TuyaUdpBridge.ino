/*
 * TuyaUdpBridge — puente UDP<->serie (PoC L1, variante 3.3/UDP)
 *
 * Flasheado en la ESP32-CAM (WiFi Xiaomi_B04D). Permite probar si los devices
 * aceptan el protocolo local Tuya por UDP 6668. 100% local, sin cuota Tuya.
 *
 * Protocolo serie (líneas):
 *   host -> ESP : "U <ip> <port> <hex>\n"   envía un datagrama UDP
 *   ESP -> host : "R <src> <sport> <hex>"   datagrama recibido
 *                 "WIFI <ip>" / "WAIT" / "TX <n>"
 */

#include <WiFi.h>
#include <WiFiUdp.h>
#include <Preferences.h>

WiFiUDP udp;

void setup() {
  Serial.begin(115200);
  delay(400);
  Serial.println("=== TUYA UDP BRIDGE (PoC L1) ===");
  Preferences p;
  p.begin("camaraesp", true);
  String ssid = p.getString("wifi_ssid", "");
  String pass = p.getString("wifi_pass", "");
  p.end();
  if (ssid.length() == 0) { Serial.println("ERR_NVS"); return; }
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid.c_str(), pass.c_str());
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 20000) delay(500);
  if (WiFi.status() != WL_CONNECTED) { Serial.println("ERR_WIFI"); return; }
  Serial.printf("WIFI %s\n", WiFi.localIP().toString().c_str());
  udp.begin(6668);
  Serial.println("WAIT");
}

void loop() {
  if (Serial.available()) {
    String line = Serial.readStringUntil('\n');
    line.trim();
    if (line.startsWith("U ")) {
      int s1 = line.indexOf(' '), s2 = line.indexOf(' ', s1 + 1), s3 = line.indexOf(' ', s2 + 1);
      if (s3 > 0) {
        String ip = line.substring(s1 + 1, s2);
        int port = line.substring(s2 + 1, s3).toInt();
        String hex = line.substring(s3 + 1);
        int n = hex.length() / 2;
        uint8_t buf[512];
        if (n > (int)sizeof(buf)) n = sizeof(buf);
        for (int i = 0; i < n; i++) buf[i] = (uint8_t)strtol(hex.substring(2 * i, 2 * i + 2).c_str(), NULL, 16);
        IPAddress a;
        a.fromString(ip);
        udp.beginPacket(a, (uint16_t)port);
        udp.write(buf, n);
        udp.endPacket();
        Serial.printf("TX %d\n", n);
      }
    }
  }
  int sz = udp.parsePacket();
  if (sz > 0) {
    uint8_t buf[512];
    int n = udp.read(buf, sizeof(buf));
    IPAddress r = udp.remoteIP();
    uint16_t rp = udp.remotePort();
    Serial.print("R "); Serial.print(r.toString()); Serial.print(' '); Serial.print(rp); Serial.print(' ');
    char b[3];
    for (int i = 0; i < n; i++) { sprintf(b, "%02x", buf[i]); Serial.print(b); }
    Serial.println();
  }
  delay(2);
}
