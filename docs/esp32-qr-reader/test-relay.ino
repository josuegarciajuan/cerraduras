/**
 * test-relay.ino — Test de relé SRD-05VDC-SL-C (ACTIVE-LOW)
 *
 * Parpadea el relé conectado a GPIO16 cada 2 segundos.
 * Debes oír un CLIC cada vez que el LED del ESP32 cambie.
 *
 * LÓGICA: LOW = relé ON, HIGH = relé OFF (active-LOW)
 *
 * Conexiones:
 *   Relé VCC  → PSU 5V (+)
 *   Relé GND  → PSU 5V (-) Y ESP32 GND (ambos juntos)
 *   Relé IN1  → ESP32 GPIO16
 *
 * El lado de potencia (COM, NO, NC) puede estar sin conectar.
 * La prueba solo verifica que el relé conmuta mecánicamente.
 */

#define RELAY_PIN 16

void setup() {
  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, HIGH);   // HIGH = relé APAGADO al inicio

  Serial.begin(115200);
  Serial.println("\n=== RELAY TEST (active-LOW) ===");
  Serial.println("LOW=ON, HIGH=OFF. El rele parpadeara cada 2s.");
  Serial.println("Debes oir CLIC-CLIC-CLIC...");
  Serial.println("Pines: VCC=5V, IN1=GPIO16, GND=GND");
  Serial.println("==================================\n");
}

void loop() {
  Serial.println("→ RELAY ON  (GPIO16=LOW)");
  digitalWrite(RELAY_PIN, LOW);    // LOW = relé ON → CLIC
  delay(2000);

  Serial.println("→ RELAY OFF (GPIO16=HIGH)");
  digitalWrite(RELAY_PIN, HIGH);   // HIGH = relé OFF → CLIC
  delay(2000);
}
