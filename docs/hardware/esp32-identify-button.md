# Circuito: Pulsador "Mírame" (Identify Button) para ESP32

> Versión: 1.0 | Trazabilidad: RF-18, design §4.20

## 1. Objetivo

Conectar un pulsador físico normalmente abierto (N.O.) al ESP32 para que,
mientras se presiona, notifique a la API que el device pack de esa habitación
está siendo "señalado". Una UI externa muestra las habitaciones marcadas.

## 2. Lista de materiales (BOM)

| # | Componente | Especificación | Cantidad |
|---|---|---|---|
| 1 | Pulsador | Normalmente abierto (N.O.), SPST, para protoboard o panel | 1 |
| 2 | Cables | AWG22, ~10 cm cada uno | 2 |
| 3 | Placa protoboard | Opcional, para fijar el pulsador | 1 |

> **Nota**: No se necesita resistencia externa. El ESP32 configura el pin con
> `INPUT_PULLUP`, que activa una resistencia de ~45kΩ interna a 3.3V.
> En reposo el pin lee HIGH; al pulsar (cierra a GND), lee LOW.

## 3. Esquema de conexión

```
     ESP32 Dev Board
     ┌──────────────────────┐
     │                      │
     │  GPIO4 ──────────────┼────┐
     │                      │    │  Pulsador N.O.
     │                      │    ├──○  ○──┐
     │  GND  ───────────────┼────┘        │
     │                      │             │
     └──────────────────────┘      (al presionar,
                                     cierra GPIO4 a GND)
```

## 4. Tabla de conexiones

| Origen | Pin | → | Destino | Pin |
|---|---|---|---|---|
| ESP32 | GPIO4 | → | Pulsador | Terminal A |
| ESP32 | GND | → | Pulsador | Terminal B |

El pulsador no tiene polaridad. Cualquier terminal puede ir a GPIO4 o GND.

## 5. Principio de funcionamiento

1. **Reposo**: `digitalRead(GPIO4)` → `HIGH`. El pull-up interno mantiene el pin a 3.3V.
2. **Pulsado**: El pulsador cierra GPIO4 a GND. `digitalRead(GPIO4)` → `LOW`.
3. **Firmware**: Detecta flanco de bajada (HIGH→LOW) → notifica `state:true`.
   Detecta flanco de subida (LOW→HIGH) → notifica `state:false`.
4. **Antirrebote**: 50ms de debounce software para evitar falsos disparos por rebotes mecánicos.

## 6. Montaje paso a paso

### Paso 1 — Preparar los cables

Corta 2 trozos de cable AWG22 de ~10 cm. Pela ~5 mm de cada extremo.

### Paso 2 — Conectar al pulsador

Inserta cada cable en un terminal del pulsador. Si usas protoboard, inserta
el pulsador en la placa y los cables en las pistas adyacentes.

### Paso 3 — Conectar al ESP32

- Cable 1: de un terminal del pulsador al pin **GPIO4** del ESP32.
- Cable 2: del otro terminal del pulsador a **GND** del ESP32.

### Paso 4 — Verificar con el Serial Monitor

Sube el firmware `scanner-relay-prod.ino` (ya con la lógica del botón).
Abre Serial Monitor a 115200 baud.

- **Sin pulsar**: No debe aparecer nada nuevo.
- **Al pulsar**: Debe aparecer `[IDENTIFY] flanco detectado → state=true (pulsado)` seguido de la respuesta HTTP.
- **Al soltar**: Debe aparecer `[IDENTIFY] flanco detectado → state=false (suelto)` seguido de la respuesta HTTP.

### Paso 5 — Verificar en la API

```bash
# Con el botón pulsado, consultar la API:
curl -s http://localhost:8080/api/v1/rooms/identified \
  -H "X-API-Key: $(grep ADMIN-CLI api/seeds/dev_api_keys.txt | cut -d= -f2)" | jq .

# Debe devolver la habitación asociada al ESP32.
```

## 7. Solución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| Siempre lee LOW | Cable suelto o pulsador cerrado (defectuoso) | Verificar con multímetro: en reposo debe medir circuito abierto |
| Siempre lee HIGH aunque pulses | Cable roto o GPIO equivocado | Verificar conexión GPIO4↔GND; probar con otro pulsador |
| Múltiples notificaciones por pulsación | Rebotes mecánicos, debounce insuficiente | Subir `IDENTIFY_DEBOUNCE_MS` a 100 |
| HTTP 403 al notificar | API key sin scope `rpi` | Verificar que el ESP32 usa una key con scope `rpi` |
| HTTP 403 `device_mismatch` | ESP32 no registrado en BD | Registrar el chip ID vía `POST /devices/register` |

## 8. Pines del ESP32 (visión completa con Identify)

| GPIO | Uso |
|---|---|
| GPIO4 | **Botón "Mírame" (identify) — entrada digital con pull-up** |
| GPIO16 | Relé (ACTIVE-LOW tri-state) |
| USB-Host | Lector QR GM65 (vía EspUsbHost) |
