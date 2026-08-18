#!/usr/bin/env bash
# =============================================================================
# diag-db.sh — Diagnóstico de la función db_exec de run-tests.sh
# =============================================================================
PROJECT_DIR="/root/cerraduras/api"

# Leer credenciales tal como lo hace run-tests.sh
DB_HOST=$(grep "^DB_HOST=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')
DB_PORT=$(grep "^DB_PORT=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')
DB_NAME=$(grep "^DB_NAME=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')
DB_USER=$(grep "^DB_USER=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')
DB_PASS=$(grep "^DB_PASS=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')

echo "=== Variables leidas de .env ==="
echo "DB_HOST=[$DB_HOST]"
echo "DB_PORT=[$DB_PORT]"
echo "DB_NAME=[$DB_NAME]"
echo "DB_USER=[$DB_USER]"
echo "DB_PASS=[${DB_PASS:0:8}...]"

echo ""
echo "=== Buscando binario MySQL ==="
MYSQL_BIN=""
for candidate in /usr/bin/mariadb /usr/bin/mysql /usr/local/bin/mysql /usr/local/bin/mariadb; do
    echo -n "  Probando $candidate: "
    if [ -x "$candidate" ]; then
        MYSQL_BIN="$candidate"
        echo "EXISTE y es ejecutable ✓"
        break
    else
        echo "no existe o no ejecutable ✗"
    fi
done
[ -z "$MYSQL_BIN" ] && MYSQL_BIN="mariadb"
echo "  Seleccionado: $MYSQL_BIN"

echo ""
echo "=== Probando métodos de conexión ==="

# Método 1: TCP con credenciales
echo -n "1) TCP (credenciales .env): "
if "$MYSQL_BIN" -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -P"$DB_PORT" "$DB_NAME" -e "SELECT 'OK'" 2>&1 | grep -q OK; then
    echo "✓ FUNCIONA"
else
    echo "✗ FALLA"
    "$MYSQL_BIN" -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -P"$DB_PORT" "$DB_NAME" -e "SELECT 'OK'" 2>&1 | head -3
fi

# Método 2: Socket local
echo -n "2) Socket (credenciales .env): "
if "$MYSQL_BIN" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 'OK'" 2>&1 | grep -q OK; then
    echo "✓ FUNCIONA"
else
    echo "✗ FALLA"
    "$MYSQL_BIN" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 'OK'" 2>&1 | head -3
fi

# Método 3: Root TCP
echo -n "3) Root TCP: "
if "$MYSQL_BIN" -u root -h"$DB_HOST" -P"$DB_PORT" "$DB_NAME" -e "SELECT 'OK'" 2>&1 | grep -q OK; then
    echo "✓ FUNCIONA"
else
    echo "✗ FALLA"
    "$MYSQL_BIN" -u root -h"$DB_HOST" -P"$DB_PORT" "$DB_NAME" -e "SELECT 'OK'" 2>&1 | head -3
fi

# Método 4: Root socket
echo -n "4) Root socket: "
if "$MYSQL_BIN" -u root "$DB_NAME" -e "SELECT 'OK'" 2>&1 | grep -q OK; then
    echo "✓ FUNCIONA"
else
    echo "✗ FALLA"
    "$MYSQL_BIN" -u root "$DB_NAME" -e "SELECT 'OK'" 2>&1 | head -3
fi

echo ""
echo "=== Verificación de --version ==="
"$MYSQL_BIN" --version 2>&1 || echo "ERROR: $MYSQL_BIN no tiene --version"

echo ""
echo "=== which/type/command ==="
which mysql 2>&1 || echo "  which mysql: no encontrado"
which mariadb 2>&1 || echo "  which mariadb: no encontrado"
type mysql 2>&1 || echo "  type mysql: no encontrado"
type mariadb 2>&1 || echo "  type mariadb: no encontrado"
command -v mysql 2>&1 || echo "  command -v mysql: no encontrado"
command -v mariadb 2>&1 || echo "  command -v mariadb: no encontrado"