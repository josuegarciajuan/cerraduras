# WS-VB6 (PHP 7.4)

Puente PHP entre la API principal y la BD del programa VB6 (`bs2026`). Escribe deudas y
eventos en las tablas existentes (`deudas`, `log_usuarios`) sin modificar el esquema del
programa VB6.

> Especificación funcional: ver `.specs/specs/` en la raíz del repo.

## Requisitos
- PHP 7.4 (+ `pdo_mysql`, `openssl`, `json`, `mbstring`, `curl`).
- Acceso a dos MySQL:
  - BD `bs2026` (remota, VB6).
  - BD auxiliar propia `ws_vb6_aux` (idempotencia).

## Configuración

```bash
cp .env.example .env
# edita .env con tus credenciales
```

## Arranque en desarrollo

```bash
php -S 127.0.0.1:8081 -t public
```

Probar:

```bash
curl -i http://127.0.0.1:8081/
# -> 404 JSON envelope (hasta que el router esté añadido en TSK-120)
```

## Estado de implementación

Ver `.specs/specs/tasks.md`.
