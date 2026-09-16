# Guía de operaciones — Cerraduras Hotel

## Arranque rápido

```bash
bash /root/cerraduras/start-all.sh
```

Esto levanta:
- **API** en `0.0.0.0:8080` → `https://<api-host>/api/v1/health`
- **WS-VB6** en `0.0.0.0:8081` → `http://92.113.151.136:8081/ws-vb6/v1/health`
- **Workers** de fondo (instancia única): `exit-scan`, `overstay-scan`,
  `outbox-worker`, `anomaly-scanner`, `presence-poller-manager`,
  `tuya-pulsar-consumer`.

`start-all.sh` ejecuta primero una parada determinista, de modo que es
**idempotente**: dos ejecuciones seguidas dejan exactamente una instancia por
worker, sin wrappers huérfanos. Cada worker corre en un wrapper supervisado
(`setsid`) con su PID en `api/run/<worker>.pid`.

## Requisitos previos (solo la primera vez)

```bash
sudo ufw allow 8080/tcp
sudo ufw allow 8081/tcp
```

## Parada

```bash
# Parada determinista de los workers (TERM → espera → KILL, borra PID files).
# NO toca los servidores `php -S` de API/WS-VB6.
bash /root/cerraduras/stop-all.sh

# Parada de los servidores web (cuando se quiera bajar todo):
pkill -f "php -S.*8080"
pkill -f "php -S.*8081"
```

`stop-all.sh` mata primero los wrappers `while true; do php bin/...` (para que
no relancen a sus hijos), luego los procesos hijo, y escala a `KILL` si no
terminan en `STOP_WAIT_SECS` (por defecto 6 s). Los patrones están acotados a
`bin/` y a los nombres de worker del proyecto.

## URLs de los servicios

| Servicio | URL |
|---|---|
| API | `https://<api-host>/api/v1` |
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
mysql -u cerraduras_user -p cerraduras_db
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

### Identificación de fábrica integrada

El único firmware productivo es
`docs/esp32-qr-reader/scanner-relay-prod.ino`. Tras completar el provisioning
WiFi existente, anuncia en segundo plano su `chip_id` eFuse al endpoint de
inventario usando la credencial individual generada por la placa. Un estado
`PENDING` se reintenta con backoff temporizado; `CLAIMED` pausa únicamente los
anuncios de ese arranque. El estado no se guarda en NVS, porque un reflasheo lo
limpia y el backend es la fuente autoritativa.

### Credenciales individuales ESP32 (F40)

El anuncio requiere TLS real; el claim se protege mediante autorización
administrativa/sesión. La API considera TLS directo solo si
PHP recibe `HTTPS` activo o `SERVER_PORT=443`; detrás de un terminador solo se
acepta `X-Forwarded-Proto=https` desde IPs listadas explícitamente en
`TRUSTED_PROXY_IPS`. Un cliente directo no puede falsificar ese encabezado.
El proxy debe eliminar/recrear el encabezado y el despliegue debe publicar un
certificado válido antes de activar el firmware.

La placa genera su clave una sola vez en el namespace NVS `device-cred`; se usa
para anuncio y operación, pero nunca se muestra por Serial ni WiFiManager. El
panel reclama por id de registro y no solicita la clave. Si una red ya guardada
falla, el equipo no vuelve a abrir el portal automáticamente.
Para reabrirlo, mantener GPIO4 pulsado durante el arranque: se limpia solo el
namespace WiFi, nunca `device-cred`. El backend conserva únicamente el hash.
El firmware productivo exige definir `CERRADURAS_API_CA_PEM` en la
configuración protegida de compilación; usa `WiFiClientSecure` y CA/pinning,
por lo que no existe una ruta HTTP alternativa. Ejemplo de build: añadir
`-DCERRADURAS_API_CA_PEM=R"CERT(...certificado desplegado...)CERT"` sin
commitear ese fichero/flag.

Para migrar legacy, no se acepta cualquier clave para un chip ya conocido:
provisiona de nuevo la placa y ejecuta el anuncio con su clave individual; si
la fila legacy tiene `enrollment_key_hash` nulo, un operador debe migrarla con
un procedimiento controlado de mantenimiento antes de reclamarla. Los
clientes API legacy sin `device_id` siguen operando durante la transición;
revócalos/desactívalos después de verificar QR, heartbeat e identify y de
confirmar que cada RPI usa su cliente vinculado.

### Recuperación: credencial de fábrica desincronizada (`403 invalid_factory_credential`)

Síntoma: el ESP32 conecta por WiFi y hace heartbeat/command-queue con
normalidad, pero repite `[FACTORY] anuncio HTTP 403` y en `api/logs/api.log`
aparece `invalid_factory_credential`. Significa que el `factory_key` de la NVS
no coincide con el hash del `api_clients` ya vinculado a la placa; como la
clave plana nunca se persiste, no se puede recuperar y hay que re-enrolar.
Mientras persista, el QR `validate`/`identify` de esa placa fallará con 401.

Re-enrolado (manual, solo afecta al chip indicado):

1. Apagar la placa ESP32 (evita que anuncie entre pasos).
2. Liberar el binding obsoleto en la BD:
   ```sql
   SET @chip := '<chip_id>';
   DELETE FROM factory_devices WHERE chip_id = @chip;
   DELETE FROM api_clients WHERE code = CONCAT('RPI-', @chip);
   ```
   Las FK `ON DELETE SET NULL` desvinculan `devices.api_client_id`
   automáticamente.
3. Borrar la NVS (`esptool erase_flash`) y reflashear la variante productiva; al
   arrancar genera `factory_key` nueva y pide provisioning WiFi.
4. Reprovisionar WiFi y esperar el anuncio `PENDING` (200/201).
5. Reclamar desde el panel "Identificación de fábrica" (etiqueta/pack).
   Reutiliza el `devices` existente por `external_id` y crea un `api_client`
   nuevo con la clave actual.
6. Verificar: `[FACTORY] chip_id=… CLAIMED`, sin más 403, y lectura QR real.

El firmware productivo detiene el anuncio ante un 4xx terminal (salvo 429) en
vez de reintentar cada 30 s; tras re-enrolar hay que reiniciar la placa.

### Callbacks USB y red (crash al conectar el lector)

En `scanner-relay-prod-12v-robusto.ino`, los callbacks `usb.onDeviceConnected`
y `usb.onKeyboard` corren en la **tarea del host USB**. Nunca deben usar el
`WiFiClientSecure`/`HTTPClient` compartidos (variable estática `apiTlsClient()`):
`loop()` los usa a la vez y el uso concurrente provoca un panic
`LoadProhibited` y reinicio en bucle al enchufar el lector HID.

Regla: los callbacks solo registran estado/flags (`scannerConnected`,
`scannerBeatPending`); el heartbeat de SCANNER se envía desde `loop()` junto al
resto de I/O de red, de forma secuencial.

### Relé (GPIO16) — polaridad configurable

El relé recuperado `SONGLE SRD-12VDC-SL-C` va sobre un módulo **ACTIVE-LOW**:
conduce con nivel **LOW** y su reposo limpio es **FLOAT** (`INPUT`). Poner la
entrada a HIGH la deja en zona indeterminada y el relé **zumba**; por eso el
reposo nunca es HIGH. En `scanner-relay-prod-12v-robusto.ino`:

- `#define RELAY_ACTIVE_LOW 1` → `relayOn() = OUTPUT + LOW`, `relayOff() = INPUT`.
- `#define RELAY_ACTIVE_LOW 0` → módulo ACTIVE-HIGH original (idle LOW), por compatibilidad.

Alimentación del módulo: `VCC` a **12 V**, `GND` común con el ESP32, señal
`IN1 → GPIO16`. El contacto de potencia: `COM → 12 V`, `NO → cerradura`, con
diodo flyback en paralelo con el solenoide.

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
