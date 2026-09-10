/**
 * test-relay-sweep.ino — Diagnóstico mínimo: ¿el relé está bien, es polaridad,
 * o está roto?
 *
 * Barre GPIO16 por los 3 estados posibles, 3 s cada uno, en bucle:
 *   1) LOW   (0 V)     -> clic si el módulo dispara por BAJA
 *   2) HIGH  (3,3 V)   -> clic si el módulo dispara por ALTA (jumper H)
 *   3) FLOAT (alta Z)  -> reposo
 *
 * Config: ESP32-S3-USB-OTG · Serial Monitor: 115200 baud
 *
 * COMPROBAR ANTES (si algo falla, no habrá clic):
 *   [1] VCC del módulo = 12 V (con 5 V NO hace clic)
 *   [2] GND común: módulo GND <-> ESP32 GND <-> fuente 12 V (-)
 *   [3] IN1 del módulo <-> GPIO16
 *   [4] Jumper en H (prueba L si aquí no dispara)
 *
 * LEER RESULTADO:
 *   - Clic/LED en fase LOW  -> activo por BAJA (jumper L)
 *   - Clic/LED en fase HIGH -> activo por ALTA (jumper H)  [esperado]
 *   - Sin clic en ninguna   -> no es polaridad: falta 12 V / GND común,
 *                              la entrada necesita 5 V (JD_VCC/buffer), o módulo roto.
 */

#define RELAY_PIN 16
#define HOLD_MS   3000UL

void pinLow()   { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, LOW); }
void pinHigh()  { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, HIGH); }
void pinFloat() { pinMode(RELAY_PIN, INPUT); }

int           phase  = 0;
unsigned long nextAt = 0;
unsigned long cycles = 0;

void setup() {
  Serial.begin(115200);
  delay(300);
  pinFloat();

  Serial.println();
  Serial.println("==============================================");
  Serial.println(" TEST RELÉ - BARRIDO MÍNIMO (bueno / roto / polaridad)");
  Serial.println("==============================================");
  Serial.printf (" Relay pin  : GPIO%d\n", RELAY_PIN);
  Serial.println(" VCC módulo : DEBE ser 12 V");
  Serial.println(" GND        : común ESP32 <-> módulo <-> 12 V(-)");
  Serial.println(" Jumper     : H (prueba L si no dispara)");
  Serial.println("----------------------------------------------");
  Serial.println(" Observa el LED del módulo y escucha el CLIC:");
  Serial.println("  FASE LOW  -> clic = activo por BAJA");
  Serial.println("  FASE HIGH -> clic = activo por ALTA (esperado)");
  Serial.println("  Sin clic en ninguna = alimentación / entrada / roto");
  Serial.println("==============================================");
}

void loop() {
  unsigned long now = millis();
  if (now < nextAt) return;
  nextAt = now + HOLD_MS;

  switch (phase) {
    case 0:
      pinLow();
      Serial.printf("[%lu] FASE LOW   (GPIO%d = 0V)   -> ¿clic/LED?\n", ++cycles, RELAY_PIN);
      break;
    case 1:
      pinHigh();
      Serial.printf("[%lu] FASE HIGH  (GPIO%d = 3.3V) -> ¿clic/LED?\n", cycles, RELAY_PIN);
      break;
    default:
      pinFloat();
      Serial.printf("[%lu] FASE FLOAT (alta impedancia, reposo)\n", cycles);
      break;
  }
  phase = (phase + 1) % 3;
}
