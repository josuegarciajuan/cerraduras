# Cambios necesarios en el programa VB6 para integrar Cerraduras

> Para el desarrollador del `bs2026`. Solo lo que necesitas saber para empezar.

---

## URL y clave de la API

```
URL:  http://92.113.151.136:8080
Key:  5a78442da74f9eba7bfb151678ad716567e47b41b3119155
```

Esa key va en el header `X-API-Key` en todas las llamadas.

---

## 1. Emitir QR al cobrar en recepción

Cuando el cliente paga, donde ahora mismo imprimes el ticket, añade esta llamada HTTP **antes** de imprimir:

```
POST /api/v1/qr
```

**Headers:**
```
X-API-Key: 5a78442da74f9eba7bfb151678ad716567e47b41b3119155
Content-Type: application/json
Idempotency-Key: vb6-<ticket>-<fecha-hora>
```

> El `Idempotency-Key` evita que un reintento accidental genere dos QRs distintos.
> Usa un valor único tipo `vb6-ticket-67890-20260728-143000`.

**Body (mínimo):**
```json
{
  "room_id": 2,
  "duracion_minutos": 120
}
```

**Body (completo, si tienes los datos del VB6 a mano):**
```json
{
  "room_id": 2,
  "duracion_minutos": 120,
  "codalq": 12345,
  "codtic": 67890,
  "codcli": 100,
  "codart": 5,
  "codlot": 3,
  "codhab": "101",
  "temporada": "2026",
  "empresa": 1,
  "departamento": 0
}
```

**Respuesta:**
```json
{
  "stay_id": 42,
  "qr_text": "eyJhbGciOi...<mucho texto>",
  "room_id": 2
}
```

**Lo que haces con eso:** conviertes `qr_text` a imagen QR y la imprimes en el ticket.

**Si no tienes el `codtic` aún:** no lo mandes. Luego lo completas con:
```
PATCH /api/v1/stays/42/vb6-refs
Body: { "codtic": 67890 }
```

**Si la habitación ya está ocupada:** la API responde `409 room_busy`. Elige otra.

---

## 2. Cambiar el panel de habitaciones

### Lo que hay ahora
Pintas cada habitación en **rojo** (ocupada) o **verde** (libre). La ocupación la marcas tú a mano.

### Lo que debe hacer ahora
Cada pocos segundos (5-10s), para cada habitación del panel, llamas:

```
GET /api/v1/rooms/{id}/live        ← sin key, sin auth
```

Y según la respuesta, pintas uno de estos 5 estados:

| Respuesta | Color |
|-----------|-------|
| `status = "FREE"` | **Verde** |
| `active_stay.status = "RESERVED"` | **Amarillo** |
| `active_stay.status = "OCCUPIED"` Y `iot.presence_state = "ABSENT"` | **Amarillo intermitente** |
| `active_stay.status = "OCCUPIED"` Y `iot.presence_state = "PRESENT"` | **Rojo** |
| `active_stay.status = "OVERSTAY"` | **Naranja parpadeante** |

### Por qué el estado "amarillo intermitente"
El huésped escanea el QR, la puerta se abre, pero el sensor de presencia tarda unos segundos en detectar que hay alguien dentro. Mientras tanto no sabes si entró de verdad o no. Ese estado suele durar **0-30 segundos**. Si tras ~60s no hay presencia, el sistema lo marca como salida automática.

### Pseudocódigo para el panel
```
Para cada habitacion:
  json = GET /api/v1/rooms/{id}/live

  si json.status = "FREE":
      pintar VERDE

  si json.active_stay existe:
      segun json.active_stay.status:
          "RESERVED"  → AMARILLO
          "OCCUPIED":
              si json.iot.presence_state = "PRESENT" → ROJO
              si no → AMARILLO INTERMITENTE
          "OVERSTAY"  → NARANJA PARPADEANTE
```

---

## 3. Cerrar estancia (checkout)

Cuando desde el VB6 haces el checkout de un cliente:

```
POST /api/v1/stays/42/close
X-API-Key: 5a78442da74f9eba7bfb151678ad716567e47b41b3119155
Idempotency-Key: vb6-close-42-20260728-160000
Body: {}
```

Esto libera la habitación, apaga la luz y escribe `log_usuarios` con `tipo='EXIT'`.

---

## 4. Cancelar un QR (si el cliente se arrepiente antes de entrar)

```
POST /api/v1/qr/<jti>/revoke
X-API-Key: 5a78442da74f9eba7bfb151678ad716567e47b41b3119155
Idempotency-Key: vb6-revoke-<jti>
```

El `jti` es el que te devolvió el POST /qr.

---

## 5. Deudas automáticas

No tienes que hacer nada. Cuando el huésped excede su tiempo, el sistema inserta
filas en tus tablas de siempre:

- **`bs2026.deudas`** → con el `codtic` que mandaste al emitir el QR
- **`bs2026.log_usuarios`** → con `rfid='QRSYS'` y `tipo='OUT'`

Tu programa ya sabe leer esas tablas. Lo único nuevo es que ahora pueden aparecer
filas generadas automáticamente.

---

## Resumen de endpoints

```
EMITIR QR      POST /api/v1/qr                    (al cobrar)
PANEL          GET  /api/v1/rooms/{id}/live        (cada 5-10s, sin auth)
CHECKOUT       POST /api/v1/stays/{id}/close
CANCELAR QR    POST /api/v1/qr/{jti}/revoke
COMPLETAR REFS PATCH /api/v1/stays/{id}/vb6-refs   (solo si falta codtic)
```

---

## Lo que NO tienes que tocar

- Tu BD `bs2026` no cambia de estructura
- Tus tablas de clientes, artículos, albaranes siguen igual
- No necesitas instalar nada nuevo, solo hacer llamadas HTTP
