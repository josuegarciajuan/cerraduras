/**
 * test-button.ino — Test del botón "mírame" (GPIO4 → GND)
 *
 * Basado en el bloque del botón identify de scanner-relay-prod.ino, SIN
 * WiFi, SIN relé, SIN factory-reset. Solo para verificar que el botón
 * está bien conectado al circuito.
 *
 * Cableado:
 *   Pulsador N.O. entre GPIO4 y GND.
 *   No hace falta resistencia externa: el ESP32 usa INPUT_PULLUP (~45kΩ a 3.3V).
 *   En reposo el pin lee HIGH. Al pulsar (cierra a GND) lee LOW.
 *
 * Qué verás en el Serial (115200 baud):
 *   - Al pulsar:   [BOTON] flanco → state=true (pulsado)
 *   - Mientras se mantiene pulsado: [BOTON] PULSANDO... cada 500 ms
 *   - Al soltar:   [BOTON] flanco → state=false (suelto)
 *
 * Placa: ESP32-S3-USB-OTG (o cualquier ESP32). Upload por UART.
 */

// ── Configuración ─────────────────────────────────────────────────────────
#define IDENTIFY_PIN          4     // GPIO4, pulsador N.O. a GND
#define IDENTIFY_DEBOUNCE_MS  50    // antirrebote (igual que producción)
#define PRINT_HELD_MS         500   // intervalo mientras se mantiene pulsado
#define BAUD                  115200

// ── Estado (igual que producción) ────────────────────────────────────────
bool          lastIdentifyState = HIGH;
bool          identifyPending   = false;
bool          pendingState      = false;
unsigned long identifyDebounce  = 0;
unsigned long lastIdentifyPrint = 0;

// ── setup ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(BAUD);
  delay(200);

  pinMode(IDENTIFY_PIN, INPUT_PULLUP);
  lastIdentifyState = digitalRead(IDENTIFY_PIN);

  Serial.println();
  Serial.println("==============================================");
  Serial.println(" TEST BOTON (test-button.ino)");
  Serial.println("==============================================");
  Serial.println(" Boton: GPIO" + String(IDENTIFY_PIN) + " -> GND (pull-up interno)");
  Serial.println(" En reposo se lee HIGH. Al pulsar, LOW.");
  Serial.println("----------------------------------------------");
  Serial.println(" Pulsa y MANTEN pulsado: debe imprimir");
  Serial.println(" [BOTON] PULSANDO... cada 500 ms.");
  Serial.println(" Al soltar, deja de imprimir.");
  Serial.println("==============================================");
  Serial.println();
  Serial.println(lastIdentifyState == LOW
                 ? "[BOTON] OJO: boton YA esta pulsado en el arranque."
                 : "[BOTON] Reposo OK (HIGH). Listo para pulsar.");
}

// ── loop ─────────────────────────────────────────────────────────────────
void loop() {
  unsigned long now = millis();

  // ── Detección de flanco con antirrebote (igual que producción) ─────
  bool raw = digitalRead(IDENTIFY_PIN);
  if (raw != lastIdentifyState) {
    if (now - identifyDebounce > IDENTIFY_DEBOUNCE_MS) {
      lastIdentifyState = raw;
      identifyPending   = true;
      pendingState      = (raw == LOW);
      Serial.printf("\n[BOTON] flanco → state=%s\n",
                    pendingState ? "true (pulsado)" : "false (suelto)");
    }
    identifyDebounce = now;
  }

  // ── Mientras se mantiene pulsado, imprimir periódicamente ──────────
  if (lastIdentifyState == LOW && now - lastIdentifyPrint > PRINT_HELD_MS) {
    lastIdentifyPrint = now;
    Serial.printf("[BOTON] PULSANDO... (GPIO%d = LOW)\n", IDENTIFY_PIN);
  }

  if (identifyPending) {
    identifyPending = false;
    // En producción aquí se notifica a la API. En este test, nada más.
  }

  delay(1);
}
