/*
 * TuyaScan — escáner local de dispositivos Tuya (vantage point)
 *
 * PoC Plan L1 (.specs/specs/plan-control-local.md), Fase 3.
 * Flasheado en la ESP32-CAM de camaraESP (en la WiFi Xiaomi_B04D) para descubrir
 * dispositivos Tuya en su misma LAN. 100% local: NO usa la nube Tuya ni cuota.
 *
 * - Credenciales WiFi del NVS (namespace "camaraesp", claves wifi_ssid/wifi_pass).
 * - Envía REQ_DEVINFO (payload generado con tinytuya, 89 bytes) en broadcast
 *   (255.255.255.255 y broadcast de subred) a 6666/6667/7000 y escucha en
 *   6666/6667/7000/6668. Decodificar luego con tinytuya.decrypt_udp().
 */

#include <WiFi.h>
#include <WiFiUdp.h>
#include <Preferences.h>

static const char *NVS_NS   = "camaraesp";
static const char *NVS_SSID = "wifi_ssid";
static const char *NVS_PASS = "wifi_pass";

// Tuya UDP discovery (REQ_DEVINFO) con IP=192.168.31.112 — generado con
// tinytuya 1.20.0 (89 bytes). Si el ESP cambia de IP, regenerar el payload.
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

// Ventana de escaneo ampliada (Plan A: localizar MC400D).
static const unsigned long SCAN_MS   = 150000UL;
static const unsigned long BCAST_MS  = 5000UL;

WiFiUDP udp6666, udp6667, udp7000, udp6668;
WiFiUDP *socks[4] = { &udp6666, &udp6667, &udp7000, &udp6668 };
const uint16_t SOCKS_PORTS[4] = { 6666, 6667, 7000, 6668 };
bool scanStarted = false;
unsigned long scanStart = 0;
unsigned long lastBcast = 0;

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

static void broadcastDiscovery() {
  IPAddress bcast = subnetBroadcast();
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

void setup() {
  Serial.begin(115200);
  delay(400);
  Serial.println();
  Serial.println("=== TUYA LOCAL SCANNER v2 (Plan A) ===");

  Preferences p;
  p.begin(NVS_NS, true);
  String ssid = p.getString(NVS_SSID, "");
  String pass = p.getString(NVS_PASS, "");
  p.end();
  Serial.printf("[NVS] ssid='%s' pass_len=%u\n", ssid.c_str(), (unsigned)pass.length());
  if (ssid.length() == 0) { Serial.println("[ERR] sin SSID en NVS; abortando"); return; }

  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid.c_str(), pass.c_str());
  Serial.print("[WIFI] conectando");
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 20000) { Serial.print("."); delay(500); }
  Serial.println();
  if (WiFi.status() != WL_CONNECTED) { Serial.printf("[ERR] WiFi fallo status=%d\n", (int)WiFi.status()); return; }

  Serial.printf("[WIFI] OK ip=%s mask=%s gw=%s bcast=%s rssi=%d\n",
    WiFi.localIP().toString().c_str(), WiFi.subnetMask().toString().c_str(),
    WiFi.gatewayIP().toString().c_str(), subnetBroadcast().toString().c_str(), WiFi.RSSI());

  for (int i = 0; i < 4; i++) {
    if (socks[i]->begin(SOCKS_PORTS[i])) Serial.printf("[UDP] bind %u OK\n", SOCKS_PORTS[i]);
    else Serial.printf("[UDP] bind %u FALLO\n", SOCKS_PORTS[i]);
  }

  for (int r = 0; r < 3; r++) { broadcastDiscovery(); Serial.printf("[TX] round %d\n", r + 1); delay(1500); }
  Serial.println("[WAIT] escuchando respuestas 150s...");
  scanStart = millis();
  scanStarted = true;
}

void loop() {
  if (scanStarted) {
    for (int i = 0; i < 4; i++) {
      WiFiUDP *u = socks[i];
      int sz = u->parsePacket();
      if (sz > 0) {
        IPAddress r = u->remoteIP();
        uint16_t rp = u->remotePort();
        uint8_t buf[512];
        int n = u->read(buf, sizeof(buf));
        Serial.printf("[RX] from=%s:%u on=%u len=%d hex=", r.toString().c_str(), rp, SOCKS_PORTS[i], n);
        hexdump(buf, n);
        Serial.println();
      }
    }
    if (millis() - scanStart < SCAN_MS && millis() - lastBcast > BCAST_MS) {
      lastBcast = millis();
      broadcastDiscovery();
      Serial.println("[TX] re-broadcast");
    }
  }
  delay(15);
}
