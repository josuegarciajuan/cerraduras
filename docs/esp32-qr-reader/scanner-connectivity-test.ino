/**
 * scanner-connectivity-test.ino — Diagnóstico de conexión del lector QR USB
 *
 * Objetivo: verificar si el lector 2D está bien conectado al ESP32-S3 y si
 * se reconoce como teclado HID. Sin WiFi, sin API. Solo USB Host + Serial.
 *
 * Qué hace:
 *   1. Inicia el USB Host (GPIO19/20, modo USB-OTG).
 *   2. Cada STATUS_INTERVAL_MS imprime el estado del lector (conectado o no),
 *      para verlo en el monitor sin necesidad de escanear.
 *   3. Callbacks en vivo: "[USB] conectado: ..." / "[USB] desconectado: ..."
 *   4. Eco de escaneo: al escanear un QR imprime ">> SCANNED [n]: <texto>".
 *   5. Si usb.begin() falla, lo reporta y reintenta cada 5 s.
 *
 * Configuración de placa (igual que producción):
 *   Board:      ESP32-S3-USB-OTG
 *   USB Mode:   USB-OTG          (el puerto nativo GPIO19/20 es HOST)
 *   Upload Mode: UART0           (flasheas por el puerto UART/CP210x)
 *   Serial Monitor: 115200 baud
 *
 * Lector: GM65 2D (o similar). El lector va al puerto USB-Host, NO al UART.
 *
 * Si el lector aparece CONECTADO pero escanear no imprime nada, el lector
 * puede estar en modo RS232 en vez de USB-HID. Configúralo escaneando los
 * QRs del manual del GM65, en orden:
 *   1) Reset Configuration to Defaults
 *   2) Interface Setup -> USB
 *   3) Keyboard Language -> United States
 *   4) End character -> Add carriage return
 */

#include "EspUsbHost.h"

// ── Configuración ─────────────────────────────────────────────────────────
#define BAUD              115200
#define STATUS_INTERVAL_MS 3000UL  // intervalo del informe de estado
#define USB_RETRY_MS       5000UL  // reintento si usb.begin() falla

// ── Globales ──────────────────────────────────────────────────────────────
EspUsbHost usb;
String     scanned;
bool       scannerConnected = false;   // F33: mismo flag semántico que producción
bool       usbHostReady      = false;
unsigned long lastStatusAt   = 0;
unsigned long lastUsbRetryAt = 0;

// ── Banner ────────────────────────────────────────────────────────────────
void printBanner() {
  Serial.println();
  Serial.println("==============================================");
  Serial.println(" LECTOR QR - TEST DE CONEXION");
  Serial.println(" (scanner-connectivity-test.ino)");
  Serial.println("==============================================");
  Serial.println(" Enchufa el lector al puerto USB-Host (USB-OTG).");
  Serial.println(" Estado del lector cada 3 s + eco al escanear.");
  Serial.println("----------------------------------------------");
  Serial.println(" SI sale CONECTADO pero escanear no imprime:");
  Serial.println("   El lector puede estar en modo RS232. Escanea");
  Serial.println("   los QRs del manual GM65 en orden:");
  Serial.println("   1) Reset Configuration to Defaults");
  Serial.println("   2) Interface Setup -> USB");
  Serial.println("   3) Keyboard Language -> United States");
  Serial.println("   4) End character -> Add carriage return");
  Serial.println("==============================================");
}

// ── Iniciar (o reintentar) el USB Host ────────────────────────────────────
void initUsbHost() {
  Serial.println("[USB-INIT] Iniciando USB Host...");
  usb.setKeyboardLayout(ESP_USB_HOST_KEYBOARD_LAYOUT_EN_US);

  usb.onDeviceConnected([](const EspUsbHostDeviceInfo &device) {
    Serial.print("\n[USB] conectado: ");
    espUsbHostPrint(device);
    scannerConnected = true;
    Serial.println("[USB] Lector detectado. Escanea un QR...");
  });

  usb.onDeviceDisconnected([](const EspUsbHostDeviceInfo &device) {
    Serial.print("\n[USB] desconectado: ");
    espUsbHostPrint(device);
    scannerConnected = false;
  });

  usb.onKeyboard([](const EspUsbHostKeyboardEvent &event) {
    if (!event.pressed) return;                                   // solo pulsaciones
    if (event.ascii == '\r' || event.ascii == '\n') {
      if (scanned.length() > 0) {
        Serial.println();
        Serial.print(">> SCANNED [");
        Serial.print(scanned.length());
        Serial.print("]: ");
        Serial.println(scanned);
        scanned = "";
      }
      return;
    }
    if (event.ascii >= 0x20 && event.ascii != 0x7F) scanned += (char)event.ascii;
  });

  if (!usb.begin()) {
    Serial.printf("[USB-INIT] usb.begin() fallo: %s\n", usb.lastErrorName());
    Serial.println("[USB-INIT] Reintentando en 5 s...");
    usbHostReady = false;
  } else {
    Serial.println("[USB-INIT] USB Host iniciado correctamente");
    usbHostReady = true;
  }
}

// ── Informe periódico de estado ───────────────────────────────────────────
void statusReport() {
  if (scannerConnected) {
    Serial.printf("[STATUS] Lector: CONECTADO (t=%lu ms)\n", millis());
  } else {
    Serial.printf("[STATUS] Lector: NO conectado - enchufalo al puerto USB-OTG (t=%lu ms)\n", millis());
  }
}

// ── setup ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(BAUD);
  delay(200);
  scanned.reserve(256);

  printBanner();
  initUsbHost();
  lastStatusAt = millis();
}

// ── loop ──────────────────────────────────────────────────────────────────
void loop() {
  unsigned long now = millis();

  // Reintentar init si falló
  if (!usbHostReady && now - lastUsbRetryAt > USB_RETRY_MS) {
    lastUsbRetryAt = now;
    initUsbHost();
  }

  // Informe periódico de estado
  if (now - lastStatusAt > STATUS_INTERVAL_MS) {
    lastStatusAt = now;
    statusReport();
  }

  delay(1);
}
