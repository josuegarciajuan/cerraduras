Este proyecto sigue metodología Spec Driven Development.

## Flujo obligatorio
- No escribir código de negocio hasta que requirements, design, contracts y tasks estén aprobados.
- Trabajar siempre en este orden:
  1. requirements.md
  2. design.md
  3. contracts.md
  4. tasks.md
  5. implementación tarea por tarea
- Cada tarea debe ser pequeña, verificable y trazable a requisitos y contratos.
- Antes de implementar una tarea, revisar dependencias y archivos afectados.
- Al terminar cada tarea:
  - validar sintaxis
  - ejecutar tests relevantes si existen
  - resumir cambios y siguientes pasos

## Ubicación de documentos
- .specs/specs/requirements.md
- .specs/specs/design.md
- .specs/specs/contracts.md
- .specs/specs/tasks.md

## Estilo de trabajo
- Primero proponer, luego ejecutar
- Modificar solo lo pedido
- Preferir cambios mínimos y trazables

---

## Testing obligatorio por fase

**Regla**: al completar cada fase (F0–F19), ANTES de considerarla cerrada, se deben
implementar los tests correspondientes y el runner debe pasar con 0 failures.

### Comando de verificación

```bash
cd /root/cerraduras/api
bash bin/run-tests.sh
```

Este único comando ejecuta todos los tests acumulados de todas las fases completadas
hasta el momento (regresión completa). Debe ejecutarse:
- Al terminar cada fase completa.
- Antes de empezar la siguiente fase.
- Después de cualquier corrección de bugs.

### Qué añadir al completar cada fase

**1. Tests unitarios PHP** (lógica de dominio sin BD):
- Crear `api/tests/Unit/<NombreServicio>Test.php` siguiendo el patrón de los
  archivos existentes (scripts PHP planos, sin PHPUnit, con `pass()`/`fail()`).
- Se auto-descubren: cualquier archivo `*Test.php` en `tests/Unit/` se ejecuta
  automáticamente en el BLOQUE 1 del runner.

**2. Tests HTTP/integración** (requieren servidor y BD):
- Añadir un nuevo bloque en `api/bin/run-tests.sh` siguiendo el patrón:
  ```bash
  # ===================================================
  # BLOCK N — FN: <Nombre de la fase>
  # Trazabilidad: RF-X, TSK-XXX
  # ===================================================
  block "BLOCK N — FN: <Nombre>"
  http_test GET  /api/v1/endpoint  200  "descripción"  --key ADMIN-CLI
  http_test POST /api/v1/endpoint  201  "descripción"  --key VB6-MAIN --body '{...}'
  ```
- Los placeholders `# (PLACEHOLDER) BLOCK N — FN` ya están reservados en el script.
  Reemplazar cada uno al completar la fase correspondiente.

### Normas del runner
- Las claves API se leen dinámicamente de `api/seeds/dev_api_keys.txt`.
  Nunca hardcodear claves en el script.
- Los tests stateful (QR, stays, debts) comprueban el estado de la BD y hacen
  SKIP con mensaje explicativo si la BD no está en el estado esperado.
- Cada FAIL imprime: expected vs got + request completa + response (truncada 600 chars).
- El log acumulativo se escribe en `api/logs/test-results.log`.
- Usar `--idem "clave-unica-$(date +%s)"` para idempotency keys en tests HTTP
  que lo requieran, garantizando unicidad entre ejecuciones.

### Fases y bloques del runner

| Fase | Bloque runner | Estado |
|------|---------------|--------|
| F0–F2 | BLOCK 1 (unit) + BLOCK 2 (DB) | Completado |
| F3 Rooms/Types/Slots | BLOCK 6 | Completado |
| F4 Stays | BLOCK 7 | Completado |
| F5 QR emisión | BLOCK 8 | Completado |
| F6 QR validación + Locks | BLOCK 9 | Completado |
| F7 Gateways simulados | BLOCK 10 | Completado |
| F8 Presence + IoT sessions | BLOCK 11 | Completado |
| F9 Regla de salida | BLOCK 12 | Completado |
| F10 Overstay + Debts | BLOCK 13 | Completado |
| F11–F13 WS-VB6 | BLOCK 14 | Completado |
| F14 Outbox + worker | BLOCK 15 | Completado |
| F15 Modo simulado /sim/* | BLOCK 16 | Completado |
| F15.5 Smart Switch | BLOCK 18 | Completado |
| F20 Integración real | BLOCK 17 | Completado |
| F21 Dashboard + QR | BLOCK 19 | Completado |
| F25 Device Packs | — | Completado |
| F26 Botón Mírame | BLOCK 17 (2) | Completado |
| F27 QR pruebas + Pack reset | BLOCK 20, BLOCK 21 | Completado |
| F28 Flujo físico (luz, regla salida, exit-scan, coreografía) | — | Completado |
| F30 Refactor canónico pack (eliminar devices.room_id) | — | **Completado** (migración 0102) |
| **F31 Verificación de salida robusta** | **BLOCK 22** | **Completado** |
| **F32 Verificación dispositivos bajo demanda** | **BLOCK 23** | **Completado** |
| **F33 Command-queue SCANNER/LOCK** | **BLOCK 24** | **Completado** |
| **F34 Coreografía completa del panel** | — | **Completado** |
| **F35 Detección de anomalías** | **BLOCK 25** | **Completado** |
| **F36 Battery Monitoring** | **BLOCK 26** | **Completado** |
| F37 Configuración + filtro anomalías | BLOCK 27, BLOCK 28 | Completado |
| **F38 Workers (QR maestro)** | **BLOCK 29** | **Pendiente** |
| **F39 Estado verídico de dispositivos** | **BLOCK 30** | **Completado** |
| **F41 Robustez sensores + coreografía** | **BLOCK 33** | **Completado** |

### F39 — Estado verídico de dispositivos (panel "Dispositivos")

El punto de cada dispositivo en el panel debe reflejar su estado REAL, no uno reactivo:
- **ESP32/local (RPI, SCANNER, LOCK)** → se verifica por heartbeat del propio chip
  (device-heartbeat). El comando `check` (command-queue) NO se lanza en bucle: se usa
  solo al cargar el panel o pulsar "Comprobar dispositivos" (evita hacer saltar el
  relé de la cerradura o iluminar el lector QR continuamente).
- **Tuya cloud (PRESENCE, PROXIMITY, SWITCH)** → consumen cuota de la API Tuya.
  Solo se verifican con una sonda real (`GET /devices/{id}` → `result.online`) **al cargar
  el panel y al pulsar "Comprobar dispositivos"** (`POST /dashboard-api/ping-all-devices`
  con `verify_tuya:true`). **NUNCA** en el poll periódico.

Resultado: un dispositivo Tuya físicamente apagado ya no aparece "conectado". Mientras no
haya sonda fresca su estado es **`unknown` ("sin verificar", ámbar)**, no verde.

Modelo de datos (migración `0103`): `devices.online_state` + `devices.online_probed_at`
(última sonda Tuya real). `last_seen_at` sigue siendo solo actividad, ya no prueba conexión
para Tuya cloud.

### F41 — Robustez del pipeline de sensores y coreografía

- **Atomicidad (RF-43)**: `IotSessionService::processEvent()` es el único escritor del
  estado IoT por habitación: auditoría fuera de transacción → `SELECT … FOR UPDATE` sobre
  `iot_sessions` → decisión → `UPDATE` por columnas → commit; los efectos externos (luz,
  anomalías, regla de salida) corren post-commit y fuera del lock.
- **Orden y deduplicación (RF-44)**: migración `0108` (`event_fingerprint` UNIQUE +
  `applied`/`discard_reason`). Un evento atrasado (`stale`) o reenviado (`duplicate`) no
  revierte el estado; todo se audita en `presence_events`.
- **Gate del poller (RF-45)**: `tuya-presence-poller.js` decide la ventana de captura por
  estado de dominio y aplica watchdog (120 s) + cooldown para no consumir cuota Tuya.
- **Coreografía (RF-46/47)**: `entry_confirmed_at` en `stays` es la autoridad de "huésped
  dentro"; `exit_deadline` se emite solo con `ABSENT` + ciclo de puerta acreditado +
  `CLOSED`, y se cancela al reaparecer presencia.
- **Workers (RF-48)**: `system-status` expone 6 workers con
  `expected/instances/pids/healthy/degraded`; `start-all.sh`/`stop-all.sh` garantizan
  instancia única.
- **SSE (RF-49)**: el stream `event-stream` emite `event: ping` cada ~5 s como señal de
  vida del panel.
- **Tests**: BLOCK 33 del runner + `tests/Unit/choreography.test.js` y
  `tests/Unit/presence-poller-gate.test.js` (invocados con `node` desde el bloque, ya que
  no se autodescubren).

## Operaciones

Guía de arranque, URLs, BD, hardware: [`docs/ops.md`](docs/ops.md)

Arranque rápido: `bash /root/cerraduras/start-all.sh`
