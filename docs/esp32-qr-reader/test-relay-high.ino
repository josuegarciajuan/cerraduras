/**
 * test-relay-high.ino — Test aislado FASE ALTA (módulo disparo por HIGH)
 *
 * Módulo identificado: SONGLE SRD-12VDC-SL-C, placa "high/low level trigger"
 * con jumper en modo H (disparo por ALTA). Tierra común ESP32↔módulo↔fuente
 * ya conectada. Este sketch SOLO prueba esa fase:
 *
 *   1. Arranca, imprime cabecera y espera STARTUP_DELAY_MS (10 s) con
 *      cuenta atrás por serial (tiempo para abrir el Serial Monitor).
 *   2. Señal de apertura: GPIO16 = HIGH durante OPEN_DURATION_MS (5 s).
 *   3. GPIO16 = LOW y resumen con marcas de tiempo + checklist.
 *   4. loop() vacío. Pulsa RST para repetir.
 *
 * LÓGICA (módulo activo por ALTA):
 *   relayOn()  = pinMode(OUTPUT) + digitalWrite(HIGH) → relé ON → cerradura abre
 *   relayOff() = pinMode(OUTPUT) + digitalWrite(LOW)  → relé OFF → cerradura cierra
 *
 * Conexiones verificadas:
 *   Módulo DC+ → 12V(+)       Módulo DC- → GND común (fuente y ESP32)
 *   Módulo IN1 → ESP32 GPIO16 (tierra común con la fuente)
 *   Relé COM   → 12V(+)       Relé NO → cerradura(+)    Cerradura(-) → GND
 *   NC sin usar. Diodo flyback (1N4007) en paralelo con la cerradura
 *   (banda/cátodo hacia el lado NO, ánodo hacia GND).
 *
 * Placa: ESP32-S3-USB-OTG · Serial Monitor: 115200 baud
 */

// ── Configuración ─────────────────────────────────────────────────────────
#define RELAY_PIN           16
#define STARTUP_DELAY_MS    10000UL // espera inicial antes del disparo
#define OPEN_DURATION_MS    5000UL  // duración de la señal de apertura
#define LED_PIN             2
#define BAUD                115200

// ── Lógica del relé (módulo activo por ALTA) ─────────────────────────────
void relayOn()  { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, HIGH); }
void relayOff() { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, LOW); }

// ── Cuenta atrás por segundos ────────────────────────────────────────────
void startupCountdown(unsigned long totalMs) {
  unsigned long left = totalMs;
  while (left > 0) {
    unsigned long step = (left > 1000) ? 1000 : left;
    Serial.printf("[wait] apertura en %lu s...\n", (left + 999) / 1000);
    delay(step);
    left -= step;
  }
}

// ── setup ────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(BAUD);
  delay(200);
  relayOff();                    // relé OFF (LOW) al arrancar

  Serial.println();
  Serial.println("==============================================");
  Serial.println(" TEST FASE ALTA (test-relay-high.ino)");
  Serial.println("==============================================");
  Serial.println(" Modulo      : SONGLE SRD-12VDC (modo H)");
  Serial.println(" Disparo     : por ALTA (GPIO16 = HIGH abre)");
  Serial.printf (" Relay pin   : GPIO%d\n", RELAY_PIN);
  Serial.printf (" Apertura    : %lu ms\n", OPEN_DURATION_MS);
  Serial.println("----------------------------------------------");
  Serial.println(" > Relé OFF (GPIO16 = LOW). La cerradura debe");
  Serial.println("   estar CERRADA y el pestillo extendido.");
  Serial.println("==============================================");

  startupCountdown(STARTUP_DELAY_MS);

  // ── Señal de apertura ────────────────────────────────────────────────
  Serial.println();
  unsigned long tOn = millis();
  Serial.printf("[APERTURA] t=%lu ms -> GPIO%d = HIGH (relé ON).\n", tOn, RELAY_PIN);
  Serial.println("            La cerradura debe ABRIRSE: CLIC firme + pestillo retraído.");
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, HIGH);   // LED encendido durante la apertura
  relayOn();

  delay(OPEN_DURATION_MS);

  // ── Fin de la señal de apertura ──────────────────────────────────────
  unsigned long tOff = millis();
  relayOff();
  digitalWrite(LED_PIN, LOW);
  Serial.printf("[CIERRE]   t=%lu ms -> GPIO%d = LOW (relé OFF).\n", tOff, RELAY_PIN);
  Serial.println("            La cerradura debe volver a CERRARSE.");

  // ── Resumen + checklist ──────────────────────────────────────────────
  Serial.println();
  Serial.println("================= RESUMEN =================");
  Serial.printf(" Espera inicial  : %lu ms\n", STARTUP_DELAY_MS);
  Serial.printf(" Inicio apertura : t=%lu ms\n", tOn);
  Serial.printf(" Fin apertura    : t=%lu ms (duración %lu ms)\n", tOff, tOff - tOn);
  Serial.println("-------------------------------------------");
  Serial.println(" Checklist:");
  Serial.println(" 1) ¿CLIC firme del relé al ABRIR y al CERRAR?");
  Serial.println(" 2) ¿La cerradura se ABRIÓ durante los 5 s?");
  Serial.println(" 3) ¿Volvió a CERRAR al cortar la señal?");
  Serial.println(" 4) ¿LED de la placa encendido durante la apertura?");
  Serial.println("===========================================");
  Serial.println("Test finalizado. Pulsa RST para repetir.");
}

// ── loop ─────────────────────────────────────────────────────────────────
void loop() {
  // Disparo único al arranque: no hacer nada hasta que pulses RST.
}
