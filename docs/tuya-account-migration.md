# Migración de la cuenta/proyecto Tuya — Runbook

> **Tipo**: runbook operativo (how-to).
> **Audiencia**: quien integra/mantiene el lado Tuya (operador + desarrollador de
> integración). No es documentación de usuario final.
> **Alcance**: migrar la integración Tuya de la cuenta/proyecto actual a una cuenta
> nueva y re-emparejar los dispositivos. **No cubre** el alta de una habitación nueva:
> eso está en `.specs/specs/design.md` §13.9.
> **Última revisión**: 2026-09-28 · **Cadencia de revisión**: al cambiar de cuenta,
> proyecto, data center o cualquier `external_id` (revisar en la misma fase).

> ⚠️ **Secretos**: las credenciales Tuya (`TUYA_ACCESS_ID`, `TUYA_ACCESS_SECRET`)
> viven **solo** en `api/.env`, que está fuera de git. Este documento usa
> placeholders (`<TUYA_ACCESS_ID>`, `<TUYA_ACCESS_SECRET>`, `<nuevos_device_id>`).
> Nunca escribas valores reales aquí, en tests ni en la BD.

---

## §1 Estado actual de la cuenta/proyecto Tuya

| Campo | Valor |
|---|---|
| Cuenta de desarrollador (dev) | `u1891023800@gmail.com` |
| Proyecto / Cloud | `cerraduras` |
| Project id | `p1783418910142fpwmep` |
| Data center | **Central Europe (EU)** |
| API base URL | `TUYA_BASE_URL=https://openapi.tuyaeu.com` |
| Message Service / Pulsar | `wss://mqe.tuyaeu.com:8285` |

Puntos críticos:

- El consumer (`api/bin/tuya-pulsar-consumer/index.js`) tiene el **host EU
  hardcodeado** (`mqe.tuyaeu.com`). Por tanto el data center de la cuenta nueva
  **DEBE ser EU**; una cuenta en otro data center (p. ej. Western America) no
  funcionará sin cambiar código de negocio.
- Las credenciales viven **solo** en `api/.env` (`TUYA_ACCESS_ID`,
  `TUYA_ACCESS_SECRET`). Nunca en git. El consumer las lee al arrancar, así que
  cambiarlas exige **reiniciar** la unit (ver §7.9).

---

## §2 Inventario de dispositivos Tuya

| id interno | pack | kind | external_id | nombre | modelo | product_id | categoría | DPs / `dp_code` | `presence_source` | calibración | migrar? |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 241 | 305 | PRESENCE | `bf9a278e76e2c3f01ay0cs` | Sensor Presencia 24G V3 | 24G-Presence Sensor V3 | `5lld8pgsoynvctqa` | hps | far_min 75 / far_max 900 / far_step 75, sens 1..10, target_scale 10 | push | `far_detection=150`, `sensitivity=10` | **SÍ** |
| 25 | 305 | PROXIMITY | `bf4c7e7d2cef28cea2nkwk` | Sensor Puerta Hab.101 | MC400D (Zigbee) | — | — | — | — | — | **SÍ** (re-emparejar también el gateway Zigbee) |
| 26 | 305 | SWITCH | `bfc8730a715c56c8d1paby` | Luz Hab.101 | EAWCBT-J | — | — | `dp_code=switch` | — | — | **SÍ** |
| 5 | 5 | PRESENCE | `bf98d27d79685e38a2wbda` | Sensor Presencia ZY-M100 (pruebas) | ZY-M100-5 | — | hps ¹ | — | `disabled` | — | **NO** (desactivado, cero cuota) |
| 24 | 5 | LOCK | `bfafd3f2013c4b1876f5g5` | Cerradura Hab.101 | WBR3/jtmspro | — | — | — | — | — | **ACLARAR** |
| 226 | 305 | LOCK | `relay-local-proto2` | Cerradura proto2 (relé) | SRD-05VDC | — | — | — | — | — | **NO** (local, sin Tuya) |

Notas:

- ¹ En la fuente, la fila ZY-M100 lista `hps` en la columna de DPs; por coherencia
  con el 24G V3 se interpreta como **categoría** (Human Presence Sensor). Confirmar
  en la consola Tuya.
- **Cerradura Hab.101 (id 24) — ACLARAR**: el lock real va por
  `LOCK_PROVIDER=LOCAL` (ESP32); confirmar si este device sigue vinculado a Tuya o
  es residual. Si es residual, **no** migrar y documentar su baja.
- **Sensor de puerta (id 25)**: es Zigbee, depende de un **gateway Zigbee**. Al
  migrar hay que re-emparejar **primero el gateway** y después el sensor. La
  relación exacta device↔gateway no consta en este inventario: **confirmarla en la
  consola Tuya (Device Debug → parent/gateway)** antes de migrar.
- **Confirmar cada `external_id` contra la consola Tuya (Device Debug) antes de
  migrar.** Tras re-emparejar, **todos** cambian (ver §6).

---

## §3 Message Service / Pulsar

- **Consumer**: `api/bin/tuya-pulsar-consumer/index.js` (Node).
- **Unit systemd**: `cerraduras-pulsar-consumer.service`.
- **Topic**: `<TUYA_ACCESS_ID>/out/event`.
- **Endpoint Reader**:
  `wss://mqe.tuyaeu.com:8285/ws/v2/reader/persistent/<TUYA_ACCESS_ID>/out/event?...`.
- **Credenciales de Pulsar**: `username=TUYA_ACCESS_ID`,
  `password=MD5(accessId+accessKey).substring(8,24)`.
- Lee `TUYA_ACCESS_ID` / `TUYA_ACCESS_SECRET` de `api/.env` **al arrancar** → hay
  que **reiniciar la unit** al cambiarlas.
- **Estado**: `api/run/pulsar-consumer-status.json`
  (`connected`, `last_msg_at`, `known_devices`).
- **Reenvío**: el consumer reenvía a `POST /api/v1/tuya/webhook`, que normaliza en
  `TuyaSensorIngress`.

**Límites del trial** (plan gratuito): 1 data center, 50 devices, 10 controllable
devices, **26.000 API calls/mes**, **68.000 mensajes/mes**.

---

## §4 Reglas de mensajes (Messaging rules)

Estado actual (Production Environment, ENABLED):

```
Messaging rules — Production Environment (ENABLED)
  BizCode (Message type) IN statusReport
  Device id in bf4c7e7d2cef28cea2nkwk,bf9a278e76e2c3f01ay0cs
```

- `bf4c7e7d2cef28cea2nkwk` = MC400D puerta (PROXIMITY).
- `bf9a278e76e2c3f01ay0cs` = 24G V3 presencia (PRESENCE).

Reglas:

- Al migrar, **recrear** la regla con los **nuevos device id** (los viejos dejan de
  emitir para la cuenta nueva). No borrar la regla vieja hasta validar (§10).
- Si un device es **IoT Core** (protocolo 1000) y no llegan sus mensajes, añadir su
  tipo a `BizCode IN …` (p. ej. `devicePropertyMessage`). `TuyaSensorIngress` ya
  soporta `bizData`/`properties`.
- Referencia de alta de sensor nuevo: `.specs/specs/design.md` §13.9.

---

## §5 Puntos de código que consumen Tuya

| Punto | Ruta |
|---|---|
| Webhook de entrada | `POST /api/v1/tuya/webhook` (`TuyaWebhookController`) |
| Cliente API IoT Core | `tuyaPresenceApi()` (`api/public/index.php`) |
| Gateway de switch | `TuyaSwitchGateway` |
| Calibración de presencia | `/dashboard-api/presence-calibrate/*` |
| Sonda de dispositivos | `/dashboard-api/ping-all-devices` |
| Batería | `/dashboard-api/battery-refresh` |
| Poller de presencia (nube) | `api/bin/tuya-presence-poller.js` |
| Consumer Pulsar (push) | `api/bin/tuya-pulsar-consumer/index.js` |
| Créditos/cuota | `GET /dashboard-api/tuya-quota` |

---

## §6 Checklist de propagación de nuevos `device_id`

Tras re-emparejar cambian **todos** los `device_id`. Actualizar:

- `api/bin/run-tests.sh` líneas `1218`, `1222`, `1283`, `1285`, `1287`, `1372`,
  `2601`, `2609`, `3194`, `3207`, `3214`.
- Tests:
  - `api/tests/Unit/ChipBatteryTest.php:51`
  - `api/tests/Unit/TuyaPresenceMoveTest.php:113,125,133,141,149,162,174`
  - `api/tests/Unit/tuya-pulsar-consumer.test.js:57,60,81,85,91,97`
- `api/public/index.php:2547` (`SIMULA_DEVICE_ID`).
- `api/public/simula.html:340`.
- `.specs/specs/design.md:2397-2400`.
- `.specs/specs/contracts.md:346`.

**Recomendación**: parametrizar por variable de entorno/BD donde sea posible en
lugar de hardcodear, para que un cambio de cuenta no requiera tocar tests.

Detección adicional (ids residuales fuera de la lista anterior):

```bash
# Localiza cualquier external_id de Tuya hardcodeado en scripts y docs.
grep -rn "bf4c7e7d2cef28cea2nkwk\|bf9a278e76e2c3f01ay0cs\|bfc8730a715c56c8d1paby\|bf98d27d79685e38a2wbda\|bfafd3f2013c4b1876f5g5" \
  --include='*.php' --include='*.js' --include='*.sh' --include='*.md' --include='*.html' .
```

Herramientas sueltas bajo `api/bin/tuya-*.php` y `docs/hardware/eawcbt-j-setup.md`
también contienen ids: revisarlas (no bloquean la migración, pero quedaran
obsoletas).

### Propagación asistida

`api/bin/tuya-propagate-ids.php` automatiza (a) la actualización de
`devices.external_id` + `meta_json` en BD y (b) la sustitución de los ids
hardcodeados del checklist. Es **idempotente** y **dry-run por defecto**: sin
`--apply` no escribe nada.

```bash
# Dry-run: solo muestra el plan (no toca BD ni ficheros)
php api/bin/tuya-propagate-ids.php --map=map.json

# Aplica los cambios en BD y genera el rollback
php api/bin/tuya-propagate-ids.php --map=map.json --apply

# Sustituye también los ids en ficheros de texto — ejecutar DENTRO DE UN WORKTREE,
# nunca en el árbol de producción
php api/bin/tuya-propagate-ids.php --map=map.json --code --apply

# Alternativa sin fichero: una entrada por --id (repetible)
php api/bin/tuya-propagate-ids.php --id=PRESENCE:<viejo>=<nuevo>
```

Formato del JSON (`devices` obligatorio; `meta` opcional y se fusiona de forma
recursiva sobre el `meta_json` existente):

```json
{
  "devices": [
    {"kind":"PRESENCE","old":"<viejo>","new":"<nuevo>",
     "meta":{"product_id":"<pid>","dp_caps":{...},
             "presence_source":"push","calibration":{...}}},
    {"kind":"PROXIMITY","old":"<viejo>","new":"<nuevo>"},
    {"kind":"SWITCH","old":"<viejo>","new":"<nuevo>"}
  ],
  "code_refs": true
}
```

Notas:

- Si el id viejo ya no está y sí el nuevo, el script lo marca como **SKIP "ya
  migrado"** (seguro para re-ejecutar). Si no encuentra ninguno, **WARN**.
- Con `--apply`, **antes** de tocar la BD escribe el SQL inverso en
  `api/run/tuya-id-rollback-<YmdHis>.sql`.
- `--code` recorre los ficheros de texto del repo excluyendo `.git`,
  `node_modules`, `data/`, `api/run/` y `api/migrations/` (historial inmutable);
  en dry-run solo lista lo que cambiaría. Revisa el diff antes de `--apply`.

---

## §7 Runbook paso a paso (variante decidida: re-emparejar)

1. **Crear cuenta de desarrollador nueva** (email dedicado) en Tuya IoT,
   **data center Central Europe**.
2. **Crear proyecto/Cloud** y activar **IoT Core** + **Message Service** (trial).
   Anotar `<TUYA_ACCESS_ID>` / `<TUYA_ACCESS_SECRET>` (solo en `api/.env`).
3. Crear/entrar en la **cuenta de app** (Smart Life/Tuya app) nueva y **vincularla**
   al proyecto nuevo desde la consola: *Devices → Link devices by app account /
   App Account*.
4. **Re-emparejar cada dispositivo** a la cuenta de app nueva:
   - **Gateway Zigbee primero**, después el sensor de puerta MC400D.
   - Poner **24G** y **EAWCBT-J** en modo pairing y añadirlos.
   - **NO** migrar el ZY-M100 (`disabled`).
5. **Capturar los nuevos ids**: `php api/bin/tuya-discover.php` (requiere crédito
   activo) o la consola (*Device Debug*). Añadirlos a la tabla §2.
6. **Recrear Messaging rules** con los nuevos device id (Production Environment,
   ENABLED) — ver §4.
7. (Si aplica) registrar/verificar webhook con `api/bin/tuya-register-*.php`.
8. **(Código)** actualizar `api/.env`, `devices.external_id` + `meta_json`
   (`product_id`, `dp_caps`, `presence_source`, calibración) y el checklist §6.
   Nada de `.env` en git.
9. **Reiniciar** `cerraduras-pulsar-consumer.service` y los pollers; verificar:
   - `api/run/pulsar-consumer-status.json` → `connected:true` y `last_msg_at`
     nuevo.
   - `[MSG]` de los nuevos `devId` en `api/logs/pulsar-consumer.log`.
10. **Reaplicar calibración 24G** (`far_detection=150`, `sensitivity=10`) desde el
    panel.

Sugerencia de reinicio (ajustar si el orquestador ya gestiona las units):

```bash
systemctl restart cerraduras-pulsar-consumer.service
systemctl restart cerraduras-presence-poller.service
bash /root/cerraduras/start-all.sh
```

---

## §8 Verificación

- `GET /dashboard-api/tuya-quota` → `"credits":"ok"`.
- `POST /api/v1/switches/{room}/off` y `/on` → `result:ok`.
- Flujo QR → enciende / cierre → apaga.
- Calibración de presencia responde con el sensor nuevo.
- `battery-refresh` devuelve datos.
- `cd api && bash bin/run-tests.sh` con **0 fallos** (ids actualizados en §6).

---

## §9 Capacidad y monitorización

- **Consumo medido**: 19.465 mensajes en ~75 días ≈ **7.800/mes** frente al límite
  de **68.000/mes** → ~8× de margen.
- **API calls**: con arquitectura push (Message Service) solo se gastan en comandos
  del switch + sondas on-demand (límite 26.000/mes).
- **Causa histórica del agotamiento**: poller continuo con la puerta atascada
  `OPEN` toda la noche. Ya resuelto con arquitectura push (Message Service) + gates
  y backoff de cuota de F46.
- **Monitorizar**: `GET /dashboard-api/tuya-quota`, el log del consumer
  (`api/logs/pulsar-consumer.log`) y revisar `local_budget` en `/live`.

---

## §10 Rollback

- Mantener la cuenta/proyecto **viejo sin borrar** dispositivos hasta validar la
  cuenta nueva (los `.env` viejos se conservan fuera de git).
- Si falla: revertir `api/.env`, reiniciar el consumer y restaurar `external_id`
  desde el backup de BD.

---

## Referencias

- `docs/ops.md` — variables de entorno, arranque y operación.
- `.specs/specs/design.md` §13.9 — alta de sensores Tuya por push.
- `.specs/specs/contracts.md` — contrato del webhook `POST /api/v1/tuya/webhook`.
