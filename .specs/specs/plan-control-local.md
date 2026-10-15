# Plan L1 — Validación de control local de dispositivos (PoC sin nube Tuya)

> **Tipo**: plan de estudio / PoC (no implementación).
> **Audiencia**: operador + quien mantiene la integración de dispositivos.
> **Alcance**: comprobar dispositivo a dispositivo si los 3 modelos en uso admiten
> control local (sin nube Tuya). **No** migra producción ni modifica dispositivos vivos
> fuera de las ventanas autorizadas.
> **Última revisión**: 2026-10-15 (verificación de red y herramientas) · **Cadencia de
> revisión**: al cerrar la PoC.

> ⚠️ **Secretos**: las llaves locales y credenciales **no** se escriben aquí. La Local Key
> del switch EAWCBT-J vive en `docs/hardware/eawcbt-j-setup.md`; las credenciales Tuya en
> `api/.env` (fuera de git). Este documento usa referencias, no valores.

---

## 0. Contexto y motivación

- Escala objetivo: **70 habitaciones × 3 dispositivos = 210 devices**. El plan gratuito de
  Tuya limita a **10 controlables**; ampliarlo se ha comunicado en ~**5.000 USD**.
- Hipótesis a validar: los 3 modelos en uso admiten **control local** (LAN), eliminando
  cuota, límite de dispositivos y dependencia del fabricante.
- Este plan es de **estudio**: comprobar viabilidad y recoger evidencia. **No** implementa.

## 0.1 Hallazgos de verificación previa (2026-10-15)

Evidencia recogida en la sesión de arranque (solo lectura, sin cuota Tuya):

- **Host de pruebas**: el host donde corre la API **no** está en la LAN del hotel.
  - `eth0 = 92.113.151.136/32` (VPS, IP pública, ruta por defecto `onlink`) → no alcanza
    la subred de los dispositivos.
  - El host válido para la PoC es el **nodo Tailscale `oficina` (100.117.92.74)**, cuyo
    endpoint directo es `93.176.170.12:24019` (mismo WAN que el hotel).
- **`93.176.170.12` es WAN del hotel, no IP local.** El campo «IP local» del EAWCBT-J en
  `docs/hardware/eawcbt-j-setup.md` es erróneo (mostraba el WAN). Hay que obtener la IP
  LAN real del dispositivo.
- **Puerto TCP 6668 cerrado desde el VPS** (probado a `93.176.170.12:6668`). El protocolo
  local Tuya **no cruza NAT ni Internet**: exige estar en la misma LAN. El *broadcast*
  UDP 6668 tampoco cruza Tailscale; el control local debe ser **unicast a la IP LAN** del
  device (Tailscale sirve solo para *llegar* al host `oficina`, que sí está en la LAN).
- **Discrepancia de tecnología del MC400D (bloqueante para P3)**:
  - Este plan y `docs/tuya-account-migration.md` §2 lo describen como **Zigbee** (vía gateway).
  - `api/bin/tuya-door-sensor-read.php:12` lo llama `MC400D (WIFI门磁)` («sensor de puerta
    **WiFi**») y `api/src/Infrastructure/Gateways/Sensor/TuyaSensorIngress.php:18` lo trata
    como categoría `mcs`; **no hay ninguna referencia a gateway/coordinador en el código**.
  - **Pendiente**: confirmar en consola Tuya (Device Debug → modelo / sub-device / gateway)
    antes de decidir si P3 usa `tinytuya` (WiFi) o Zigbee2MQTT (Zigbee).
- **Herramientas locales ausentes**: `tinytuya`, `zigbee2mqtt` y `mosquitto` **no** están
  instalados en el entorno. Los `api/bin/tuya-*.php` son **todos de nube**
  (`https://openapi.tuyaeu.com`) y **consumen cuota**: solo valen para la extracción
  puntual de `local_key` / IP / versión de protocolo, no para la PoC local.

## 0.2 Resultado de Fase 0 — Desbloqueo de red (2026-10-15)

Ejecutado desde el nodo **`oficina`** (`172.16.51.242/24`, eno1, gateway `172.16.51.1`),
con `tinytuya 1.20.0` (instalado vía `pip --user`, **sin nube, sin cuota**) y `arp-scan`/`nmap`.

| Prueba | Comando | Resultado |
|---|---|---|
| Discovery *broadcast* Tuya | `tinytuya scan` (6666/6667/7000) | **0 devices** (solo se ve a sí mismo) |
| Discovery *unicast* sobre `/24` | probe con payload `REQ_DEVINFO` a 254 IP × 3 puertos | **0 respuestas** |
| UDP 6668 | `nmap -sU -p6668` | nada abierto |
| TCP 6668 | `nmap -p6668` | nada abierto |
| Enumeración ARP | `arp-scan -I eno1 --localnet` (49 hosts) | **ningún vendor Tuya** |

Vendors de los hosts de `172.16.51.0/24` (todos componentes de la operación, no devices Tuya):
Dahua (cámaras, `b4:4c:3b`/`9c:14:63`), LCFC/ASUS/GIGABYTE (PCs), Ricoh (impresoras),
Yealink (teléfonos VoIP), USI (módulos WiFi), Xiaomi.

**Veredicto de red: NO-GO desde la LAN cableada.** Los 3 devices Tuya **no** son visibles ni
alcanzables en `172.16.51.0/24`. Causa más probable: **segmentación de red** — los WiFi
(24G, EAWCBT-J) cuelgan de la **red WiFi del hotel** (VLAN/subred distinta, o aislamiento de
clientes del AP), y el MC400D probablemente es Zigbee (sin IP LAN).

**Siguientes pasos (requieren acceso a infraestructura de red):**
1. Identificar la VLAN/subred de la WiFi del hotel (router/AP o leases DHCP).
2. Conseguir un **host de pruebas dentro de esa red WiFi** (p. ej. portátil en la SSID de los
   devices) y **repetir §4** desde ahí.
3. Confirmar en consola Tuya la tecnología del MC400D (Zigbee vs WiFi) — ver §0.1.

> Nota: el *subnet router* de `oficina` anuncia `172.16.51.0/24`, pero esta LAN **no** es la
> de los devices; el control local exigirá un host en la red WiFi correspondiente.

## 0.3 Identificación — Fase 1 (2026-10-15)

### Router de los devices
- Los devices cuelgan de un **router Xiaomi** (SSID **`Xiaomi_B04D`**) con **doble NAT**: su WAN
  está en la red cableada de `oficina` (`172.16.51.0/24`) y su LAN es otra subred (típica Xiaomi
  `192.168.31.0/24`), por lo que **`oficina` no alcanza a los devices** (confirma el 0 de §0.2).
- Candidato a WAN del router Xiaomi en la LAN de oficina: MAC `cc:d8:43` (Xiaomi) en
  `172.16.51.138` y `.140` (a confirmar). El TP-Link `172.16.51.130` con login es **otro** router
  de la red, no el de los devices.

### Ventana de nube única (ejecutada)
| device | product_id | category | online | `ip` (nube) | local_key |
|---|---|---|---|---|---|
| 24G | `5lld8pgsoynvctqa` | hps | true | `93.176.170.12` (WAN) | 16 chars ✓ |
| MC400D | `5etnzhfidcmm0ang` | mcs | true | `93.176.173.242` (WAN) | 16 chars ✓ |
| EAWCBT-J | `odhgp5hewa1o7mdn` | dlq | true | `93.176.170.12` (WAN) | 16 chars ✓ |

- El campo `ip` de Tuya es la **IP WAN, no la LAN** → no sirve para localizarlos.
- **MC400D: `sub=false` + `local_key`** → **NO es un sub-device Zigbee**; apunta a **WiFi propio**
  (contradice `tuya-account-migration.md` §2). Queda pendiente la confirmación visual en consola.
- Claves guardadas fuera de git: `api/run/local-tuya-devices.json` (modo 600).
- 4 llamadas realizadas (1 token + 3 detalle); el contador de la app (`api/run/tuya-quota.json`) **no
  las contabiliza** (solo cuenta las de `tuyaPresenceApi`).

### ESP32-CAM (`camaraESP`) como vantage
- Físicamente en `oficina` como `/dev/ttyUSB0` (adaptador FTDI), unido a `Xiaomi_B04D`.
- Firmware `esp32cam_ip.ino` v2.6.4 push-only: sin HTTP entrante, sin mDNS, **no imprime su IP LAN**
  por serie (reset verificado: `POWERON_RESET` → conecta con credenciales guardadas).
- **No sirve como host tal cual** → se **reflasheará como escáner** (Paso 3), con backup y
  restauración vía `camaraESP/scripts/deploy_camara.sh` (`arduino-cli`, `esp32:esp32:esp32cam`,
  `/dev/ttyUSB0`).

### Vantage elegido
ESP32-CAM reflasheado como escáner (`docs/hardware/tuya-local-scan/TuyaScan.ino`) — **ejecutado**;
resultados en §0.4.

## 0.4 Fase 3 — Escáner ESP32-CAM: identificación local (2026-10-15)

- **Sketch**: `docs/hardware/tuya-local-scan/TuyaScan.ino`, flasheado en la ESP32-CAM vía
  `arduino-cli` (FQBN `esp32:esp32:esp32cam`, `/dev/ttyUSB0`). **100 % local, sin cuota Tuya**.
  Envía el REQ_DEVINFO de Tuya en broadcast (UDP 6666/6667/7000) y escucha en los mismos puertos.
- **Red del router Xiaomi**: subred **`192.168.31.0/24`**, gateway `192.168.31.1`; la ESP32-CAM
  obtiene `192.168.31.112`.
- **Resultado del discovery: 2 de 3 devices responden**, `version 3.4`, `encrypt:true`:

| IP LAN | `gwId` | dispositivo | `productKey` (discovery) |
|---|---|---|---|
| `192.168.31.109` | `bf9a278e76e2c3f01ay0cs` | 24G-Presence V3 | `5lld8pgsoynvctqa` |
| `192.168.31.249` | `bfc8730a715c56c8d1paby` | EAWCBT-J (luz) | `keyuh3jxk9wu8ruj` |

- **MC400D NO responde** en `192.168.31.0/24`. Su `ip` de nube (`93.176.173.242`) era una **WAN
  distinta** a la de los otros dos (`93.176.170.12`) → probablemente está en **otra red/SSID**
  (o es Zigbee). Pendiente de localizar.
- **Nota**: el `productKey` del switch en el discovery (`keyuh3jxk9wu8ruj`) difiere del `product_id`
  de la nube (`odhgp5hewa1o7mdn`); manda el discovery.
- **Decodificación**: `tinytuya.decrypt_udp(...)` con la `udpkey` universal (sin `local_key`).
- **Restauración**: `camaraESP` re-subido y verificado en streaming (v2.6.4-qvga). Backup del sketch
  remoto en `/home/oficina/Arduino/ESP32cam/ESP32cam.ino.bak-20261015-155437`.

## 0.5 Fase B1 — Prueba de control local vía puente TCP↔serie (2026-10-15)

- **Puente**: `docs/hardware/tuya-local-scan/TuyaBridge.ino` (ESP32-CAM) +
  `docs/hardware/tuya-local-scan/tuya_bridge_client.py` (host). El ESP32 une a `Xiaomi_B04D` y
  conecta **TCP al device:6668**; el host relaya con `tinytuya` (que conecta a `127.0.0.1:6668`).
  **100 % local, cero cuota.**
- **Integridad del puente verificada**: una petición HTTP a `192.168.31.1:80` (router Xiaomi) a
  través del ESP devuelve `HTTP/1.1 200 OK` (nginx) con cabeceras íntegras.
- **Alcance TCP**: ambos devices (24G `.109`, switch `.249`) **aceptan TCP 6668** localmente.
- **Handshake de control (v3.4)**: se envía `SESS_KEY_NEG_START`; **el device no responde**
  (`session key negotiation failed on step 1` → **error 914**). Probado también con v3.5 (914) y
  v3.3/v3.1 (timeout) y con 3 s extra de escucha: **sin bytes de vuelta**.
- **Conclusión**: el **control local está bloqueado por la `local_key`**: la clave obtenida de la
  nube **no es aceptada** por ninguno de los dos devices (posible **clave obsoleta** — dispositivo
  re-emparejado a otra cuenta — o **control local deshabilitado** en el firmware). La
  **conectividad local queda probada**; falta una clave válida para completar el control.
- **Restauración**: `camaraESP` re-subido y verificado. Pista: el `productKey` del switch en
  discovery (`keyuh3jxk9wu8ruj`) difiere del `product_id` de la nube (`odhgp5hewa1o7mdn`).

## 1. Objetivo y criterio de éxito

- Para **cada modelo**: veredicto **GO / GO-con-condiciones / NO-GO**, documentado con datos.
- Éxito global: los 3 modelos controlables en local (o con sustituto definido) **sin
  llamadas a la nube Tuya** en régimen normal.

## 2. Dispositivos en alcance

| Modelo | kind | external_id | product_id | Tecnología | Notas |
|---|---|---|---|---|---|
| 24G-Presence Sensor V3 | PRESENCE | `bf9a278e76e2c3f01ay0cs` | `5lld8pgsoynvctqa` | WiFi | **IP LAN `192.168.31.109`**; v3.4; `far_detection`/sensibilidad ya calibrados |
| MC400D | PROXIMITY (puerta) | `bf4c7e7d2cef28cea2nkwk` | `5etnzhfidcmm0ang` | **WiFi probable** (local_key + `sub=false`) | **no hallado** en `192.168.31.0/24` (WAN distinta) — sensor crítico (F86) |
| EAWCBT-J | SWITCH | `bfc8730a715c56c8d1paby` | `odhgp5hewa1o7mdn` | WiFi | **IP LAN `192.168.31.249`**; v3.4; DP `switch` |

Referencia de inventario completo: `docs/tuya-account-migration.md` §2.

**Fuera de alcance:** LOCK (ya local, relé ESP32), ZY-M100 (`disabled`), cámaras del almacén
(go2rtc, ya locales).

**Host de pruebas:** nodo Tailscale **`oficina` (100.117.92.74)** — ver §0.1.

## 3. Reglas de seguridad (obligatorias)

- **Cero** llamadas a la nube Tuya durante las lecturas locales; verificar contra el contador
  de cuota (`api/run/tuya-quota.json`).
- **Ventana de nube única**: los scripts `api/bin/tuya-*.php` se ejecutan **una sola vez**
  para extraer `local_key` / IP LAN / versión de protocolo; después quedan **prohibidos** en
  la PoC. Registrar el delta de `api/run/tuya-quota.json` antes y después de esa ventana.
- **No** re-emparejar ni alterar dispositivos de producción en horario de huésped. Usar la
  ventana acordada.
- **Escritura autorizada**: el operador ha autorizado encender/apagar la luz (EAWCBT-J) con
  los dispositivos actuales para la PoC. Aun así, las pruebas de escritura deben ser
  **reversibles** (dejar el estado original) y, si es posible, sin huésped.
- **No** introducir pollers a la nube (RF-101 intacto). El sondeo local es libre pero moderado.
- Las pruebas locales se ejecutan **en el host `oficina`**, nunca en el VPS de la API.
- Si se toca código, trabajar en **copia/worktree**, nunca en el árbol de producción.

## 4. Precondiciones / Fase 0 — Desbloqueo de red (bloqueante, sin cuota)

> **Estado: ejecutada el 2026-10-15 → NO-GO desde la LAN cableada de `oficina`.** Ver §0.2.
> Solo hay GO de red si el *host de pruebas* está en la **misma red WiFi/VLAN que los devices**.

Ejecutar **en el host de pruebas dentro de la red de los devices** (no en el VPS; `oficina`
en su LAN cableada no sirve, ver §0.2):

```bash
# 1. Ver la(s) subred(es) que el host alcanza directamente
ip -br addr && ip route
```

- **IP LAN real** de 24G y EAWCBT-J: desde la app Smart Life (Info del dispositivo) o, si no
  se ve, con **una única** ejecución de `php api/bin/tuya-discover.php` en la ventana de nube
  (muestra `ip` y `local_key`).
- **Versión de protocolo** de cada WiFi (3.1/3.3/3.4/3.5) → determina el método de `tinytuya`.
- **Reachability** por device (sin cuota):

```bash
ping -c 3 <IP_LAN_device>
timeout 3 nc -u -v <IP_LAN_device> 6668   # UDP; verificar que el host está en la misma subred
```

Si el **unicast 6668** no responde desde `oficina`, la PoC es **NO-GO de red** y hay que
resolver topología (host en la misma VLAN que los devices) antes de continuar.

## 5. Herramientas

### Nube (una sola vez, consume cuota — ventana autorizada)

- `api/bin/tuya-discover.php` (lista devices + `ip` + `local_key` + `product_id`).
- `api/bin/tuya-verify-all-devices.php`, `api/bin/tuya-explore.php`,
  `api/bin/tuya-door-sensor-read.php` — **solo** para confirmar DPs/estado una vez.
- `api/bin/presence-latency-probe.js` — medición local (SSE + BD), **sin cuota**; sirve para
  comparar la latencia local contra la del push actual.

### Locales (PoC, sin cuota — instalar en `oficina`)

```bash
# Cliente de protocolo local Tuya (WiFi)
python3 -m venv .venv-local-tuya
.venv-local-tuya/bin/pip install tinytuya

# Solo si el MC400D resulta ser Zigbee: coordinador neutro + Zigbee2MQTT en banco
# (CC2652/SkyConnect + mosquitto + zigbee2mqtt) — nunca en la puerta de producción.
```

## 6. Pruebas por dispositivo

> **Precondición común (P1/P2)**: la **Fase 0** (§4) debe dar GO de red (unicast 6668
> alcanzable desde `oficina`). Si no, P1/P2 quedan bloqueados.

### P1 — 24G Presence V3 (WiFi)

1. Obtener IP LAN, `local_key`, `product_id` y **versión de protocolo** (ventana de nube única).
2. Conectar en local y volcar los DPs (`tinytuya`).
3. **Escuchar** cambios de presencia (marcar con el latency probe: `DELANTE_SENSOR`, `ALEJO`).
4. Medir latencia local vs push actual; estabilidad 15–30 min.

- **Criterios:** GO si llegan `presence`/`move`/`none` en local con latencia ≤ push y sin
  cortes; GO-con-condiciones si exige polling y empeora la latencia; NO-GO si no responde.
- **Riesgo:** firmware que bloquee local o que no emita cambios.

### P2 — EAWCBT-J (WiFi, luz) — ventana autorizada

1. Usar IP LAN + Local Key documentadas; confirmar versión de protocolo.
2. Leer `switch` en local (sin nube).
3. Enviar on/off en local y confirmar cambio físico + lectura (autorizado con los devices
   actuales; **dejar el estado original al terminar**).
4. Repetir N ciclos; medir latencia y repetibilidad.

- **Criterios:** GO si on/off y lectura locales funcionan; NO-GO si no acepta órdenes locales.
- **Riesgo:** **corta la luz de la habitación** → coordinar y revertir.

### P3 — MC400D puerta (tecnología a confirmar) — bifurca según hallazgo §0.1

**Paso 0 (bloqueante):** confirmar en consola Tuya si el MC400D es **WiFi** o **Zigbee**
(modelo, categoría `mcs`, `sub`/gateway). Después ejecutar **una** de las dos ramas:

#### P3-WiFi (si es WiFi)

1. Obtener IP LAN + `local_key`.
2. Leer `doorcontact_state` y `battery_percentage` en local con `tinytuya`.
3. Comparar fidelidad con F86 (OPEN/CLOSED reales y repetidos; comportamiento edge-triggered).

- **Criterios:** GO si la lectura local es fiable y ≤ latencia del push; NO-GO si no responde.

#### P3-Zigbee (si es Zigbee)

1. Verificar que **Zigbee2MQTT soporta el modelo MC400D** (lista oficial / conversor).
2. Conseguir coordinador neutro (CC2652/SkyConnect) + Zigbee2MQTT en `oficina` (banco).
3. Emparejar el MC400D al coordinador (no al gateway Tuya) **en banco**, y leer open/close.
4. Comparar fidelidad con F86.

- **Criterios:** GO si Z2M lo soporta y lee fiable; GO-con-sustituto si no está soportado →
  valorar cambiar el sensor por otro Zigbee soportado.
- **Riesgo:** modelo no soportado; pérdida temporal del sensor → **probar en banco, nunca en
  la puerta de producción**.

## 7. Métricas a registrar

| Modelo | ¿Local? | Método | Latencia lectura | Latencia escritura | Estabilidad | ¿Cuota Tuya? | Veredicto |
|---|---|---|---|---|---|---|---|
| 24G | | | | — | | | |
| EAWCBT-J | | | | | | | |
| MC400D | | | | | | | |

## 8. Entregables

- Este documento.
- Tabla §7 cumplimentada.
- Veredicto por modelo + recomendación de hardware (coordinador Zigbee si aplica) y sustituto
  si hay NO-GO.
- Decisión de red (VLANs) y nº de enlaces/repetidores necesarios.

## 9. Riesgos generales

- **NAT / red segmentada**: el protocolo local no cruza NAT; requiere host en la LAN (§0.1).
- **Discrepancia de tecnología del MC400D** sin confirmar (bloquea P3).
- Modelos no soportados localmente.
- Fiabilidad/mantenimiento propios (llaves, protocolo, uptime).
- Confusión pruebas/producción → mitigado con host `oficina`, ventana y no re-emparejar en
  producción.

## 10. Preguntas abiertas

1. ¿El pago a Tuya es único o anual? (define urgencia).
2. **Topología de red**: resuelta parcialmente — los devices están en `192.168.31.0/24` (router
   Xiaomi `Xiaomi_B04D`), alcanzables vía el vantage (§0.4/§0.5). Falta un **host Linux estable**
   en esa subred para el control (p. ej. dongle WiFi en `oficina`).
3. **`local_key` / control local**: la clave de la nube **no la aceptan** los devices (§0.5).
   ¿Re-emparejar en ventana controlada, verificar la cuenta/proyecto, o habilitar el control
   local en el firmware?
4. ¿Presupuesto para un coordinador Zigbee de pruebas? (solo si P3 resultara Zigbee).
5. **MC400D**: no aparece en la LAN del router Xiaomi (§0.4) → probable **Zigbee** u otra red/SSID;
   confirmar en consola Tuya (modelo/gateway).

**Resueltas:** host de pruebas = nodo Tailscale `oficina`; escritura EAWCBT-J autorizada con
los devices actuales.

## 11. Arranque de la próxima sesión

1. **Fase 0 (red)** — *ejecutada 2026-10-15, NO-GO en LAN cableada (§0.2)*. Retomar en un
   host **dentro de la red WiFi de los devices**: descubrir la VLAN/subred, IP LAN de los
   devices y test unicast 6668. Si no hay GO de red, parar y resolver topología.
2. **Ventana de nube única**: extraer `local_key`/IP/versión (y confirmar tecnología del
   MC400D en consola); registrar delta de cuota.
3. Instalar `tinytuya` en `oficina` (§5).
4. **P1** (24G) → **P2** (EAWCBT-J) → **P3** (MC400D, según rama).
5. Rellenar §7 → emitir veredictos → recomendar el siguiente paso (diseño formal
   `requirements/design/contracts/tasks` o compra de hardware).

## 12. Impacto en la arquitectura actual al saltar Tuya (a corregir en su fase)

Cuando el control local sustituya a la nube, las siguientes piezas quedan **obsoletas o
reformables**: se implementaron **solo para no gastar cuota de Tuya**, motivo que desaparece en local.

- **Push (Message Service / Pulsar, F50)** y la regla **"sin polling a Tuya" (F74/RF-101/F78)**:
  existían por la cuota. Con control local, la presencia se puede obtener por **polling local
  continuo** o **escucha local**, sin cuota → candidatos a derogar/reescribir.
  *Matiz*: el "no polling" seguirá aplicando a la **nube Tuya** mientras conviva con lo local;
  el polling **local** es libre (aunque conviene moderarlo: límites del device/red).
- **`TuyaSwitchGateway`** (on/off del switch): sustituir por un **gateway local** (protocolo 6668).
- **Calibración de presencia**, **`battery-refresh`** y **`ping-all-devices`**: equivalentes locales.
- **Obtención de `local_key`**: hoy vía nube; solo se necesita al **re-emparejar** (rota entonces);
  en régimen normal, cero nube.

Nada de esto se toca en la PoC: queda **documentado** para su fase.
