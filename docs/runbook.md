# 🔐 Cerraduras Hotel — Runbook de Operaciones

> Última actualización: 2026-08-03 (Fase 3)
> Propósito: Procedimientos de recuperación ante fallos comunes.

---

## 1. Arranque Completo del Sistema

```bash
bash /root/cerraduras/start-all.sh
```

Verificar:
```bash
curl http://127.0.0.1:8080/api/v1/health/deep | python3 -m json.tool
bash /root/cerraduras/api/bin/smoke-test.sh
```

---

## 2. Caída de MySQL

### Síntomas
- `/api/v1/health/deep` muestra `database.status = error`
- Dashboard muestra "error" al cargar datos
- Workers imprimen "MySQL server has gone away"

### Recuperación (automática — Fase 1)
1. Los workers con `PdoFactory::ping()` reconectan automáticamente en la siguiente iteración
2. La API crea una nueva conexión en cada request (PHP built-in server no mantiene conexiones persistentes)

### Recuperación (manual, si la automática falla)
```bash
systemctl restart mysql
# Esperar 5s para que los workers reconecten
curl http://127.0.0.1:8080/api/v1/health/deep | python3 -c "import sys,json;d=json.load(sys.stdin);print(d['checks']['database'])"
```

### Verificar integridad
```bash
mysql -u cerraduras_user -p'f83bdcfaf5fece29e91f968a' cerraduras_db -e "SELECT COUNT(*) as stays FROM stays WHERE status IN ('RESERVED','OCCUPIED')"
```

---

## 3. Caída de Tuya API

### Síntomas
- Circuit breaker se abre automáticamente tras 5 fallos consecutivos (Fase 2 — T2.2)
- `/api/v1/health/deep` muestra `circuit_breaker.state = OPEN`
- Sensores dejan de reportar en el dashboard
- Pulsar consumer se desconecta y reconecta automáticamente

### Recuperación (automática)
- El circuit breaker se cierra solo tras 60s de pausa
- Pulsar consumer tiene backoff exponencial y `while true` (nunca se rinde)
- Presence poller tiene backoff de cuota de 10 min cuando Tuya reporta "quota exhausted"

### Recuperación (manual — reinicio completo Tuya)
```bash
pkill -f 'tuya-pulsar-consumer'
pkill -f 'tuya-presence-poller'
# El wrapper while true de start-all.sh los reinicia automáticamente
# Si usas systemd: systemctl restart cerraduras-workers
```

### Verificar conectividad Tuya
```bash
curl -s -X GET "https://openapi.tuyaeu.com/v1.0/token?grant_type=1" \
  -H "client_id: $(grep TUYA_ACCESS_ID /root/cerraduras/api/.env | cut -d= -f2)" \
  -H "sign: ..."  # requiere firma HMAC — usar el health deep endpoint en su lugar
```

---

## 4. Worker Caído

### Síntomas
- `/api/v1/health/deep` → `workers.<nombre> = stopped`
- Exit rule no se evalúa (si `exit-scan` caído)
- Deudas no se sincronizan (si `outbox-worker` caído)

### Recuperación (automática — Fase 1)
- `start-all.sh` usa bash `while true` para todos los workers
- Si un worker muere, se reinicia en <5s

### Recuperación (manual)
```bash
# Reiniciar un worker específico:
nohup bash -c "while true; do php /root/cerraduras/api/bin/exit-scan.php >> /root/cerraduras/api/logs/exit-scan.log 2>&1; sleep 2; done" > /dev/null 2>&1 &

# O reiniciar todos:
bash /root/cerraduras/start-all.sh

# Con systemd:
systemctl restart cerraduras-workers
```

---

## 5. Outbox Estancado (Items en FAILED)

### Síntomas
- `/api/v1/health/deep` → `outbox_failed.count > 0`
- Deudas no llegan al VB6

### Causas
- WS-VB6 inalcanzable
- Error de validación en los datos (codtic/codcli incorrectos)
- Rate limit del VB6

### Recuperación
```bash
# Ver los items fallidos:
mysql -u cerraduras_user -p'f83bdcfaf5fece29e91f968a' cerraduras_db -e \
  "SELECT id, topic, attempts, last_error, updated_at FROM outbox_vb6 WHERE status='FAILED' ORDER BY updated_at DESC LIMIT 10"

# Reintentar un item específico (vía API):
curl -X POST http://127.0.0.1:8080/api/v1/admin/outbox/ID_DEL_ITEM/retry \
  -H "X-Api-Key: ADMIN-CLI"

# Reintentar todos los FAILED (vía BD):
mysql -u cerraduras_user -p'f83bdcfaf5fece29e91f968a' cerraduras_db -e \
  "UPDATE outbox_vb6 SET status='PENDING', next_attempt_at=UTC_TIMESTAMP(3), attempts=0 WHERE status='FAILED'"
```

---

## 6. Batería Baja en Sensor MC400D

### Síntomas
- `/api/v1/health/deep` → `battery.devices_low` contiene el sensor
- Panel CRM → Salud → 🔋 Batería en amarillo/rojo
- Sensor de puerta deja de reportar OPEN/CLOSE → acceso bloqueado para esa habitación

### Recuperación
1. Identificar el sensor: `SELECT label, room_id, battery_pct FROM devices WHERE kind='PROXIMITY' AND battery_pct < 20`
2. Cambiar la pila CR123A del MC400D
3. Verificar que el sensor vuelve a reportar: abrir/cerrar la puerta y verificar en dashboard
4. Forzar refresh de batería: `POST /api/v1/admin/device-battery-refresh` (si el endpoint existe)

---

## 7. Habitación en Estado Inconsistente

### Síntomas
- Room status incorrecto (ej. OCCUPIED pero no hay nadie)
- IoT session desincronizada
- QR no funciona

### Recuperación
```bash
# Hard reset de la habitación (vía panel CRM):
# 1. Panel CRM → Habitaciones → Seleccionar habitación → "Resetear habitación"
# O vía API:
curl -X POST http://127.0.0.1:8080/dashboard-api/rooms/reset \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: ADMIN-CLI" \
  -d '{"room_id": ID_DE_LA_HABITACION}'

# Esto: revoca QRs, cierra stays, limpia IoT session, borra debts, apaga luz
```

---

## 8. Restauración desde Backup

```bash
# 1. Restaurar BD
mysql -u cerraduras_user -p'f83bdcfaf5fece29e91f968a' cerraduras_db < backup.sql

# 2. Aplicar migraciones (si el backup es antiguo)
cd /root/cerraduras/api && php bin/migrate.php

# 3. Aplicar seeds (API keys)
cd /root/cerraduras/api && php bin/seed.php

# 4. Arrancar
bash /root/cerraduras/start-all.sh

# 5. Verificar
bash /root/cerraduras/api/bin/smoke-test.sh
```

---

## 9. Comandos Útiles

```bash
# Estado de todos los componentes (un solo comando)
curl -s http://127.0.0.1:8080/api/v1/health/deep | python3 -m json.tool

# Ver procesos corriendo
ps aux | grep -E 'php.*bin/|node.*index|node.*poller'

# Ver logs en tiempo real
tail -f /root/cerraduras/api/logs/api.log
tail -f /root/cerraduras/api/logs/exit-scan.log
tail -f /root/cerraduras/api/logs/outbox-worker.log

# Ver logs de systemd
journalctl -u cerraduras-api -f
journalctl -u cerraduras-workers -f

# Ver items pendientes en outbox
mysql -u cerraduras_user -p'f83bdcfaf5fece29e91f968a' cerraduras_db -e \
  "SELECT status, COUNT(*) as cnt FROM outbox_vb6 GROUP BY status"

# Forzar evaluación de regla de salida (útil si un stay no transitó a EXITED)
curl -X POST http://127.0.0.1:8080/api/v1/admin/force-exit-check \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: ADMIN-CLI" \
  -d '{"room_id": 1}'

# Ver estancias activas
mysql -u cerraduras_user -p'f83bdcfaf5fece29e91f968a' cerraduras_db -e \
  "SELECT s.id, s.room_id, r.code, s.status, s.first_entry_at, s.duracion_minutos FROM stays s JOIN rooms r ON r.id=s.room_id WHERE s.status IN ('RESERVED','OCCUPIED','OVERSTAY')"

# Ver anomalías activas
curl -s http://127.0.0.1:8080/api/v1/anomalies?status=OPEN \
  -H "X-Api-Key: ADMIN-CLI" | python3 -m json.tool

# Smoke test rápido
bash /root/cerraduras/api/bin/smoke-test.sh
```

---

## 10. Contacto de Emergencia

- **API:** `http://92.113.151.136:8080/api/v1/health`
- **Dashboard:** `http://92.113.151.136:8080/dashboard?room=1`
- **Panel CRM:** `http://92.113.151.136:8080/panel/login.html`
- **Logs:** `/root/cerraduras/api/logs/`
- **BD:** `mysql -u cerraduras_user -p'f83bdcfaf5fece29e91f968a' cerraduras_db`
