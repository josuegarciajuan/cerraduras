/**
 * scanner-s3-usbhost.ino — Escáner 2D (USB HID) en ESP32-S3 vía USB Host
 *
 * BASELINE: solo lee e imprime por Serial Monitor. Sin WiFi, sin HTTP.
 * Usar este fichero como referencia para restaurar si el sketch WiFi se rompe.
 *
 * Hardware
 * --------
 *   ESP32-S3 DevKitC:
 *     - Puerto "UART" (CP210x) → PC (alimenta + programa + Serial Monitor)
 *     - Puerto "USB"  (GPIO19/20, nativo) → lector (host USB)
 *   Placa:
 *     Board:     ESP32-S3-USB-OTG
 *     USB Mode:  USB-OTG
 *     Upload Mode: UART0 / Hardware CDC
 *     Core Debug: None (recomendado) o Verbose (solo debug)
 *   Lector 2D → ESP32-S3 (cable fabricado micro↔USB-C):
 *     - VBUS (+5V) común (pin 5V de la placa o fuente externa)
 *     - GND común
 *     - D+ (verde en cable micro, ↔ D+ del cable USB-C)
 *     - D− (blanco en cable micro, ↔ D− del cable USB-C)
 *
 * Librería: EspUsbHost (tanakamasayuki, vía Library Manager)
 *
 * Config del lector (escanear estos QRs, en orden):
 *   1. Reset Configuration to Defaults
 *   2. Interface Setup → USB
 *   3. Keyboard Language → United States
 *   4. End character → Add carriage return
 */
#include "EspUsbHost.h"

EspUsbHost usb;
String scanned;

void flushScan() {
  if (scanned.length() == 0) return;
  Serial.println();
  Serial.print(">> SCANNED [");
  Serial.print(scanned.length());
  Serial.print("]: ");
  Serial.println(scanned);
  scanned = "";
}

void setup() {
  Serial.begin(115200);
  delay(3000);
  Serial.println("\n==============================================");
  Serial.println(" ESP32-S3 USB Host - Escaner 2D (HID)");
  Serial.println("==============================================");
  scanned.reserve(256);

  // Layout EEUU: imprescindible para que . - _ salgan bien en tokens QR
  usb.setKeyboardLayout(ESP_USB_HOST_KEYBOARD_LAYOUT_EN_US);

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
    if (!event.pressed) return;                                   // solo pulsaciones
    if (event.ascii == '\r' || event.ascii == '\n') { flushScan(); return; }
    if (event.ascii >= 0x20 && event.ascii != 0x7F) scanned += (char)event.ascii;
  });

  if (!usb.begin()) {
    Serial.printf("usb.begin() fallo: %s\n", usb.lastErrorName());
  }
}

void loop() {
  delay(1);
}
