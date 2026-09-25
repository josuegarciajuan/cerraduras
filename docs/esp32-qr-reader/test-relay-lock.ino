/**
 * test-relay-lock.ino v2 — Barrido de polaridad relé SONGLE (placa roja 3 pines)
 *
 * PROBLEMA detectado: con la señal de producción (LOW = activar) el relé nuevo
 * NO hace CLIC. Modelo identificado: SRD-12VDC-SL-C (bobina 12V) en módulo
 * "high/low level trigger". El jumper está en las 2 patas de la derecha = modo H
 * (disparo por ALTA). REQUISITOS:
 *   1) VCC del módulo debe ser 12V (con 5V no hace CLIC).
 *   2) Con jumper en H, la señal que dispara es ALTA (3.3V) → FASE B.
 *   3) GND común entre módulo y ESP32.
 *
 * Este sketch barre TODOS los estados eléctricos del pin para descubrir
 * en cuál dispara el módulo:
 *
 *   FASE A: pin en BAJA  (0V)     → si el módulo dispara por BAJA  → CLIC
 *   FASE B: pin en ALTA  (3.3V)   → si el módulo dispara por ALTA  → CLIC
 *   Reposo entre fases: pin flotante (alta impedancia, como producción).
 *
 * Además acepta comandos por Serial Monitor para mantener un estado fijo:
 *   '1' → señal BAJA (0V)          '2' → señal ALTA (3.3V)
 *   '0' → reposo (pin flotante)    'a' → volver al barrido automático
 *
 * Antes de flashear, comprueba (ver checklist en serial al arrancar):
 *   1) VCC del módulo debe ser 12V (este relé es SRD-12VDC). NO 5V.
 *   2) Jumper en las 2 patas de la derecha = modo H (disparo por ALTA).
 *   3) GND común entre módulo y ESP32.
 *   4) LED indicador de la placa encendido en la fase correcta.
 *
 * Placa: ESP32-S3-USB-OTG · Serial Monitor: 115200 baud
 */

#define RELAY_PIN           16
#define BAUD                115200
#define LED_PIN             2
#define STARTUP_DELAY_MS    3000UL   // tiempo para abrir el monitor
#define HOLD_MS             2000UL   // duración de cada fase en el barrido

// ── Estados del pin de control ────────────────────────────────────────────
void relayFloat() { pinMode(RELAY_PIN, INPUT); }                    // reposo (tri-state)
void relayLow()   { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, LOW); }
void relayHigh()  { pinMode(RELAY_PIN, OUTPUT); digitalWrite(RELAY_PIN, HIGH); }

// LED mirror: encendido siempre que el pin NO esté flotante (hay señal)
void ledSet(bool on) { pinMode(LED_PIN, OUTPUT); digitalWrite(LED_PIN, on ? HIGH : LOW); }

// ── Cabecera + checklist ──────────────────────────────────────────────────
void printBanner() {
  Serial.println();
  Serial.println("==============================================");
  Serial.println(" BARRIDO RELE SONGLE (test-relay-lock v2)");
  Serial.println("==============================================");
  Serial.printf (" Relay pin  : GPIO%d\n", RELAY_PIN);
  Serial.println(" Este sketch NO sabe la polaridad de tu modulo.");
  Serial.println(" Barre estados para DESCUBRIRLA:");
  Serial.println("   FASE A: pin BAJA (0V)   -> disparo por BAJA?");
  Serial.println("   FASE B: pin ALTA (3.3V) -> disparo por ALTA?");
  Serial.println("----------------------------------------------");
 Serial.println(" COMPRUEBA ANTES (si algo falla, no habra CLIC):");
 Serial.println(" [1] VCC del modulo -> 12V. Este rele es SRD-12VDC");
 Serial.println("     (bobina 12V). Alimentado a 5V NO hara CLIC.");
 Serial.println(" [2] Tu jumper esta a la DERECHA = modo H (disparo");
 Serial.println("     por ALTA) -> debe reaccionar en FASE B.");
 Serial.println(" [3] GND comun: modulo GND <-> ESP32 GND.");
 Serial.println(" [4] LED de la placa encendido al disparar.");
 Serial.println("     Si con 12V no responde a ALTA, prueba mover el");
 Serial.println("     jumper a la IZQUIERDA (modo L) y observa FASE A.");
  Serial.println("==============================================");
  Serial.println(" Observa en que FASE hace CLIC el rele y si el");
  Serial.println(" LED de la placa se enciende.");
  Serial.println(" Comandos: '1'=BAJA  '2'=ALTA  '0'=reposo  'a'=auto");
  Serial.println("==============================================");
}

// ── Aplicar un estado con anuncio por serial ──────────────────────────────
void showState(const char *name, void (*apply)(), bool driven) {
  apply();
  ledSet(driven);
  Serial.printf("[%lu ms] Estado: %s\n", millis(), name);
}

// ── Barrido automático (una fase cada HOLD_MS) ────────────────────────────
bool autoMode = true;
unsigned long nextChangeAt = 0;
int phase = 0;   // 0=Baja  1=Reposo  2=Alta  3=Reposo

void autoStep() {
  unsigned long now = millis();
  if (now < nextChangeAt) return;
  nextChangeAt = now + HOLD_MS;
  switch (phase) {
    case 0:
      Serial.println(">> FASE A: pin en BAJA (0V). Si el modulo es de disparo por BAJA -> CLIC + abre");
      showState("BAJA (0V)", relayLow, true);
      break;
    case 1:
      Serial.println("   (reposo: pin flotante. Espera el CLIC de desactivacion si estaba activo)");
      showState("REPOSO (flotante)", relayFloat, false);
      break;
    case 2:
      Serial.println(">> FASE B: pin en ALTA (3.3V). Si el modulo es de disparo por ALTA -> CLIC + abre");
      showState("ALTA (3.3V)", relayHigh, true);
      break;
    case 3:
      Serial.println("   (reposo: pin flotante. Espera el CLIC de desactivacion si estaba activo)");
      showState("REPOSO (flotante)", relayFloat, false);
      break;
  }
  phase = (phase + 1) % 4;
}

// ── setup ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(BAUD);
  delay(200);
  relayFloat();
  ledSet(false);

  printBanner();

  // Cuenta atrás para abrir el monitor a tiempo
  for (int i = (int)(STARTUP_DELAY_MS / 1000); i > 0; i--) {
    Serial.printf("[wait] barrido en %d s...\n", i);
    delay(1000);
  }
  nextChangeAt = millis();
  phase = 0;
}

// ── loop ─────────────────────────────────────────────────────────────────
void loop() {
  // Comandos manuales
  if (Serial.available() > 0) {
    char c = Serial.read();
    switch (c) {
      case '1': autoMode = false; showState("BAJA (0V) [manual]", relayLow, true);   break;
      case '2': autoMode = false; showState("ALTA (3.3V) [manual]", relayHigh, true); break;
      case '0': autoMode = false; showState("REPOSO flotante [manual]", relayFloat, false); break;
      case 'a': case 'A': autoMode = true; phase = 0; nextChangeAt = millis();
                Serial.println("(modo auto: barriendo fases...)");
                break;
    }
  }

  if (autoMode) autoStep();
}
