# Hardware Incidents

## IR-001 — ESP32 Dev Board destroyed (2026-06-22)

### Evento
- ESP32 Dev Board (chip ESP32-D0WD-V3, MAC: `30:76:f5:92:d7:2c`)
  sufrió descarga eléctrica al contacto durante verificación manual de temperatura
- Placa inutilizada — no responde a flasheo, no enciende
- El sketch anterior ocupaba ~83% de flash (~1.09MB / 1.31MB)

### Causa probable
- Sobrecalentamiento acumulado por sketch pesado en ejecución continua (WiFi activo + Serial2 polling + OTA)
- Posible fuga a tierra a través de la fuente de alimentación externa
- El flasheo fallido previo (`Invalid head of packet 0xF0`) ya indicaba corrupción

### Impacto
- Bloquea todas las pruebas del R35D-B hasta disponer de ESP32 de repuesto
- El sketch `r35d-test.ino` no pudo ser flasheado con `SERIAL_8E1`
- El sketch `r35d.ino` (producción) queda sin verificar

### Lecciones de seguridad
- **No superar 70% de uso de flash** en sketches de producción
- **Añadir watchdog** en `loop()` para evitar bucles infinitos
- **Añadir sleep periódico**: si `millis() - lastCheck > 30s`, entrar en deep sleep 5s
- **Aislar eléctricamente** la parte inferior de la placa (carcasa o cinta aislante)
- **Verificar temperatura con sensor** antes de manipular la placa
- **Desconectar alimentación** antes de tocar cualquier componente

### Lecciones de sketch
- El sketch actual (`r35d-test.ino`) debe ser **reducido** antes de flashear en una nueva placa:
  - Quitar `ArduinoOTA` (solo para debug)
  - Reducir buffer de `buf[2048]` a `buf[1024]` o `buf[512]`
  - Eliminar `hexDump()` completo por Serial (solo enviar CRC32 + primeras líneas)
  - Usar `String::reserve()` para evitar fragmentación de heap

### Acciones pendientes
- [ ] Conseguir ESP32 de repuesto (mismo modelo Dev Board)
- [ ] Reducir sketch antes de flashear (target: <60% flash)
- [ ] Probar `SERIAL_8E1` en el R35D-B
- [ ] Si no funciona, volver a `SERIAL_7E1` (la más prometedora)
- [ ] Si aún no funciona, implementar MODBUS bidireccional desde el sketch reducido
