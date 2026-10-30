# ESP-imán — OTA (F100)

Compilar con dos slots de aplicación:

```bash
arduino-cli compile --profile esp32s3usbotg .
```

`sketch.yaml` fija `PartitionScheme=min_spiffs` (ota_0 + ota_1 + otadata).
**No usar** el esquema por defecto sin OTA. Verificar con:

```bash
arduino-cli compile --profile esp32s3usbotg --show-properties . | grep partitions
```

Publicar el binario (no el `.ino`):

```bash
php api/bin/firmware-publish.php --variant=IMAN --version=2.5-ota \
  --file=build/esp32.esp32.esp32s3usbotg/esp-iman.ino.bin --commit=$(git rev-parse --short HEAD)
```

El primer flasheo de un equipo ya instalado debe hacerse por cable. Después, las
actualizaciones se lanzan desde `/panel` y se confirman tras el reinicio.
