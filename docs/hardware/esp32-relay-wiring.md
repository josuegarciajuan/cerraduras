# Circuito: ESP32 + Módulo Relé + Pestillo Eléctrico 12V

> Versión: 1.0 | Trazabilidad: RF-15, design §2.7
>
> ⚠️ Documento histórico (ESP32-WROOM-32, GPIO26, módulo de 5 V). El montaje
> actual es **ESP32-S3-USB-OTG + GPIO16 + módulo relé 12 V ACTIVE-LOW tri-state**;
> ver `docs/ops.md` (sección "Relé (GPIO16) — polaridad configurable") y
> `docs/esp32-qr-reader/scanner-relay-prod-12v-robusto.ino`.

## 1. Lista de materiales (BOM)

| # | Componente | Especificación | Cantidad |
|---|---|---|---|
| 1 | ESP32 Dev Board | ESP32-WROOM-32 (el existente con lector QR) | 1 |
| 2 | Módulo relé | SRD-05VDC-SL-C sobre placa driver (VCC/IN1/GND) | 1 |
| 3 | Pestillo eléctrico | 12V DC, normalmente cerrado (fail-safe) | 1 |
| 4 | Fuente 12V DC | 12V, ≥2A | 1 |
| 5 | Fuente 5V DC | 5V, ≥1A (solo para VCC del relé) | 1 |
| 6 | Cables | AWG22 (señal) + AWG18 (potencia pestillo) | varios |
| 7 | Multímetro | Para verificar conexiones | 1 |
| 8 | Destornillador | Para bornes del relé | 1 |

## 2. Pinout de cada componente

### 2.1 ESP32 Dev Board
```
                     ┌──────────────────────────┐
                     │  🎯 EN              D34  │
                     │  .  VP  (36)        D35  │
           USB ──────┤  .  VN  (39)        D32  │
                     │  .  D35 (34)        D33  │
                     │  .  D34 (35)        D25  │
      ┌─LED rojo     │  .  D32 (32)        D26  │ ← GPIO26 → relé IN1
      │  ┌─LED azul  │  .  D33 (33)        D27  │
      │  │           │  .  D25 (25)        D14  │
      │  │           │  .  D26 (26)         .   │
      │  │           │  .  D27 (27)         .   │
      │  │           │  .  D14 (14)         .   │
      │  │           │  .  D12 (12)         .   │
      │  │           │  .  D13 (13)         .   │
      │  │           │  .  GND ──── tierra común │
      │  │           │  .  5V  ──── (no usar)*   │
      │  │           │  .  3V3 ──── (solo test)  │
      │  │           │  .  EN              RX0  │
      │  │           │  .  CMD             TX0  │
      │  │           │  .  GPIO16 •        .    │ ← RX2 (lector QR GM65)
      │  │           │  .  GPIO17 •        .    │ ← TX2 (lector QR GM65)
      │  │           │  .  5V               .   │
      │  │           │  .  GND ──── tierra común │
      └──┴───────────┴──────────────────────────┘
           │     │
      USB 5V  GND (a fuente/alimentación)
```

### 2.2 Módulo Relé (SRD-05VDC-SL-C sobre placa)
```
       LADO DE SEÑAL (patitas)        LADO DE POTENCIA (bornes roscados)
       ┌───────────────────┐          ┌──────────────────────┐
       │  VCC  IN1  GND    │          │  NO    COM     NC    │
       │   ○    ○    ○     │   ===    │   ○     ○      ○    │
       └──┬────┬────┬──────┘   relé   └──┬─────┬──────┬─────┘
          │    │    │                    │     │      │
        5V   GPIO  GND               pestillo 12V  (no usado)
        (PSU) (ESP32) (común)         (+)    (+)     en este
                                              desde   diseño
                                              PSU 12V
```

**Circuito interno de la placa (componentes visibles: resistencias + transistor + diodo):**

```
 VCC (5V) ──────┬──────────────────────────────┐
                 │                              │
                 │   ┌─ bobina relé ────┐        │
                 │   │       ┌─┤◁──┐    │        │
                 │   │       │     │    │        │
                 │   │    C (colector)  │        │
                 │   └──────┤           │        │
                 │          │ NPN       │        │
 IN1 ──[R1 1kΩ]──┼── B (base)          │        │
                 │          │           │        │
                 │          └── E (emisor) ──┐   │
                 │                            │   │
 GND ────────────┴────────────────────────────┴───┘
                                            │
                                        [R2] LED

 Diodo flyback (1N4148 o 1N4007): protege el transistor del pico
 inductivo cuando la bobina se desenergiza.
```

**Cómo funciona:**
1. ESP32 GPIO26 = HIGH (3.3V) → corriente circula por R1 → base del NPN → transistor conduce
2. El transistor cierra el circuito: 5V → bobina relé → colector → emisor → GND
3. La bobina se energiza → campo magnético → atrae el contacto COM hacia NO
4. COM se desconecta de NC y se conecta a NO
5. 12V fluye: PSU 12V(+) → COM → NO → pestillo(+) → pestillo(-) → PSU 12V(-)
6. El pestillo se abre

**El diodo flyback es necesario:**
- Al cortar GPIO26 (LOW), el transistor deja de conducir
- La bobina, que estaba magnetizada, colapsa su campo → genera un pulso de cientos de voltios
- Sin diodo: ese pulso va al colector del transistor → lo quema → cascada al GPIO26 → quema el ESP32
- Con diodo: el pulso circula en bucle bobina↔diodo y se disipa como calor. El transistor y el ESP32 están protegidos.

### 2.3 Pestillo Eléctrico 12V
```
     ┌─────────────────────────────┐
     │     PESTILLO ELÉCTRICO      │
     │                             │
     │    ┌─────────┐              │
     │    │ BOBINA   │              │
     │    │ 12V DC   │              │
     │    │          │              │
     │    └────┬─────┘              │
     │         │                    │
     │    ┌────┴─────┐              │
     │    │  PESTILLO │ ─── se retrae al energizar
     │    │ MECÁNICO  │              │
     │    └──────────┘              │
     │                             │
     │  (+) rojo ──── 12V positivo │
     │  (-) negro ─── GND / 12V retorno │
     └─────────────────────────────┘

     Normalmente cerrado = sin 12V → pestillo extendido → PUERTA BLOQUEADA
     Con 12V             → pestillo retraído → PUERTA LIBRE
```

### 2.4 Fuentes de alimentación
```
     FUENTE 12V (≥2A)                    FUENTE 5V (≥1A)
     ┌──────────────┐                    ┌──────────────┐
     │  (+) ── rojo │                    │  (+) ── rojo │
     │  (-) ── negr │                    │  (-) ── negr │
     └──────────────┘                    └──────────────┘
     
     PSU 12V (+) → Relé COM             PSU 5V  (+) → Relé VCC
     PSU 12V (-) → Pestillo (-)         PSU 5V  (-) → TODOS LOS GND (común)
                → NO tocar con 5V
```

## 3. Esquema completo de cableado

```
 ╔══════════════════════════════════════════════════════════════════════════╗
 ║                        DIAGRAMA DE CONEXIONES                            ║
 ╠══════════════════════════════════════════════════════════════════════════╣
 ║                                                                          ║
 ║   ┌──────────────────────────────────────────────────────────────┐      ║
 ║   │                       ESP32 DEV BOARD                         │      ║
 ║   │                                                               │      ║
 ║   │  USB ────► alimentación (PC, cargador o 5V PSU por Vin)      │      ║
 ║   │                                                               │      ║
 ║   │  GPIO16 •──────► RX del lector QR GM65                        │      ║
 ║   │  GPIO17 •──────► TX del lector QR GM65                        │      ║
 ║   │                                                               │      ║
 ║   │  GPIO26 •──────────────────┐                                   │      ║
 ║   │                            │  cable AWG22 (señal)             │      ║
 ║   │                            ▼                                   │      ║
 ║   │  GND   •─────────────┬─────┼──────┬──────────────────┐        │      ║
 ║   │                      │     │      │                  │        │      ║
 ║   └──────────────────────┼─────┼──────┼──────────────────┼────────┘      ║
 ║                          │     │      │                  │               ║
 ║                          │     │      │                  │               ║
 ║   ┌─────────────────────┐│     │      │                  │               ║
 ║   │   FUENTE 5V DC      ││     │      │                  │               ║
 ║   │                     ││     │      │                  │               ║
 ║   │  (+) ──── rojo ─────┼─────┼──────┼──┐               │               ║
 ║   │  (-) ──── negro ────┼─────┼──────┼──┼───────────────┘               ║
 ║   └─────────────────────┘│     │      │  │                               ║
 ║                          │     │      │  │                               ║
 ║   ┌─────────────────────────────────┐  │                                 ║
 ║   │      MÓDULO RELÉ                │  │                                 ║
 ║   │  SRD-05VDC-SL-C (placa driver)  │  │                                 ║
 ║   │                                 │  │                                 ║
 ║   │  VCC ○──────────────── rojo ────┼──┘   (5V desde PSU 5V)            ║
 ║   │  IN1 ○──────────────── cable ───┘      (GPIO26 del ESP32)            ║
 ║   │  GND ○──────────────── negro ────────── (tierra común)               ║
 ║   │                                 │                                    ║
 ║   │   ╔═══ RELÉ ═══╗               │                                    ║
 ║   │   ║ bobina 5V   ║    contacto   │                                    ║
 ║   │   ║   ┌─┤◁──┐   ║  ┌──────────┐│                                    ║
 ║   │   ║   │diodo│   ║  │ COM  o  ─┼┼──── rojo ──── (+) FUENTE 12V      ║
 ║   │   ║   └─────┘   ║  │ NO   o  ─┼┼──── rojo ──── (+) PESTILLO         ║
 ║   │   ╚═════════════╝  │ NC   o  ─┼┼──── (no conectado)                 ║
 ║   │                     └──────────┘│                                    ║
 ║   └─────────────────────────────────┘                                    ║
 ║                                                                          ║
 ║   ┌─────────────────────────────────┐                                    ║
 ║   │       FUENTE 12V DC             │                                    ║
 ║   │                                 │                                    ║
 ║   │  (+) ──── rojo ────────────────► Relé COM                            ║
 ║   │                                 │                                    ║
 ║   │  (-) ──── negro ───────────────► Pestillo (-)                        ║
 ║   │                                 │                                    ║
 ║   │                                 │   ❗ ATENCIÓN:                      ║
 ║   │                                 │   El negativo de 12V NO se conecta ║
 ║   │                                 │   al negativo de 5V. El pestillo   ║
 ║   └─────────────────────────────────┘   cierra su circuito SOLO por el   ║
 ║                                         relé. Mantener las tierras de    ║
 ║   ┌─────────────────────────────────┐   5V y 12V SEPARADAS.             ║
 ║   │       PESTILLO ELÉCTRICO        │                                    ║
 ║   │                                 │                                    ║
 ║   │  (+) ──── rojo ────────────────► Relé NO                             ║
 ║   │  (-) ──── negro ───────────────► PSU 12V (-)                         ║
 ║   │                                 │                                    ║
 ║   │  ⚡ 12V → pestillo se ABRE      │                                    ║
 ║   │  ⚡ 0V  → pestillo CIERRA       │                                    ║
 ║   │     (mecánicamente por muelle)  │                                    ║
 ║   └─────────────────────────────────┘                                    ║
 ║                                                                          ║
 ╚══════════════════════════════════════════════════════════════════════════╝
```

## 4. Tabla resumen de conexiones

| Origen | Pin/Borne | → | Destino | Pin/Borne | Cable | Nota |
|---|---|---|---|---|---|---|
| ESP32 | GPIO26 | → | Relé | IN1 | AWG22 | Señal control 3.3V |
| ESP32 | GND | → | Relé | GND | AWG22 | Tierra común señal |
| ESP32 | GND | → | PSU 5V | (-) negro | AWG22 | Tierra común |
| PSU 5V | (+) rojo | → | Relé | VCC | AWG22 | Alimenta bobina |
| PSU 12V | (+) rojo | → | Relé | COM | AWG18 | Potencia pestillo |
| Relé | NO | → | Pestillo | (+) rojo | AWG18 | Interruptor |
| Pestillo | (-) negro | → | PSU 12V | (-) negro | AWG18 | Retorno potencia |

## 5. Secuencia de montaje (paso a paso)

### Paso 1 — Verificar el relé SIN conectar nada
```
Con el multímetro en modo CONTINUIDAD (pitido):
  1. Mide entre COM y NC → debe PITAR (están conectados en reposo)
  2. Mide entre COM y NO → NO debe pitar (están desconectados en reposo)
  3. Mide entre VCC e IN1 → NO debe pitar (no hay cortocircuito)
  4. Mide entre VCC y GND → NO debe pitar
  5. Mide entre IN1 y GND → NO debe pitar

✅ Si todo OK, continúa.
```

### Paso 2 — Probar el relé con 5V manual
```
Conecta TEMPORALMENTE:
  Relé VCC  → (+) de la fuente 5V
  Relé GND  → (-) de la fuente 5V

Ahora toca IN1 con un cable al (+) 5V:
  → El relé debe hacer CLIC audible
  → El LED (si lo tiene) debe encenderse
  → Mide COM↔NO con continuidad: ahora DEBE PITAR
  → Mide COM↔NC con continuidad: ahora NO debe pitar

Quita IN1 de 5V:
  → El relé debe hacer CLIC de nuevo (vuelve a reposo)
  → COM↔NC vuelve a pitar
  → COM↔NO deja de pitar

✅ Relé funciona.
```

### Paso 3 — Probar con 3.3V desde el ESP32
```
Conecta:
  Relé VCC  → PSU 5V (+)
  Relé GND  → PSU 5V (-) Y ESP32 GND (ambos juntos)

Desde el ESP32 (con un cable, sin soldar aún):
  Toca el pin 3.3V del ESP32 → Relé IN1
  → ¿Hace CLIC? ✅ El módulo acepta 3.3V. Perfecto.

  Si NO hace clic:
  → El módulo necesita ≥4V en IN1 (probablemente tiene optoacoplador).
  → Solución: usar un transistor NPN externo (2N2222) como buffer:
    
     ESP32 GPIO26 ──[R 1kΩ]── B (2N2222)
                               E ── GND
                               C ── Relé IN1
                               Relé VCC ── R_pullup 10kΩ ── 5V
     
     Así el ESP32 maneja 3.3V en la base pero el colector
     conmuta 5V hacia IN1. Añadir esto si falla el test.
```

### Paso 4 — Conectar el pestillo (SIN alimentar aún)
```
Relé COM → PSU 12V (+)  (cable AWG18 rojo)
Relé NO  → Pestillo (+)  (cable AWG18 rojo)
Pestillo (-) → PSU 12V (-) (cable AWG18 negro)

❗ ATENCIÓN: no conectes 12V(-) con el GND de 5V.
   Son circuitos separados. El único punto de unión es
   el relé (que aísla galvánicamente señal de potencia).
```

### Paso 5 — Conexión final definitiva
```
1. Conecta los GND comunes: ESP32 GND ↔ Relé GND ↔ PSU 5V (-)
2. Conecta PSU 5V (+) → Relé VCC
3. Conecta ESP32 GPIO26 → Relé IN1
4. Conecta el lado de potencia como en Paso 4
5. Enchufa PSU 5V y PSU 12V a la red
6. Enchufa ESP32 vía USB

El pestillo está en reposo (cerrado). Al activar GPIO26=HIGH,
debe oírse el clic del relé y el pestillo debe abrirse.
```

### Paso 6 — Test con código mínimo
```
Sube este sketch de prueba al ESP32:

  #define RELAY_PIN 26

  void setup() {
    pinMode(RELAY_PIN, OUTPUT);
    digitalWrite(RELAY_PIN, LOW);
    Serial.begin(115200);
    Serial.println("Relay test ready. Send '1' to open, '0' to close.");
  }

  void loop() {
    if (Serial.available()) {
      char c = Serial.read();
      if (c == '1') {
        Serial.println("→ RELAY ON (abriendo pestillo)");
        digitalWrite(RELAY_PIN, HIGH);
      } else if (c == '0') {
        Serial.println("→ RELAY OFF (cerrando pestillo)");
        digitalWrite(RELAY_PIN, LOW);
      }
    }
  }

Abre Serial Monitor (115200 baud).
  → Envía '1' → relé CLIC → pestillo se abre  ✅
  → Envía '0' → relé CLIC → pestillo se cierra ✅
```

## 6. Comprobaciones de seguridad

| # | Verificación | Cómo | Esperado |
|---|---|---|---|
| 1 | Tierra común 5V | Continuidad: ESP32 GND ↔ Relé GND | Pita ✅ |
| 2 | Aislamiento 12V | Continuidad: 12V(-) ↔ 5V(-) | NO pita ✅ |
| 3 | Aislamiento señal-potencia | Continuidad: IN1 ↔ COM | NO pita ✅ |
| 4 | Pestillo en reposo cerrado | Sin alimentar, intenta abrir puerta | No abre ✅ |
| 5 | Pestillo con 12V abre | Alimenta 12V directo al pestillo (sin relé) | Se abre ✅ |
| 6 | Relé conmuta con 3.3V | Paso 3 del montaje | CLIC audible ✅ |
| 7 | Diodo flyback presente | Inspección visual de la placa | Se ve el diodo ✅ |

## 7. Solución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| Relé no hace clic con 3.3V | Módulo requiere 5V en IN1 | Añadir transistor buffer (Ver Paso 3) |
| Relé hace clic pero pestillo no abre | Cable suelto en NO/COM/Pestillo | Verificar con multímetro |
| ESP32 se reinicia al activar relé | Ruido eléctrico / pico de corriente | Condensador 100µF entre VCC y GND del relé |
| Pestillo siempre abierto | NC conectado en vez de NO | Cambiar cable de NC a NO |
| El relé "chatterea" (vibra) | Fuente 5V insuficiente | Medir voltaje en VCC bajo carga; fuente ≥1A |
