/**
 * test-relay-open-5s.ino — Verificación del relé recuperado (ACTIVE-LOW tri-state)
 *
 * Envía una señal de apertura (LOW) cada 5 s en bucle y vuelve a FLOAT (reposo).
 *
 * Esperado: CLIC firme al abrir (LOW) y al cerrar (FLOAT), SIN zumbido.
 *
 * Hardware (relé recuperado SRD-12VDC-SL-C, módulo ACTIVE-LOW):
 *   VCC módulo -> 12 V
 *   GND        -> común ESP32 <-> módulo <-> 12 V(-)
 *   IN1        -> GPIO16
 *
 * ESP32-S3-USB-OTG · Serial Monitor: 115200 baud
 */

#define RELAY_PIN 16
#define OPEN_MS   2000UL   // duración de la señal de apertura
#define CYCLE_MS  5000UL   // período entre aperturas

void relayOn()  { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, LOW); }  // LOW = ON
void relayOff() { pinMode(RELAY_PIN, INPUT); }                                 // FLOAT = OFF

void setup() {
  Serial.begin(115200);
  delay(300);
  relayOff();  // reposo desde el arranque (sin clic)

  Serial.println();
  Serial.println("==============================================");
  Serial.println(" TEST RELÉ RECUPERADO - APERTURA CADA 5 s");
  Serial.println("==============================================");
  Serial.printf (" Relay pin : GPIO%d (ACTIVE-LOW, reposo FLOAT)\n", RELAY_PIN);
  Serial.printf (" Apertura  : %lu ms cada %lu ms\n", OPEN_MS, CYCLE_MS);
  Serial.println(" VCC módulo: 12 V | GND común | IN1 -> GPIO16");
  Serial.println("----------------------------------------------");
  Serial.println(" Esperado: CLIC al abrir y al cerrar, SIN zumbido.");
  Serial.println("==============================================");
}

void loop() {
  static unsigned long n = 0;
  n++;

  Serial.printf("[%lu] APERTURA: GPIO%d = LOW (%lu ms)\n", n, RELAY_PIN, OPEN_MS);
  relayOn();
  delay(OPEN_MS);

  relayOff();
  Serial.printf("[%lu] REPOSO: GPIO%d = FLOAT\n", n, RELAY_PIN);

  delay(CYCLE_MS > OPEN_MS ? CYCLE_MS - OPEN_MS : 0);
}
