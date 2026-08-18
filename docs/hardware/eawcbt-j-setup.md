# EAWCBT-J — Smart Electricity Protector Setup

> Modelo: 品诺-有温度-计量 (Pinno Smart Circuit Breaker with Metering)
> Categoría Tuya: `dlq` (circuit breaker)
> Integrado: 2026-07-14

---

## 1. Datos del dispositivo

| Campo | Valor |
|-------|-------|
| **Tuya Device ID** | `bfc8730a715c56c8d1paby` |
| **DP code on/off** | `switch` (boolean: `true` = ON, `false` = OFF) |
| **Product ID** | `odhgp5hewa1o7mdn` |
| **Categoría** | `dlq` |
| **IP local** | `93.176.170.12` |
| **Local Key** | `qhvE^7.4XW25}]i8` |
| **Voltaje entrada** | 220V AC |
| **Voltaje salida** | 220V AC |

### DP codes del dispositivo

| DP | Tipo | Descripción |
|----|------|-------------|
| `switch` | boolean | **Encendido/apagado** (principal) |
| `countdown_1` | integer | Temporizador (segundos) |
| `cur_voltage` | integer | Voltaje actual (en décimas: 2257 = 225.7V) |
| `cur_power` | integer | Potencia actual (W) |
| `cur_current` | integer | Corriente actual (mA) |
| `relay_status` | string | Estado del relé al arrancar |
| `child_lock` | boolean | Bloqueo infantil |
| `light_mode` | string | Modo luz (`relay`) |

---

## 2. Proceso de alta en Tuya

### 2.1 Emparejar en la app Tuya Smart

1. Conectar EAWCBT-J a 220V (entrada). El LED debe parpadear rápido (modo pairing).
   - Si no parpadea: mantener pulsado el botón físico hasta que parpadee.
2. Abrir **Tuya Smart** en el móvil.
3. Pulsar **"+"** → elegir categoría **"Interruptor"** o **"Disyuntor"**.
4. Conectar a la red WiFi del hotel (misma red que la API).
5. Verificar que se enciende/apaga desde la app.

### 2.2 Vincular al proyecto Tuya IoT

1. Ir a [iot.tuya.com](https://iot.tuya.com) → Cloud → **tu proyecto**.
2. Ir a **Devices** → comprobar si el dispositivo aparece automáticamente.
   - Si **NO aparece**: ir a **Devices** → **Link Tuya App Account** → escanear QR con la app Tuya Smart.
   - Alternativa: obtener el Device ID desde la app (Ajustes del dispositivo → Información) y usar **Link Device by ID**.
3. Verificar con el script de descubrimiento:
   ```bash
   php api/bin/tuya-discover.php <access_id> <access_secret>
   ```
4. El dispositivo debe aparecer como **"计量断路器"** con categoría `dlq`.

### 2.3 Verificar DP codes

1. En Tuya IoT → Cloud → **API Explorer** → **Device Control**.
2. Usar **"Get Device Specification"** → confirmar que el DP `switch` existe.
3. Probar **"Send Command"**:
   ```json
   {"code": "switch", "value": true}
   ```
   El dispositivo debe encenderse físicamente (LED indicador cambia).

---

## 3. Registro en el proyecto Cerraduras

### 3.1 Base de datos

```sql
INSERT INTO devices (room_id, kind, external_id, meta_json)
VALUES (1, 'SWITCH', 'bfc8730a715c56c8d1paby',
        '{"model": "EAWCBT-J", "dp_code": "switch"}');
```

Si el dispositivo ya existe (placeholder), actualizar:

```sql
UPDATE devices
SET external_id = 'bfc8730a715c56c8d1paby',
    meta_json = JSON_SET(COALESCE(meta_json, '{}'), '$.dp_code', 'switch')
WHERE room_id = 1 AND kind = 'SWITCH';
```

### 3.2 Modo de operación

| Entorno | `SIMULATED_MODE` | `simulated_override` | Gateway usado |
|---------|-------------------|---------------------|---------------|
| Producción | `false` | `NULL` | `TuyaSwitchGateway` (real) |
| Desarrollo | `true` | `NULL` o no importa | `SimulatedSwitchGateway` |
| Pruebas mixtas | `false` | `true` en room 1 | `SimulatedSwitchGateway` (solo room 1) |

### 3.3 Flujo de integración

```
QR escaneado → POST /qr/validate → API abre puerta
                                  → API enciende luz (SwitchService::turnOn)
Huésped sale → sensor presencia detecta ausencia
             → ExitRuleEvaluator dispara
             → API bloquea puerta
             → API apaga luz (SwitchService::turnOff)

Admin manual → POST /switches/{room}/on|off
```

---

## 4. Endpoints

| Método | Ruta | Scope | Descripción |
|--------|------|-------|-------------|
| GET | `/api/v1/rooms/{id}/switches` | `rooms:read` | Listar switches de la habitación |
| POST | `/api/v1/switches/{id}/on` | `switches:write` | Encender luz |
| POST | `/api/v1/switches/{id}/off` | `switches:write` | Apagar luz |

---

## 5. Troubleshooting

### El dispositivo no aparece en tuya-discover.php
- Verificar que la cuenta de Tuya Smart está vinculada al proyecto IoT (Link Tuya App Account).
- Si sigue sin aparecer, usar "Link Device by ID" con el Device ID obtenido de la app móvil.

### Error "permission deny" en TuyaSwitchGateway
- El dispositivo no está vinculado al proyecto IoT. Vincularlo en iot.tuya.com → Devices.
- Verificar que el `external_id` en BD coincide con el Device ID real.

### El switch no responde a comandos
- Verificar que el dispositivo está ONLINE en Tuya (🟢).
- Si está OFFLINE, comprobar conexión WiFi y alimentación 220V.
- Probar desde la app Tuya Smart primero; si funciona ahí pero no desde la API, revisar credenciales ACCESS_ID/ACCESS_SECRET.

### El DP code no es "switch"
- Algunos modelos EAWCBT-J pueden usar `switch_1` o `switch_2`.
- Para averiguarlo: `php api/bin/tuya-discover.php ...` → mirar los DPs listados.
- Actualizar `meta_json.dp_code` en la BD con el código correcto.
