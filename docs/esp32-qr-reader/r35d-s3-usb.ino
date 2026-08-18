/**
 * r35d-s3-usb.ino — R35D-B QR Reader via USB Host (ESP32-S3)
 *
 * Reads raw USB HID keyboard reports via USBHIDVendor and decodes
 * keycodes to ASCII. Works with ESP32 core 3.x where USBHIDKeyboard
 * lacks host-side available()/read().
 *
 * Hardware:
 *   R35D-B ╌USB-C╌► ESP32-S3 [OTG]
 *   VCC (rojo)  → 5V fuente externa
 *   GND (negro) → GND común
 *   ESP32-S3 [COM] → Ordenador (Serial Monitor)
 *
 * Arduino IDE:
 *   Board:    ESP32-S3-USB-OTG
 *   USB Mode: USB-OTG (TinyUSB)
 *   PSRAM:    OPI PSRAM
 *   Core:     esp32 >= 3.x
 */

#include "USB.h"
#include "USBHIDVendor.h"
#include <cstring>

USBHIDVendor Vendor;
String    scanned   = "";
uint8_t   prevKeys[6] = {0};

// ── USB HID keycode → ASCII (simplified) ──────────────────────────────────

char keyToAscii(uint8_t kc, bool shift) {
  // a-z
  if (kc >= 0x04 && kc <= 0x1D) {
    char base = 'a' + (kc - 0x04);
    return shift ? base - 32 : base;
  }
  // 1-9, 0
  if (kc >= 0x1E && kc <= 0x27) {
    const char* nums = "1234567890";
    const char* syms = "!@#$%^&*()";
    return shift ? syms[kc - 0x1E] : nums[kc - 0x1E];
  }
  // Special keys
  switch (kc) {
    case 0x28: return '\n';                     // Enter
    case 0x2C: return ' ';                      // Space
    case 0x2D: return shift ? '_' : '-';        // - / _
    case 0x34: return shift ? '"' : '\'';        // ' / "
    case 0x36: return shift ? '<' : ',';         // , / <
    case 0x37: return shift ? '>' : '.';         // . / >
    case 0x38: return shift ? '?' : '/';         // / / ?
    default:   return 0;                         // unhandled
  }
}

// ── setup ─────────────────────────────────────────────────────────────────

void setup() {
  Serial.begin(115200);
  delay(3000);

  Serial.println();
  Serial.println("=========================================");
  Serial.println(" ESP32-S3 USB Host — Raw HID Keyboard");
  Serial.println("=========================================");

  // Activar VBUS en puerto OTG
  pinMode(47, OUTPUT);
  digitalWrite(47, HIGH);

  Vendor.begin();
  USB.begin();

  Serial.println(" USB host started");
  Serial.println(" Ready. Scan a QR code now...");
  Serial.println("=========================================");
  Serial.println();
}

// ── loop ──────────────────────────────────────────────────────────────────

void loop() {
  // Read HID report (8 bytes for keyboard boot protocol)
  static uint8_t buf[8];
  static int     bufPos = 0;

  while (Vendor.available()) {
    buf[bufPos++] = Vendor.read();
    if (bufPos >= 8) {
      bufPos = 0;
      processReport(buf);
    }
  }

  delay(5);
}

// ── Process a single 8-byte HID keyboard report ───────────────────────────

void processReport(const uint8_t* buf) {
  uint8_t modifier = buf[0];     // byte 0: modifiers
  bool    shift    = modifier & 0x02;  // Left Shift or Right Shift

  uint8_t currentKeys[6] = {0};
  int     keyCount = 0;

  // Extract pressed keys from bytes 2-7
  for (int i = 2; i < 8; i++) {
    if (buf[i] == 0) continue;
    currentKeys[keyCount++] = buf[i];
  }

  // Find new keys (pressed now, not held from previous report)
  for (int i = 0; i < keyCount; i++) {
    uint8_t kc = currentKeys[i];
    bool isNew = true;
    for (int j = 0; j < 6 && prevKeys[j] != 0; j++) {
      if (prevKeys[j] == kc) { isNew = false; break; }
    }
    if (!isNew) continue;

    char c = keyToAscii(kc, shift);
    if (c == '\n' || c == '\r') {
      if (scanned.length() > 0) {
        Serial.println();
        Serial.print(">> SCANNED: [");
        Serial.print(scanned);
        Serial.println("]");
        Serial.println();
        scanned = "";
      }
    } else if (c != 0) {
      scanned += c;
      Serial.print(c);
    }
  }

  // Save for next comparison
  memcpy(prevKeys, currentKeys, 6);
}
