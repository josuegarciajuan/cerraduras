/**
 * scan-simple.ino — Prueba de lectura del lector QR USB (lo más simple posible)
 *
 * Basado en la parte USB-Host de scanner-relay-prod.ino, SIN WiFi, SIN relé,
 * SIN API. Solo: detectar el lector y volcar por Serial lo que escanea.
 *
 * CONFIGURACIÓN DE PLACA OBLIGATORIA (Arduino IDE):
 *   Board:      ESP32-S3-USB-OTG
 *   USB Mode:   USB-OTG          ← imprescindible (si no, el host no funciona)
 *   Upload Mode: UART0           ← subir por el puerto UART/CP210x
 *   Serial Monitor: 115200 baud
 *
 * Cableado físico:
 *   - Cable de subida (PC → placa) en el puerto UART.
 *   - Lector QR USB en el conector USB NATIVO (USB-OTG, GPIO19/20).
 *   - El lector debe tener el LED encendido (recibe 5V del host).
 *
 * Qué verás en el Serial:
 *   - Al arrancar: "[USB-INIT] USB Host iniciado correctamente" (o fallo: ...)
 *   - Al conectar el lector: "[USB] CONECTADO" + VID/PID
 *   - Al escanear:  ">> LECTURA [longitud]: <texto>"
 *   - Al desconectar: "[USB] DESCONECTADO"
 */

#include "EspUsbHost.h"

// ── Configuración ─────────────────────────────────────────────────────────
#define BAUD 115200

// ── Globales ──────────────────────────────────────────────────────────────
EspUsbHost usb;
String     scanned;

// ── setup ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(BAUD);
  delay(200);

  Serial.println();
  Serial.println("==============================================");
  Serial.println(" SCAN TEST (scan-simple.ino)");
  Serial.println("==============================================");
  Serial.println(" Escanea un QR delante del lector.");
  Serial.println(" Lo leido aparecera aqui abajo.");
  Serial.println("==============================================");

  scanned.reserve(512);

  // Layout EEUU (imprescindible para . - _ en tokens base64url)
  usb.setKeyboardLayout(ESP_USB_HOST_KEYBOARD_LAYOUT_EN_US);

  // ── Evento: dispositivo USB conectado ────────────────────────────────
  usb.onDeviceConnected([](const EspUsbHostDeviceInfo &device) {
    Serial.println("\n[USB] CONECTADO:");
    espUsbHostPrint(device);
    Serial.println("[USB] Lector listo. Escanea un QR...");
  });

  // ── Evento: dispositivo USB desconectado ─────────────────────────────
  usb.onDeviceDisconnected([](const EspUsbHostDeviceInfo &device) {
    Serial.println("\n[USB] DESCONECTADO:");
    espUsbHostPrint(device);
  });

  // ── Evento: teclas HID (el lector escribe como un teclado) ───────────
  usb.onKeyboard([](const EspUsbHostKeyboardEvent &event) {
    if (!event.pressed) return;                                   // solo pulsaciones
    if (event.ascii == '\r' || event.ascii == '\n') {             // fin de lectura
      if (scanned.length() > 0) {
        scanned.trim();
        Serial.println();
        Serial.print(">> LECTURA [");
        Serial.print(scanned.length());
        Serial.print("]: ");
        Serial.println(scanned);
        scanned = "";
      }
      return;
    }
    if (event.ascii >= 0x20 && event.ascii != 0x7F) scanned += (char)event.ascii;
  });

  // ── Iniciar USB Host ─────────────────────────────────────────────────
  if (!usb.begin()) {
    Serial.printf("[USB-INIT] usb.begin() fallo: %s\n", usb.lastErrorName());
    Serial.println("[USB-INIT] Comprueba: USB Mode = USB-OTG y que el");
    Serial.println("           lector esta en el conector USB nativo.");
  } else {
    Serial.println("[USB-INIT] USB Host iniciado correctamente");
    Serial.println("[USB-INIT] Esperando lector...");
  }
}

// ── loop ─────────────────────────────────────────────────────────────────
void loop() {
  // EspUsbHost procesa los eventos USB en segundo plano.
  // No hay nada que hacer aqui: todo llega por los callbacks.
  delay(1);
}
