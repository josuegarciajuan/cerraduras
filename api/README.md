# API (PHP 7.4)

Backend PHP 7.4 plano + router propio + PDO para gestión de habitaciones, QR, presencia y estancias.

> Especificación funcional: ver `.specs/specs/` en la raíz del repo.

## Requisitos
- PHP 7.4 con extensiones: `pdo_mysql`, `openssl`, `json`, `mbstring`, `curl`.
- MySQL 5.7+ o 8.0 (InnoDB, UTF-8MB4).

## Configuración

```bash
cp .env.example .env
# edita .env con tus credenciales
```

## Arranque en desarrollo

```bash
php -S 127.0.0.1:8080 -t public
```

Probar:

```bash
curl -i http://127.0.0.1:8080/
# -> 404 JSON envelope (hasta que el router esté añadido en TSK-010)
```

## Estructura

Ver `.specs/specs/design.md §12`.

## Estado de implementación

Trabajamos tarea por tarea según `.specs/specs/tasks.md`.
