/**
 * scanner-s3-usbhost-wifi.ino — Escáner 2D (USB HID) con WiFi + POST /qr/validate
 *
 * Extiende scanner-s3-usbhost.ino añadiendo:
 *   - Conexión WiFi (Xiaomi_B04D)
 *   - chipId() único del ESP32 como device_id
 *   - POST a /api/v1/qr/validate con el QR escaneado
 *   - Heartbeat de salud cada 60s
 *
 * Hardware: idéntico a scanner-s3-usbhost.ino.
 * Placa:   Board: ESP32-S3-USB-OTG / USB Mode: USB-OTG / Upload Mode: UART0
 * Librería: EspUsbHost (tanakamasayuki)
 */

#include "EspUsbHost.h"
#include <WiFi.h>
#include <HTTPClient.h>

// ── Config ────────────────────────────────────────────────────────────────
const char* WIFI_SSID    = "Xiaomi_B04D";
const char* WIFI_PASS    = "bakcAse4#@";
const char* API_BASE_URL = "http://92.113.151.136:8080";
const char* API_KEY      = "8974517de1cfb1c3e6e8f2473c5f34a4cbd0252cb52ff6e1"; // RPI-DEV

// ── Globales ──────────────────────────────────────────────────────────────
EspUsbHost usb;
String     scanned;
String     pendingQr;
bool       hasPending = false;
unsigned   long lastBeat = 0;

// ── chipId: eFuse MAC en hex (sin ':', lowercase) ─────────────────────────
String chipId() {
  uint64_t mac = ESP.getEfuseMac();
  char buf[13];
  snprintf(buf, sizeof(buf), "%02x%02x%02x%02x%02x%02x",
           (uint8_t)(mac >> 40), (uint8_t)(mac >> 32), (uint8_t)(mac >> 24),
           (uint8_t)(mac >> 16), (uint8_t)(mac >> 8),  (uint8_t)(mac));
  return String(buf);
}

// ── WiFi reconnect ────────────────────────────────────────────────────────
bool ensureWiFi() {
  if (WiFi.status() == WL_CONNECTED) return true;
  WiFi.disconnect();
  delay(500);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  for (int i = 0; i < 20 && WiFi.status() != WL_CONNECTED; i++) delay(500);
  return WiFi.status() == WL_CONNECTED;
}

// ── setup ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(3000);

  String id = chipId();
  Serial.println("\n==============================================");
  Serial.println(" ESP32-S3 QR Reader (USB Host + WiFi)");
  Serial.println(" Device ID: " + id);
  Serial.println("==============================================");
  scanned.reserve(256);
  pendingQr.reserve(256);

  // Layout EEUU (para . - _ en tokens base64url)
  usb.setKeyboardLayout(ESP_USB_HOST_KEYBOARD_LAYOUT_EN_US);

  // ── USB Host callbacks ──────────────────────────────────────────────
  usb.onDeviceConnected([](const EspUsbHostDeviceInfo &device) {
    Serial.print("\n[USB] conectado: ");
    espUsbHostPrint(device);
    Serial.println("Listo. Escanea un QR...");
  });

  usb.onDeviceDisconnected([](const EspUsbHostDeviceInfo &device) {
    Serial.print("\n[USB] desconectado: ");
    espUsbHostPrint(device);
  });

  usb.onKeyboard([](const EspUsbHostKeyboardEvent &event) {
    if (!event.pressed) return;
    if (event.ascii == '\r' || event.ascii == '\n') {
      if (scanned.length() > 0) {
        pendingQr  = scanned;
        hasPending = true;
        scanned    = "";
      }
      return;
    }
    if (event.ascii >= 0x20 && event.ascii != 0x7F) scanned += (char)event.ascii;
  });

  if (!usb.begin()) {
    Serial.printf("usb.begin() fallo: %s\n", usb.lastErrorName());
  }

  // ── WiFi ────────────────────────────────────────────────────────────
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.print("\nWiFi");
  for (int i = 0; i < 40 && WiFi.status() != WL_CONNECTED; i++) {
    delay(500); Serial.print(".");
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println(" OK  IP: " + WiFi.localIP().toString());
  } else {
    Serial.println(" FALLO — se reintentara al escanear");
  }

  // ── Health check ────────────────────────────────────────────────────
  if (ensureWiFi()) {
    HTTPClient h;
    h.begin(String(API_BASE_URL) + "/api/v1/health");
    h.setTimeout(4000);
    int c = h.GET();
    Serial.println("API health: HTTP " + String(c));
    h.end();
  }
}

// ── loop ──────────────────────────────────────────────────────────────────
void loop() {
  // Procesar QR pendiente (con reintento si WiFi no disponible)
  if (hasPending) {
    if (!ensureWiFi()) {
      delay(1000);  // esperar y reintentar en la siguiente iteración
      return;
    }

    hasPending = false;
    String qr   = pendingQr;
    String id   = chipId();

    Serial.println();
    Serial.print(">> SCANNED [");
    Serial.print(qr.length());
    Serial.print("]: ");
    Serial.println(qr);

    String body = "{\"qr_text\":\"" + qr + "\",\"device_id\":\"" + id + "\"}";

    HTTPClient http;
    http.begin(String(API_BASE_URL) + "/api/v1/qr/validate");
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-API-Key", API_KEY);
    http.setTimeout(4000);

    int    code = http.POST(body);
    String resp = http.getString();
    http.end();

    Serial.println("API HTTP " + String(code));
    Serial.println("Respuesta: " + resp);

    if (code == 200) {
      Serial.println(">> ACCESO PERMITIDO");
    } else {
      Serial.println(">> ACCESO DENEGADO (ver respuesta)");
    }
  }

  // Heartbeat cada 60s
  unsigned long now = millis();
  if (now - lastBeat > 60000) {
    lastBeat = now;
    if (ensureWiFi()) {
      HTTPClient h;
      h.begin(String(API_BASE_URL) + "/api/v1/health");
      h.setTimeout(4000);
      int c = h.GET();
      Serial.println("♥ " + String(c));
      h.end();
    }
  }

  delay(1);
}
