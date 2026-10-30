# ESP-cerradura — OTA y flasheo directo (F100)

Variante `CERRADURA`, versión `2.6-ota`. `sketch.yaml` fija
`PartitionScheme=min_spiffs` (`ota_0` + `ota_1` + `otadata`).

## Compilar

```bash
arduino-cli compile --profile esp32s3usbotg .
```

Verificar la tabla de particiones:

```bash
arduino-cli compile --profile esp32s3usbotg --show-properties . | grep partitions
```

## Flasheo directo (primera vez o recuperación)

`firmware/flash-variant.sh` compila, hace backup del flash actual y escribe
`bootloader + partitions + boot_app0 + app` sin tocar la NVS (WiFi y
`device-cred` se conservan):

```bash
ARDUINO_DIRECTORIES_DATA=/home/oficina/.arduino15 \
ARDUINO_DIRECTORIES_USER=/home/oficina/Arduino \
  ./firmware/flash-variant.sh --variant=CERRADURA --port=/dev/ttyACM0
```

El script imprime la ruta y el SHA-256 del `.bin`. Publica **ese mismo**
binario para que la confirmación OTA coincida con la versión compilada:

```bash
php api/bin/firmware-publish.php --variant=CERRADURA --version=2.6-ota \
  --file=<ruta-del-bin> --commit=$(git rev-parse --short HEAD)
```

## OTA desde el panel

Con el equipo ya en `2.6-ota` y `ota_capable=true`, el panel puede cambiar la
variante a `IMAN` (y volver a `CERRADURA`) sin cable. El reinicio interrumpe
brevemente la secuencia de la cerradura; hacerlo sin un QR en curso.

El firmware solo limpia la operación pendiente si `firmware-confirm` responde
JSON de la API. Un HTML del panel-gate se reintenta en el siguiente heartbeat.
