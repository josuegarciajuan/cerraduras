/**
 * r35d-usb-test.ino — R35D-B QR Reader via USB Host (ESP32-S3)
 *
 * Uses keyboard HID events to capture QR text from R35D-B.
 *
 * Arduino IDE:
 *   Board:      ESP32-S3-USB-OTG
 *   USB Mode:   USB-OTG (TinyUSB)
 *   USB CDC On Boot: Enabled
 */

#ifndef ARDUINO_USB_MODE
#error This ESP32 SoC has no Native USB interface
#elif ARDUINO_USB_MODE == 1
#error Set Tools > USB Mode > USB-OTG (TinyUSB)
#endif

#include "USB.h"
#include "USBHID.h"

USBHID HID;
String scanned = "";

void setup() {
  Serial.begin(115200);
  delay(2000);

  Serial.println();
  Serial.println("=========================================");
  Serial.println(" ESP32-S3 USB Host — R35D-B QR Reader");
  Serial.println("=========================================");

  HID.begin();
  USB.begin();

  Serial.println(" USB host started (generic HID)");
  Serial.println(" Ready. Scan a QR code now...");
  Serial.println("=========================================");
  Serial.println();
}

void loop() {
  // Poll HID reports
  if (HID.available()) {
    uint8_t buf[64];
    int len = HID.recv(buf, sizeof(buf));

    if (len > 2) {
      // Keyboard boot protocol: byte 0 = modifiers, byte 1 = reserved
      // byte 2.. = keycodes pressed
      for (int i = 2; i < len; i++) {
        if (buf[i] == 0) continue; // no key

        // Convert USB HID keycode to ASCII (simplified: only a-z, 0-9, space, enter)
        uint8_t kc = buf[i];
        char c = 0;

        // Map common USB HID keycodes to ASCII
        if (kc >= 0x04 && kc <= 0x1D) c = 'a' + (kc - 0x04);         // a-z
        else if (kc >= 0x1E && kc <= 0x27) c = '1' + (kc - 0x1E);     // 1-0
        else if (kc == 0x28) c = '\n';                                 // Enter
        else if (kc == 0x2C) c = ' ';                                  // Space
        else if (kc >= 0x36 && kc <= 0x37) c = ',' + (kc - 0x36);     // , .
        else if (kc == 0x38) c = '/';                                  // /

        if (c == '\n') {
          if (scanned.length() > 0) {
            Serial.println();
            Serial.print(">> SCANNED: ["); Serial.print(scanned); Serial.println("]");
            Serial.println();
            scanned = "";
          }
        } else if (c != 0) {
          scanned += c;
          Serial.print(c);
        }
      }
    }
  }

  delay(10);
}
