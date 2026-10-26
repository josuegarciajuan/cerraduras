# Brief — Caracterización funcional de los dispositivos Zigbee del banco

> **Uso**: pegar este contenido como prompt en una sesión nueva de OpenCode.
> **Fecha de referencia**: 2026-10-26 · **Estado**: los tres dispositivos ya están emparejados.
> **Objetivo**: descubrir y medir todas las capacidades reales antes de integrarlas en el proyecto.

---

## Prompt para la nueva sesión

```text
Actúa como ingeniero de integración Zigbee del proyecto Cerraduras. Trabaja en modo investigación
y documentación. No implementes la integración en producción ni cambies el flujo actual de Tuya.

CONTEXTO YA VALIDADO (no lo repitas ni lo pongas en duda sin evidencia):
- Host de banco: nodo SSH `oficina` (172.16.51.242), ordenador de trabajo. No lo reinicies ni
  toques su red, firewall, systemd, escritorio, agentes de cámara ni Docker ajeno.
- Proyecto aislado: `/opt/cerraduras-zigbee` (Compose `zigbee-local`).
- Mosquitto 2.0.22 y Zigbee2MQTT 2.14.2, puertos solo en loopback (1883 y 8080).
- Coordinador: SONOFF Dongle-M EFR32MG24 por Ethernet/PoE en 172.16.51.215:6638.
- Dispositivos emparejados y renombrados:
  1. `puerta-oficina` — IEEE 0xc09b9efffef431fb — SONOFF SNZB-04P (EndDevice).
  2. `presencia-oficina` — IEEE 0xa4c138b2a3c49dbe — Tuya ZY-M100-S_2 (Router).
  3. `luz-oficina` — IEEE 0xf044d3fffecd9e8e — SONOFF ZBMINIL2 (EndDevice).
- `permit_join` está desactivado. No lo actives salvo que necesites re-emparejar y lo justifiques.
- Los secretos están en `/opt/cerraduras-zigbee/.env`; NUNCA los imprimas, copies al repo ni los
  pases como argumentos visibles. Usa las variables de entorno dentro de los contenedores.
- Regla del proyecto: no polling a Tuya cloud. Esta investigación es 100% local y no debe llamar
  a la API de Tuya ni consumir cuota.

OBJETIVO:
Producir un inventario funcional y medido de los tres dispositivos, con evidencia reproducible,
para decidir después qué se puede integrar y qué algoritmos del proyecto se pueden optimizar.
No asumas capacidades por el nombre comercial: verifica lo que Zigbee2MQTT expone y publica.

INVESTIGA Y DOCUMENTA, POR CADA DISPOSITIVO:
1. Identidad: IEEE, modelo, vendor, tipo Z2M (EndDevice/Router), firmware, fecha, batería/alimentación.
2. Entidades expuestas y su semántica exacta: nombre, tipo, unidad, rango, valores posibles,
   accesibilidad (solo lectura / lectura-escritura), y qué significa cada valor.
3. Topics MQTT:
   - estado (`zigbee2mqtt/<friendly_name>`),
   - comandos (`.../set`, `.../get`),
   - disponibilidad (`.../availability`),
   - eventos del bridge (`zigbee2mqtt/bridge/event`, `.../response/...`).
   Indica payloads reales observados, no inventados.
4. ¿Push o consulta? Determina qué mensajes llegan solos y cuáles requieren una petición.
   Comprueba si `/get` funciona y qué devuelve para cada atributo.
5. Comandos disponibles: para el relé `state` y `power_on_behavior`; para los sensores, ajustes
   como sensibilidad, rangos, delays, fading, `time_start`, etc. Verifica cuáles aceptan escritura.
6. Eventos y latencia:
   - Mide con marcas temporales físicas (acción humana) y timestamps de MQTT/Z2M.
   - Usa `mosquitto_sub -F '%I %t %p'` o logs de Z2M; documenta el método.
   - Repite N veces (mínimo 5) y reporta mínimo/mediana/máximo, no solo una muestra.
   - Separa latencia de sensor, de radio, de Z2M y de MQTT.
7. Frecuencia y volumen:
   - Mensajes en reposo durante 2–5 minutos.
   - Mensajes durante actividad.
   - Tamaño aproximado y mensajes/segundo.
   - Impacto estimado si se multiplica por 70 habitaciones y qué haría falta para reducirlo.
8. Comportamiento específico:
   - SNZB-04P: `contact` abierto/cerrado, `tamper`, batería, repetidos, recuperación tras reinicio.
   - ZY-M100-S_2: `presence` true/false, tiempo de retorno a false, `target_distance` (¿es fiable?),
     `illuminance`, falsos positivos, detección estática, efecto de `fading_time`/`detection_delay`.
   - ZBMINIL2: `state` ON/OFF, confirmación real, botón físico, interruptor externo S1/S2,
     `power_on_behavior` tras corte, comportamiento con carga mínima/LED.
9. OTA/firmware: si hay versión disponible, changelog y si merece la pena. No actualices sin
   autorización explícita.
10. Límites: qué no se puede hacer, qué es poco fiable, qué requiere calibración o instalación.

REGLAS DE SEGURIDAD:
- No cambies `permit_join`, nombres, opciones ni firmware sin decir qué vas a hacer y por qué.
- Para el relé, no lo enciendas si no está claro que la lámpara y el cableado son seguros.
  La prueba física del banco ya se hizo con el electricista; cualquier cambio de cableado es
  responsabilidad del electricista.
- No expongas el broker ni la UI fuera de loopback.
- No toques `start-all.sh`, `stop-all.sh`, systemd ni el proyecto hotelero.
- No crees pollers de Tuya. Ninguna llamada a Tuya openapi.
- No escribas secretos, claves de red, contraseñas ni contenido de `.env` en documentos.

ENTREGABLES:
- Crear/actualizar `docs/hardware/zigbee-capabilities.md` con una tabla por dispositivo:
  entidad, tipo, acceso, unidad/rango, topic, comando, observaciones y evidencia.
- Añadir una sección de latencia y otra de volumen/frecuencia con los números medidos.
- Añadir una sección "qué se puede optimizar en el proyecto" separando hechos de hipótesis.
- Actualizar `docs/hardware/zigbee-bench-oficina.md` solo si hay hallazgos nuevos.
- Si tocas specs o código, usa worktree y commits atómicos; no edites `main` directamente.
- Al terminar, entrega un resumen con: qué está confirmado, qué es dudoso, qué falta por probar,
  y una recomendación de siguiente paso (integrar, calibrar o descartar).

EMPIEZA POR:
1. Leer `docs/hardware/zigbee-bench-oficina.md` y `.specs/specs/{requirements,design,contracts,tasks}.md`
   en la parte F90.
2. Consultar el estado actual con `docker compose -p zigbee-local ps`, `bridge/devices` y los
   logs recientes; no imprimir secretos.
3. Diseñar un plan de medidas corto y ejecutarlo de forma controlada.
```

---

## Notas para el operador

- El brief asume que la sesión nueva tiene acceso SSH a `oficina` y al repositorio.
- Si se prefiere una sesión sin acceso a hardware, se puede limitar a leer logs y documentación,
  pero no podrá medir latencia ni comprobar comandos reales.
- La frase «router Zigbee» del uso coloquial no debe confundirse con el rol Z2M: el Dongle-M es
  coordinador; `presencia-oficina` aparece como Router; puerta y relé son EndDevice.
