/*
 * TuyaTool — herramienta local Tuya para la PoC L1 (cuenta nueva)
 *
 * Flasheada en la ESP32-CAM (WiFi Xiaomi_B04D). 100% local, sin nube ni cuota.
 *
 * Comandos por serie (líneas de texto):
 *   DISCOVER                 -> broadcast de discovery Tuya y volcado de respuestas
 *                               como "D <ip> <port> <hex>"; termina con "DISCOVER_DONE"
 *   CONNECT <ip> <port>      -> abre TCP; "TCPOK"|"TCPFAIL"; si OK, "BRIDGE" y
 *                               a partir de ahí el serie es binario puro (sin texto).
 *
 * Tras cerrarse el TCP, vuelve silenciosamente al modo comandos (el host puede
 * reiniciar la placa o enviar un comando nuevo).
 *
 * Restaurar camaraESP al terminar.
 */

#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiUdp.h>
#include <Preferences.h>

// Payload de discovery Tuya (REQ_DEVINFO), 89 bytes (generado con tinytuya).
static const uint8_t TUYA_REQ[] = {
  0x00, 0x00, 0x66, 0x99, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
  0x00, 0x25, 0x00, 0x00, 0x00, 0x43, 0x65, 0x0e, 0xef, 0xc7, 0xea, 0xa0,
  0x6a, 0xae, 0x90, 0x9d, 0xe4, 0x79, 0x68, 0x08, 0x02, 0xe5, 0x27, 0x60,
  0x4e, 0x8b, 0x5f, 0xce, 0x1c, 0xa9, 0xe4, 0x6e, 0x52, 0x2e, 0x47, 0xc1,
  0x72, 0x5c, 0xb3, 0xb4, 0x8b, 0xb5, 0x2d, 0xf7, 0x90, 0x77, 0x62, 0x48,
  0xfb, 0xd9, 0x2f, 0x7a, 0x33, 0x14, 0x5d, 0xd0, 0x52, 0xa6, 0x59, 0x11,
  0x99, 0xe0, 0xea, 0x70, 0xeb, 0xf8, 0xd2, 0x7d, 0x87, 0xa8, 0xe1, 0x88,
  0x77, 0x00, 0x00, 0x99, 0x66,
};
static const size_t TUYA_REQ_LEN = sizeof(TUYA_REQ);

WiFiClient tcp;
bool bridging = false;
WiFiUDP udp6666, udp6667, udp7000, udp6668;
WiFiUDP *socks[4] = { &udp6666, &udp6667, &udp7000, &udp6668 };
const uint16_t SOCKS_PORTS[4] = { 6666, 6667, 7000, 6668 };

static IPAddress subnetBroadcast() {
  IPAddress ip = WiFi.localIP(), mask = WiFi.subnetMask();
  return IPAddress(
    (uint8_t)(ip[0] | (~mask[0] & 0xff)),
    (uint8_t)(ip[1] | (~mask[1] & 0xff)),
    (uint8_t)(ip[2] | (~mask[2] & 0xff)),
    (uint8_t)(ip[3] | (~mask[3] & 0xff)));
}

static void hexdump(const uint8_t *d, int n) {
  char b[3];
  for (int i = 0; i < n; i++) { sprintf(b, "%02x", d[i]); Serial.print(b); }
}

static bool connectWiFi() {
  Preferences p;
  p.begin("camaraesp", true);
  String ssid = p.getString("wifi_ssid", "");
  String pass = p.getString("wifi_pass", "");
  p.end();
  if (ssid.length() == 0) { Serial.println("ERR_NVS"); return false; }
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid.c_str(), pass.c_str());
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 20000) delay(500);
  if (WiFi.status() != WL_CONNECTED) { Serial.println("ERR_WIFI"); return false; }
  Serial.printf("WIFI %s\n", WiFi.localIP().toString().c_str());
  return true;
}

static void doDiscover() {
  IPAddress bcast = subnetBroadcast();
  for (int i = 0; i < 4; i++) {
    if (!socks[i]->begin(SOCKS_PORTS[i])) Serial.printf("BIND_FAIL %u\n", SOCKS_PORTS[i]);
  }
  unsigned long start = millis();
  unsigned long lastTx = 0;
  while (millis() - start < 15000) {
    if (millis() - lastTx > 2000) {
      lastTx = millis();
      for (int i = 0; i < 4; i++) {
        WiFiUDP *u = socks[i];
        uint16_t port = SOCKS_PORTS[i];
        u->beginPacket(IPAddress(255, 255, 255, 255), port);
        u->write(TUYA_REQ, TUYA_REQ_LEN);
        u->endPacket();
        u->beginPacket(bcast, port);
        u->write(TUYA_REQ, TUYA_REQ_LEN);
        u->endPacket();
      }
    }
    for (int i = 0; i < 4; i++) {
      int sz = socks[i]->parsePacket();
      if (sz > 0) {
        IPAddress r = socks[i]->remoteIP();
        uint16_t rp = socks[i]->remotePort();
        uint8_t buf[512];
        int n = socks[i]->read(buf, sizeof(buf));
        Serial.printf("D %s %u len=%d hex=", r.toString().c_str(), rp, n);
        hexdump(buf, n);
        Serial.println();
      }
    }
    delay(10);
  }
  for (int i = 0; i < 4; i++) socks[i]->stop();
  Serial.println("DISCOVER_DONE");
}

void setup() {
  Serial.begin(115200);
  delay(400);
  Serial.println("=== TUYA TOOL (PoC L1) ===");
  if (!connectWiFi()) return;
  Serial.println("READY");
}

void loop() {
  if (!bridging) {
    if (!Serial.available()) { delay(5); return; }
    String line = Serial.readStringUntil('\n');
    line.trim();
    if (line == "DISCOVER") {
      doDiscover();
    } else if (line.startsWith("CONNECT ")) {
      int s1 = line.indexOf(' ');
      int s2 = line.indexOf(' ', s1 + 1);
      if (s2 < 0) { Serial.println("TCPFAIL"); return; }
      String ip = line.substring(s1 + 1, s2);
      int port = line.substring(s2 + 1).toInt();
      IPAddress a;
      if (!a.fromString(ip)) { Serial.println("TCPFAIL"); return; }
      Serial.printf("CONNECTING %s %d\n", ip.c_str(), port);
      if (tcp.connect(a, (uint16_t)port, 6000)) {
        Serial.println("TCPOK");
        Serial.println("BRIDGE");
        tcp.setNoDelay(true);
        bridging = true;
      } else {
        Serial.println("TCPFAIL");
      }
    }
  } else {
    if (tcp.available()) {
      while (tcp.available()) Serial.write(tcp.read());
    }
    if (Serial.available()) {
      while (Serial.available()) tcp.write(Serial.read());
    }
    if (!tcp.connected()) {
      tcp.stop();
      bridging = false;
      // sin texto: el host reinicia o envía un comando nuevo
    }
    delay(1);
  }
}
