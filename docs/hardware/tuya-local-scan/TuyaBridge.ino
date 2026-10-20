/*
 * TuyaBridge — puente TCP<->serie (vantage point para control local)
 *
 * PoC Plan L1 (.specs/specs/plan-control-local.md), Fase B1.
 * Flasheado en la ESP32-CAM (en la WiFi Xiaomi_B04D). Permite que un host
 * (oficina) hable con los dispositivos Tuya por su protocolo local TCP 6668
 * a través del USB-serie. 100% local: NO usa la nube Tuya ni cuota.
 *
 * Protocolo serie (líneas de texto; luego modo puente crudo):
 *    host -> ESP : "CONNECT <ip> <port>\n"
 *    ESP  -> host: "WIFI <ip>" / "WAIT" / "CONNECTING ..." / "TCPOK" | "TCPFAIL"
 *                  y al conectar: "BRIDGE" (a partir de ahí, bytes crudos).
 * Si el TCP se cierra, vuelve a imprimir "WAIT" para admitir otro CONNECT.
 *
 * Restaurar camaraESP después con scripts/deploy_camara.sh.
 */

#include <WiFi.h>
#include <WiFiClient.h>
#include <Preferences.h>

WiFiClient tcp;
bool bridging = false;

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

void setup() {
  Serial.begin(115200);
  delay(400);
  Serial.println("=== TUYA TCP BRIDGE (PoC L1/B1) ===");
  if (!connectWiFi()) return;
  Serial.println("WAIT");
}

void loop() {
  if (!bridging) {
    if (Serial.available()) {
      String line = Serial.readStringUntil('\n');
      line.trim();
      if (line.startsWith("CONNECT ")) {
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
          bridging = true;
          tcp.setNoDelay(true);
        } else {
          Serial.println("TCPFAIL");
          Serial.println("WAIT");
        }
      }
    }
    delay(5);
  } else {
    if (tcp.available()) {
      while (tcp.available()) Serial.write(tcp.read());
    }
    if (Serial.available()) {
      while (Serial.available()) tcp.write(Serial.read());
    }
    if (!tcp.connected()) {
      // Fin de sesión: NO escribir texto (el stream serie es binario puro).
      // El host reinicia la placa para una nueva sesión (CONNECT).
      tcp.stop();
      bridging = false;
    }
    delay(1);
  }
}
