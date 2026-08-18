# Guía de operaciones — Cerraduras Hotel

## Arranque rápido

```bash
bash /root/cerraduras/start-all.sh
```

Esto levanta:
- **API** en `0.0.0.0:8080` → `http://92.113.151.136:8080/api/v1/health`
- **WS-VB6** en `0.0.0.0:8081` → `http://92.113.151.136:8081/ws-vb6/v1/health`

## Requisitos previos (solo la primera vez)

```bash
sudo ufw allow 8080/tcp
sudo ufw allow 8081/tcp
```

## Parada

```bash
pkill -f "php -S.*8080"
pkill -f "php -S.*8081"
```

## URLs de los servicios

| Servicio | URL |
|---|---|
| API | `http://92.113.151.136:8080/api/v1` |
| WS-VB6 | `http://92.113.151.136:8081/ws-vb6/v1` |
| Simulación | `http://92.113.151.136:8080/sim/rooms/{id}/door` |

## Variables de entorno y claves

Las claves API están en `api/seeds/dev_api_keys.txt`.

| Cliente | Uso |
|---|---|
| VB6-MAIN | Emitir QR (recepción) |
| RPI-DEV | Validar QR (lector en puerta) |
| ADMIN-CLI | Admin total |
| SIM-CLIENT | Simular sensores |
| VB6-BRIDGE | Outbox worker → WS-VB6 |

### Variables de entorno (.env)

| Variable | Valores | Default | Notas |
|---|---|---|---|
| `SIMULATED_MODE` | `true`/`false` | `true` | Sensores/locks simulados |
| `LOCK_PROVIDER` | `LOCAL`/`ESP32`/`TUYA`/`SIMULATED` | `LOCAL` | Provider de apertura de cerradura |
| **`QR_EXP_FROM_DB`** | `true`/`false` | **`false`** | **Caducidad de QR desde BD** |

### `QR_EXP_FROM_DB` — reutilización de QR físicos (pruebas)

- **`false` (producción)**: la caducidad del QR la manda el `exp` sellado
  en el token HMAC. No se puede extender un PNG ya impreso sin regenerarlo.
- **`true` (dev/testing)**: se ignora el `exp` del token; la ventana temporal
  la controla la columna `qr_credentials.expires_at` (editable vía SQL).
  Permite resetear y reutilizar el mismo PNG impreso durante varios días.

#### Cómo pasar a producción
```bash
# 1. Asegurar que el flag está en false
grep QR_EXP_FROM_DB api/.env   # debe decir =false

# 2. Reiniciar la API
bash /root/cerraduras/start-all.sh
```
A partir de ese momento, la caducidad vuelve a estar sellada en cada token.

## Base de datos

```bash
mysql -u cerraduras_user -p'f83bdcfaf5fece29e91f968a' cerraduras_db
```

Migraciones: `php /root/cerraduras/api/bin/migrate.php`
Semillas: `php /root/cerraduras/api/bin/seed.php`

## Tests

```bash
cd /root/cerraduras/api
bash bin/run-tests.sh
```

## Logs

```
/root/cerraduras/api/logs/php-server.log
/root/cerraduras/api/logs/api.log
/root/cerraduras/ws-vb6/logs/php-server.log
/root/cerraduras/ws-vb6/logs/ws-vb6.log
```

## Hardware

### ESP32 + GM65 (lector QR)

- Puerto UART: GPIO16 (RX2), GPIO17 (TX2)
- Device ID: chip ID del ESP32 (hex sin `:`, ej. `92f57630`)
- Registro en BD: tabla `devices`, `kind=RPI`, `external_id=chip_id`
- Firmware: `docs/esp32-qr-reader/esp32-qr-reader.ino`

### Conexión GM65 ↔ ESP32

```
GM65 VCC (rojo)  → ESP32 VIN
GM65 GND (verde) → ESP32 GND
GM65 TX           → Level shifter → ESP32 GPIO16
GM65 RX           → Level shifter → ESP32 GPIO17
```

### Registro masivo de dispositivos

```bash
php /root/cerraduras/api/bin/register-devices.php devices.csv
```

Formato CSV: `external_id,room_code` (ej. `92f57630,101`)
