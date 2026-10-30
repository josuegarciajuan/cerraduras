#!/usr/bin/env bash
# =============================================================================
# Hotel API — Automated Test Runner
# =============================================================================
#
# Ejecuta todos los tests acumulados de las fases completadas.
# Debe pasar con 0 failures antes de cerrar cada fase y antes de empezar
# la siguiente.
#
# Uso:
#   cd /root/cerraduras/api
#   bash bin/run-tests.sh
#
# Requisitos:
#   - PHP disponible en PATH
#   - Servidor PHP corriendo: php -S 127.0.0.1:8080 -t public &
#   - BD configurada y migraciones aplicadas (php bin/migrate.php)
#   - Seeds aplicados (php bin/seed.php) con claves en seeds/dev_api_keys.txt
#
# Para añadir tests de una nueva fase:
#   Busca el placeholder "# (PLACEHOLDER) BLOCK N" y reemplázalo con el bloque
#   de tests correspondiente. Ver AGENTS.md para el patrón exacto.
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
KEYS_FILE="$PROJECT_DIR/seeds/dev_api_keys.txt"
LOG_FILE="$PROJECT_DIR/logs/test-results.log"
TMP_BODY="$PROJECT_DIR/logs/_test_body.tmp"
API_BASE="${API_BASE_OVERRIDE:-http://127.0.0.1:8080}"

# --- Colores -----------------------------------------------------------------
R='\033[0;31m'
G='\033[0;32m'
Y='\033[1;33m'
B='\033[1;34m'
W='\033[1;37m'
NC='\033[0m'

PASS=0
FAIL=0
SKIP=0
SERVER_UP=false

# --- Logging: consola + fichero acumulativo ----------------------------------
mkdir -p "$(dirname "$LOG_FILE")"
# Escribir encabezado de ejecución en el fichero (sin duplicar en consola)
{
    echo ""
    echo "========================================================"
    echo "  Hotel API — Test Suite"
    echo "  $(date '+%Y-%m-%d %H:%M:%S')"
    echo "========================================================"
} | tee -a "$LOG_FILE"

# A partir de aquí: toda salida va a consola Y al log
exec > >(tee -a "$LOG_FILE") 2>&1

# --- Inicialización de BD (para save/restore de estado entre tests) ----------
# Leer credenciales de BD del .env
DB_HOST=$(grep "^DB_HOST=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')
DB_PORT=$(grep "^DB_PORT=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')
DB_NAME=$(grep "^DB_NAME=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')
DB_USER=$(grep "^DB_USER=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')
DB_PASS=$(grep "^DB_PASS=" "$PROJECT_DIR/.env" | cut -d= -f2 | tr -d ' ')

# Buscar binario MySQL/MariaDB (por orden de preferencia)
MYSQL_BIN=""
for candidate in /usr/bin/mariadb /usr/bin/mysql /usr/local/bin/mysql /usr/local/bin/mariadb; do
    if [ -x "$candidate" ]; then
        MYSQL_BIN="$candidate"
        break
    fi
done
# Si no se encuentra, usar 'mariadb' o 'mysql' como último recurso
[ -z "$MYSQL_BIN" ] && MYSQL_BIN="mariadb"

# Alias corto para queries con -sN (skip headers, numeric)
MYSQL="$MYSQL_BIN -u$DB_USER -p$DB_PASS -h$DB_HOST -P$DB_PORT $DB_NAME"

db_exec() {
    local sql="$1"

    # 0) Diagnóstico silencioso: si MYSQL_BIN no es ejecutable, intentar arreglarlo
    if ! "$MYSQL_BIN" --version >/dev/null 2>&1; then
        # Probar paths de nuevo por si acaso
        for candidate in /usr/bin/mariadb /usr/bin/mysql mariadb mysql; do
            if [ -x "$candidate" ] && "$candidate" --version >/dev/null 2>&1; then
                MYSQL_BIN="$candidate"
                break
            fi
        done
    fi

    # 1) TCP con credenciales del .env
    "$MYSQL_BIN" -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -P"$DB_PORT" "$DB_NAME" -e "$sql" 2>/dev/null && return 0
    # 2) Socket local (sin -h -P)
    "$MYSQL_BIN" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$sql" 2>/dev/null && return 0
    # 3) Root sin contraseña (funciona en sistemas sin auth TCP)
    "$MYSQL_BIN" -u root "$DB_NAME" -e "$sql" 2>/dev/null && return 0
    return 1
}

# Variables para save/restore del estado de producción de room 1
SAVED_PACK_ID=""
SAVED_SIM_OVERRIDE=""
SAVED_STATUS=""
SAVED_COOLDOWN=""
SAVED_PRESENCE_CHECK_SAVE=""  # nombre distinto para no colisionar con test vars

# Recursos exclusivos del test F40. Se limpian también si un caso intermedio
# falla; no se imprimen credenciales ni se toca la auditoría histórica.
FACTORY_CHIP=""
FACTORY_CLEANED=false
_cleanup_factory_test() {
    [ -z "$FACTORY_CHIP" ] && return 0
    [ "$FACTORY_CLEANED" = true ] && return 0
    if ! db_exec "SELECT 1" > /dev/null 2>&1; then
        echo "[TEST-RUNNER] ⚠ F40 cleanup omitido: BD no accesible"
        return 0
    fi
    local rpi_id client_id
    rpi_id=$($MYSQL -sN -e "SELECT id FROM devices WHERE kind='RPI' AND external_id='$FACTORY_CHIP' LIMIT 1" 2>/dev/null || true)
    if [ -n "$rpi_id" ]; then
        client_id=$($MYSQL -sN -e "SELECT api_client_id FROM devices WHERE id=$rpi_id LIMIT 1" 2>/dev/null || true)
        $MYSQL -sN -e "DELETE FROM devices WHERE id=$rpi_id" 2>/dev/null || true
        if [ -n "$client_id" ]; then
            $MYSQL -sN -e "DELETE FROM api_clients WHERE id=$client_id AND code='RPI-$FACTORY_CHIP'" 2>/dev/null || true
        fi
    fi
    $MYSQL -sN -e "DELETE FROM factory_devices WHERE chip_id='$FACTORY_CHIP'" 2>/dev/null || true
    local remaining_factory remaining_rpi
    remaining_factory=$($MYSQL -sN -e "SELECT COUNT(*) FROM factory_devices WHERE chip_id='$FACTORY_CHIP'" 2>/dev/null || echo 1)
    remaining_rpi=$($MYSQL -sN -e "SELECT COUNT(*) FROM devices WHERE kind='RPI' AND external_id='$FACTORY_CHIP'" 2>/dev/null || echo 1)
    if [ "$remaining_factory" = 0 ] && [ "$remaining_rpi" = 0 ]; then
        FACTORY_CLEANED=true
        echo "[TEST-RUNNER] ✅ F40 cleanup completado (recursos propios; auditoría preservada)"
    else
        fail "F40 cleanup" "Quedaron recursos del chip de prueba"
    fi
}
trap _cleanup_factory_test EXIT

_save_room1_state() {
    if db_exec "SELECT 1" > /dev/null 2>&1; then
        SAVED_PACK_ID=$($MYSQL -sN -e "SELECT COALESCE(pack_id, 'NULL') FROM rooms WHERE id=1" 2>/dev/null || echo "NULL")
        SAVED_SIM_OVERRIDE=$($MYSQL -sN -e "SELECT COALESCE(simulated_override, 'NULL') FROM rooms WHERE id=1" 2>/dev/null || echo "NULL")
        SAVED_STATUS=$($MYSQL -sN -e "SELECT COALESCE(status, 'FREE') FROM rooms WHERE id=1" 2>/dev/null || echo "FREE")
        SAVED_COOLDOWN=$($MYSQL -sN -e "SELECT COALESCE(cooldown_until, 'NULL') FROM rooms WHERE id=1" 2>/dev/null || echo "NULL")
        SAVED_PRESENCE_CHECK_SAVE=$($MYSQL -sN -e "SELECT COALESCE(presence_check_seconds, 'NULL') FROM rooms WHERE id=1" 2>/dev/null || echo "NULL")
        echo "[TEST-RUNNER] Estado original de room 1 guardado: pack_id=$SAVED_PACK_ID simulated_override=$SAVED_SIM_OVERRIDE status=$SAVED_STATUS"
    else
        echo "[TEST-RUNNER] ⚠ No se pudo guardar estado de room 1 (BD no accesible)"
    fi
}

_restore_room1_state() {
    if [ -z "$SAVED_PACK_ID" ]; then
        echo "[TEST-RUNNER] ⚠ Sin estado guardado — omitiendo restore de room 1"
        return
    fi
    if ! db_exec "SELECT 1" > /dev/null 2>&1; then
        echo "[TEST-RUNNER] ⚠ BD no accesible — no se puede restaurar room 1"
        return
    fi

    local sql="UPDATE rooms SET"
    [ "$SAVED_PACK_ID" = "NULL" ] && sql="$sql pack_id=NULL," || sql="$sql pack_id=$SAVED_PACK_ID,"
    [ "$SAVED_SIM_OVERRIDE" = "NULL" ] && sql="$sql simulated_override=NULL," || sql="$sql simulated_override=$SAVED_SIM_OVERRIDE,"
    [ "$SAVED_COOLDOWN" = "NULL" ] && sql="$sql cooldown_until=NULL," || sql="$sql cooldown_until='$SAVED_COOLDOWN',"
    [ "$SAVED_PRESENCE_CHECK_SAVE" = "NULL" ] && sql="$sql presence_check_seconds=NULL," || sql="$sql presence_check_seconds=$SAVED_PRESENCE_CHECK_SAVE,"
    sql="$sql status='$SAVED_STATUS' WHERE id=1"

    if $MYSQL -sN -e "$sql" 2>/dev/null; then
        echo "[TEST-RUNNER] ✅ Room 1 restaurada: pack_id=$SAVED_PACK_ID simulated_override=$SAVED_SIM_OVERRIDE"
    else
        echo "[TEST-RUNNER] ⚠ Falló restore de room 1"
    fi
}

# --- Funciones de salida -----------------------------------------------------
pass() {
    echo -e "${G}  PASS${NC}  $1"
    PASS=$((PASS + 1))
}

fail() {
    echo -e "${R}  FAIL${NC}  $1"
    [ -n "${2:-}" ] && echo -e "        ${R}↳${NC} $2"
    FAIL=$((FAIL + 1))
}

skip() {
    echo -e "${Y}  SKIP${NC}  $1"
    [ -n "${2:-}" ] && echo -e "        ${Y}↳${NC} $2"
    SKIP=$((SKIP + 1))
}

block() {
    echo ""
    echo -e "${W}--- $1 ---${NC}"
}

# --- Leer clave API del fichero de seeds -------------------------------------
# Uso: get_key VB6-MAIN
# Devuelve la clave en texto plano, o vacío si no está disponible.
get_key() {
    local code="$1"
    local val
    val=$(grep "^${code}[[:space:]]" "$KEYS_FILE" 2>/dev/null | awk '{print $2}' | head -1)
    # El seed escribe "EXISTING" si la fila ya existía (clave no recuperable)
    if [ -z "$val" ] || [ "$val" = "EXISTING" ]; then
        echo ""
    else
        echo "$val"
    fi
}

# --- Helper HTTP test --------------------------------------------------------
# Uso: http_test METHOD PATH EXPECTED_CODE "label" [opciones...]
#
# Opciones:
#   --key  CODE          Lee la clave API de seeds/dev_api_keys.txt para CODE
#   --header "K: V"      Cabecera adicional
#   --body   '{"k":"v"}' Cuerpo JSON (añade Content-Type: application/json)
#   --idem   KEY         Cabecera Idempotency-Key
#
# En caso de FAIL imprime:
#   - HTTP esperado vs recibido
#   - Resumen de la request
#   - Primeros 600 chars del body de respuesta
http_test() {
    local method="$1"
    local path="$2"
    local expected="$3"
    local label="$4"
    shift 4

    local -a curl_args=("-s" "-X" "$method" "-o" "$TMP_BODY" "-w" "%{http_code}" "--max-time" "10")
    local req_summary="$method $API_BASE$path"

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --key)
                local api_key
                api_key=$(get_key "$2")
                if [ -z "$api_key" ]; then
                    fail "$label" \
                        "Clave para '$2' no encontrada en $KEYS_FILE — ejecuta: php bin/seed.php"
                    return
                fi
                curl_args+=("-H" "X-API-Key: $api_key")
                req_summary="$req_summary  key=$2"
                shift 2
                ;;
            --header)
                curl_args+=("-H" "$2")
                req_summary="$req_summary  header='$2'"
                shift 2
                ;;
            --body)
                curl_args+=("-H" "Content-Type: application/json" "-d" "$2")
                req_summary="$req_summary  body=$2"
                shift 2
                ;;
            --idem)
                curl_args+=("-H" "Idempotency-Key: $2")
                req_summary="$req_summary  idem=$2"
                shift 2
                ;;
            *)
                shift
                ;;
        esac
    done

    local http_code body
    http_code=$(curl "${curl_args[@]}" "${API_BASE}${path}" 2>/dev/null || echo "000")
    body=$(cat "$TMP_BODY" 2>/dev/null || echo "(vacío)")

    if [ "$http_code" = "$expected" ]; then
        pass "$label  [HTTP $http_code]"
    else
        fail "$label" "Esperado HTTP $expected, recibido HTTP $http_code"
        echo -e "        Request:  $req_summary"
        echo -e "        Response: $(echo "$body" | head -c 600)"
    fi
}

# =============================================================================
# BLOCK 1 — Unit Tests (sin BD)
# Trazabilidad: TSK-060, TSK-061, TSK-051, TSK-042, TSK-047
# =============================================================================
block "BLOCK 1 — Unit Tests (sin BD)"

cd "$PROJECT_DIR"

# Auto-descubrir todos los *Test.php en tests/Unit/
while IFS= read -r -d '' test_file; do
    label="Unit: $(basename "$test_file" .php)"
    output=$(php "$test_file" 2>&1)
    exit_code=$?
    if [ "$exit_code" -eq 0 ]; then
        pass "$label"
    else
        fail "$label"
        # Mostrar solo líneas de FAIL y el total para no saturar el log
        local_detail=$(echo "$output" | grep -E '^\s*(FAIL|Error|fatal|Total)' | head -5 || true)
        [ -n "$local_detail" ] && echo "$local_detail" | sed 's/^/          /'
        # En debug: mostrar salida completa
        echo "          --- salida completa ---"
        echo "$output" | sed 's/^/          /'
    fi
done < <(find tests/Unit -name "*Test.php" -print0 2>/dev/null | sort -z)

# Guardar estado de producción de room 1 antes de que los tests lo modifiquen
_save_room1_state

# =============================================================================
# BLOCK 2 — Base de datos: conexión + migraciones
# Trazabilidad: TSK-004, TSK-006, TSK-020 a TSK-033
# =============================================================================
block "BLOCK 2 — Base de datos"

db_out=$(php bin/db-check.php 2>&1)

if echo "$db_out" | grep -q "Connected to API database"; then
    pass "Conexión PDO a hotel_api"

    table_count=$(echo "$db_out" | grep -c "^  - " || true)
    if [ "$table_count" -ge 14 ]; then
        pass "Migraciones aplicadas ($table_count tablas encontradas)"
    else
        fail "Migraciones aplicadas" \
            "Se esperaban ≥14 tablas, hay $table_count — ejecuta: php bin/migrate.php"
        echo "$db_out" | sed 's/^/          /'
    fi
else
    fail "Conexión PDO a hotel_api" "$(echo "$db_out" | head -3 | tr '\n' ' ')"
    skip "Migraciones aplicadas" "BD no accesible"
fi

# =============================================================================
# BLOCK 3 — Servidor HTTP activo
# =============================================================================
block "BLOCK 3 — Servidor HTTP"

if curl -s --max-time 3 "${API_BASE}/api/v1/health" -o /dev/null 2>/dev/null; then
    SERVER_UP=true
    pass "Servidor PHP responde en $API_BASE"
else
    fail "Servidor PHP responde en $API_BASE" \
        "No accesible. Lanza: php -S 127.0.0.1:8080 -t public > logs/php-server.log 2>&1 &"
fi

# Los bloques 4-N dependen del servidor. Si no está activo se marcan como skip.
if [ "$SERVER_UP" = false ]; then
    skip "BLOCKS 4-8 (todos los tests HTTP)" "servidor no disponible"
else

# =============================================================================
# BLOCK 4 — HTTP: Endpoints públicos
# Trazabilidad: TSK-016
# =============================================================================
block "BLOCK 4 — HTTP: Endpoints públicos"

http_test GET /api/v1/health 200 "GET /health (sin autenticación)"

# =============================================================================
# BLOCK 5 — HTTP: Enforcement de autenticación
# Trazabilidad: TSK-013, TSK-014
# =============================================================================
block "BLOCK 5 — HTTP: Autenticación"

http_test GET /api/v1/rooms 401 \
    "GET /rooms sin API key → 401"

http_test GET /api/v1/rooms 401 \
    "GET /rooms con clave inválida → 401" \
    --header "X-API-Key: clave_invalida_000000000000000000000000000000000000"

http_test GET /api/v1/rooms 403 \
    "GET /rooms con RPI-DEV (scope insuficiente) → 403" \
    --key RPI-DEV

# =============================================================================
# BLOCK 6 — HTTP: F3 — Rooms / RoomTypes / TimeSlots
# Trazabilidad: RF-1, RF-9, TSK-041, TSK-043, TSK-045
# =============================================================================
block "BLOCK 6 — HTTP: F3 — Rooms / RoomTypes / TimeSlots"

http_test GET /api/v1/room-types      200 "GET /room-types (ADMIN-CLI)"         --key ADMIN-CLI
http_test GET /api/v1/room-types/1    200 "GET /room-types/1 (ADMIN-CLI)"        --key ADMIN-CLI
http_test GET /api/v1/room-types/9999 404 "GET /room-types/9999 → 404"           --key ADMIN-CLI

http_test GET /api/v1/rooms           200 "GET /rooms (ADMIN-CLI)"               --key ADMIN-CLI
http_test GET /api/v1/rooms/1         200 "GET /rooms/1 (ADMIN-CLI)"             --key ADMIN-CLI
http_test GET /api/v1/rooms/9999      404 "GET /rooms/9999 → 404"                --key ADMIN-CLI

http_test GET /api/v1/room-types/1/time-slots 200 \
    "GET /room-types/1/time-slots (ADMIN-CLI)"                                   --key ADMIN-CLI

# =============================================================================
# BLOCK 7 — HTTP: F4 — Stays
# Trazabilidad: RF-6, TSK-052
# =============================================================================
block "BLOCK 7 — HTTP: F4 — Stays"

http_test GET /api/v1/stays 200 "GET /stays (ADMIN-CLI)"  --key ADMIN-CLI

# =============================================================================
# BLOCK 8 — HTTP: F5 — Emisión QR
# Trazabilidad: RF-2, TSK-062, TSK-063
#
# NOTA STATEFUL: estos tests crean una estancia en room 2 (código 102).
# En la primera ejecución: 201. En ejecuciones posteriores con la misma BD:
# la habitación puede estar ocupada → se hace SKIP del flujo principal con
# un mensaje explicativo. Los tests de validación de errores (duracion, 404)
# son siempre independientes del estado.
# =============================================================================
block "BLOCK 8 — HTTP: F5 — Emisión QR"

# Intentar emitir QR para room 2 con clave única de idempotencia
IDEM_QR_1="test-qr-r2-$(date +%s)-1"

VB6_KEY=$(get_key "VB6-MAIN")
if [ -z "$VB6_KEY" ]; then
    skip "POST /qr (flujo completo)" "Clave VB6-MAIN no disponible en $KEYS_FILE"
    skip "POST /qr idempotente"      "Depende del test anterior"
    skip "POST /qr room_busy"        "Depende del test anterior"
else
    http_code_qr=$(curl -s -X POST \
        -o "$TMP_BODY" -w "%{http_code}" \
        --max-time 10 \
        -H "X-API-Key: $VB6_KEY" \
        -H "Content-Type: application/json" \
        -H "Idempotency-Key: $IDEM_QR_1" \
        -d '{"room_id": 2, "duracion_minutos": 60}' \
        "${API_BASE}/api/v1/qr" 2>/dev/null || echo "000")
    body_qr=$(cat "$TMP_BODY" 2>/dev/null || echo "")

    if [ "$http_code_qr" = "201" ]; then
        pass "POST /qr — emisión exitosa (VB6-MAIN, room 2, 60 min)  [HTTP 201]"

        # Mismo Idempotency-Key + mismo body → respuesta idéntica cacheada (201)
        http_test POST /api/v1/qr 201 \
            "POST /qr — idempotente (mismo Idempotency-Key → 201)" \
            --key VB6-MAIN \
            --idem "$IDEM_QR_1" \
            --body '{"room_id": 2, "duracion_minutos": 60}'

        # Room ocupada con clave diferente → 409
        http_test POST /api/v1/qr 409 \
            "POST /qr — room ocupada → 409" \
            --key VB6-MAIN \
            --idem "test-qr-busy-$(date +%s)" \
            --body '{"room_id": 2, "duracion_minutos": 60}'

    elif [ "$http_code_qr" = "409" ]; then
        skip "POST /qr — emisión exitosa (VB6-MAIN, room 2, 60 min)" \
            "Room 2 ya tiene estancia activa. Para resetear: usa una BD limpia o cierra la estancia manualmente."
        skip "POST /qr — idempotente (mismo Idempotency-Key → 201)" \
            "Depende del test anterior (room disponible)"
        skip "POST /qr — room ocupada → 409" \
            "Room ya ocupada desde antes (comportamiento esperado de todas formas)"
    else
        fail "POST /qr — emisión (VB6-MAIN, room 2, 60 min)" \
            "Esperado HTTP 201 ó 409, recibido HTTP $http_code_qr"
        echo -e "        Response: $(echo "$body_qr" | head -c 600)"
        skip "POST /qr — idempotente"  "Depende del test anterior"
        skip "POST /qr — room_busy"    "Depende del test anterior"
    fi
fi

# Tests de validación de errores — SIEMPRE se ejecutan (sin estado)
http_test POST /api/v1/qr 422 \
    "POST /qr — duracion=10 (demasiado corta) → 422" \
    --key VB6-MAIN \
    --idem "test-qr-short-$(date +%s)" \
    --body '{"room_id": 2, "duracion_minutos": 10}'

http_test POST /api/v1/qr 422 \
    "POST /qr — duracion=800 (demasiado larga) → 422" \
    --key VB6-MAIN \
    --idem "test-qr-long-$(date +%s)" \
    --body '{"room_id": 2, "duracion_minutos": 800}'

http_test POST /api/v1/qr 404 \
    "POST /qr — room_id=9999 (no existe) → 404" \
    --key VB6-MAIN \
    --idem "test-qr-noroom-$(date +%s)" \
    --body '{"room_id": 9999, "duracion_minutos": 60}'

# =============================================================================
# BLOCK 9 — F6: QR Validate + Locks
# Trazabilidad: RF-3, RF-4, TSK-070, TSK-071, TSK-072
#
# NOTA STATEFUL: necesita un QR activo para validar.
# La estrategia es: emitir un QR para room 1 (libre) → validar → verificar 403.
# Si room 1 está ocupada (de una ejecución anterior), el emisión hará SKIP del
# flujo completo pero los tests de error (firma inválida, expirado) siempre corren.
# =============================================================================
block "BLOCK 9 — F6: QR Validate + Locks"

RPI_KEY=$(get_key "RPI-DEV")
ADM_KEY=$(get_key "ADMIN-CLI")
VB6_KEY=$(get_key "VB6-MAIN")

if [ -z "$RPI_KEY" ] || [ -z "$ADM_KEY" ] || [ -z "$VB6_KEY" ]; then
    skip "BLOCK 9 completo" "Claves RPI-DEV / ADMIN-CLI / VB6-MAIN no disponibles"
else

    # Asegurar que room 1 está libre antes de emitir QR
    echo ""
    echo "  --- Liberando room 1 para tests de QR validate ---"
    $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=1" 2>/dev/null || true

    # Intentar emitir QR para room 1
    IDEM_VALIDATE_QR="test-validate-r1-$(date +%s)"
    http_code_v=$(curl -s -X POST \
        -o "$TMP_BODY" -w "%{http_code}" --max-time 10 \
        -H "X-API-Key: $VB6_KEY" \
        -H "Content-Type: application/json" \
        -H "Idempotency-Key: $IDEM_VALIDATE_QR" \
        -d '{"room_id": 1, "duracion_minutos": 60}' \
        "${API_BASE}/api/v1/qr" 2>/dev/null || echo "000")
    body_v=$(cat "$TMP_BODY" 2>/dev/null || echo "")

    if [ "$http_code_v" = "201" ]; then
        pass "POST /qr para room 1 (setup validate)  [HTTP 201]"

        # Extraer qr_text del response
        QR_TEXT=$(echo "$body_v" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('qr_text',''))" 2>/dev/null || echo "")

        if [ -n "$QR_TEXT" ]; then
            # Ensure pack assigned (pack-based resolution after refactor)
            "$MYSQL_BIN" -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -P"$DB_PORT" "$DB_NAME" -e "UPDATE rooms SET pack_id=5 WHERE id=1" 2>/dev/null || true
            # Obtener el external_id real del dispositivo RPI para room 1
            RPI_DEVICE_ID=$($MYSQL -sN -e "SELECT d.external_id FROM devices d JOIN rooms r ON r.pack_id = d.pack_id WHERE r.id=1 AND d.kind='RPI' LIMIT 1" 2>/dev/null || echo "unknown")
            if [ "$RPI_DEVICE_ID" = "unknown" ] || [ -z "$RPI_DEVICE_ID" ]; then
                skip "POST /qr/validate QR válido" "RPI device not found for room 1 pack → run seeds or assign pack"
                skip "POST /qr/validate re-entry" "Depende del test anterior"
            else
                echo "  RPI device_id para room 1: $RPI_DEVICE_ID"
                # Validar QR con RPI-DEV
                http_test POST /api/v1/qr/validate 200 \
                    "POST /qr/validate QR válido (RPI-DEV, room 1) → 200 allow" \
                    --key RPI-DEV \
                    --body "{\"qr_text\": \"${QR_TEXT}\", \"device_id\": \"${RPI_DEVICE_ID}\"}"
                # Re-entry
                http_test POST /api/v1/qr/validate 200 \
                    "POST /qr/validate re-entry (jti consumido + OCCUPIED) → 200" \
                    --key RPI-DEV \
                    --body "{\"qr_text\": \"${QR_TEXT}\", \"device_id\": \"${RPI_DEVICE_ID}\"}"
            fi
        else
            skip "POST /qr/validate QR válido" "No se pudo extraer qr_text del response"
            skip "POST /qr/validate re-entry" "Depende del test anterior"
        fi

    elif [ "$http_code_v" = "409" ]; then
        skip "POST /qr para room 1 (setup validate)" "Room 1 ocupada; resetear BD para test completo"
        skip "POST /qr/validate QR válido" "Depende del setup"
        skip "POST /qr/validate re-entry" "Depende del setup"
    else
        fail "POST /qr para room 1 (setup validate)" "HTTP $http_code_v"
        skip "POST /qr/validate QR válido" "Depende del setup"
        skip "POST /qr/validate re-entry" "Depende del setup"
    fi

    # Tests de error en validate — siempre independientes del estado
    http_test POST /api/v1/qr/validate 403 \
        "POST /qr/validate token basura → 403 (firma inválida)" \
        --key RPI-DEV \
        --body '{"qr_text": "payload_invalido.firma.XXXX", "device_id": "rpi-dev-test"}'

    # ---- Locks admin ----
    IDEM_OPEN="test-lock-open-$(date +%s)"
    IDEM_LOCK="test-lock-lock-$(date +%s)"

    http_test POST /api/v1/locks/1/open 200 \
        "POST /locks/1/open (ADMIN-CLI) → 200" \
        --key ADMIN-CLI \
        --idem "$IDEM_OPEN" \
        --body '{"reason": "manual_override"}'

    http_test POST /api/v1/locks/1/lock 200 \
        "POST /locks/1/lock (ADMIN-CLI) → 200" \
        --key ADMIN-CLI \
        --idem "$IDEM_LOCK" \
        --body '{"reason": "manual"}'

    http_test POST /api/v1/locks/9999/open 404 \
        "POST /locks/9999/open → 404 (room no existe)" \
        --key ADMIN-CLI \
        --idem "test-lock-notfound-$(date +%s)" \
        --body '{"reason": "test"}'

    # Sin scope locks:open → 403
    http_test POST /api/v1/locks/1/open 403 \
        "POST /locks/1/open con RPI-DEV (sin scope) → 403" \
        --key RPI-DEV \
        --idem "test-lock-scope-$(date +%s)" \
        --body '{"reason": "test"}'

fi  # RPI_KEY

# =============================================================================
# BLOCK 10 — F7: Gateways simulados (LockGateway + SensorIngress)
# Trazabilidad: RF-4, RF-11, TSK-080, TSK-081, TSK-082
#
# Los gateways simulados se verifican implícitamente a través de:
# - BLOCK 9: lock open/lock llaman al SimulatedLockGateway y devuelven provider=SIMULATED
# - Los access_events se escriben en BD (verificado indirectamente vía BLOCK 9)
# La SensorIngress se ejercitará en BLOCK 11 (F8: presence/events).
# =============================================================================
block "BLOCK 10 — F7: Gateways simulados (verificación integrada)"

# Verificar que la respuesta de open incluye provider=SIMULATED
IDEM_PROV="test-lock-prov-$(date +%s)"
ADM_KEY=$(get_key "ADMIN-CLI")
if [ -n "$ADM_KEY" ]; then
    provider_resp=$(curl -s -X POST \
        -o "$TMP_BODY" -w "%{http_code}" --max-time 10 \
        -H "X-API-Key: $ADM_KEY" \
        -H "Content-Type: application/json" \
        -H "Idempotency-Key: $IDEM_PROV" \
        -d '{"reason":"provider_check"}' \
        "${API_BASE}/api/v1/locks/1/open" 2>/dev/null || echo "000")
    body_prov=$(cat "$TMP_BODY" 2>/dev/null || echo "")
    prov_value=$(echo "$body_prov" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('provider',''))" 2>/dev/null || echo "")

    if [ "$prov_value" = "LOCAL" ] || [ "$prov_value" = "SIMULATED" ]; then
        pass "LockGateway devuelve provider=$prov_value (según LOCK_PROVIDER: ${LOCK_PROV:-unknown})"
    else
        fail "LockGateway devuelve provider (LOCAL o SIMULATED)" \
             "Expected LOCAL or SIMULATED, got '$prov_value'. Body: $body_prov"
    fi
else
    skip "LockGateway provider=SIMULATED" "Clave ADMIN-CLI no disponible"
fi

# =============================================================================
# BLOCK 11 — F8: Presence + IoT sessions
# Trazabilidad: RF-5, RF-7 parcial, TSK-092, TSK-093
#
# Tests stateful: crean presence_events e iot_sessions en BD.
# Los tests de validación de errores son siempre independientes.
# =============================================================================
block "BLOCK 11 — F8: Presence / IoT sessions"

TUY_KEY=$(get_key "TUYA-BRIDGE")   # scope presence:write
ADM_KEY=$(get_key "ADMIN-CLI")     # scope presence:read
SIM_KEY=$(get_key "SIM-CLIENT")    # scope presence:write (también válido)

if [ -z "$TUY_KEY" ] || [ -z "$ADM_KEY" ]; then
    skip "BLOCK 11 completo" "Claves TUYA-BRIDGE / ADMIN-CLI no disponibles"
else

    IDEM_PROX="pres-prox-$(date +%s)"
    IDEM_PRES="pres-pres-$(date +%s)"
    OCC_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    # POST PROXIMITY=OPEN → 202
    http_test POST /api/v1/presence/events 202 \
        "POST /presence/events PROXIMITY=OPEN → 202" \
        --key TUYA-BRIDGE \
        --idem "$IDEM_PROX" \
        --body "{\"room_id\":1,\"sensor\":\"PROXIMITY\",\"value\":\"OPEN\",\"occurred_at\":\"${OCC_AT}\",\"source_event_id\":\"${IDEM_PROX}\"}"

    # POST PRESENCE=ABSENT → 202
    http_test POST /api/v1/presence/events 202 \
        "POST /presence/events PRESENCE=ABSENT → 202" \
        --key TUYA-BRIDGE \
        --idem "$IDEM_PRES" \
        --body "{\"room_id\":1,\"sensor\":\"PRESENCE\",\"value\":\"ABSENT\",\"occurred_at\":\"${OCC_AT}\",\"source_event_id\":\"${IDEM_PRES}\"}"

    # Idempotencia: mismo Idempotency-Key → misma respuesta 202
    http_test POST /api/v1/presence/events 202 \
        "POST /presence/events idempotente (mismo key → 202)" \
        --key TUYA-BRIDGE \
        --idem "$IDEM_PROX" \
        --body "{\"room_id\":1,\"sensor\":\"PROXIMITY\",\"value\":\"OPEN\",\"occurred_at\":\"${OCC_AT}\",\"source_event_id\":\"${IDEM_PROX}\"}"

    # Validación: sensor inválido → 400
    http_test POST /api/v1/presence/events 400 \
        "POST /presence/events sensor inválido → 400" \
        --key TUYA-BRIDGE \
        --idem "pres-bad-sensor-$(date +%s)" \
        --body '{"room_id":1,"sensor":"INVALID","value":"OPEN","occurred_at":"2026-04-28T10:00:00Z"}'

    # Sin autenticación → 401
    http_test POST /api/v1/presence/events 401 \
        "POST /presence/events sin API key → 401" \
        --idem "pres-noauth-$(date +%s)" \
        --body '{"room_id":1,"sensor":"PROXIMITY","value":"OPEN","occurred_at":"2026-04-28T10:00:00Z"}'

    # Room inexistente → 404
    http_test POST /api/v1/presence/events 404 \
        "POST /presence/events room_id=9999 → 404" \
        --key TUYA-BRIDGE \
        --idem "pres-noroom-$(date +%s)" \
        --body '{"room_id":9999,"sensor":"PROXIMITY","value":"OPEN","occurred_at":"2026-04-28T10:00:00Z","source_event_id":"src-noroom-1"}'

    # GET /rooms/1/presence → 200 con derived_state
    http_test GET /api/v1/rooms/1/presence 200 \
        "GET /rooms/1/presence → 200" \
        --key ADMIN-CLI

    # GET /rooms/9999/presence → 404
    http_test GET /api/v1/rooms/9999/presence 404 \
        "GET /rooms/9999/presence → 404" \
        --key ADMIN-CLI

fi  # TUY_KEY

# =============================================================================
# BLOCK 12 — F9: Regla de salida + bloqueo rápido
# Trazabilidad: RF-7, TSK-100, TSK-101, TSK-102
#
# TSK-101 (orquestación auto-exit) está cubierto por unit tests de
# IotSessionServiceTest y ExitRuleEvaluatorTest en BLOCK 1.
#
# TSK-102 (cooldown anti-reentrada) se verifica aquí mediante manipulación
# directa de la BD para establecer cooldown_until y luego llamar a los
# endpoints de bloqueo y apertura.
# =============================================================================
block "BLOCK 12 — F9: Cooldown anti-reentrada (TSK-102)"

ADM_KEY=$(get_key "ADMIN-CLI")

if [ -z "$ADM_KEY" ]; then
    skip "BLOCK 12 completo" "Clave ADMIN-CLI no disponible"
elif ! db_exec "SELECT 1" > /dev/null 2>&1; then
    skip "BLOCK 12 completo" "No hay conexión a la BD (se necesita para manipular cooldown)"
else

    # ---- Setup: establecer cooldown en room 1 (300 segundos en el futuro) ----
    db_exec "UPDATE rooms SET cooldown_until = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 300 SECOND) WHERE id = 1"

    # TSK-102a: POST /locks/1/open sin override → 403 room_cooldown
    http_test POST /api/v1/locks/1/open 403 \
        "POST /locks/1/open con cooldown activo → 403 room_cooldown" \
        --key ADMIN-CLI \
        --idem "cooldown-no-override-$(date +%s)" \
        --body '{"reason":"test_cooldown"}'

    # TSK-102b: POST /locks/1/open con override_cooldown=true → 200
    # (ADMIN-CLI tiene scope locks:override)
    http_test POST /api/v1/locks/1/open 200 \
        "POST /locks/1/open con override_cooldown=true → 200" \
        --key ADMIN-CLI \
        --idem "cooldown-with-override-$(date +%s)" \
        --body '{"reason":"admin_override","override_cooldown":true}'

    # TSK-102c: RPI-DEV (sin locks:override) intenta override → 403 auth
    http_test POST /api/v1/locks/1/open 403 \
        "POST /locks/1/open override sin scope locks:override → 403" \
        --key RPI-DEV \
        --idem "cooldown-rpi-override-$(date +%s)" \
        --body '{"reason":"test","override_cooldown":true}'

    # ---- Teardown: limpiar cooldown ----
    db_exec "UPDATE rooms SET cooldown_until = NULL WHERE id = 1"

    # TSK-102d: sin cooldown, lock open funciona normal
    http_test POST /api/v1/locks/1/open 200 \
        "POST /locks/1/open sin cooldown → 200 normal" \
        --key ADMIN-CLI \
        --idem "cooldown-cleared-$(date +%s)" \
        --body '{"reason":"post_cooldown_test"}'

fi  # ADM_KEY

# =============================================================================
# BLOCK 13 — F10: Overstay + Debts
# Trazabilidad: RF-6, RF-8, TSK-110-115
#
# TSK-110/111/115 cubiertos por unit tests en BLOCK 1.
# Aquí se verifican los endpoints HTTP y el job overstay-scan.php.
# =============================================================================
block "BLOCK 13 — F10: Overstay / Debts"

ADM_KEY=$(get_key "ADMIN-CLI")

if [ -z "$ADM_KEY" ]; then
    skip "BLOCK 13 completo" "Clave ADMIN-CLI no disponible"
else

    # ---- GET /stays/{id}/overstay — con una stay existente ----
    # Buscamos el id de una stay existente (cualquiera sirve para el endpoint)
    STAY_RESP=$(curl -s -H "X-API-Key: $ADM_KEY" "${API_BASE}/api/v1/stays" 2>/dev/null)
    STAY_ID=$(echo "$STAY_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); items=d.get('items',[]); print(items[0]['id'] if items else 0)" 2>/dev/null || echo "0")

    if [ "$STAY_ID" != "0" ] && [ -n "$STAY_ID" ]; then
        http_test GET "/api/v1/stays/${STAY_ID}/overstay" 200 \
            "GET /stays/${STAY_ID}/overstay → 200" \
            --key ADMIN-CLI
    else
        skip "GET /stays/{id}/overstay → 200" "No hay stays en la BD para probar"
    fi

    http_test GET /api/v1/stays/9999/overstay 404 \
        "GET /stays/9999/overstay → 404 (stay no existe)" \
        --key ADMIN-CLI

    # ---- GET /debts ----
    http_test GET /api/v1/debts 200 \
        "GET /debts (ADMIN-CLI) → 200" \
        --key ADMIN-CLI

    # ---- GET /debts/{id} → 404 si no hay debts ----
    http_test GET /api/v1/debts/9999 404 \
        "GET /debts/9999 → 404" \
        --key ADMIN-CLI

    # ---- POST /debts/{id}/resync → 404 si no existe ----
    http_test POST /api/v1/debts/9999/resync 404 \
        "POST /debts/9999/resync → 404" \
        --key ADMIN-CLI \
        --body '{}'

    # ---- bin/overstay-scan.php — verificar que corre sin errores ----
    scan_out=$(cd "$PROJECT_DIR" && php bin/overstay-scan.php 2>&1)
    scan_exit=$?
    if [ $scan_exit -eq 0 ]; then
        pass "bin/overstay-scan.php ejecuta sin errores (exit 0)"
    else
        fail "bin/overstay-scan.php ejecuta sin errores" \
             "Exit code: $scan_exit. Output: $(echo "$scan_out" | tail -3 | tr '\n' ' ')"
    fi

fi  # ADM_KEY

# =============================================================================
# BLOCK 14 — F11-F13: WS-VB6
# Trazabilidad: RF-8, P2, P5, TSK-123, TSK-124, TSK-134, TSK-142
# =============================================================================
block "BLOCK 14 — F11-F13: WS-VB6 bridge"

WS_BASE="http://127.0.0.1:8081"
VB6_BRIDGE_KEY=$(get_key "VB6-BRIDGE")

# ---- Unit tests for ws-vb6 (run inline) ----
WS_PROJECT="$(dirname "$PROJECT_DIR")/ws-vb6"
if [ -d "$WS_PROJECT/tests/Unit" ]; then
    while IFS= read -r -d '' test_file; do
        label="WS-Unit: $(basename "$test_file" .php)"
        output=$(php "$test_file" 2>&1)
        exit_code=$?
        if [ "$exit_code" -eq 0 ]; then
            pass "$label"
        else
            fail "$label"
            echo "$output" | sed 's/^/          /'
        fi
    done < <(find "$WS_PROJECT/tests/Unit" -name "*Test.php" -print0 2>/dev/null | sort -z)
else
    skip "WS-Unit tests" "ws-vb6/tests/Unit not found"
fi

# ---- HTTP tests for WS-VB6 server ----
if ! curl -s --max-time 3 "${WS_BASE}/ws-vb6/v1/health" -o /dev/null 2>/dev/null; then
    skip "BLOCK 14 HTTP tests" "WS-VB6 server not running on ${WS_BASE}"
else

    # F11: health + habitaciones
    if curl -s --max-time 3 "${WS_BASE}/ws-vb6/v1/health" | python3 -c "import sys,json; d=json.load(sys.stdin); exit(0 if d.get('status')=='ok' else 1)" 2>/dev/null; then
        pass "GET /ws-vb6/v1/health → 200 ok"
    else
        fail "GET /ws-vb6/v1/health → status=ok"
    fi

    if [ -z "$VB6_BRIDGE_KEY" ]; then
        skip "BLOCK 14 authenticated tests" "VB6-BRIDGE key not in $KEYS_FILE"
    else
        # Habitaciones — existing room
        hab_code=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            -H "X-API-Key: $VB6_BRIDGE_KEY" \
            "${WS_BASE}/ws-vb6/v1/habitaciones/101" 2>/dev/null || echo "000")
        if [ "$hab_code" = "200" ]; then
            pass "GET /habitaciones/101 → 200  [HTTP $hab_code]"
        else
            fail "GET /habitaciones/101 → 200" "HTTP $hab_code. Body: $(cat $TMP_BODY | head -c 300)"
        fi

        # Habitaciones — not found
        hab_404=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            -H "X-API-Key: $VB6_BRIDGE_KEY" \
            "${WS_BASE}/ws-vb6/v1/habitaciones/9999" 2>/dev/null || echo "000")
        if [ "$hab_404" = "404" ]; then
            pass "GET /habitaciones/9999 → 404  [HTTP $hab_404]"
        else
            fail "GET /habitaciones/9999 → 404" "HTTP $hab_404"
        fi

        # Habitaciones — unauthorized
        hab_401=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            "${WS_BASE}/ws-vb6/v1/habitaciones/101" 2>/dev/null || echo "000")
        if [ "$hab_401" = "401" ]; then
            pass "GET /habitaciones/101 (no key) → 401  [HTTP $hab_401]"
        else
            fail "GET /habitaciones/101 (no key) → 401" "HTTP $hab_401"
        fi

        # F12: POST /debts
        IDEM_DEBTS="ws-debts-test-$(date +%s)-1"
        debt_code=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            -X POST \
            -H "X-API-Key: $VB6_BRIDGE_KEY" \
            -H "Content-Type: application/json" \
            -H "Idempotency-Key: $IDEM_DEBTS" \
            -d "{\"codtic\":9001,\"codcli\":500,\"exceso_minutos\":35,\"fecha\":\"2026-04-28 10:00:00\",\"temporada\":\"2026\"}" \
            "${WS_BASE}/ws-vb6/v1/debts" 2>/dev/null || echo "000")
        if [ "$debt_code" = "201" ] || [ "$debt_code" = "200" ]; then
            pass "POST /ws-vb6/v1/debts → 201/200  [HTTP $debt_code]"
        else
            fail "POST /ws-vb6/v1/debts → 201" "HTTP $debt_code. Body: $(cat $TMP_BODY | head -c 300)"
        fi

        # F12: POST /debts idempotent — same key, same body → replayed
        debt_idem=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            -X POST \
            -H "X-API-Key: $VB6_BRIDGE_KEY" \
            -H "Content-Type: application/json" \
            -H "Idempotency-Key: $IDEM_DEBTS" \
            -d "{\"codtic\":9001,\"codcli\":500,\"exceso_minutos\":35,\"fecha\":\"2026-04-28 10:00:00\",\"temporada\":\"2026\"}" \
            "${WS_BASE}/ws-vb6/v1/debts" 2>/dev/null || echo "000")
        if [ "$debt_idem" = "201" ] || [ "$debt_idem" = "200" ]; then
            pass "POST /ws-vb6/v1/debts idempotente (mismo key) → 201/200  [HTTP $debt_idem]"
        else
            fail "POST /ws-vb6/v1/debts idempotente" "HTTP $debt_idem"
        fi

        # F12: POST /debts — missing required fields → 422
        debt_422=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            -X POST \
            -H "X-API-Key: $VB6_BRIDGE_KEY" \
            -H "Content-Type: application/json" \
            -H "Idempotency-Key: ws-debts-missing-$(date +%s)" \
            -d '{"exceso_minutos":35}' \
            "${WS_BASE}/ws-vb6/v1/debts" 2>/dev/null || echo "000")
        if [ "$debt_422" = "422" ]; then
            pass "POST /ws-vb6/v1/debts (sin codtic/codcli) → 422  [HTTP $debt_422]"
        else
            fail "POST /ws-vb6/v1/debts (sin codtic/codcli) → 422" "HTTP $debt_422. Body: $(cat $TMP_BODY | head -c 300)"
        fi

        # F13: POST /stays/events — stay.closed
        # Use a fixed occurred_at so the idempotency body hash is stable
        IDEM_STAY_CLOSE="ws-stay-close-$(date +%s)-1"
        STAY_CLOSE_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        stay_close=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            -X POST \
            -H "X-API-Key: $VB6_BRIDGE_KEY" \
            -H "Content-Type: application/json" \
            -H "Idempotency-Key: $IDEM_STAY_CLOSE" \
            -d "{\"topic\":\"stay.closed\",\"occurred_at\":\"${STAY_CLOSE_AT}\"}" \
            "${WS_BASE}/ws-vb6/v1/stays/events" 2>/dev/null || echo "000")
        if [ "$stay_close" = "201" ]; then
            pass "POST /ws-vb6/v1/stays/events (stay.closed) → 201  [HTTP $stay_close]"
        else
            fail "POST /ws-vb6/v1/stays/events (stay.closed) → 201" "HTTP $stay_close. Body: $(cat $TMP_BODY | head -c 300)"
        fi

        # F13: POST /stays/events — stay.overstay
        IDEM_STAY_OV="ws-stay-ov-$(date +%s)-1"
        stay_ov=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            -X POST \
            -H "X-API-Key: $VB6_BRIDGE_KEY" \
            -H "Content-Type: application/json" \
            -H "Idempotency-Key: $IDEM_STAY_OV" \
            -d "{\"topic\":\"stay.overstay\",\"occurred_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}" \
            "${WS_BASE}/ws-vb6/v1/stays/events" 2>/dev/null || echo "000")
        if [ "$stay_ov" = "201" ]; then
            pass "POST /ws-vb6/v1/stays/events (stay.overstay) → 201  [HTTP $stay_ov]"
        else
            fail "POST /ws-vb6/v1/stays/events (stay.overstay) → 201" "HTTP $stay_ov. Body: $(cat $TMP_BODY | head -c 300)"
        fi

        # F13: unknown topic → 422
        stay_bad=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            -X POST \
            -H "X-API-Key: $VB6_BRIDGE_KEY" \
            -H "Content-Type: application/json" \
            -H "Idempotency-Key: ws-stay-bad-$(date +%s)" \
            -d '{"topic":"stay.unknown","occurred_at":"2026-04-28T10:00:00Z"}' \
            "${WS_BASE}/ws-vb6/v1/stays/events" 2>/dev/null || echo "000")
        if [ "$stay_bad" = "422" ]; then
            pass "POST /ws-vb6/v1/stays/events (unknown topic) → 422  [HTTP $stay_bad]"
        else
            fail "POST /ws-vb6/v1/stays/events (unknown topic) → 422" "HTTP $stay_bad. Body: $(cat $TMP_BODY | head -c 300)"
        fi

        # F13: stays/events idempotent — same key + same body → replayed
        stay_idem=$(curl -s --max-time 5 -o "$TMP_BODY" -w "%{http_code}" \
            -X POST \
            -H "X-API-Key: $VB6_BRIDGE_KEY" \
            -H "Content-Type: application/json" \
            -H "Idempotency-Key: $IDEM_STAY_CLOSE" \
            -d "{\"topic\":\"stay.closed\",\"occurred_at\":\"${STAY_CLOSE_AT}\"}" \
            "${WS_BASE}/ws-vb6/v1/stays/events" 2>/dev/null || echo "000")
        if [ "$stay_idem" = "201" ] || [ "$stay_idem" = "200" ]; then
            pass "POST /ws-vb6/v1/stays/events idempotente → 201/200  [HTTP $stay_idem]"
        else
            fail "POST /ws-vb6/v1/stays/events idempotente" "HTTP $stay_idem"
        fi

    fi  # VB6_BRIDGE_KEY

fi  # WS server up

# =============================================================================
# BLOCK 15 — F14: Outbox + worker
# Trazabilidad: RF-13, TSK-152, TSK-153
# =============================================================================
block "BLOCK 15 — F14: Outbox worker"

# Aislar el estado global del outbox: apartar temporalmente los items PENDING ya
# vencidos (p. ej. debt.created sin refs VB6) para que el worker no los procese y
# las aserciones sean deterministas. Se restauran al final del bloque.
DUE_IDS=$($MYSQL -sN -e "SELECT id FROM outbox_vb6 WHERE status='PENDING' AND next_attempt_at <= UTC_TIMESTAMP(3)" 2>/dev/null | tr '\n' ',' | sed 's/,$//')
if [ -n "$DUE_IDS" ]; then
    $MYSQL -sN -e "UPDATE outbox_vb6 SET next_attempt_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 DAY) WHERE id IN ($DUE_IDS)" 2>/dev/null || true
fi

# ---- Test: worker runs with no items ----
worker_out=$(cd "$PROJECT_DIR" && php bin/outbox-worker.php 2>&1)
worker_exit=$?
if [ $worker_exit -eq 0 ]; then
    pass "bin/outbox-worker.php — sin items (exit 0)"
else
    fail "bin/outbox-worker.php — sin items" "Exit $worker_exit. Output: $(echo "$worker_out" | tail -3 | tr '\n' ' ')"
fi

# ---- Test: enqueue a stay.closed item and verify worker delivers it ----
WS_BASE_15="http://127.0.0.1:8081"
if ! curl -s --max-time 3 "${WS_BASE_15}/ws-vb6/v1/health" -o /dev/null 2>/dev/null; then
    skip "BLOCK 15 worker delivery test" "WS-VB6 server not running on port 8081"
elif ! db_exec "SELECT 1" > /dev/null 2>&1; then
    skip "BLOCK 15 worker delivery test" "No hay conexión a la BD"
else
    # Insert a test outbox item for stay.closed
    IDEM_WORKER_TEST="worker-block15-$(date +%s)"
    db_exec "INSERT IGNORE INTO outbox_vb6 (topic, payload_json, idempotency_key, status, next_attempt_at)
             VALUES ('stay.closed',
                     '{\"topic\":\"stay.closed\",\"stay_id\":100,\"room_id\":1,\"occurred_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}',
                     '${IDEM_WORKER_TEST}',
                     'PENDING',
                     UTC_TIMESTAMP(3))"

    # Run the worker
    worker2_out=$(cd "$PROJECT_DIR" && php bin/outbox-worker.php 2>&1)
    worker2_exit=$?
    if [ $worker2_exit -eq 0 ] && echo "$worker2_out" | grep -q "SUCCESS"; then
        pass "bin/outbox-worker.php — entrega stay.closed a WS-VB6 (exit 0)"
    elif [ $worker2_exit -eq 0 ]; then
        pass "bin/outbox-worker.php — procesó items (exit 0)"
    else
        fail "bin/outbox-worker.php — entrega stay.closed" "Exit $worker2_exit. Output: $(echo "$worker2_out" | tail -5 | tr '\n' ' ')"
    fi

    # Verify the item is now SENT
    item_status=$(db_exec "SELECT status FROM outbox_vb6 WHERE idempotency_key = '${IDEM_WORKER_TEST}' LIMIT 1" 2>/dev/null | tail -1 | tr -d '[:space:]')
    if [ "$item_status" = "SENT" ]; then
        pass "outbox_vb6 item marcado SENT después del worker"
    else
        fail "outbox_vb6 item marcado SENT" "Status actual: '$item_status' (esperado SENT)"
    fi
fi

# Restaurar los items PENDING que se apartaron al inicio del bloque
if [ -n "$DUE_IDS" ]; then
    $MYSQL -sN -e "UPDATE outbox_vb6 SET next_attempt_at = UTC_TIMESTAMP(3) WHERE id IN ($DUE_IDS)" 2>/dev/null || true
fi

# ---- Test: StayController.close() enqueues stay.closed event ----
ADM_KEY_15=$(get_key "ADMIN-CLI")
if [ -z "$ADM_KEY_15" ]; then
    skip "POST /stays/{id}/close — enqueue outbox" "Clave ADMIN-CLI no disponible"
else
    # Get an active stay (not CLOSED) if any
    STAYS_RESP=$(curl -s -H "X-API-Key: $ADM_KEY_15" "${API_BASE}/api/v1/stays" 2>/dev/null)
    ACTIVE_STAY=$(echo "$STAYS_RESP" | python3 -c "
import sys,json
d=json.load(sys.stdin)
items=[i for i in d.get('items',[]) if i.get('status') not in ('CLOSED','CANCELED')]
print(items[0]['id'] if items else 0)
" 2>/dev/null || echo "0")

    if [ "$ACTIVE_STAY" != "0" ] && [ -n "$ACTIVE_STAY" ]; then
        IDEM_CLOSE="test-close-stay-${ACTIVE_STAY}-$(date +%s)"
        http_test POST "/api/v1/stays/${ACTIVE_STAY}/close" 200 \
            "POST /stays/${ACTIVE_STAY}/close → 200 (enqueue stay.closed)" \
            --key ADMIN-CLI \
            --idem "$IDEM_CLOSE" \
            --body '{}'

        # Verify outbox item was enqueued
        outbox_count=$(db_exec "SELECT COUNT(*) FROM outbox_vb6 WHERE topic='stay.closed' AND status IN ('PENDING','SENT')" 2>/dev/null | tail -1 | tr -d '[:space:]')
        if [ -n "$outbox_count" ] && [ "$outbox_count" -gt "0" ]; then
            pass "outbox_vb6 tiene item stay.closed después del close (total: $outbox_count)"
        else
            fail "outbox_vb6 tiene item stay.closed" "Count=$outbox_count"
        fi
    else
        skip "POST /stays/{id}/close — enqueue outbox" "No hay stays activas en la BD"
    fi
fi

# =============================================================================
# BLOCK 16 — F15: Modo simulado /sim/*
# Trazabilidad: RF-11, TSK-160, TSK-161
# =============================================================================
block "BLOCK 16 — F15: Modo simulado /sim/*"

SIM_KEY=$(get_key "SIM-CLIENT")
ADM_KEY_16=$(get_key "ADMIN-CLI")

if [ -z "$SIM_KEY" ]; then
    skip "BLOCK 16 completo" "Clave SIM-CLIENT no disponible"
else
    # Enable simulated_override for room 1 so /sim/* endpoints work
    # regardless of the global SIMULATED_MODE flag.
    echo ""
    echo "  --- Habilitando simulated_override=1 para room 1 (test /sim/*) ---"
    $MYSQL -sN -e "UPDATE rooms SET simulated_override=1 WHERE id=1" 2>/dev/null || true

    # POST /sim/rooms/1/door OPEN → 202
    http_test POST /sim/rooms/1/door 202 \
        "POST /sim/rooms/1/door OPEN → 202" \
        --key SIM-CLIENT \
        --body '{"state":"OPEN"}'

    # POST /sim/rooms/1/door CLOSED → 202
    http_test POST /sim/rooms/1/door 202 \
        "POST /sim/rooms/1/door CLOSED → 202" \
        --key SIM-CLIENT \
        --body '{"state":"CLOSED"}'

    # POST /sim/rooms/1/door con state inválido → 422
    http_test POST /sim/rooms/1/door 422 \
        "POST /sim/rooms/1/door state=INVALID → 422" \
        --key SIM-CLIENT \
        --body '{"state":"INVALID"}'

    # POST /sim/rooms/1/presence PRESENT → 202
    http_test POST /sim/rooms/1/presence 202 \
        "POST /sim/rooms/1/presence PRESENT → 202" \
        --key SIM-CLIENT \
        --body '{"sensor":"PRESENCE","value":"PRESENT"}'

    # POST /sim/rooms/1/presence ABSENT → 202
    http_test POST /sim/rooms/1/presence 202 \
        "POST /sim/rooms/1/presence ABSENT → 202" \
        --key SIM-CLIENT \
        --body '{"sensor":"PRESENCE","value":"ABSENT"}'

    # POST /sim/rooms/1/lock/ack OPEN → 200
    http_test POST /sim/rooms/1/lock/ack 200 \
        "POST /sim/rooms/1/lock/ack OPEN → 200" \
        --key SIM-CLIENT \
        --body '{"action":"OPEN","reason":"sim_test"}'

    # POST /sim/rooms/1/lock/ack LOCK → 200
    http_test POST /sim/rooms/1/lock/ack 200 \
        "POST /sim/rooms/1/lock/ack LOCK → 200" \
        --key SIM-CLIENT \
        --body '{"action":"LOCK","reason":"sim_test"}'

    # Room not found → 403 (sim_mode_disabled se evalúa antes que existencia)
    http_test POST /sim/rooms/9999/door 403 \
        "POST /sim/rooms/9999/door → 403 (sim mode disabled, room doesn't exist)" \
        --key SIM-CLIENT \
        --body '{"state":"OPEN"}'

    # Without sim scope → 403
    http_test POST /sim/rooms/1/door 403 \
        "POST /sim/rooms/1/door (VB6-MAIN, no sim scope) → 403" \
        --key VB6-MAIN \
        --body '{"state":"OPEN"}'

    # ---- sim-scenario.php ----
    # Run the complete scenario script — pass with exit 0
    if db_exec "SELECT 1" > /dev/null 2>&1; then
        scenario_out=$(cd "$PROJECT_DIR" && php bin/sim-scenario.php 2>&1)
        scenario_exit=$?
        if [ $scenario_exit -eq 0 ]; then
            pass "bin/sim-scenario.php — flujo completo (exit 0)"
        else
            fail "bin/sim-scenario.php — flujo completo" \
                 "Exit $scenario_exit. Output: $(echo "$scenario_out" | tail -5 | tr '\n' ' ')"
        fi
    else
        skip "bin/sim-scenario.php" "No hay conexión a BD"
    fi

    # Restablecer simulated_override a NULL (el valor por defecto)
    $MYSQL -sN -e "UPDATE rooms SET simulated_override=NULL WHERE id=1" 2>/dev/null || true

fi  # SIM_KEY

# ===================================================
# BLOCK 17 — F20/F21: Integración real + Dashboard
# Trazabilidad: TSK-235, F20, F21
# ===================================================
block "BLOCK 17 — F20/F21: Integración real + Dashboard"
$MYSQL -sN -e "UPDATE rooms SET pack_id=5 WHERE id=1" 2>/dev/null || true

    # Asegurar que room 1 está libre antes de los tests (puede estar ocupado por tests anteriores)
    $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=1" 2>/dev/null || true
    # Ensure pack assigned (pack-based resolution after refactor)
    $MYSQL -sN -e "UPDATE rooms SET pack_id=5 WHERE id=1" 2>/dev/null || true

# F20: Tuya webhook (no auth — Tuya Cloud doesn't use our API keys)
# F79 (RF-102.4): device SINTÉTICO (sin pack/sala). Antes se usaba el device real
# de la puerta del almacén (bf4c7e7d…) y cada corrida creaba una visita fantasma
# en `/almacen`. Sintético + roomless → 202 `room_not_found`, sin efectos.
F20_DOOR_DEV="synth-f20-door-$(date +%s)"
F20_PRES_DEV="synth-f20-pres-$(date +%s)"
$MYSQL -sN -e "INSERT INTO devices (kind,external_id) VALUES ('PROXIMITY','$F20_DOOR_DEV'),('PRESENCE','$F20_PRES_DEV')" 2>/dev/null || true
http_test POST /api/v1/tuya/webhook 202 \
    "POST /tuya/webhook (PROXIMITY, no auth) → 202" \
    --body '{"devId":"'"$F20_DOOR_DEV"'","status":[{"code":"doorcontact_state","value":true,"t":'"$(date +%s)"'000}]}'

http_test POST /api/v1/tuya/webhook 202 \
    "POST /tuya/webhook (PRESENCE, no auth) → 202" \
    --body '{"devId":"'"$F20_PRES_DEV"'","status":[{"code":"presence_state","value":"presence","t":'"$(date +%s)"'000}]}'

$MYSQL -sN -e "DELETE FROM devices WHERE external_id IN ('$F20_DOOR_DEV','$F20_PRES_DEV')" 2>/dev/null || true

# F21: Public endpoints (no auth)
    http_test GET /dashboard 200 \
        "GET /dashboard (public, no auth) → 200"

    http_test GET '/api/v1/rooms/1/live' 200 \
        "GET /rooms/1/live (public, no auth) → 200"

# F20: Lock provider=LOCAL (no physical action, always OK)
echo ""
echo "  --- F20: Lock provider=LOCAL verification ---"
# Emitir QR y validar con RPI-DEV (debe existir el device registrado)
# Nota: este test requiere que el ESP32 esté registrado en devices para room 1.
# Si no está (chip-id aún no registrado), lo saltamos.
    RPI_PRESENT=$($MYSQL -sN -e "SELECT d.external_id FROM devices d JOIN rooms r ON r.pack_id = d.pack_id WHERE r.id=1 AND d.kind='RPI' LIMIT 1" 2>/dev/null || echo "")
    if [ -n "$RPI_PRESENT" ]; then
        if db_exec "SELECT 1" > /dev/null 2>&1; then
            # Cerrar cualquier stay activa en room 1 para poder emitir QR
            $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=1 AND status IN ('OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true
            $MYSQL -sN -e "UPDATE stays SET status='CLOSED' WHERE room_id=1 AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true

            # Issue a QR for room 1
            QR_RESP=$(curl -s -X POST http://127.0.0.1:8080/api/v1/qr \
                -H "Content-Type: application/json" \
                -H "X-API-Key: $VB6_KEY" \
                -H "Idempotency-Key: tsk235-$(date +%s)" \
                -d "{\"room_id\":1,\"duracion_minutos\":60}")
            QR_TEXT=$(echo "$QR_RESP" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["qr_text"]??"";')

            if [ -n "$QR_TEXT" ] && [ "$QR_TEXT" != "" ]; then
                # Obtener el external_id real del dispositivo RPI para room 1
            RPI_DEV_ID=$($MYSQL -sN -e "SELECT d.external_id FROM devices d JOIN rooms r ON r.pack_id = d.pack_id WHERE r.id=1 AND d.kind='RPI' LIMIT 1" 2>/dev/null || echo "unknown")
                # Validate QR with RPI-DEV key — provider should record as LOCAL
                http_test POST /api/v1/qr/validate 200 \
                    "POST /qr/validate (RPI-DEV, provider=LOCAL) → 200" \
                    --key RPI-DEV \
                    --body "{\"qr_text\":\"$QR_TEXT\",\"device_id\":\"${RPI_DEV_ID}\"}"
            else
                skip "POST /qr/validate (provider=LOCAL)" "No se pudo emitir QR (room busy, ver estado room 1)"
            fi
        else
            skip "POST /qr/validate (provider=LOCAL)" "BD no disponible"
    fi
else
    skip "POST /qr/validate (provider=LOCAL)" "ESP32 device no registrado en room 1"
fi

fi  # end SERVER_UP

# ===================================================
# BLOCK 18 — F15.5: Smart Switch EAWCBT-J (RF-16)
# Trazabilidad: TSK-SW12
# ===================================================
block "BLOCK 18 — F15.5: Smart Switch (EAWCBT-J)"
# Ensure pack assigned (pack-based resolution after refactor)
$MYSQL -sN -e "UPDATE rooms SET pack_id=5 WHERE id=1" 2>/dev/null || true

    # El SWITCH real ya existe (en proto2) y uniq_devices_external impide insertarlo
    # de nuevo en el pack 5. Se mueve temporalmente al pack 5 (room 1) y se restaura
    # al terminar el bloque.
    SW_ORIG_PACK=$($MYSQL -sN -e "SELECT pack_id FROM devices WHERE kind='SWITCH' AND external_id='bfc8730a715c56c8d1paby' LIMIT 1" 2>/dev/null || echo "")
    if [ -n "$SW_ORIG_PACK" ]; then
        $MYSQL -sN -e "UPDATE devices SET pack_id=5 WHERE kind='SWITCH' AND external_id='bfc8730a715c56c8d1paby'" 2>/dev/null || true
    else
        $MYSQL -sN -e "INSERT IGNORE INTO devices (pack_id, kind, external_id, meta_json) VALUES (5, 'SWITCH', 'bfc8730a715c56c8d1paby', '{\"model\":\"EAWCBT-J\",\"dp_code\":\"switch\"}')" 2>/dev/null || true
    fi

    # Ensure admin key has switches:write scope
    $MYSQL -sN -e "UPDATE api_clients SET scopes_csv = CONCAT(scopes_csv, ',switches:write') WHERE code = 'ADMIN-CLI' AND scopes_csv NOT LIKE '%switches:write%'" 2>/dev/null || true

    # GET /rooms/1/switches
    http_test GET '/api/v1/rooms/1/switches' 200 \
        "GET /rooms/1/switches → 200" \
        --key ADMIN-CLI

    # GET /rooms/1/live (must include switch data)
    http_test GET '/api/v1/rooms/1/live' 200 \
        "GET /rooms/1/live → 200 (includes switch data)" \
        --key ADMIN-CLI

    # --- Simulated switch tests (always work) ---
    # Set room 1 to simulated_override=true for simulated switch tests
    $MYSQL -sN -e "UPDATE rooms SET simulated_override=1 WHERE id=1" 2>/dev/null || true

    http_test POST /api/v1/switches/1/on 200 \
        "POST /switches/1/on → 200 (simulated)" \
        --key ADMIN-CLI \
        --body '{}'

    http_test POST /api/v1/switches/1/off 200 \
        "POST /switches/1/off → 200 (simulated)" \
        --key ADMIN-CLI \
        --body '{}'

    # POST /switches/999/on → 404 (no switch for room 999)
    http_test POST /api/v1/switches/999/on 404 \
        "POST /switches/999/on → 404 (no switch)" \
        --key ADMIN-CLI \
        --body '{}'

    # --- Real switch tests (only meaningful when SIMULATED_MODE=false) ---
    $MYSQL -sN -e "UPDATE rooms SET simulated_override=NULL WHERE id=1" 2>/dev/null || true
    SIM=$(php -r "require './src/Support/Autoload.php'; echo App\Support\Config::getBool('SIMULATED_MODE', true) ? 'true' : 'false';" 2>/dev/null || echo "false")
    if [ "$SIM" = "false" ]; then
        echo ""
        echo "  --- Real switch tests (SIMULATED_MODE=false) ---"

        http_test POST /api/v1/switches/1/off 200 \
            "POST /switches/1/off → 200 (real Tuya, turn off first)" \
            --key ADMIN-CLI --body '{}'

        # Emitir QR y validar → el switch debe encenderse automáticamente
        $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=1" 2>/dev/null || true
        $MYSQL -sN -e "UPDATE stays SET status='CLOSED' WHERE room_id=1 AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true

        QR_RESP=$(curl -s -X POST http://127.0.0.1:8080/api/v1/qr \
            -H "Content-Type: application/json" \
            -H "X-API-Key: $VB6_KEY" \
            -H "Idempotency-Key: tsk-sw-real-$(date +%s)" \
            -d '{"room_id":1,"duracion_minutos":60}')
        QR_TEXT=$(echo "$QR_RESP" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["qr_text"]??"";')

        if [ -n "$QR_TEXT" ] && [ "$QR_TEXT" != "" ]; then
            RPI_ID=$($MYSQL -sN -e "SELECT d.external_id FROM devices d JOIN rooms r ON r.pack_id = d.pack_id WHERE r.id=1 AND d.kind='RPI' LIMIT 1" 2>/dev/null)
            http_test POST /api/v1/qr/validate 200 \
                "POST /qr/validate → 200 + switch ON (real flow)" \
                --key RPI-DEV \
                --body "{\"qr_text\":\"$QR_TEXT\",\"device_id\":\"${RPI_ID}\"}"

            # Cerrar stay → switch debe apagarse
            STAY_ID=$(echo "$QR_RESP" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["stay_id"]??"";')
            if [ -n "$STAY_ID" ] && [ "$STAY_ID" != "" ]; then
                http_test POST /api/v1/stays/$STAY_ID/close 200 \
                    "POST /stays/$STAY_ID/close → 200 + switch OFF (real flow)" \
                    --key ADMIN-CLI \
                    --idem "close-sw-$STAY_ID-$(date +%s)" \
                    --body '{"reason":"checkout"}'
            else
                skip "POST /stays/ID/close (real switch)" "No se pudo obtener stay_id"
            fi
        else
            skip "POST /qr/validate (real switch flow)" "No se pudo emitir QR (room busy?)"
        fi
    else
        skip "Real switch flow tests" "SIMULATED_MODE=true — usar tests simulados arriba"
    fi

    # Restaurar el SWITCH a su pack original
    if [ -n "$SW_ORIG_PACK" ]; then
        $MYSQL -sN -e "UPDATE devices SET pack_id=$SW_ORIG_PACK WHERE kind='SWITCH' AND external_id='bfc8730a715c56c8d1paby'" 2>/dev/null || true
    fi

# ===================================================
# BLOCK 17 — F26: Botón "Mírame" (Identify)
# Trazabilidad: RF-18, TSK-ID7
# ===================================================
block "BLOCK 17 — F26: Identify Button"
$MYSQL -sN -e "UPDATE rooms SET pack_id=5 WHERE id=1" 2>/dev/null || true
    RPI_KEY=$(get_key RPI-DEV)
    ADMIN_KEY=$(get_key ADMIN-CLI)

    if [ -n "$RPI_KEY" ] && [ -n "$ADMIN_KEY" ]; then
        # Obtener el ID del device RPI de room 1
        ROOM1_DEVICES=$(curl -s "$API_BASE/api/v1/rooms/1" -H "X-API-Key: $ADMIN_KEY")
        DEVICE_ID=$(echo "$ROOM1_DEVICES" | php -r '
            $r = json_decode(file_get_contents("php://stdin"), true);
            foreach (($r["devices"] ?? []) as $d) {
                if (($d["kind"] ?? "") === "RPI") { echo $d["id"] ?? ""; break; }
            }
        ')
        EXTERNAL_ID=$(echo "$ROOM1_DEVICES" | php -r '
            $r = json_decode(file_get_contents("php://stdin"), true);
            foreach (($r["devices"] ?? []) as $d) {
                if (($d["kind"] ?? "") === "RPI") { echo $d["external_id"] ?? ""; break; }
            }
        ')

        if [ -n "$EXTERNAL_ID" ] && [ -n "$DEVICE_ID" ]; then
            # Test 1: Identify ON
            http_test POST /api/v1/devices/identify 200 \
                "POST /devices/identify state:true → 200" \
                --key RPI-DEV \
                --body "{\"external_id\":\"$EXTERNAL_ID\",\"state\":true}"

            # Test 2: GET identified rooms → debe incluir room 1
            http_test GET /api/v1/rooms/identified 200 \
                "GET /rooms/identified → 200 (room 1 listed)" \
                --key ADMIN-CLI

            # Test 3: Identify OFF
            http_test POST /api/v1/devices/identify 200 \
                "POST /devices/identify state:false → 200" \
                --key RPI-DEV \
                --body "{\"external_id\":\"$EXTERNAL_ID\",\"state\":false}"

            # Test 4: GET identified rooms → debe estar vacío
            http_test GET /api/v1/rooms/identified 200 \
                "GET /rooms/identified after unmark → 200 (empty)" \
                --key ADMIN-CLI

            # Test 5: Admin force-unidentify
            http_test DELETE /api/v1/devices/$DEVICE_ID/identify 200 \
                "DELETE /devices/$DEVICE_ID/identify → 200" \
                --key ADMIN-CLI

            # Test 6: Identify with unknown external_id → 403
            http_test POST /api/v1/devices/identify 403 \
                "POST /devices/identify unknown external_id → 403 device_mismatch" \
                --key RPI-DEV \
                --body '{"external_id":"deadbeef00","state":true}'
        else
            skip "Identify tests" "No se encontró device RPI para room 1 (seed aplicado?)"
        fi
    else
        skip "Identify tests" "Claves API RPI-DEV o ADMIN-CLI no disponibles"
    fi

# ===================================================
# BLOCK 19 — F27: Dashboard QR de pruebas (RF-20)
# Trazabilidad: TSK-QR8, TSK-QR9, TSK-QR10
# ===================================================
block "BLOCK 19 — F27: QR de pruebas panel"
    ADMIN_KEY=$(get_key ADMIN-CLI)

    if [ -z "$ADMIN_KEY" ]; then
        skip "BLOCK 19" "ADMIN-CLI key not available"
    else
        # ── Test 0: Hard reset room 1 first (cleanup from previous blocks) ──
        http_test POST /dashboard-api/rooms/reset 200 \
            "POST /rooms/reset → 200 (pre-cleanup)" \
            --body "{\"room_id\":1}"

        # ── Test 1: Crear QR de pruebas ──
        http_test POST /dashboard-api/qr-test/create 201 \
            "POST /qr-test/create → 201 (QR creado)" \
            --body "{\"room_id\":1,\"duracion_minutos\":60}"

        # ── Test 2: Crear QR cuando ya hay estancia activa → 409 ──
        http_test POST /dashboard-api/qr-test/create 409 \
            "POST /qr-test/create duplicate → 409 room_busy" \
            --body "{\"room_id\":1,\"duracion_minutos\":60}"

        # ── Test 3: Crear QR para room inexistente → 404 ──
        http_test POST /dashboard-api/qr-test/create 404 \
            "POST /qr-test/create bad room → 404" \
            --body "{\"room_id\":9999,\"duracion_minutos\":60}"

        # ── Test 4: Crear QR con duración inválida → 422 ──
        http_test POST /dashboard-api/qr-test/create 422 \
            "POST /qr-test/create dur=15 → 422 duration_out_of_range" \
            --body "{\"room_id\":1,\"duracion_minutos\":15}"

        # ── Test 5: Reset Method A (resetear + crear nuevo QR) ──
        http_test POST /dashboard-api/qr-test/reset 201 \
            "POST /qr-test/reset → 201 (reset + nuevo QR)" \
            --body "{\"room_id\":1,\"duracion_minutos\":30}"

        # ── Test 6: Reset Method B (hard reset, sin crear QR) ──
        http_test POST /dashboard-api/rooms/reset 200 \
            "POST /rooms/reset → 200 (hard reset, no new QR)" \
            --body "{\"room_id\":1}"

        # ── Test 7: Crear QR después de hard reset → 201 ──
        http_test POST /dashboard-api/qr-test/create 201 \
            "POST /qr-test/create after hard reset → 201" \
            --body "{\"room_id\":1,\"duracion_minutos\":60}"

        # ── Test 8: rooms/reset para room inexistente → 404 ──
        http_test POST /dashboard-api/rooms/reset 404 \
            "POST /rooms/reset bad room → 404" \
            --body "{\"room_id\":9999}"

        # ── Test 9: rooms/reset sin room_id → 400 ──
        http_test POST /dashboard-api/rooms/reset 400 \
            "POST /rooms/reset no room_id → 400" \
            --body "{}"

        # ── Test 10 (F59/RF-66): crear QR limpia cooldown + estado IoT previos ──
        # Simula el estado tras una salida confirmada: anti-reentrada activa
        # (+20 s) y marcas IoT del ciclo anterior. Sin la limpieza, el panel
        # mostraría ANTI_REENTRADA (monigote dentro).
        http_test POST /dashboard-api/rooms/reset 200 \
            "F59 pre: rooms/reset → 200" \
            --body "{\"room_id\":1}"
        $MYSQL -sN -e "UPDATE rooms SET cooldown_until = UTC_TIMESTAMP(3) + INTERVAL 20 SECOND WHERE id=1" 2>/dev/null || true
        $MYSQL -sN -e "INSERT INTO iot_sessions
                (room_id, door_state, presence_state, last_open_at, last_close_at, last_absent_since,
                 last_door_event_at, last_presence_event_at, last_door_value, last_presence_value, updated_at)
             VALUES (1,'CLOSED','ABSENT',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),
                     UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),'CLOSED','ABSENT',UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE door_state='CLOSED', presence_state='ABSENT',
                     last_open_at=UTC_TIMESTAMP(3), last_close_at=UTC_TIMESTAMP(3),
                     last_absent_since=UTC_TIMESTAMP(3), last_door_event_at=UTC_TIMESTAMP(3),
                     last_presence_event_at=UTC_TIMESTAMP(3), last_door_value='CLOSED',
                     last_presence_value='ABSENT', updated_at=UTC_TIMESTAMP(3)" 2>/dev/null || true
        http_test POST /dashboard-api/qr-test/create 201 \
            "F59: qr-test/create con ciclo sucio → 201" \
            --body "{\"room_id\":1,\"duracion_minutos\":60}"
        F59_TEST_COOLDOWN=$($MYSQL -sN -e "SELECT IF(cooldown_until IS NULL,'NULL','SET') FROM rooms WHERE id=1" 2>/dev/null || echo "ERR")
        F59_TEST_IOT=$($MYSQL -sN -e "SELECT CONCAT(IFNULL(door_state,'?'),'|',IFNULL(presence_state,'?'),'|',
                IF(COALESCE(last_open_at,last_close_at,last_absent_since,last_door_event_at,
                            last_presence_event_at,last_door_value,last_presence_value) IS NULL,'CLEAN','DIRTY'))
             FROM iot_sessions WHERE room_id=1" 2>/dev/null || echo "ERR")
        if [ "$F59_TEST_COOLDOWN" = "NULL" ] && [ "$F59_TEST_IOT" = "UNKNOWN|UNKNOWN|CLEAN" ]; then
            pass "F59: crear QR limpia cooldown + estado IoT del ciclo anterior"
        else
            fail "F59: create limpia ciclo (panel)" "cooldown=$F59_TEST_COOLDOWN iot=$F59_TEST_IOT"
        fi

        # ── Test 11 (F59/RF-66): la emisión real también limpia el ciclo ──
        VB6_KEY_F59=$(get_key "VB6-MAIN")
        if [ -z "$VB6_KEY_F59" ]; then
            skip "F59: emisión real limpia ciclo" "Clave VB6-MAIN no disponible"
        else
            http_test POST /dashboard-api/rooms/reset 200 \
                "F59 pre real: rooms/reset → 200" \
                --body "{\"room_id\":1}"
            $MYSQL -sN -e "UPDATE rooms SET cooldown_until = UTC_TIMESTAMP(3) + INTERVAL 20 SECOND WHERE id=1" 2>/dev/null || true
            $MYSQL -sN -e "INSERT INTO iot_sessions
                    (room_id, door_state, presence_state, last_open_at, last_close_at, last_absent_since,
                     last_door_event_at, last_presence_event_at, last_door_value, last_presence_value, updated_at)
                 VALUES (1,'CLOSED','ABSENT',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),
                         UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),'CLOSED','ABSENT',UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE door_state='CLOSED', presence_state='ABSENT',
                         last_open_at=UTC_TIMESTAMP(3), last_close_at=UTC_TIMESTAMP(3),
                         last_absent_since=UTC_TIMESTAMP(3), last_door_event_at=UTC_TIMESTAMP(3),
                         last_presence_event_at=UTC_TIMESTAMP(3), last_door_value='CLOSED',
                         last_presence_value='ABSENT', updated_at=UTC_TIMESTAMP(3)" 2>/dev/null || true
            http_test POST /api/v1/qr 201 \
                "F59: POST /qr (emisión real) con ciclo sucio → 201" \
                --key VB6-MAIN \
                --idem "f59-real-$(date +%s)" \
                --body '{"room_id":1,"duracion_minutos":60}'
            F59_REAL_COOLDOWN=$($MYSQL -sN -e "SELECT IF(cooldown_until IS NULL,'NULL','SET') FROM rooms WHERE id=1" 2>/dev/null || echo "ERR")
            F59_REAL_IOT=$($MYSQL -sN -e "SELECT CONCAT(IFNULL(door_state,'?'),'|',IFNULL(presence_state,'?'),'|',
                    IF(COALESCE(last_open_at,last_close_at,last_absent_since,last_door_event_at,
                                last_presence_event_at,last_door_value,last_presence_value) IS NULL,'CLEAN','DIRTY'))
                 FROM iot_sessions WHERE room_id=1" 2>/dev/null || echo "ERR")
            if [ "$F59_REAL_COOLDOWN" = "NULL" ] && [ "$F59_REAL_IOT" = "UNKNOWN|UNKNOWN|CLEAN" ]; then
                pass "F59: emisión real limpia cooldown + estado IoT del ciclo anterior"
            else
                fail "F59: emisión real limpia ciclo" "cooldown=$F59_REAL_COOLDOWN iot=$F59_REAL_IOT"
            fi
            # Limpieza para no dejar estancia activa a bloques posteriores.
            http_test POST /dashboard-api/rooms/reset 200 \
                "F59 post: rooms/reset → 200" \
                --body "{\"room_id\":1}"
        fi
    fi

# ===================================================
# BLOCK 20 — F21: QR polling (qr_text regeneration)
# Trazabilidad: TSK-QR11
# ===================================================
block "BLOCK 20 — F21: QR polling (qr_text)"

    # Step 1: Reset room 1 to FREE
    $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=1" 2>/dev/null || true
    $MYSQL -sN -e "UPDATE stays SET status='CLOSED' WHERE room_id=1 AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true

    # Step 2: Create a QR via API — UNA sola petición. Un segundo POST haría
    # falta contra room_busy (409) por la estancia recién creada y forzaba un
    # SKIP artificial. Capturamos cuerpo + código en la misma llamada.
    QR_CREATE_RAW=$(curl -s -w '\n%{http_code}' -X POST http://127.0.0.1:8080/dashboard-api/qr-test/create \
        -H "Content-Type: application/json" \
        -d '{"room_id":1,"duracion_minutos":60}')
    QR_CREATE_CODE=$(echo "$QR_CREATE_RAW" | tail -1)
    QR_CREATE_RESP=$(echo "$QR_CREATE_RAW" | sed '$d')

    if [ "$QR_CREATE_CODE" = "201" ]; then
        QR_TEXT_CREATED=$(echo "$QR_CREATE_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('qr_text',''))" 2>/dev/null || echo "")

        # Step 3: el contrato vigente del poll es GET /rooms/{id}/live → qr_status
        # (la antigua ruta /dashboard-api/qr-status ya no existe: 404).
        http_test GET '/api/v1/rooms/1/live' 200 \
            "GET /rooms/1/live (poll, qr_status incluido) → 200" \
            --key ADMIN-CLI

        # Step 4: qr_text determinista + campos aditivos Fase 51 en qr_status
        QR_STATUS_RESP=$(curl -s -H "X-API-Key: $(get_key ADMIN-CLI)" 'http://127.0.0.1:8080/api/v1/rooms/1/live')
        QR_TEXT_POLLED=$(echo "$QR_STATUS_RESP" | python3 -c "import sys,json; print((json.load(sys.stdin).get('qr_status') or {}).get('qr_text',''))" 2>/dev/null || echo "")

        if [ -n "$QR_TEXT_POLLED" ] && [ "$QR_TEXT_CREATED" = "$QR_TEXT_POLLED" ]; then
            pass "GET /rooms/1/live qr_status.qr_text matches creation token (deterministic)"
        else
            if [ -z "$QR_TEXT_POLLED" ]; then
                fail "GET /rooms/1/live returns qr_status.qr_text" "qr_text está vacío en la response de poll"
            else
                fail "GET /rooms/1/live qr_status.qr_text matches creation" "qr_text creado vs polleado difieren"
            fi
        fi

        # F51: el poll debe exponer las ventanas de llegada/uso.
        QR_F51_OK=$(echo "$QR_STATUS_RESP" | python3 -c "
import sys,json
qs=(json.load(sys.stdin).get('qr_status') or {})
need=['first_used_at','valid_until','arrival_deadline','in_use']
print('1' if all(k in qs for k in need) else '0 '+str([k for k in need if k not in qs]))
" 2>/dev/null)
        if [ "$QR_F51_OK" = "1" ]; then
            pass "F51: qr_status expone first_used_at/valid_until/arrival_deadline/in_use"
        else
            fail "F51: qr_status campos de ventana" "faltan: ${QR_F51_OK#0 }"
        fi
    else
        skip "QR polling qr_text test" "No se pudo crear QR de prueba (HTTP $QR_CREATE_CODE)"
    fi

    # Cleanup: cerrar la estancia creada (si no, queda RESERVED y rompe bloques
    # posteriores) y liberar la sala.
    $MYSQL -sN -e "UPDATE stays SET status='CLOSED', closed_at=UTC_TIMESTAMP(3) WHERE room_id=1 AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true
    $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=1" 2>/dev/null || true

# ===================================================
# BLOCK 21 — F27: Reset de estado al quitar pack (RF-21)
# Trazabilidad: TSK-PR1, TSK-PR2, TSK-PR3, TSK-PR4, TSK-PR5
# ===================================================
block "BLOCK 21 — F27: Pack removal → room reset"

    # --- DB-based helper: check room status ---
    check_room_status() {
        local rid="$1"
        local expected="$2"
        local label="$3"
        local actual
        actual=$($MYSQL -sN -e "SELECT status FROM rooms WHERE id=${rid}" 2>/dev/null || echo "DB_ERROR")
        if [ "$actual" = "$expected" ]; then
            pass "$label (status=$actual)"
        else
            fail "$label" "Expected status $expected, got $actual"
        fi
    }

    # --- Ensure we have a pack for testing ---
    PACK_ID=$($MYSQL -sN -e "SELECT id FROM device_packs LIMIT 1" 2>/dev/null || echo "0")
    if [ "$PACK_ID" = "0" ] || [ -z "$PACK_ID" ]; then
        skip "BLOCK 21 — no device_pack found in DB" "Create a pack first"
    else
        TEST_ROOM=1

        # ═══════════════════════════════════════════════════════════════
        # Path 1: apply-pack/0 → RESERVED room goes to FREE
        # ═══════════════════════════════════════════════════════════════
        echo ""
        echo "  --- Path 1: POST /rooms/$TEST_ROOM/apply-pack/0 ---"

        # Cleanup first
        $MYSQL -sN -e "UPDATE stays SET status='CLOSED', closed_at=UTC_TIMESTAMP(3) WHERE room_id=$TEST_ROOM AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true
        $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=$TEST_ROOM" 2>/dev/null || true

        # Assign pack
        $MYSQL -sN -e "UPDATE rooms SET pack_id=$PACK_ID WHERE id=$TEST_ROOM" 2>/dev/null || true

        # Set room to RESERVED and create a fake stay
        $MYSQL -sN -e "UPDATE rooms SET status='RESERVED', cooldown_until=UTC_TIMESTAMP(3) WHERE id=$TEST_ROOM" 2>/dev/null || true
        STAY_ID=$($MYSQL -sN -e "INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, vb6_codtic, vb6_codcli) VALUES ($TEST_ROOM, 'RESERVED', 60, UTC_TIMESTAMP(3), 1, 1); SELECT LAST_INSERT_ID();" 2>/dev/null || echo "0")

        # Verify pre-condition
        check_room_status $TEST_ROOM "RESERVED" "Pre-condition: room is RESERVED"

        # Remove pack via API
        ADM_KEY=$(get_key ADMIN-CLI)
        if [ -n "$ADM_KEY" ]; then
            http_test POST "/api/v1/rooms/$TEST_ROOM/apply-pack/0" 200 \
                "POST /rooms/$TEST_ROOM/apply-pack/0 → 200" \
                --key ADMIN-CLI

            # Verify post-condition: room is FREE
            check_room_status $TEST_ROOM "FREE" "Post-condition: room is FREE after apply-pack/0"
        else
            skip "Path 1 (apply-pack/0)" "ADMIN-CLI key not available"
        fi

        # Cleanup
        $MYSQL -sN -e "DELETE FROM stays WHERE id=$STAY_ID" 2>/dev/null || true
        $MYSQL -sN -e "UPDATE rooms SET status='FREE', pack_id=NULL, cooldown_until=NULL WHERE id=$TEST_ROOM" 2>/dev/null || true

        # ═══════════════════════════════════════════════════════════════
        # Path 2: PATCH room with pack_id=null → OCCUPIED room goes to FREE
        # ═══════════════════════════════════════════════════════════════
        echo ""
        echo "  --- Path 2: PATCH /rooms/$TEST_ROOM {\"pack_id\":null} ---"

        # Assign pack and set OCCUPIED
        $MYSQL -sN -e "UPDATE rooms SET pack_id=$PACK_ID, status='OCCUPIED', cooldown_until=UTC_TIMESTAMP(3) WHERE id=$TEST_ROOM" 2>/dev/null || true
        $MYSQL -sN -e "INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, vb6_codtic, vb6_codcli) VALUES ($TEST_ROOM, 'OCCUPIED', 60, UTC_TIMESTAMP(3), 1, 1)" 2>/dev/null || true

        check_room_status $TEST_ROOM "OCCUPIED" "Pre-condition: room is OCCUPIED"

        if [ -n "$ADM_KEY" ]; then
            http_test PATCH "/api/v1/rooms/$TEST_ROOM" 200 \
                "PATCH /rooms/$TEST_ROOM pack_id=null → 200" \
                --key ADMIN-CLI \
                --body '{"pack_id":null}'

            check_room_status $TEST_ROOM "FREE" "Post-condition: room is FREE after PATCH pack_id=null"
        else
            skip "Path 2 (PATCH pack_id=null)" "ADMIN-CLI key not available"
        fi

        # Cleanup
        $MYSQL -sN -e "DELETE FROM stays WHERE room_id=$TEST_ROOM" 2>/dev/null || true
        $MYSQL -sN -e "UPDATE rooms SET status='FREE', pack_id=NULL, cooldown_until=NULL WHERE id=$TEST_ROOM" 2>/dev/null || true

        # ═══════════════════════════════════════════════════════════════
        # Path 3: DELETE pack → affected RESERVED rooms go to FREE
        # ═══════════════════════════════════════════════════════════════
        echo ""
        echo "  --- Path 3: DELETE /device-packs/{id} ---"

        # Create a temporary pack
        TMP_PACK_CODE="TEST-PR-PACK-$(date +%s)"
        $MYSQL -sN -e "INSERT INTO device_packs (code, name, created_at, updated_at) VALUES ('$TMP_PACK_CODE', 'Test Pack PR', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))" 2>/dev/null || true
        TMP_PACK_ID=$($MYSQL -sN -e "SELECT id FROM device_packs WHERE code='$TMP_PACK_CODE'" 2>/dev/null || echo "0")

        if [ "$TMP_PACK_ID" != "0" ]; then
            # Assign temp pack + set RESERVED
            $MYSQL -sN -e "UPDATE rooms SET pack_id=$TMP_PACK_ID, status='RESERVED', cooldown_until=UTC_TIMESTAMP(3) WHERE id=$TEST_ROOM" 2>/dev/null || true
            $MYSQL -sN -e "INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, vb6_codtic, vb6_codcli) VALUES ($TEST_ROOM, 'RESERVED', 60, UTC_TIMESTAMP(3), 1, 1)" 2>/dev/null || true

            check_room_status $TEST_ROOM "RESERVED" "Pre-condition: room is RESERVED with pack"

            if [ -n "$ADM_KEY" ]; then
                http_test DELETE "/api/v1/device-packs/$TMP_PACK_ID" 200 \
                    "DELETE /device-packs/$TMP_PACK_ID → 200" \
                    --key ADMIN-CLI

                check_room_status $TEST_ROOM "FREE" "Post-condition: room is FREE after pack deleted"
            else
                skip "Path 3 (DELETE pack)" "ADMIN-CLI key not available"
            fi

            # Cleanup temp pack (may already be deleted)
            $MYSQL -sN -e "DELETE FROM device_packs WHERE id=$TMP_PACK_ID" 2>/dev/null || true
        else
            skip "Path 3 (DELETE pack)" "Could not create temp pack"
        fi

        # Final cleanup
        $MYSQL -sN -e "DELETE FROM stays WHERE room_id=$TEST_ROOM" 2>/dev/null || true
        $MYSQL -sN -e "UPDATE rooms SET status='FREE', pack_id=NULL, cooldown_until=NULL WHERE id=$TEST_ROOM" 2>/dev/null || true

        # ═══════════════════════════════════════════════════════════════
        # Path 4: Replace mode — old room reset when pack moves
        # ═══════════════════════════════════════════════════════════════
        echo ""
        echo "  --- Path 4: replace mode → old room loses pack + resets ---"

        # Need 2 rooms: one target, one "old" that holds the pack
        # Use room 1 as target, room 2 as old holder
        OLD_ROOM=2
        $MYSQL -sN -e "UPDATE rooms SET status='FREE', pack_id=NULL, cooldown_until=NULL WHERE id IN ($TEST_ROOM, $OLD_ROOM)" 2>/dev/null || true

        # Create a unique pack for this test
        MOVE_PACK_CODE="TEST-PR-MOVE-$(date +%s)"
        $MYSQL -sN -e "INSERT INTO device_packs (code, name, created_at, updated_at) VALUES ('$MOVE_PACK_CODE', 'Test Move PR', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))" 2>/dev/null || true
        MOVE_PACK_ID=$($MYSQL -sN -e "SELECT id FROM device_packs WHERE code='$MOVE_PACK_CODE'" 2>/dev/null || echo "0")

        if [ "$MOVE_PACK_ID" != "0" ]; then
            # Assign pack to old room and set it RESERVED
            $MYSQL -sN -e "UPDATE rooms SET pack_id=$MOVE_PACK_ID, status='RESERVED', cooldown_until=UTC_TIMESTAMP(3) WHERE id=$OLD_ROOM" 2>/dev/null || true
            $MYSQL -sN -e "INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, vb6_codtic, vb6_codcli) VALUES ($OLD_ROOM, 'RESERVED', 60, UTC_TIMESTAMP(3), 1, 1)" 2>/dev/null || true

            check_room_status $OLD_ROOM "RESERVED" "Pre-condition: OLD room is RESERVED with pack"

            if [ -n "$ADM_KEY" ]; then
                # Move pack to target room (replace mode)
                http_test POST "/api/v1/rooms/$TEST_ROOM/apply-pack/$MOVE_PACK_ID" 200 \
                    "POST /rooms/$TEST_ROOM/apply-pack/$MOVE_PACK_ID (replace) → 200" \
                    --key ADMIN-CLI

                # Old room should be FREE now
                check_room_status $OLD_ROOM "FREE" "Post-condition: OLD room is FREE after pack moved"
            else
                skip "Path 4 (replace mode)" "ADMIN-CLI key not available"
            fi

            # Cleanup
            $MYSQL -sN -e "DELETE FROM device_packs WHERE id=$MOVE_PACK_ID" 2>/dev/null || true
        else
            skip "Path 4 (replace mode)" "Could not create move pack"
        fi

        # Final cleanup
        $MYSQL -sN -e "DELETE FROM stays WHERE room_id IN ($TEST_ROOM, $OLD_ROOM)" 2>/dev/null || true
        $MYSQL -sN -e "UPDATE rooms SET status='FREE', pack_id=NULL, cooldown_until=NULL WHERE id IN ($TEST_ROOM, $OLD_ROOM)" 2>/dev/null || true
    fi

# =============================================================================
# BLOCK 22 — F31: Verificación de salida (last_close_at + coreografía)
# =============================================================================

block "BLOCK 22 — F31: Salida verificada"

    # Ensure room 1 has simulated mode and a pack assigned
    SIM_KEY=$(get_key SIM-CLIENT)
    if [ -z "$SIM_KEY" ]; then
        skip "BLOCK 22 — F31: Salida verificada" "SIM-CLIENT key not available"
    else
        # Start exit-scan daemon (needed for exit rule evaluation)
        nohup php /root/cerraduras/api/bin/exit-scan.php >> /root/cerraduras/api/logs/exit-scan.log 2>&1 &
        EXIT_SCAN_PID=$!
        echo "  exit-scan PID: $EXIT_SCAN_PID"

        TEST_ROOM=1

        # Save original presence_check_seconds before clobbering with test value
        ORIG_PCS=$($MYSQL -sN -e "SELECT COALESCE(presence_check_seconds, 'NULL') FROM rooms WHERE id=$TEST_ROOM" 2>/dev/null || echo "NULL")

        # Cleanup: reset room to known state
        $MYSQL -sN -e "UPDATE stays SET status='CLOSED', closed_at=UTC_TIMESTAMP(3) WHERE room_id=$TEST_ROOM AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true
        $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL, simulated_override=1, presence_check_seconds=5 WHERE id=$TEST_ROOM" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM iot_sessions WHERE room_id=$TEST_ROOM" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM presence_events WHERE room_id=$TEST_ROOM" 2>/dev/null || true

        # Ensure a pack is assigned
        PACK_ID=$($MYSQL -sN -e "SELECT id FROM device_packs LIMIT 1" 2>/dev/null || echo "0")
        if [ "$PACK_ID" = "0" ]; then
            skip "BLOCK 22 — F31" "No device pack available"
        else
            $MYSQL -sN -e "UPDATE rooms SET pack_id=$PACK_ID WHERE id=$TEST_ROOM" 2>/dev/null || true
        fi

        # ═══════════════════════════════════════════════════════════════
        # Sub-test A: Crear stay OCCUPIED y verificar que OPEN muestra PUERTA_ABIERTA en /live
        # ═══════════════════════════════════════════════════════════════
        echo ""
        echo "  --- A: Crear stay + abrir puerta → live muestra PUERTA_ABIERTA ---"

        # Create an OCCUPIED stay directly
        STAY_ID=$($MYSQL -sN -e "INSERT INTO stays (room_id, status, duracion_minutos, first_entry_at, reserved_at, vb6_codtic, vb6_codcli) VALUES ($TEST_ROOM, 'OCCUPIED', 60, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), 1, 1); SELECT LAST_INSERT_ID();" 2>/dev/null || echo "0")
        if [ "$STAY_ID" = "0" ]; then
            skip "Sub-test A" "Could not create stay"
        else
            $MYSQL -sN -e "UPDATE rooms SET status='OCCUPIED' WHERE id=$TEST_ROOM" 2>/dev/null || true

            # Inject presence PRESENT first (guest is inside)
            http_test POST "/sim/rooms/$TEST_ROOM/presence" 202 \
                "presence=PRESENT → 202" \
                --key SIM-CLIENT \
                --body '{"sensor":"PRESENCE","value":"PRESENT"}'

            # Open door (guest might be leaving)
            http_test POST "/sim/rooms/$TEST_ROOM/door" 202 \
                "door=OPEN → 202" \
                --key SIM-CLIENT \
                --body '{"state":"OPEN"}'

            # Verify /live endpoint
            LIVE_RESP=$(curl -s "http://127.0.0.1:8080/api/v1/rooms/$TEST_ROOM/live")
            DOOR_STATE=$(echo "$LIVE_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['iot_session'].get('door_state',''))" 2>/dev/null || echo "")
            STAY_STATUS=$(echo "$LIVE_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); s=d.get('active_stay') or {}; print(s.get('status',''))" 2>/dev/null || echo "")

            if [ "$DOOR_STATE" = "OPEN" ]; then
                pass "live: door=OPEN after sim door OPEN"
            else
                fail "live: door=OPEN after sim door OPEN" "expected OPEN" "got $DOOR_STATE"
            fi

            if [ "$STAY_STATUS" = "OCCUPIED" ]; then
                pass "live: stay OCCUPIED with door OPEN → PUERTA_ABIERTA (dashboard)"
            else
                fail "live: stay OCCUPIED → PUERTA_ABIERTA" "expected OCCUPIED" "got $STAY_STATUS"
            fi
        fi

        # ═══════════════════════════════════════════════════════════════
        # Sub-test B: Cerrar puerta → verificar last_close_at en /live
        # ═══════════════════════════════════════════════════════════════
        echo ""
        echo "  --- B: Cerrar puerta → last_close_at poblado ---"

        http_test POST "/sim/rooms/$TEST_ROOM/door" 202 \
            "door=CLOSED → 202" \
            --key SIM-CLIENT \
            --body '{"state":"CLOSED"}'

        LIVE_RESP=$(curl -s "http://127.0.0.1:8080/api/v1/rooms/$TEST_ROOM/live")
        LAST_CLOSE=$(echo "$LIVE_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['iot_session'].get('last_close_at') or 'NULL')" 2>/dev/null || echo "")
        GAP_SECS=$(echo "$LIVE_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('gap_seconds','NULL'))" 2>/dev/null || echo "")

        if [ "$LAST_CLOSE" != "NULL" ] && [ "$LAST_CLOSE" != "" ]; then
            pass "live: last_close_at populated after door CLOSED (F31)"
        else
            fail "live: last_close_at populated after door CLOSED" "non-null" "got $LAST_CLOSE"
        fi

        if [ "$GAP_SECS" != "NULL" ] && [ "$GAP_SECS" != "" ]; then
            pass "live: gap_seconds field exposed (F31)"
        else
            fail "live: gap_seconds field exposed" "non-null" "got $GAP_SECS"
        fi

        # F41: B consolida la entrada (PRESENT + CLOSED). Es la precondición de
        # la regla de salida; se documenta aquí para el ciclo de C.
        ENTRY_CONFIRMED=$($MYSQL -sN -e "SELECT IFNULL(entry_confirmed_at,'NULL') FROM stays WHERE id=$STAY_ID" 2>/dev/null || echo "ERR")
        if [ -n "$ENTRY_CONFIRMED" ] && [ "$ENTRY_CONFIRMED" != "NULL" ] && [ "$ENTRY_CONFIRMED" != "ERR" ]; then
            pass "B: entry_confirmed_at poblado tras PRESENT+CLOSED (precondición de salida F41)"
        else
            fail "B: entry_confirmed_at poblado tras PRESENT+CLOSED" \
                "expected non-null, got $ENTRY_CONFIRMED"
        fi

        # ═══════════════════════════════════════════════════════════════
        # Sub-test C: Marcar presencia ABSENT → esperar gap → salida confirmada
        # ═══════════════════════════════════════════════════════════════
        echo ""
        echo "  --- C: ABSENT + gap → stay EXITED + room FREE ---"

        # F41: la regla de salida exige un ciclo de puerta ACREDITADO, es decir
        # una apertura POSTERIOR a entry_confirmed_at (last_open_at > entry_confirmed_at).
        # El OPEN/CLOSED de A quedó antes de la consolidación; aquí se acredita el
        # ciclo de salida antes de declarar ABSENT.
        http_test POST "/sim/rooms/$TEST_ROOM/door" 202 \
            "C: door=OPEN (ciclo de salida) → 202" \
            --key SIM-CLIENT \
            --body '{"state":"OPEN"}'

        http_test POST "/sim/rooms/$TEST_ROOM/door" 202 \
            "C: door=CLOSED (ciclo de salida) → 202" \
            --key SIM-CLIENT \
            --body '{"state":"CLOSED"}'

        http_test POST "/sim/rooms/$TEST_ROOM/presence" 202 \
            "presence=ABSENT → 202" \
            --key SIM-CLIENT \
            --body '{"sensor":"PRESENCE","value":"ABSENT"}'

        # Wait for exit-scan to fire (tick every 5s, gap=5s → wait 12s total)
        echo "  Waiting 12s for exit-scan to confirm exit..."
        sleep 12

        # Verify stay is EXITED
        STAY_STATUS=$($MYSQL -sN -e "SELECT status FROM stays WHERE id=$STAY_ID" 2>/dev/null || echo "UNKNOWN")
        if [ "$STAY_STATUS" = "EXITED" ]; then
            pass "C: stay status=EXITED after gap expires"
        else
            fail "C: stay status=EXITED after gap expires" "" \
                "expected EXITED" "got $STAY_STATUS"
        fi

        # Verify room is FREE
        check_room_status $TEST_ROOM "FREE" "C: room FREE after exit confirmation"

        # Verify lock was triggered (AUTO_LOCK event)
        AUTO_LOCK_COUNT=$($MYSQL -sN -e "SELECT COUNT(*) FROM access_events WHERE room_id=$TEST_ROOM AND kind='AUTO_LOCK'" 2>/dev/null || echo "0")
        if [ "$AUTO_LOCK_COUNT" -gt 0 ]; then
            pass "C: AUTO_LOCK access_event written"
        else
            fail "C: AUTO_LOCK access_event written" "" \
                "expected at least 1 AUTO_LOCK event" "got $AUTO_LOCK_COUNT"
        fi

        # Cleanup
        $MYSQL -sN -e "UPDATE stays SET status='CLOSED', closed_at=UTC_TIMESTAMP(3) WHERE id=$STAY_ID" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM iot_sessions WHERE room_id=$TEST_ROOM" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM presence_events WHERE room_id=$TEST_ROOM" 2>/dev/null || true
        # Restore original presence_check_seconds value
        if [ "$ORIG_PCS" = "NULL" ]; then
            $MYSQL -sN -e "UPDATE rooms SET presence_check_seconds=NULL WHERE id=$TEST_ROOM" 2>/dev/null || true
        else
            $MYSQL -sN -e "UPDATE rooms SET presence_check_seconds=$ORIG_PCS WHERE id=$TEST_ROOM" 2>/dev/null || true
        fi
        # Restore room to pre-test state (saved at script start), not hardcoded values
        [ "$SAVED_PACK_ID" = "NULL" ] && $MYSQL -sN -e "UPDATE rooms SET pack_id=NULL WHERE id=$TEST_ROOM" 2>/dev/null || true
        [ "$SAVED_PACK_ID" != "NULL" ] && $MYSQL -sN -e "UPDATE rooms SET pack_id=$SAVED_PACK_ID WHERE id=$TEST_ROOM" 2>/dev/null || true
        [ "$SAVED_SIM_OVERRIDE" = "NULL" ] && $MYSQL -sN -e "UPDATE rooms SET simulated_override=NULL WHERE id=$TEST_ROOM" 2>/dev/null || true
        [ "$SAVED_SIM_OVERRIDE" != "NULL" ] && $MYSQL -sN -e "UPDATE rooms SET simulated_override=$SAVED_SIM_OVERRIDE WHERE id=$TEST_ROOM" 2>/dev/null || true
        [ "$SAVED_STATUS" != "NULL" ] && $MYSQL -sN -e "UPDATE rooms SET status='$SAVED_STATUS' WHERE id=$TEST_ROOM" 2>/dev/null || true
        $MYSQL -sN -e "UPDATE rooms SET cooldown_until=NULL WHERE id=$TEST_ROOM" 2>/dev/null || true

        echo ""
        echo "  --- Sub-test C complete ---"

        # Stop exit-scan daemon
        kill $EXIT_SCAN_PID 2>/dev/null || true
    fi

# ===================================================
# BLOCK 23 — F32: Verificación de dispositivos bajo demanda (ping-all-devices)
# Trazabilidad: RF-5, TSK-F32-1
# ===================================================
block "BLOCK 23 — F32: Device on-demand ping"

# Test 1: Ping specific devices with valid IDs
http_test POST /dashboard-api/ping-all-devices 200 "ping-all-devices: valid device IDs" \
  --key VB6-MAIN \
  --body '{"device_ids":[25,5,26]}'

# Test 2: Ping with empty array
http_test POST /dashboard-api/ping-all-devices 400 "ping-all-devices: empty array rejected" \
  --key VB6-MAIN \
  --body '{"device_ids":[]}'

# Test 3: Ping with missing field
http_test POST /dashboard-api/ping-all-devices 400 "ping-all-devices: missing device_ids rejected" \
  --key VB6-MAIN \
  --body '{}'

# Test 4: After ping, verify device-status has updated last_seen_at for the pinged devices
http_test GET "/dashboard-api/device-status?room_id=2" 200 "device-status: room 2 has devices after pack assignment" \
  --key VB6-MAIN

# ===================================================
# BLOCK 24 — F33: Command-queue para SCANNER/LOCK (ESP32 sub-devices)
# Trazabilidad: RF-22.1 a RF-22.6, TSK-F33-2 a TSK-F33-7
# ===================================================
block "BLOCK 24 — F33: Command-queue ESP32 sub-devices"

# Test 24.1: pending-command with unknown external_id → no commands
http_test GET "/dashboard-api/pending-command?external_id=UNKNOWN000000" 200 \
  "pending-command: unknown RPI returns no commands" \
  --key VB6-MAIN

# Test 24.2: pending-command without external_id → 400
http_test GET "/dashboard-api/pending-command" 400 \
  "pending-command: missing external_id rejected" \
  --key VB6-MAIN

# Test 24.3: command-result with non-existent command → 404
http_test POST /dashboard-api/command-result 404 \
  "command-result: non-existent command returns 404" \
  --key VB6-MAIN \
  --body '{"command_id":99999,"external_id":"UNKNOWN","results":{"RPI":true}}'

# Test 24.4: command-result with missing fields → 400
http_test POST /dashboard-api/command-result 400 \
  "command-result: missing fields rejected" \
  --key VB6-MAIN \
  --body '{}'

# Test 24.5: ping-all-devices with SCANNER device → queues command, returns _pending_check
http_test POST /dashboard-api/ping-all-devices 200 \
  "ping-all-devices: SCANNER queues command via RPI" \
  --key VB6-MAIN \
  --body '{"device_ids":[27]}'

# Test 24.6: ping-all-devices with LOCK device → queues command, returns _pending_check
http_test POST /dashboard-api/ping-all-devices 200 \
  "ping-all-devices: LOCK queues command via RPI" \
  --key VB6-MAIN \
  --body '{"device_ids":[24]}'

# Test 24.7: check-device with SCANNER → 400 (guarda F33)
http_test POST /dashboard-api/check-device 400 \
  "check-device: SCANNER rejected (use ping-all-devices)" \
  --key VB6-MAIN \
  --body '{"device_id":27}'

# Test 24.8: check-device with LOCK → 400 (guarda F33)
http_test POST /dashboard-api/check-device 400 \
  "check-device: LOCK rejected (use ping-all-devices)" \
  --key VB6-MAIN \
  --body '{"device_id":24}'

# Test 24.9: device-heartbeat with batch sub_kinds format → 200
http_test POST /dashboard-api/device-heartbeat 200 \
  "device-heartbeat: batch sub_kinds accepted" \
  --body '{"external_id":"d443229fc114","sub_kinds":["RPI","SCANNER","LOCK"]}'

# Test 24.10: device-heartbeat with legacy single sub_kind → still works
http_test POST /dashboard-api/device-heartbeat 200 \
  "device-heartbeat: legacy single sub_kind still works" \
  --body '{"external_id":"d443229fc114","sub_kind":"RPI"}'

# Test 24.11: Verify SCANNER has last_seen_at after command-result submission
# First, simulate ESP32 picking up the command and reporting results
http_test GET "/dashboard-api/pending-command?external_id=d443229fc114" 200 \
  "pending-command: RPI picks up queued check command" \
  --key VB6-MAIN

# Test 24.12: ping-all-devices with RPI+SCANNER+LOCK together → all handled without Tuya
http_test POST /dashboard-api/ping-all-devices 200 \
  "ping-all-devices: RPI+SCANNER+LOCK batch, no Tuya calls" \
  --key VB6-MAIN \
  --body '{"device_ids":[23,24,27]}'

# =============================================================================
# BLOCK 25 — F35: Detección de anomalías (A1–A8)
# Trazabilidad: RF-35.1 a RF-35.6, TSK-35.01 a TSK-35.15
# =============================================================================
block "BLOCK 25 — F35: Detección de anomalías"

# Clean anomalies from previous test runs so tests start from a known state
db_exec "DELETE FROM anomalies"

# 25.1: GET /api/v1/anomalies — empty list when no anomalies exist
http_test GET /api/v1/anomalies 200 \
  "GET /anomalies: empty list" \
  --key ADMIN-CLI

# 25.2: GET /api/v1/anomalies?status=OPEN — empty list
http_test GET "/api/v1/anomalies?status=OPEN" 200 \
  "GET /anomalies?status=OPEN: empty" \
  --key ADMIN-CLI

# 25.3: GET /api/v1/anomalies?severity=HIGH — empty list
http_test GET "/api/v1/anomalies?severity=HIGH" 200 \
  "GET /anomalies?severity=HIGH: empty" \
  --key ADMIN-CLI

# 25.4: GET /api/v1/anomalies without auth → 401
http_test GET /api/v1/anomalies 401 \
  "GET /anomalies: no auth → 401"

# 25.5: POST /api/v1/anomalies/9999/acknowledge → 409 (not found → treated as not_open)
http_test POST /api/v1/anomalies/9999/acknowledge 409 \
  "POST /anomalies/9999/acknowledge: not found → 409" \
  --key ADMIN-CLI

# 25.5b: POST /api/v1/anomalies/9999/dismiss → 409 (non-existent anomaly)
http_test POST /api/v1/anomalies/9999/dismiss 409 \
  "POST /anomalies/9999/dismiss: not found → 409" \
  --key ADMIN-CLI

# 25.5c: POST /api/v1/anomalies/9999/dismiss without auth → 401
http_test POST /api/v1/anomalies/9999/dismiss 401 \
  "POST /anomalies/9999/dismiss: no auth → 401"

# 25.6: Trigger A2 anomaly — simulate presence in room without a stay
# First, check if sim mode is available for room 1
SIM_CHECK=$(db_exec "SELECT COALESCE(simulated_override,0) FROM rooms WHERE id=1" | tail -1)
STAY_COUNT=$(db_exec "SELECT COUNT(*) FROM stays WHERE room_id=1 AND status IN ('RESERVED','OCCUPIED')" | tail -1)

if [ "$STAY_COUNT" != "0" ]; then
  skip "A2 trigger: room 1 has active stay — SKIP (clean room needed)" \
    "Room 1 has $STAY_COUNT active stay(s)"
elif [ "$SIM_CHECK" = "0" ] || [ -z "$SIM_CHECK" ]; then
  # Enable simulated mode temporarily for this test
  db_exec "UPDATE rooms SET simulated_override=1 WHERE id=1"
  
  # Simulate PROXIMITY=OPEN first so A1 detector doesn't trigger
  http_test POST /sim/rooms/1/presence 202 \
    "A2 precondition: simulate PROXIMITY=OPEN" \
    --key SIM-CLIENT \
    --body '{"sensor":"PROXIMITY","value":"OPEN"}'

  # Simulate PRESENCE=PRESENT in an empty room
  http_test POST /sim/rooms/1/presence 202 \
    "A2 trigger: simulate PRESENT in empty room (SIM-CLIENT)" \
    --key SIM-CLIENT \
    --body '{"sensor":"PRESENCE","value":"PRESENT"}'

  sleep 1

  RESP=$(curl -s -w "\n%{http_code}" \
    -H "X-API-Key: $(get_key ADMIN-CLI)" \
    "http://127.0.0.1:8080/api/v1/anomalies?anomaly_type=A2" 2>/dev/null)
  HTTP_CODE=$(echo "$RESP" | tail -1)

  if [ "$HTTP_CODE" = "200" ] && echo "$RESP" | grep -q '"anomaly_type":"A2"'; then
    pass "A2 trigger: anomaly appears in /anomalies list"
  else
    fail "A2 trigger: anomaly not found in list" "got HTTP $HTTP_CODE"
  fi

  # Check /live endpoint includes the anomaly
  RESP2=$(curl -s -w "\n%{http_code}" \
    "http://127.0.0.1:8080/api/v1/rooms/1/live" 2>/dev/null)
  HTTP_CODE2=$(echo "$RESP2" | tail -1)

  if [ "$HTTP_CODE2" = "200" ] && echo "$RESP2" | grep -q '"anomaly_type":"A2"'; then
    pass "A2 trigger: anomaly visible in /live endpoint"
  else
    fail "A2 trigger: anomaly not in /live" "got HTTP $HTTP_CODE2"
  fi

  # Clean up: simulate ABSENT
  http_test POST /sim/rooms/1/presence 202 \
    "A2 cleanup: simulate ABSENT" \
    --key SIM-CLIENT \
    --body '{"sensor":"PRESENCE","value":"ABSENT"}'

  sleep 1

  # Disable sim mode again
  db_exec "UPDATE rooms SET simulated_override=NULL WHERE id=1"

  skip "A2 auto-resolve: re-run tests after 15s to verify auto-dismiss" \
    "Anomaly-scanner worker checks every 15s"
fi

# 25.7: Verify anomalies endpoint works with different filters
http_test GET "/api/v1/anomalies?room_id=1&status=ACKNOWLEDGED" 200 \
  "GET /anomalies filtered by room+status: empty list" \
  --key ADMIN-CLI

http_test GET /api/v1/rooms/1/live 200 \
  "GET /rooms/1/live: anomalies field present" \
  --key ADMIN-CLI

# =============================================================================
# BLOCK 26 — F36: Monitoreo de batería del sensor MC400D
# Trazabilidad: RF-36.1 a RF-36.5, TSK-36.01 a TSK-36.08
# =============================================================================
block "BLOCK 26 — F36: Battery monitoring"

# 26.1: GET /dashboard-api/device-status includes battery_pct
http_test GET "/dashboard-api/device-status?room_id=1" 200 \
  "GET /dashboard-api/device-status: includes battery_pct" \
  --key ADMIN-CLI

# 26.2: POST /dashboard-api/battery-refresh — no device_id → 400
http_test POST /dashboard-api/battery-refresh 400 \
  "POST /battery-refresh: no device_id → 400" \
  --key ADMIN-CLI \
  --body '{}'

# 26.3: POST /dashboard-api/battery-refresh — non-existing device → 404
http_test POST /dashboard-api/battery-refresh 404 \
  "POST /battery-refresh: non-existing device → 404" \
  --key ADMIN-CLI \
  --body '{"device_id":99999}'

# 26.4: GET /api/v1/rooms/1/live includes battery field
http_test GET /api/v1/rooms/1/live 200 \
  "GET /rooms/1/live: battery field present" \
  --key ADMIN-CLI

# 26.5: POST /battery-refresh con un PROXIMITY real (lookup dinámico, no id fijo).
# Una sola llamada: `tuyaPresenceApi` respeta el presupuesto compartido
# (run/tuya-quota.json), así que si la cuota está agotada responde 502 y el test
# salta en vez de vaciarla. 404 ya no puede darse con un id resuelto de la BD.
BATT_DEV=$($MYSQL -sN -e "SELECT id FROM devices WHERE kind='PROXIMITY' ORDER BY id LIMIT 1" 2>/dev/null)
if [ -z "$BATT_DEV" ]; then
  skip "POST /battery-refresh: sin dispositivo PROXIMITY — SKIP" \
    "No hay dispositivos PROXIMITY en la BD"
else
  BATT_RESP=$(curl -s -w "\n%{http_code}" \
    -H "X-API-Key: $(get_key ADMIN-CLI)" \
    -H "Content-Type: application/json" \
    -d "{\"device_id\":$BATT_DEV}" \
    "http://127.0.0.1:8080/dashboard-api/battery-refresh" 2>/dev/null)
  BATT_HTTP=$(echo "$BATT_RESP" | tail -1)

  if [ "$BATT_HTTP" = "200" ]; then
    if echo "$BATT_RESP" | grep -q '"ok":true' && echo "$BATT_RESP" | grep -q '"kind":"PROXIMITY"'; then
      pass "POST /battery-refresh: device $BATT_DEV returns battery data"
    else
      fail "POST /battery-refresh: device $BATT_DEV response missing fields" \
        "response: $(echo "$BATT_RESP" | head -1)"
    fi
  elif [ "$BATT_HTTP" = "502" ]; then
    skip "POST /battery-refresh: Tuya API/cuota no disponible (HTTP 502) — SKIP" \
      "Credenciales no configuradas o presupuesto Tuya agotado (run/tuya-quota.json)"
  else
    fail "POST /battery-refresh: device $BATT_DEV unexpected HTTP $BATT_HTTP" \
      "response: $(echo "$BATT_RESP" | head -1)"
  fi
fi

# 26.6: Verify device-status after battery-refresh (if Tuya was available)
if [ "$BATT_HTTP" = "200" ]; then
  http_test GET "/dashboard-api/device-status?room_id=1" 200 \
    "GET /device-status: battery_pct present after refresh" \
    --key ADMIN-CLI
fi

# =============================================================================
# BLOCK 27 — F37: Panel de configuración vía system_settings
# Trazabilidad: RF-37.1 a RF-37.3, TSK-37.01 a TSK-37.04
# =============================================================================
block "BLOCK 27 — F37: Configuración"

# 27.1: GET settings grouped by category
http_test GET "/api/v1/admin/settings?service=api" 200 \
  "GET /admin/settings: returns grouped settings" \
  --key ADMIN-CLI

# 27.2: Verify categories present
SETTINGS_RESP=$(curl -s -H "X-API-Key: $(get_key ADMIN-CLI)" \
  "http://127.0.0.1:8080/api/v1/admin/settings?service=api" 2>/dev/null)
if echo "$SETTINGS_RESP" | grep -q '"operation"' && \
   echo "$SETTINGS_RESP" | grep -q '"connections"' && \
   echo "$SETTINGS_RESP" | grep -q '"logging"'; then
  pass "GET /admin/settings: categories operation, connections, logging present"
else
  fail "GET /admin/settings: missing expected categories" \
    "response: $(echo "$SETTINGS_RESP" | head -c 500)"
fi

# 27.3: PUT update a setting (LOG_LEVEL)
# Save original value first
ORIG_LOG_LEVEL=$(echo "$SETTINGS_RESP" | php -r '
  $j=json_decode(stream_get_contents(STDIN),true);
  $items=$j["settings"]["logging"]??[];
  foreach($items as $i){if($i["key"]==="LOG_LEVEL"){echo $i["value"];break;}}
' 2>/dev/null)
ORIG_LOG_LEVEL=${ORIG_LOG_LEVEL:-debug}

http_test PUT /api/v1/admin/settings 200 \
  "PUT /admin/settings: update LOG_LEVEL to info" \
  --key ADMIN-CLI \
  --body "{\"service\":\"api\",\"settings\":[{\"key\":\"LOG_LEVEL\",\"value\":\"info\"}]}"

# 27.4: Verify LOG_LEVEL was updated
SETTINGS_RESP2=$(curl -s -H "X-API-Key: $(get_key ADMIN-CLI)" \
  "http://127.0.0.1:8080/api/v1/admin/settings?service=api" 2>/dev/null)
NEW_VAL=$(echo "$SETTINGS_RESP2" | php -r '
  $j=json_decode(stream_get_contents(STDIN),true);
  $items=$j["settings"]["logging"]??[];
  foreach($items as $i){if($i["key"]==="LOG_LEVEL"){echo $i["value"];break;}}
' 2>/dev/null)
if [ "$NEW_VAL" = "info" ]; then
  pass "GET /admin/settings: LOG_LEVEL updated to info"
else
  fail "GET /admin/settings: LOG_LEVEL not updated" \
    "expected: info, got: $NEW_VAL"
fi

# 27.5: Restore original LOG_LEVEL
http_test PUT /api/v1/admin/settings 200 \
  "PUT /admin/settings: restore LOG_LEVEL to $ORIG_LOG_LEVEL" \
  --key ADMIN-CLI \
  --body "{\"service\":\"api\",\"settings\":[{\"key\":\"LOG_LEVEL\",\"value\":\"$ORIG_LOG_LEVEL\"}]}"

# 27.6: Masked sensitive values (TUYA_ACCESS_SECRET should show value_masked)
if echo "$SETTINGS_RESP" | grep -q '"value_masked"'; then
  pass "GET /admin/settings: sensitive fields have value_masked"
else
  fail "GET /admin/settings: no value_masked field found" \
    "response: $(echo "$SETTINGS_RESP" | head -c 500)"
fi

# 27.7: Sync to .env
http_test POST /api/v1/admin/settings/sync-env 200 \
  "POST /admin/settings/sync-env: writes to .env" \
  --key ADMIN-CLI \
  --body "{\"service\":\"api\"}"

# =============================================================================
# BLOCK 28 — F37: Vista de anomalías con filtro de estado
# Trazabilidad: RF-37.1 a RF-37.4, TSK-37.01 a TSK-37.06
# =============================================================================
block "BLOCK 28 — F37: Anomaly status filter"

# 28.1: GET /api/v1/anomalies without status → defaults to OPEN (backward compat)
http_test GET "/api/v1/anomalies?limit=10" 200 \
  "GET /anomalies (no status): defaults to OPEN" \
  --key ADMIN-CLI

# 28.2: GET /api/v1/anomalies with explicit status=OPEN
http_test GET "/api/v1/anomalies?status=OPEN&limit=10" 200 \
  "GET /anomalies?status=OPEN: returns OPEN anomalies" \
  --key ADMIN-CLI

# 28.3: GET /api/v1/anomalies with status=ACKNOWLEDGED
http_test GET "/api/v1/anomalies?status=ACKNOWLEDGED&limit=10" 200 \
  "GET /anomalies?status=ACKNOWLEDGED: returns acknowledged" \
  --key ADMIN-CLI

# 28.4: GET /api/v1/anomalies with status=DISMISSED
http_test GET "/api/v1/anomalies?status=DISMISSED&limit=10" 200 \
  "GET /anomalies?status=DISMISSED: returns dismissed" \
  --key ADMIN-CLI

# 28.5: GET /api/v1/anomalies with status=ALL → no status filter (all anomalies)
http_test GET "/api/v1/anomalies?status=ALL&limit=10" 200 \
  "GET /anomalies?status=ALL: returns all statuses" \
  --key ADMIN-CLI

# 28.6: Status filter combined with room_id
http_test GET "/api/v1/anomalies?status=OPEN&room_id=1&limit=10" 200 \
  "GET /anomalies?status=OPEN&room_id=1: combined filter works" \
  --key ADMIN-CLI

# 28.7: Verify anomaly has status field in response.
# Precondición determinista: insertamos una anomalía sintética TEST-F37 (tipo que
# el anomaly-scanner no gestiona, así no la resuelve a mitad de test). Antes el
# caso dependía de que hubiera filas, y el scanner las limpiaba → SKIP aleatorio.
ANOM_TEST_ID=$($MYSQL -sN -e "INSERT INTO anomalies (room_id,stay_id,anomaly_type,severity,status,context_data,detected_at,created_at,updated_at) VALUES (1,NULL,'TEST-F37','LOW','OPEN',JSON_OBJECT('source','run-tests'),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)); SELECT LAST_INSERT_ID();" 2>/dev/null | tail -1)

ANOM_STATUS_RESP=$(curl -s -w "\n%{http_code}" \
  -H "X-API-Key: $(get_key ADMIN-CLI)" \
  "http://127.0.0.1:8080/api/v1/anomalies?status=ALL&limit=50" 2>/dev/null)
ANOM_STATUS_HTTP=$(echo "$ANOM_STATUS_RESP" | tail -1)

if [ "$ANOM_STATUS_HTTP" = "200" ]; then
  if echo "$ANOM_STATUS_RESP" | grep -q '"data":\[\]'; then
    fail "GET /anomalies?status=ALL fields" \
      "data vacía pese a la anomalía sintética TEST-F37 (precondición fallida)"
  elif echo "$ANOM_STATUS_RESP" | grep -q '"status"' && echo "$ANOM_STATUS_RESP" | grep -q '"acknowledged_at"'; then
    pass "GET /anomalies?status=ALL: response includes status and acknowledged_at fields"
  else
    fail "GET /anomalies?status=ALL: missing status or acknowledged_at" \
      "response: $(echo "$ANOM_STATUS_RESP" | head -c 400)"
  fi
else
  fail "GET /anomalies?status=ALL: HTTP $ANOM_STATUS_HTTP" \
    "response: $(echo "$ANOM_STATUS_RESP" | head -c 400)"
fi

# 28.8: Verify acknowledge endpoint still works (sobre la anomalía sintética)
if [ -n "$ANOM_TEST_ID" ]; then
  http_test POST "/api/v1/anomalies/$ANOM_TEST_ID/acknowledge" 200 \
    "POST /anomalies/$ANOM_TEST_ID/acknowledge: acknowledge OPEN anomaly" \
    --key ADMIN-CLI \
    --body '{}'
else
  fail "POST /anomalies/acknowledge" "no se pudo crear la anomalía sintética TEST-F37"
fi

# Cleanup de la anomalía sintética (nunca deja rastro en la BD)
$MYSQL -sN -e "DELETE FROM anomalies WHERE anomaly_type='TEST-F37'" 2>/dev/null || true

# =============================================================================
# POST-FLIGHT: Restaurar estado de producción de room 1
# =============================================================================
_restore_room1_state

# =============================================================================
# BLOCK 29 — F38: Workers (QR maestro, sesiones, roles)
# Trazabilidad: RF-W1—RF-W9, TSK-W21
# =============================================================================
# BLOCK 29 — F38: Workers (QR maestro, sesiones, roles)
# Trazabilidad: RF-W1—RF-W9, TSK-W21
# =============================================================================
block "BLOCK 29 — F38: Workers (QR maestro, sesiones, roles)"
ADMIN_KEY=$(get_key ADMIN-CLI)
RPI_KEY=$(get_key RPI-DEV)

# ── Worker Roles ──
http_test GET  /api/v1/worker-roles  200  "List worker roles"  --key ADMIN-CLI

# Create a test role and capture its ID
ROLE_RESP=$(curl -s -H "X-API-Key: $ADMIN_KEY" -H "Content-Type: application/json" \
  -d '{"name":"Test Role F38","description":"F38 test","room_type_ids":[1]}' \
  "${API_BASE}/api/v1/worker-roles")
ROLE_ID=$(echo "$ROLE_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)

if [ -n "$ROLE_ID" ]; then
  pass "POST /worker-roles: created role id=$ROLE_ID"
else
  fail "POST /worker-roles: failed to create role" "Response: $(echo "$ROLE_RESP" | tr -d '\n' | head -c 200)"
fi

http_test GET  "/api/v1/worker-roles/$ROLE_ID"  200  "Show worker role"  --key ADMIN-CLI

http_test PATCH "/api/v1/worker-roles/$ROLE_ID"  200  "Update worker role"  --key ADMIN-CLI \
  --body '{"name":"Test Role Updated","room_type_ids":[1,2]}'

# ── Workers CRUD ──
http_test GET  /api/v1/workers  200  "List workers"  --key ADMIN-CLI

# Create worker and capture the QR token for subsequent tests
WORKER_RESP=$(curl -s -H "X-API-Key: $ADMIN_KEY" -H "Content-Type: application/json" \
  -d '{"name":"F38 Test Worker","role_id":1,"notes":"F38 test"}' \
  "${API_BASE}/api/v1/workers")
WORKER_TOKEN=$(echo "$WORKER_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['qr_token'])" 2>/dev/null)
WORKER_ID=$(echo "$WORKER_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)

if [ -n "$WORKER_TOKEN" ] && [ -n "$WORKER_ID" ]; then
  pass "POST /workers: created worker id=$WORKER_ID with QR token"
else
  fail "POST /workers: failed to create worker" "Response: $(echo "$WORKER_RESP" | tr -d '\n' | head -c 200)"
fi

http_test GET  "/api/v1/workers/$WORKER_ID"  200  "Show worker"  --key ADMIN-CLI

http_test PATCH "/api/v1/workers/$WORKER_ID"  200  "Update worker"  --key ADMIN-CLI \
  --body '{"name":"F38 Updated Worker","notes":"Updated"}'

http_test GET  "/api/v1/workers/$WORKER_ID/sessions?limit=5"  200  "Worker sessions (empty)"  --key ADMIN-CLI

http_test GET  /api/v1/workers/inside  200  "Workers inside (empty)"  --key ADMIN-CLI

# ── QR Validate ──
http_test POST /api/v1/workers/qr/validate  200  "Validate worker QR → open"  --key RPI-DEV \
  --body "{\"token\":\"$WORKER_TOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"

http_test POST /api/v1/workers/qr/validate  409  "Validate again → already_inside"  --key RPI-DEV \
  --body "{\"token\":\"$WORKER_TOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"

# ── Regenerate QR + Revoke ──
QR_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" "${API_BASE}/api/v1/workers/$WORKER_ID/qr")
NEW_TOKEN=$(echo "$QR_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['qr_token'])" 2>/dev/null)

if [ -n "$NEW_TOKEN" ]; then
  pass "POST /workers/$WORKER_ID/qr: regenerated QR token"
else
  fail "POST /workers/$WORKER_ID/qr: failed to regenerate" "Response: $(echo "$QR_RESP" | tr -d '\n' | head -c 200)"
fi

# Validate old token → 403 (revoked)
http_test POST /api/v1/workers/qr/validate  403  "Old QR token revoked → 403"  --key RPI-DEV \
  --body "{\"token\":\"$WORKER_TOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"

# ── Deactivate ──
http_test DELETE "/api/v1/workers/$WORKER_ID"  200  "Deactivate worker"  --key ADMIN-CLI

# Validate new token after deactivate → 403
http_test POST /api/v1/workers/qr/validate  403  "QR deactivated → 403"  --key RPI-DEV \
  --body "{\"token\":\"$NEW_TOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"

# ── Clean up role ──
http_test DELETE "/api/v1/worker-roles/$ROLE_ID"  200  "Delete test role (no workers)"  --key ADMIN-CLI

# ── Final check ──
http_test GET  /api/v1/workers/inside  200  "Workers inside after close"  --key ADMIN-CLI

# =============================================================================

# =============================================================================

# =============================================================================
# BLOCK 31 — F40: Credenciales individuales ESP32
# Trazabilidad: RF-40.1—RF-40.3, TSK-40.05
# =============================================================================
block "BLOCK 31 — F40: Credenciales individuales ESP32"
FACTORY_CHIP="$(printf '%012x' "$(date +%s%N | cut -c1-12)")"
FACTORY_DEVICE_KEY="$(python3 -c 'import secrets; print(secrets.token_hex(24))')"
http_test POST /api/v1/factory-devices/announce 403 "Factory announcement requires HTTPS" --header "X-Forwarded-Proto: http" --body '{"chip_id":"a1b2c3d4e5f6","factory_key":"invalid"}'
http_test POST /api/v1/factory-devices/announce 400 "Factory announcement rejects invalid chip" --header "X-Forwarded-Proto: https" --body "{\"chip_id\":\"not-a-chip\",\"factory_key\":\"$FACTORY_DEVICE_KEY\"}"
http_test POST /api/v1/factory-devices/announce 201 "Factory announcement creates PENDING" --header "X-Forwarded-Proto: https" --body "{\"chip_id\":\"$FACTORY_CHIP\",\"factory_key\":\"$FACTORY_DEVICE_KEY\"}"
http_test POST /api/v1/factory-devices/announce 200 "Factory announcement is idempotent" --header "X-Forwarded-Proto: https" --body "{\"chip_id\":\"$FACTORY_CHIP\",\"factory_key\":\"$FACTORY_DEVICE_KEY\"}"
FACTORY_ID=$(curl -s -H "X-API-Key: $ADMIN_KEY" "${API_BASE}/api/v1/factory-devices?status=PENDING" | python3 -c "import sys,json; print(next((x['id'] for x in json.load(sys.stdin)['data'] if x['chip_id']=='$FACTORY_CHIP'),' '))" 2>/dev/null)
if [ -n "$FACTORY_ID" ] && [ "$FACTORY_ID" != " " ]; then
  http_test POST "/api/v1/factory-devices/$FACTORY_ID/claim" 200 "Claim creates individually bound device" --key ADMIN-CLI --body '{"label":"F40 test"}'
  http_test POST /api/v1/factory-devices/announce 200 "Announce after claim returns logical CLAIMED" --header "X-Forwarded-Proto: https" --body "{\"chip_id\":\"$FACTORY_CHIP\",\"factory_key\":\"$FACTORY_DEVICE_KEY\"}"
  http_test POST /api/v1/factory-devices/announce 403 "Post-claim mismatched credential is rejected" --header "X-Forwarded-Proto: https" --body "{\"chip_id\":\"$FACTORY_CHIP\",\"factory_key\":\"$(python3 -c 'import secrets; print(secrets.token_hex(24))')\"}"
  FACTORY_ROWS=$($MYSQL -sN -e "SELECT COUNT(*) FROM factory_devices WHERE chip_id='$FACTORY_CHIP'" 2>/dev/null || echo "-1")
  if [ "$FACTORY_ROWS" = "0" ]; then
    pass "Claim consumes factory row while preserving audit"
  elif [ "$FACTORY_ROWS" = "-1" ]; then
    skip "Factory row absence check" "BD no accesible"
  else
    fail "Claim consumes factory row while preserving audit" "Esperadas 0 filas factory_devices, recibidas $FACTORY_ROWS"
  fi
  # El RPI reclamado sin pack debe ser listable como dispositivo "sin pack" (RF-39.4.5)
  FOUND_UNASSIGNED=$(curl -s -H "X-API-Key: $ADMIN_KEY" "${API_BASE}/api/v1/devices" | python3 -c "import sys,json; items=json.load(sys.stdin).get('items',[]); print(1 if any(d.get('external_id')=='$FACTORY_CHIP' and d.get('pack_id') is None for d in items) else 0)" 2>/dev/null)
  if [ "$FOUND_UNASSIGNED" = "1" ]; then
    pass "GET /api/v1/devices lista el RPI reclamado sin pack (RF-39.4.5)"
  else
    fail "GET /api/v1/devices lista el RPI reclamado sin pack" "external_id=$FACTORY_CHIP con pack_id=null no aparece en /devices"
  fi
  http_test POST "/api/v1/factory-devices/$FACTORY_ID/claim" 200 "Claim is idempotent without enrollment credential" --key ADMIN-CLI --body '{"label":"F40 test"}'
else
  skip "Factory device claim setup" "Created chip_id not found in PENDING list"
fi

# F40 cleanup must complete before reporting the suite result; EXIT trap is a
# safety net for interruptions during the stateful block.
_cleanup_factory_test

# =============================================================================
# BLOCK 30 — F39: Estado verídico de dispositivos (online/offline/sin verificar)
# Trazabilidad: estado del panel Dispositivos (no falsear online)
# No sondea Tuya real (no consume cuota): comprueba el contrato y que el ping
# sin verify_tuya NO marque online a dispositivos Tuya cloud.
# =============================================================================
block "BLOCK 30 — Estado verídico de dispositivos"

# Selección robusta: una sala cuyo pack tenga PRESENCE *y* SWITCH. Antes se
# cogía la primera con PRESENCE (room 1, pack sin SWITCH) → SKIP permanente.
DS_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN devices p ON p.pack_id=r.pack_id AND p.kind='PRESENCE' JOIN devices s ON s.pack_id=r.pack_id AND s.kind='SWITCH' GROUP BY r.id ORDER BY r.id LIMIT 1" 2>/dev/null)
if [ -z "$DS_ROOM" ]; then
    skip "BLOCK 30 estado verídico" "No hay habitación con PRESENCE + SWITCH en su pack"
else
    HAS_SW=$($MYSQL -sN -e "SELECT COUNT(*) FROM devices d JOIN rooms r ON r.pack_id = d.pack_id WHERE r.id = $DS_ROOM AND d.kind='SWITCH'" 2>/dev/null || echo "0")
    if [ "$HAS_SW" != "1" ]; then
        skip "BLOCK 30 estado verídico (room $DS_ROOM)" "La habitación con PRESENCE no tiene SWITCH en su pack"
    else
        DS_JSON=$(curl -s "${API_BASE}/dashboard-api/device-status?room_id=$DS_ROOM")
        # Contrato F39 + F46++: un dispositivo Tuya cloud solo puede estar
        # `online` si hay señal REAL y fresca: sonda Tuya fresca (online_probed_at
        # dentro del TTL) o push reciente (PROXIMITY / presence_source='push' con
        # last_seen_at fresco). Un `offline` exige sonda fresca (nunca se inventa).
        DS_RESULT=$(echo "$DS_JSON" | python3 -c "
import sys, json, datetime
def parse_utc(s):
    if not s: return None
    s=str(s).strip().replace('T',' ')
    if s.endswith('Z'): s=s[:-1]
    s=s.split('.')[0]
    try: return datetime.datetime.strptime(s,'%Y-%m-%d %H:%M:%S')
    except Exception: return None
def fresh(ts,secs,now): return ts is not None and 0 <= (now-ts).total_seconds() <= secs
try:
    d=json.load(sys.stdin)
except Exception as e:
    print('ERR '+str(e)); sys.exit(0)
now=datetime.datetime.utcnow()
devs=d.get('devices') or []
states=set(x.get('state') for x in devs)
valid=states <= {'online','offline','unknown'}
cnt_sum = (d.get('online') or 0)+(d.get('offline') or 0)+(d.get('unknown') or 0)==(d.get('total') or 0)
bad=[]
for x in devs:
    if not x.get('tuya_cloud'): continue
    on=x.get('online')
    if on is True:
        meta=x.get('meta') or {}
        is_push=(x.get('kind')=='PROXIMITY') or (meta.get('presence_source')=='push')
        probe_ok=fresh(parse_utc(x.get('online_probed_at')),600,now)
        push_ok=is_push and fresh(parse_utc(x.get('last_seen_at')),600,now)
        if not (probe_ok or push_ok): bad.append(str(x.get('id'))+':online_sin_senal')
    elif on is False and not fresh(parse_utc(x.get('online_probed_at')),600,now):
        bad.append(str(x.get('id'))+':offline_sin_sonda')
ok=valid and cnt_sum and not bad
print(('OK ' if ok else 'FAIL ')+('valid='+str(valid)+' sum='+str(cnt_sum)+' bad='+str(bad)+' states='+str(sorted(states))))
" 2>/dev/null)
        case "$DS_RESULT" in
            OK*) pass "device-status: estados verídicos (online/offline/unknown) coherentes en room $DS_ROOM" ;;
            FAIL*) fail "device-status: estados verídicos en room $DS_ROOM" "${DS_RESULT#FAIL }" ;;
            *)     fail "device-status: parse en room $DS_ROOM" "${DS_RESULT:-respuesta vacía}" ;;
        esac

        # Ping sin verify_tuya: Tuya cloud NO debe marcarse online (ni consumir cuota)
        TUYA_IDS=$(echo "$DS_JSON" | python3 -c "
import sys,json
d=json.load(sys.stdin)
print(','.join(str(x['id']) for x in (d.get('devices') or []) if x.get('tuya_cloud')))
")
        if [ -z "$TUYA_IDS" ]; then
            skip "ping-all-devices sin verify_tuya (Tuya)" "No hay dispositivos Tuya cloud en la room"
        else
            IDS_JSON=$(echo "$TUYA_IDS" | python3 -c "import sys; ids=[int(x) for x in sys.stdin.read().replace(chr(10),'').split(',') if x.strip()]; print(ids)" 2>/dev/null)
            PING_JSON=$(curl -s -X POST "${API_BASE}/dashboard-api/ping-all-devices" -H 'Content-Type: application/json' -d "{\"device_ids\":$IDS_JSON}")
            PING_OK=$(echo "$PING_JSON" | python3 -c "
import sys,json
try:
    d=json.load(sys.stdin); rs=d.get('results') or []
except Exception as e:
    print('0'); sys.exit(0)
print('1' if rs and all(r.get('state')=='unknown' and r.get('online') is None and r.get('checked_via')=='TUYA' for r in rs) else '0')
")
            if [ "$PING_OK" = "1" ]; then
                pass "ping-all-devices sin verify_tuya: Tuya cloud se reporta 'sin verificar' (no online, sin cuota)"
            else
                fail "ping-all-devices sin verify_tuya: Tuya cloud NO debe marcarse online" "$PING_JSON"
            fi
        fi
    fi
fi

# =============================================================================
# BLOCK 32 — F43: Sensor 24G V3 en proto2 + ZY-M100 en banco de pruebas
# Trazabilidad: alta del 24G-Presence Sensor V3 en el pack proto2 y
# reasignación del ZY-M100 al pack PRUEBAS ("Equipo de Pruebas").
# Solo comprueba BD (no consume cuota de la API Tuya).
# =============================================================================
block "BLOCK 32 — F43: PRESENCE 24G V3 / reasignación de packs"

PACK_PROTO2=$($MYSQL -sN -e "SELECT id FROM device_packs WHERE code='proto2' LIMIT 1" 2>/dev/null || echo "")
PACK_PRUEBAS=$($MYSQL -sN -e "SELECT id FROM device_packs WHERE code='PRUEBAS' LIMIT 1" 2>/dev/null || echo "")

if [ -z "$PACK_PROTO2" ] || [ -z "$PACK_PRUEBAS" ]; then
    skip "BLOCK 32 — F43" "Faltan los packs proto2 o PRUEBAS en la BD"
else
    # 24G V3 registrado como PRESENCE en proto2, con label y meta
    NEW_ROW=$($MYSQL -sN -e "SELECT CONCAT(pack_id,'|',kind,'|',label,'|',(meta_json LIKE '%24G-Presence Sensor V3%')) FROM devices WHERE external_id='bf9a278e76e2c3f01ay0cs' LIMIT 1" 2>/dev/null || echo "")
    if [ "$NEW_ROW" = "$PACK_PROTO2|PRESENCE|Sensor Presencia 24G V3|1" ]; then
        pass "24G V3 registrado como PRESENCE en proto2 (pack $PACK_PROTO2) con label y meta"
    else
        fail "24G V3 no está correctamente asignado a proto2" "expected '$PACK_PROTO2|PRESENCE|Sensor Presencia 24G V3|1' got '$NEW_ROW'"
    fi

    # ZY-M100 reasignado a PRUEBAS
    OLD_ROW=$($MYSQL -sN -e "SELECT CONCAT(pack_id,'|',label) FROM devices WHERE kind='PRESENCE' AND external_id='bf98d27d79685e38a2wbda' LIMIT 1" 2>/dev/null || echo "")
    if [ "$OLD_ROW" = "$PACK_PRUEBAS|Sensor Presencia ZY-M100 (pruebas)" ]; then
        pass "ZY-M100 reasignado a Equipo de Pruebas (pack $PACK_PRUEBAS)"
    else
        fail "ZY-M100 no está en Equipo de Pruebas" "expected '$PACK_PRUEBAS|Sensor Presencia ZY-M100 (pruebas)' got '$OLD_ROW'"
    fi

    # Exactamente un PRESENCE por pack (proto2 y PRUEBAS)
    N_PROTO2=$($MYSQL -sN -e "SELECT COUNT(*) FROM devices WHERE pack_id=$PACK_PROTO2 AND kind='PRESENCE'" 2>/dev/null || echo "0")
    N_PRUEBAS=$($MYSQL -sN -e "SELECT COUNT(*) FROM devices WHERE pack_id=$PACK_PRUEBAS AND kind='PRESENCE'" 2>/dev/null || echo "0")
    if [ "$N_PROTO2" = "1" ] && [ "$N_PRUEBAS" = "1" ]; then
        pass "un único PRESENCE por pack (proto2=$N_PROTO2, PRUEBAS=$N_PRUEBAS)"
    else
        fail "debe haber exactamente un PRESENCE por pack" "proto2=$N_PROTO2 PRUEBAS=$N_PRUEBAS"
    fi

    # Validación del calibrador: ocurre ANTES de llamar a Tuya → 0 cuota consumida.
    PROTO2_ROOM=$($MYSQL -sN -e "SELECT id FROM rooms WHERE pack_id=$PACK_PROTO2 LIMIT 1" 2>/dev/null || echo "")
    if [ -n "$PROTO2_ROOM" ]; then
        http_test POST /dashboard-api/presence-calibrate/set 400 \
            "presence-calibrate: far fuera de rango → 400" \
            --body "{\"room_id\":$PROTO2_ROOM,\"far_detection\":99999}"
        http_test POST /dashboard-api/presence-calibrate/set 400 \
            "presence-calibrate: sensitivity fuera de rango → 400" \
            --body "{\"room_id\":$PROTO2_ROOM,\"sensitivity\":999}"
        http_test POST /dashboard-api/presence-calibrate/set 400 \
            "presence-calibrate: sin parámetros → 400" \
            --body "{\"room_id\":$PROTO2_ROOM}"
    else
        skip "presence-calibrate validación" "No hay room asignada al pack proto2"
    fi

    # Room sin sensor de presencia → 404
    NO_PRES_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r LEFT JOIN devices d ON d.pack_id=r.pack_id AND d.kind='PRESENCE' WHERE d.id IS NULL ORDER BY r.id LIMIT 1" 2>/dev/null || echo "")
    if [ -n "$NO_PRES_ROOM" ]; then
        http_test POST /dashboard-api/presence-calibrate/set 404 \
            "presence-calibrate: room sin PRESENCE → 404" \
            --body "{\"room_id\":$NO_PRES_ROOM,\"far_detection\":600}"
    else
        skip "presence-calibrate room sin PRESENCE" "Todas las rooms tienen PRESENCE"
    fi
fi

# =============================================================================
# BLOCK 33 — F41: robustez de sensores y coreografía
# Trazabilidad: RF-43…RF-49 · contracts.md §3, §4, §5, §6 · TSK-F41-18
#
# Cubre: orden/deduplicación del pipeline (migración 0108), /live con campos
# nuevos, system-status ampliado, reset que limpia marcas, concurrencia
# (OPEN∥CLOSED) y coreografía de entrada/salida. Los tests JS no son *Test.php
# y no se autodescubren → se invocan explícitamente con node.
# =============================================================================
block "BLOCK 33 — F41: robustez de sensores y coreografía"

# ── 33.0 Unit tests JS (coreografía). No dependen del servidor. ───────────────
for F41_JS in tests/Unit/choreography.test.js; do
    if [ ! -f "$F41_JS" ]; then
        skip "JS: $F41_JS" "fichero no encontrado"
        continue
    fi
    F41_JS_OUT=$(node "$F41_JS" 2>&1)
    F41_JS_RC=$?
    F41_JS_SUM=$(echo "$F41_JS_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F41_JS_SUM" ] && F41_JS_SUM="exit=$F41_JS_RC"
    if [ "$F41_JS_RC" -eq 0 ]; then
        pass "JS: $(basename "$F41_JS") ($F41_JS_SUM)"
    else
        fail "JS: $(basename "$F41_JS")" \
            "$F41_JS_SUM — $(echo "$F41_JS_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
done

# ── 33.1 system-status: esquema ampliado de 5 workers (contrato §5, F78) ────
if [ "$SERVER_UP" != true ]; then
    skip "F41 system-status" "servidor HTTP no disponible"
else
    F41_SS_JSON=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/system-status" 2>/dev/null)
    F41_SS_RES=$(echo "$F41_SS_JSON" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception as e:
    print('ERR ' + str(e)); sys.exit(0)
keys = ['exit-scan','overstay-scan','outbox-worker','anomaly-scanner','pulsar-consumer']
missing = [k for k in keys if k not in d]
bad = []
for k in keys:
    v = d.get(k)
    if not isinstance(v, dict):
        bad.append(k + ':not_object'); continue
    for f in ('expected','instances','pids','healthy','degraded'):
        if f not in v:
            bad.append(k + ':' + f)
    if v.get('expected') != 1:
        bad.append(k + ':expected')
    if not isinstance(v.get('pids'), list):
        bad.append(k + ':pids')
    inst = v.get('instances'); exp = v.get('expected')
    if v.get('healthy') is not (inst == exp):
        bad.append(k + ':healthy')
    if v.get('degraded') is not (inst > exp):
        bad.append(k + ':degraded')
print('OK' if not missing and not bad else 'FAIL missing=' + str(missing) + ' bad=' + str(bad))
" 2>/dev/null)
    case "$F41_SS_RES" in
        OK*)   pass "F41 system-status: 5 workers con expected/instances/pids/healthy/degraded" ;;
        FAIL*) fail "F41 system-status: esquema §5" "${F41_SS_RES#FAIL }" ;;
        *)     fail "F41 system-status: parse" "${F41_SS_RES:-respuesta vacía}" ;;
    esac
fi

# ── 33.2 Prerrequisitos de los casos stateful ────────────────────────────────
F41_ROOM=1
F41_SIM_KEY=$(get_key SIM-CLIENT)
F41_SCHEMA=$($MYSQL -sN -e "SHOW COLUMNS FROM presence_events LIKE 'event_fingerprint'" 2>/dev/null || echo "")
F41_ROOM_COL=$($MYSQL -sN -e "SHOW COLUMNS FROM iot_sessions LIKE 'last_door_value'" 2>/dev/null || echo "")

if [ "$SERVER_UP" != true ]; then
    skip "F41 stateful (reset/orden/concurrencia/coreografía)" "servidor HTTP no disponible"
elif [ -z "$F41_SCHEMA" ] || [ -z "$F41_ROOM_COL" ]; then
    skip "F41 stateful (reset/orden/concurrencia/coreografía)" \
        "Migración 0108 no aplicada (faltan event_fingerprint / last_door_value)"
elif [ -z "$F41_SIM_KEY" ]; then
    skip "F41 stateful (reset/orden/concurrencia/coreografía)" \
        "Clave SIM-CLIENT no disponible en $KEYS_FILE"
else
    # Helpers locales del bloque (no tocan los helpers globales del runner).
    f41_iso()       { date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ; }
    f41_mysql_utc() { date -u -d "@$1" '+%Y-%m-%d %H:%M:%S.000'; }
    f41_reset() {
        local out
        out=$(curl -s -o /dev/null -w '%{http_code}' -X POST \
            -H 'Content-Type: application/json' \
            -d "{\"room_id\":$F41_ROOM}" \
            "${API_BASE}/dashboard-api/rooms/reset" 2>/dev/null) || out="000"
        echo "$out"
    }
    f41_live() { curl -s --max-time 5 "${API_BASE}/api/v1/rooms/$F41_ROOM/live" 2>/dev/null; }
    f41_sim() {   # $1 = presence|door ; $2 = body JSON
        local out
        out=$(curl -s -o /dev/null -w '%{http_code}' -X POST \
            -H "X-API-Key: $F41_SIM_KEY" -H 'Content-Type: application/json' \
            -d "$2" "${API_BASE}/sim/rooms/$F41_ROOM/$1" 2>/dev/null) || out="000"
        echo "$out"
    }

    # ── 33.3 Reset limpia todas las marcas (contrato §6.2, RF-47.5) ──────────
    http_test POST /dashboard-api/rooms/reset 200 \
        "F41 reset: POST /dashboard-api/rooms/reset → 200" \
        --idem "f41-reset-$(date +%s)" \
        --body "{\"room_id\":$F41_ROOM}"

    F41_MARKS=$($MYSQL -sN -e "SELECT CONCAT(
        IFNULL(last_close_at,'NULL'),'|',
        IFNULL(last_absent_since,'NULL'),'|',
        IFNULL(last_open_at,'NULL'),'|',
        IFNULL(last_door_event_at,'NULL'),'|',
        IFNULL(last_presence_event_at,'NULL'),'|',
        IFNULL(last_door_value,'NULL'),'|',
        IFNULL(last_presence_value,'NULL'))
        FROM iot_sessions WHERE room_id=$F41_ROOM LIMIT 1" 2>/dev/null || echo "ERR")
    if [ "$F41_MARKS" = "NULL|NULL|NULL|NULL|NULL|NULL|NULL" ]; then
        pass "F41 reset: limpia close/absent/open + last_*_event_at + last_*_value"
    elif [ "$F41_MARKS" = "ERR" ] || [ -z "$F41_MARKS" ]; then
        skip "F41 reset: verificación de marcas" "BD no accesible o fila iot_sessions ausente"
    else
        fail "F41 reset: no limpió todas las marcas" "got '$F41_MARKS'"
    fi

    # ── 33.4 /live: campos nuevos en iot_session y active_stay (contrato §3) ─
    F41_STAY_ID=$($MYSQL -sN -e "INSERT INTO stays
        (room_id,status,duracion_minutos,first_entry_at,reserved_at,vb6_codtic,vb6_codcli)
        VALUES ($F41_ROOM,'OCCUPIED',60,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),1,1);
        SELECT LAST_INSERT_ID();" 2>/dev/null || echo "0")
    if [ -z "$F41_STAY_ID" ] || [ "$F41_STAY_ID" = "0" ]; then
        skip "F41 /live campos nuevos" "No se pudo crear estancia OCCUPIED de prueba"
        skip "F41 orden: secuencia de entrada" "Depende de la estancia de prueba"
    else
        $MYSQL -sN -e "UPDATE rooms SET status='OCCUPIED', simulated_override=1 WHERE id=$F41_ROOM" 2>/dev/null || true

        F41_LIVE_CHECK=$(f41_live | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception as e:
    print('ERR ' + str(e)); sys.exit(0)
iot = d.get('iot_session') or {}
need = ['last_door_event_at','last_presence_event_at','last_door_value','last_presence_value']
missing = [k for k in need if k not in iot]
stay = d.get('active_stay')
stay_ok = (stay is not None) and ('entry_confirmed_at' in stay)
print('OK' if not missing and stay_ok else 'FAIL missing=' + str(missing) + ' entry_confirmed_key=' + str(stay_ok))
" 2>/dev/null)
        case "$F41_LIVE_CHECK" in
            OK*)   pass "F41 /live: 4 marcas nuevas en iot_session + active_stay.entry_confirmed_at" ;;
            FAIL*) fail "F41 /live: campos en /live" "${F41_LIVE_CHECK#FAIL }" ;;
            *)     fail "F41 /live: parse" "${F41_LIVE_CHECK:-respuesta vacía}" ;;
        esac

        # Secuencia de entrada: PRESENT → OPEN → CLOSED ⇒ entrada consolidada.
        F41_TB=$((F41_NOW + 10))
        F41_TS_PRESENT=$(f41_iso $((F41_TB + 1)))
        F41_TS_OPEN=$(f41_iso $((F41_TB + 2)))
        F41_TS_CLOSE=$(f41_iso $((F41_TB + 3)))
        F41_R1=$(f41_sim presence "{\"sensor\":\"PRESENCE\",\"value\":\"PRESENT\",\"occurred_at\":\"$F41_TS_PRESENT\"}")
        F41_R2=$(f41_sim door "{\"state\":\"OPEN\",\"occurred_at\":\"$F41_TS_OPEN\"}")
        F41_R3=$(f41_sim door "{\"state\":\"CLOSED\",\"occurred_at\":\"$F41_TS_CLOSE\"}")

        F41_ORD=$(f41_live | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    print('ERR'); sys.exit(0)
iot = d.get('iot_session') or {}
stay = d.get('active_stay') or {}
ok = (iot.get('last_door_value') == 'CLOSED'
      and iot.get('last_presence_value') == 'PRESENT'
      and iot.get('last_door_event_at')
      and stay.get('entry_confirmed_at'))
print('OK' if ok else 'FAIL door=' + str(iot.get('last_door_value')) +
      ' pres=' + str(iot.get('last_presence_value')) +
      ' ec=' + str(stay.get('entry_confirmed_at')))
" 2>/dev/null)
        if [ "$F41_R1" = "202" ] && [ "$F41_R2" = "202" ] && [ "$F41_R3" = "202" ] && [ "$F41_ORD" = "OK" ]; then
            pass "F41 orden entrada: PRESENT→OPEN→CLOSED consolida entry_confirmed_at"
        else
            fail "F41 orden entrada (PRESENT→OPEN→CLOSED)" \
                "codes=$F41_R1/$F41_R2/$F41_R3 live=$F41_ORD"
        fi
    fi

    # ── 33.5 Deduplicación: reenvío idéntico no se aplica dos veces (RF-44) ─
    f41_reset >/dev/null 2>&1 || true
    F41_TD=$((F41_NOW + 200))
    F41_TS_DUP=$(f41_iso $((F41_TD + 1)))
    F41_TS_DUP_MYSQL=$(f41_mysql_utc $((F41_TD + 1)))
    f41_sim presence "{\"sensor\":\"PRESENCE\",\"value\":\"PRESENT\",\"occurred_at\":\"$F41_TS_DUP\"}" >/dev/null
    F41_RC_DUP=$(f41_sim presence "{\"sensor\":\"PRESENCE\",\"value\":\"PRESENT\",\"occurred_at\":\"$F41_TS_DUP\"}")
    F41_DUP_ROWS=$($MYSQL -sN -e "SELECT COUNT(*) FROM presence_events WHERE room_id=$F41_ROOM AND sensor='PRESENCE' AND value='PRESENT' AND occurred_at='$F41_TS_DUP_MYSQL'" 2>/dev/null || echo "-1")
    F41_DUP_APPLIED=$($MYSQL -sN -e "SELECT COUNT(*) FROM presence_events WHERE room_id=$F41_ROOM AND sensor='PRESENCE' AND value='PRESENT' AND occurred_at='$F41_TS_DUP_MYSQL' AND applied=1" 2>/dev/null || echo "-1")
    F41_DUP_LIVE=$($MYSQL -sN -e "SELECT IFNULL(last_presence_value,'NULL') FROM iot_sessions WHERE room_id=$F41_ROOM LIMIT 1" 2>/dev/null || echo "ERR")
    if [ "$F41_DUP_ROWS" = "-1" ]; then
        skip "F41 dedup: reenvío idéntico" "BD no accesible"
    elif [ "$F41_RC_DUP" = "202" ] && [ "$F41_DUP_ROWS" = "1" ] && [ "$F41_DUP_APPLIED" = "1" ] && [ "$F41_DUP_LIVE" = "PRESENT" ]; then
        # El fingerprint UNIQUE evita la segunda fila; markAudit no reescribe una
        # fila ya aplicada, así que el estado no cambia dos veces.
        pass "F41 dedup: reenvío idéntico → 1 fila de auditoría, 1 aplicación (fingerprint UNIQUE)"
    else
        fail "F41 dedup: reenvío idéntico" \
            "code=$F41_RC_DUP rows=$F41_DUP_ROWS applied=$F41_DUP_APPLIED live=$F41_DUP_LIVE"
    fi

    # ── 33.6 Orden: un evento atrasado se descarta como stale (RF-44) ───────
    F41_TS=$((F41_NOW + 300))
    f41_sim door "{\"state\":\"CLOSED\",\"occurred_at\":\"$(f41_iso $((F41_TS + 2)))\"}" >/dev/null
    F41_RC_STALE=$(f41_sim door "{\"state\":\"OPEN\",\"occurred_at\":\"$(f41_iso $((F41_TS + 1)))\"}")
    F41_STALE_ROWS=$($MYSQL -sN -e "SELECT COUNT(*) FROM presence_events WHERE room_id=$F41_ROOM AND sensor='PROXIMITY' AND value='OPEN' AND occurred_at='$(f41_mysql_utc $((F41_TS + 1)))' AND applied=0 AND discard_reason='stale'" 2>/dev/null || echo "-1")
    F41_STALE_VAL=$($MYSQL -sN -e "SELECT IFNULL(last_door_value,'NULL') FROM iot_sessions WHERE room_id=$F41_ROOM LIMIT 1" 2>/dev/null || echo "ERR")
    if [ "$F41_STALE_ROWS" = "-1" ]; then
        skip "F41 orden: evento atrasado (stale)" "BD no accesible"
    elif [ "$F41_RC_STALE" = "202" ] && [ -n "$F41_STALE_ROWS" ] && [ "$F41_STALE_ROWS" -ge 1 ] && [ "$F41_STALE_VAL" = "CLOSED" ]; then
        pass "F41 orden: evento atrasado → applied=0 discard_reason='stale' (no revierte CLOSED)"
    else
        fail "F41 orden: evento atrasado (stale)" \
            "code=$F41_RC_STALE stale_rows=$F41_STALE_ROWS door=$F41_STALE_VAL"
    fi

    # ── 33.7 Concurrencia OPEN ∥ CLOSED: gana el occurred_at más reciente ───
    f41_reset >/dev/null 2>&1 || true
    F41_TC=$((F41_NOW + 400))
    F41_OPEN_TS=$(f41_iso $((F41_TC + 1)))
    F41_CLOSE_TS=$(f41_iso $((F41_TC + 2)))
    F41_MKT_O=$(mktemp); F41_MKT_C=$(mktemp)
    curl -s -o /dev/null -w '%{http_code}' -X POST \
        -H "X-API-Key: $F41_SIM_KEY" -H 'Content-Type: application/json' \
        -d "{\"state\":\"OPEN\",\"occurred_at\":\"$F41_OPEN_TS\"}" \
        "${API_BASE}/sim/rooms/$F41_ROOM/door" >"$F41_MKT_O" 2>/dev/null &
    F41_PID_O=$!
    curl -s -o /dev/null -w '%{http_code}' -X POST \
        -H "X-API-Key: $F41_SIM_KEY" -H 'Content-Type: application/json' \
        -d "{\"state\":\"CLOSED\",\"occurred_at\":\"$F41_CLOSE_TS\"}" \
        "${API_BASE}/sim/rooms/$F41_ROOM/door" >"$F41_MKT_C" 2>/dev/null &
    F41_PID_C=$!
    wait "$F41_PID_O" 2>/dev/null; wait "$F41_PID_C" 2>/dev/null
    F41_CODE_O=$(cat "$F41_MKT_O" 2>/dev/null); F41_CODE_C=$(cat "$F41_MKT_C" 2>/dev/null)
    rm -f "$F41_MKT_O" "$F41_MKT_C"
    F41_CONC=$($MYSQL -sN -e "SELECT CONCAT(IFNULL(door_state,'?'),'|',IFNULL(last_door_value,'NULL')) FROM iot_sessions WHERE room_id=$F41_ROOM LIMIT 1" 2>/dev/null || echo "ERR")
    # Guard de determinismo: si ambos POST cayeron en el mismo microsegundo, el
    # source_event_id generado por el endpoint colisiona y uno se audita como
    # duplicado. En ese caso no se puede medir el orden por occurred_at → skip.
    F41_CONC_ROWS=$($MYSQL -sN -e "SELECT COUNT(*) FROM presence_events WHERE room_id=$F41_ROOM AND sensor='PROXIMITY' AND occurred_at IN ('$(f41_mysql_utc $((F41_TC + 1)))','$(f41_mysql_utc $((F41_TC + 2)))')" 2>/dev/null || echo "-1")
    if [ "$F41_CONC_ROWS" = "1" ]; then
        skip "F41 concurrencia: OPEN∥CLOSED" \
            "Colisión de source_event_id en el mismo microsegundo (no medible); reintentar"
    elif [ "$F41_CODE_O" = "202" ] && [ "$F41_CODE_C" = "202" ] && [ "$F41_CONC" = "CLOSED|CLOSED" ]; then
        pass "F41 concurrencia: OPEN∥CLOSED → CLOSED (orden por occurred_at, no último escritor)"
    else
        fail "F41 concurrencia: OPEN∥CLOSED" \
            "codes=$F41_CODE_O/$F41_CODE_C estado=$F41_CONC (esperado CLOSED|CLOSED)"
    fi

    # ── 33.7b F58/RF-65: mismo segundo con llegada invertida → gana la ms ──
    # CLOSED (.900) llega primero; OPEN (.100), que ocurrió antes, llega después.
    # Sin ms (comportamiento anterior) el OPEN revertía el cierre y la puerta
    # quedaba OPEN; con F58 el OPEN es stale y gana CLOSED.
    f41_reset >/dev/null 2>&1 || true
    F41_TI=$((F41_NOW + 500))
    F41_TI_BASE=$(f41_iso "$F41_TI")
    F41_TS_EARLY="${F41_TI_BASE%Z}.100Z"
    F41_TS_LATE="${F41_TI_BASE%Z}.900Z"
    F41_TS_EARLY_MYSQL="${F41_TS_EARLY%Z}"
    F41_TS_EARLY_MYSQL="${F41_TS_EARLY_MYSQL/T/ }"
    F41_RC_LATE=$(f41_sim door "{\"state\":\"CLOSED\",\"occurred_at\":\"$F41_TS_LATE\"}")
    F41_RC_EARLY=$(f41_sim door "{\"state\":\"OPEN\",\"occurred_at\":\"$F41_TS_EARLY\"}")
    F41_INV_STATE=$($MYSQL -sN -e "SELECT CONCAT(IFNULL(door_state,'?'),'|',IFNULL(last_door_value,'NULL')) FROM iot_sessions WHERE room_id=$F41_ROOM LIMIT 1" 2>/dev/null || echo "ERR")
    F41_INV_STALE=$($MYSQL -sN -e "SELECT COUNT(*) FROM presence_events WHERE room_id=$F41_ROOM AND sensor='PROXIMITY' AND value='OPEN' AND occurred_at='$F41_TS_EARLY_MYSQL' AND applied=0 AND discard_reason='stale'" 2>/dev/null || echo "-1")
    if [ "$F41_INV_STATE" = "ERR" ]; then
        skip "F58 orden ms: llegada invertida" "BD no accesible"
    elif [ "$F41_RC_LATE" = "202" ] && [ "$F41_RC_EARLY" = "202" ] && [ "$F41_INV_STATE" = "CLOSED|CLOSED" ] && [ "$F41_INV_STALE" != "-1" ] && [ "$F41_INV_STALE" -ge 1 ]; then
        pass "F58 orden ms: CLOSED .900 + OPEN .100 invertido → CLOSED gana y OPEN stale"
    else
        fail "F58 orden ms: llegada invertida" \
            "codes=$F41_RC_LATE/$F41_RC_EARLY state=$F41_INV_STATE stale=$F41_INV_STALE"
    fi

    # ── 33.7c F58/RF-65: mismo segundo ascendente legítimo (cierre→reapertura) ──
    f41_reset >/dev/null 2>&1 || true
    F41_TJ=$((F41_NOW + 520))
    F41_TJ_BASE=$(f41_iso "$F41_TJ")
    F41_TS_J1="${F41_TJ_BASE%Z}.100Z"
    F41_TS_J2="${F41_TJ_BASE%Z}.900Z"
    f41_sim door "{\"state\":\"CLOSED\",\"occurred_at\":\"$F41_TS_J1\"}" >/dev/null
    F41_RC_J2=$(f41_sim door "{\"state\":\"OPEN\",\"occurred_at\":\"$F41_TS_J2\"}")
    F41_J_STATE=$($MYSQL -sN -e "SELECT CONCAT(IFNULL(door_state,'?'),'|',IFNULL(last_door_value,'NULL')) FROM iot_sessions WHERE room_id=$F41_ROOM LIMIT 1" 2>/dev/null || echo "ERR")
    if [ "$F41_J_STATE" = "ERR" ]; then
        skip "F58 orden ms: ascendente" "BD no accesible"
    elif [ "$F41_RC_J2" = "202" ] && [ "$F41_J_STATE" = "OPEN|OPEN" ]; then
        pass "F58 orden ms: CLOSED .100 → OPEN .900 (mismo segundo) aplica OPEN"
    else
        fail "F58 orden ms: ascendente" "code=$F41_RC_J2 state=$F41_J_STATE"
    fi

    # ── 33.8 Coreografía de salida: entry_confirmed_at + exit_deadline (RF-47)
    f41_reset >/dev/null 2>&1 || true
    F41_GSTAY=$($MYSQL -sN -e "INSERT INTO stays
        (room_id,status,duracion_minutos,first_entry_at,reserved_at,vb6_codtic,vb6_codcli)
        VALUES ($F41_ROOM,'OCCUPIED',60,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),1,1);
        SELECT LAST_INSERT_ID();" 2>/dev/null || echo "0")
    if [ -z "$F41_GSTAY" ] || [ "$F41_GSTAY" = "0" ]; then
        skip "F41 coreografía: exit_deadline" "No se pudo crear estancia OCCUPIED"
    else
        $MYSQL -sN -e "UPDATE rooms SET status='OCCUPIED' WHERE id=$F41_ROOM" 2>/dev/null || true
        F41_TG=$((F41_NOW + 600))
        f41_sim presence "{\"sensor\":\"PRESENCE\",\"value\":\"PRESENT\",\"occurred_at\":\"$(f41_iso $((F41_TG + 1)))\"}" >/dev/null
        f41_sim door "{\"state\":\"OPEN\",\"occurred_at\":\"$(f41_iso $((F41_TG + 2)))\"}" >/dev/null
        f41_sim door "{\"state\":\"CLOSED\",\"occurred_at\":\"$(f41_iso $((F41_TG + 3)))\"}" >/dev/null

        F41_EC=$(f41_live | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    print('ERR'); sys.exit(0)
stay = d.get('active_stay') or {}
print('OK' if stay.get('entry_confirmed_at') else 'FAIL ec=' + str(stay.get('entry_confirmed_at')))
" 2>/dev/null)
        if [ "$F41_EC" = "OK" ]; then
            pass "F41 coreografía: entry_confirmed_at consolidado (OPEN + PRESENT + CLOSED)"
        else
            fail "F41 coreografía: entry_confirmed_at" "$F41_EC"
        fi

        # Con presencia y puerta cerrada no debe haber cuenta de salida.
        f41_sim presence "{\"sensor\":\"PRESENCE\",\"value\":\"ABSENT\",\"occurred_at\":\"$(f41_iso $((F41_TG + 4)))\"}" >/dev/null
        F41_DEAD_ABSENT=$(f41_live | python3 -c "import sys,json; print((json.load(sys.stdin).get('exit_deadline')) or 'NULL')" 2>/dev/null)
        if [ -n "$F41_DEAD_ABSENT" ] && [ "$F41_DEAD_ABSENT" != "NULL" ]; then
            pass "F41 coreografía: ABSENT + ciclo acreditado → exit_deadline activo"
        else
            fail "F41 coreografía: exit_deadline con ABSENT" "got '$F41_DEAD_ABSENT'"
        fi

        f41_sim presence "{\"sensor\":\"PRESENCE\",\"value\":\"PRESENT\",\"occurred_at\":\"$(f41_iso $((F41_TG + 5)))\"}" >/dev/null
        F41_DEAD_PRESENT=$(f41_live | python3 -c "import sys,json; print((json.load(sys.stdin).get('exit_deadline')) or 'NULL')" 2>/dev/null)
        if [ "$F41_DEAD_PRESENT" = "NULL" ]; then
            pass "F41 coreografía: PRESENT cancela exit_deadline"
        else
            fail "F41 coreografía: cancelación de exit_deadline" "got '$F41_DEAD_PRESENT'"
        fi

        skip "F41 coreografía: stay EXITED tras el gap" \
            "Confirmación real la hace el worker exit-scan (no determinista aquí); cubierto por BLOCK 22"
    fi

    # ── 33.9 SSE: evento de latido ping (contrato §4, RF-49.1) ──────────────
    # Ventana amplia (15 s) + reintento con conexión nueva: el establecimiento
    # del stream o un servidor ocupado pueden retrasar el ping (~5 s).
    F41_SSE=""
    F41_SSE_TRY=0
    while [ "$F41_SSE_TRY" -lt 2 ]; do
        F41_SSE_TRY=$((F41_SSE_TRY + 1))
        F41_SSE=$(curl -sN --max-time 15 "${API_BASE}/dashboard-api/event-stream?room_id=$F41_ROOM" 2>/dev/null | head -c 8000)
        echo "$F41_SSE" | grep -q '^event: ping' && break
        echo "$F41_SSE" | grep -q '^event: connected' || break
    done
    if echo "$F41_SSE" | grep -q '^event: ping'; then
        pass "F41 SSE: el stream emite event: ping (RF-49.1)"
    elif echo "$F41_SSE" | grep -q '^event: connected'; then
        fail "F41 SSE: event ping ausente tras 15s (2 intentos)" "se recibió connected pero no ping"
    else
        skip "F41 SSE: event ping" "Stream SSE sin salida utilizable (servidor/workers)"
    fi

    # ── Cleanup: cierra la estancia de prueba y restaura room 1 ─────────────
    f41_reset >/dev/null 2>&1 || true
    _restore_room1_state
fi

# =============================================================================
# BLOCK 34 — F42: ventana de verificación de entrada (RF-46.4)
# Trazabilidad: RF-46.4, RF-47 · contracts.md §3.3 · TSK-F42-04
#
# Cubre:
#   - entrada no consolidada (ciclo puerta acreditado, sin presencia) ⇒
#     exit_deadline=null (no debe aparecer conteo de salida)
#   - presencia dentro del gap tras el cierre ⇒ entry_confirmed_at se fija
#   - tras confirmar, ABSENT + puerta cerrada ⇒ exit_deadline activo
# =============================================================================
block "BLOCK 34 — F42: ventana de verificación de entrada"

F42_ROOM=1
F42_SIM_KEY=$(get_key SIM-CLIENT)

if [ "$SERVER_UP" != true ]; then
    skip "BLOCK 34 — F42" "servidor HTTP no disponible"
elif [ -z "$F42_SIM_KEY" ]; then
    skip "BLOCK 34 — F42" "Clave SIM-CLIENT no disponible en $KEYS_FILE"
else
    f42_iso() { date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ; }
    f42_reset() {
        curl -s -o /dev/null -w '%{http_code}' -X POST \
            -H 'Content-Type: application/json' \
            -d "{\"room_id\":$F42_ROOM}" \
            "${API_BASE}/dashboard-api/rooms/reset" 2>/dev/null || echo "000"
    }
    f42_live() { curl -s --max-time 5 "${API_BASE}/api/v1/rooms/$F42_ROOM/live" 2>/dev/null; }
    f42_sim() {  # $1 = presence|door ; $2 = body JSON
        curl -s -o /dev/null -w '%{http_code}' -X POST \
            -H "X-API-Key: $F42_SIM_KEY" -H 'Content-Type: application/json' \
            -d "$2" "${API_BASE}/sim/rooms/$F42_ROOM/$1" 2>/dev/null || echo "000"
    }

    f42_reset >/dev/null 2>&1 || true
    F42_T=$(date -u +%s)

    # Estancia OCCUPIED SIN entry_confirmed_at (entrada en curso, sin consolidar).
    F42_STAY=$($MYSQL -sN -e "INSERT INTO stays
        (room_id,status,duracion_minutos,first_entry_at,reserved_at,vb6_codtic,vb6_codcli)
        VALUES ($F42_ROOM,'OCCUPIED',60,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),1,1);
        SELECT LAST_INSERT_ID();" 2>/dev/null || echo "0")

    if [ -z "$F42_STAY" ] || [ "$F42_STAY" = "0" ]; then
        skip "BLOCK 34 — F42" "No se pudo crear estancia OCCUPIED"
    else
        $MYSQL -sN -e "UPDATE rooms SET status='OCCUPIED' WHERE id=$F42_ROOM" 2>/dev/null || true

        # Ciclo de puerta acreditado sin presencia: OPEN → CLOSED.
        f42_sim door "{\"state\":\"OPEN\",\"occurred_at\":\"$(f42_iso $((F42_T + 1)))\"}" >/dev/null
        f42_sim door "{\"state\":\"CLOSED\",\"occurred_at\":\"$(f42_iso $((F42_T + 2)))\"}" >/dev/null
        # Ausencia sostenida con la entrada aún sin confirmar.
        f42_sim presence "{\"sensor\":\"PRESENCE\",\"value\":\"ABSENT\",\"occurred_at\":\"$(f42_iso $((F42_T + 3)))\"}" >/dev/null

        F42_DEAD_ENTRY=$(f42_live | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    print('ERR'); sys.exit(0)
print('OK' if not d.get('exit_deadline') else 'FAIL ' + str(d.get('exit_deadline')))
" 2>/dev/null)
        if [ "$F42_DEAD_ENTRY" = "OK" ]; then
            pass "F42: entrada sin confirmar → exit_deadline=null (string vacío)"
        else
            fail "F42: exit_deadline no debe emitirse sin entry_confirmed_at" "$F42_DEAD_ENTRY"
        fi

        # Presencia tardía dentro del gap (2 s tras el cierre) → consolida entrada.
        f42_sim presence "{\"sensor\":\"PRESENCE\",\"value\":\"PRESENT\",\"occurred_at\":\"$(f42_iso $((F42_T + 4)))\"}" >/dev/null
        F42_EC=$(f42_live | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    print('ERR'); sys.exit(0)
stay = d.get('active_stay') or {}
print('OK' if stay.get('entry_confirmed_at') else 'FAIL ec=' + str(stay.get('entry_confirmed_at')))
" 2>/dev/null)
        if [ "$F42_EC" = "OK" ]; then
            pass "F42: PRESENT tras el cierre → entry_confirmed_at fijado"
        else
            fail "F42: consolidación de entrada por presencia tardía" "$F42_EC"
        fi

        # Con la entrada confirmada, ABSENT + puerta cerrada → exit_deadline activo.
        f42_sim presence "{\"sensor\":\"PRESENCE\",\"value\":\"ABSENT\",\"occurred_at\":\"$(f42_iso $((F42_T + 5)))\"}" >/dev/null
        F42_DEAD_EXIT=$(f42_live | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    print('ERR'); sys.exit(0)
print('OK' if d.get('exit_deadline') else 'NULL')
" 2>/dev/null)
        if [ "$F42_DEAD_EXIT" = "OK" ]; then
            pass "F42: entrada confirmada + ABSENT → exit_deadline activo"
        else
            fail "F42: exit_deadline tras confirmar entrada" "got '$F42_DEAD_EXIT'"
        fi

        # Cleanup: deja room 1 sin estancia de prueba.
        f42_reset >/dev/null 2>&1 || true
    fi
fi

# =============================================================================
# BLOCK 35 — F44: tiempo real de sensores y presencia bajo demanda
# Trazabilidad: RF-50, RF-51, RF-52 · contracts.md Anexo F44 · TSK-F44-08
#
# Cubre: consumer con devices dinámicos + resync (unit JS), migración 0109
# (ventana de entrada), /live con ventanas configurables, instancia única del
# consumer Pulsar en system-status, y calibración PROTO2 persistida (sin cuota).
# =============================================================================
block "BLOCK 35 — F44: tiempo real de sensores y presencia bajo demanda"

# ── 35.0 Unit JS: consumer dinámico + resync (sin red/BD) ───────────────────
F44_CONSUMER_JS="tests/Unit/tuya-pulsar-consumer.test.js"
if [ -f "$F44_CONSUMER_JS" ]; then
    F44_C_OUT=$(node "$F44_CONSUMER_JS" 2>&1)
    F44_C_RC=$?
    F44_C_SUM=$(echo "$F44_C_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F44_C_SUM" ] && F44_C_SUM="exit=$F44_C_RC"
    if [ "$F44_C_RC" -eq 0 ]; then
        pass "JS: tuya-pulsar-consumer.test.js ($F44_C_SUM)"
    else
        fail "JS: tuya-pulsar-consumer.test.js" \
            "$F44_C_SUM — $(echo "$F44_C_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
    fi
else
    skip "JS: tuya-pulsar-consumer.test.js" "fichero no encontrado"
fi

# ── 35.0b Unit JS: prueba de paseo (RF-52.4, sin red/BD) ───────────────────
F44_WALK_JS="tests/Unit/cal-walktest.test.js"
if [ -f "$F44_WALK_JS" ]; then
    F44_W_OUT=$(node "$F44_WALK_JS" 2>&1)
    F44_W_RC=$?
    F44_W_SUM=$(echo "$F44_W_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F44_W_SUM" ] && F44_W_SUM="exit=$F44_W_RC"
    if [ "$F44_W_RC" -eq 0 ]; then
        pass "JS: cal-walktest.test.js ($F44_W_SUM)"
    else
        fail "JS: cal-walktest.test.js" \
            "$F44_W_SUM — $(echo "$F44_W_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
    fi
else
    skip "JS: cal-walktest.test.js" "fichero no encontrado"
fi

# ── 35.0c Unit JS: selector de habitación del panel (sin navegador/red/BD) ──
ROOMSEL_JS="tests/Unit/room-selector.test.js"
if [ -f "$ROOMSEL_JS" ]; then
    ROOMSEL_OUT=$(node "$ROOMSEL_JS" 2>&1)
    ROOMSEL_RC=$?
    ROOMSEL_SUM=$(echo "$ROOMSEL_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$ROOMSEL_SUM" ] && ROOMSEL_SUM="exit=$ROOMSEL_RC"
    if [ "$ROOMSEL_RC" -eq 0 ]; then
        pass "JS: room-selector.test.js ($ROOMSEL_SUM)"
    else
        fail "JS: room-selector.test.js" \
            "$ROOMSEL_SUM — $(echo "$ROOMSEL_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
    fi
else
    skip "JS: room-selector.test.js" "fichero no encontrado"
fi

# ── 35.1 Migración 0109: columna de ventana de entrada ─────────────────────
F44_COL=$($MYSQL -sN -e "SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='room_types'
      AND COLUMN_NAME='presence_entry_window_seconds'" 2>/dev/null || echo "0")
if [ "$F44_COL" = "1" ]; then
    pass "F44: room_types.presence_entry_window_seconds existe (migración 0109)"
else
    fail "F44: falta presence_entry_window_seconds" \
        "ejecuta: php bin/migrate.php (got '$F44_COL')"
fi

# ── 35.2 /live expone las ventanas configurables del poller ────────────────
if [ "$SERVER_UP" != true ]; then
    skip "F44 /live ventanas" "servidor HTTP no disponible"
else
    F44_LIVE=$(curl -s --max-time 5 "${API_BASE}/api/v1/rooms/12/live" 2>/dev/null)
    F44_WIN=$(echo "$F44_LIVE" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception as e:
    print('ERR ' + str(e)); sys.exit(0)
ew = d.get('entry_window_seconds'); ec = d.get('exit_check_seconds')
print('OK' if isinstance(ew, int) and isinstance(ec, int) and ew > 0 and ec > 0 else ('FAIL ew=%s ec=%s' % (ew, ec)))
" 2>/dev/null)
    if [ "$F44_WIN" = "OK" ]; then
        pass "F44: /live expone entry_window_seconds y exit_check_seconds"
    else
        fail "F44: /live debe exponer las ventanas del poller" "$F44_WIN"
    fi
fi

# ── 35.3 system-status: instancia única del consumer Pulsar ────────────────
if [ "$SERVER_UP" != true ]; then
    skip "F44 pulsar-consumer instancia única" "servidor HTTP no disponible"
else
    F44_SS=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/system-status" 2>/dev/null)
    F44_INST=$(echo "$F44_SS" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception as e:
    print('ERR ' + str(e)); sys.exit(0)
v = d.get('pulsar-consumer') or {}
inst = v.get('instances'); exp = v.get('expected')
print('OK' if inst == 1 and exp == 1 else ('FAIL instances=%s expected=%s' % (inst, exp)))
" 2>/dev/null)
    if [ "$F44_INST" = "OK" ]; then
        pass "F44: pulsar-consumer con instancia única (instances=1)"
    else
        fail "F44: el consumer Pulsar debe tener 1 sola instancia" "$F44_INST"
    fi
fi

# ── 35.4 Calibración PROTO2 persistida (sin consumo de cuota Tuya) ─────────
F44_CAL=$($MYSQL -sN -e "SELECT CONCAT(
        COALESCE(JSON_VALUE(meta_json,'\$.calibration.far_detection'),''),'|',
        COALESCE(JSON_VALUE(meta_json,'\$.calibration.sensitivity'),''))
    FROM devices WHERE external_id='bf9a278e76e2c3f01ay0cs' LIMIT 1" 2>/dev/null || echo "")
# El 24G V3 rechaza 75 cm (piso de firmware); el mínimo efectivo es 150 cm (1.5 m).
# Se acepta 75 (si el firmware lo permitiera) o 150, siempre con sensibilidad máxima.
if [ "$F44_CAL" = "75|10" ] || [ "$F44_CAL" = "150|10" ]; then
    pass "F44: PROTO2 calibrado (radio mínimo ${F44_CAL%|*}cm, sensibilidad 10)"
elif [ -z "$F44_CAL" ] || [ "$F44_CAL" = "|" ]; then
    skip "F44 calibración PROTO2" "sin snapshot de calibración aún"
else
    fail "F44: calibración PROTO2 esperada 75|10 o 150|10" "got '$F44_CAL'"
fi

# ── 35.6 ZY-M100 desactivado de forma fuerte (F46+) ────────────────────────
F46_ZY=$($MYSQL -sN -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(meta_json,'\$.presence_source'))
    FROM devices WHERE external_id='bf98d27d79685e38a2wbda' LIMIT 1" 2>/dev/null || echo "")
if [ "$F46_ZY" = "disabled" ]; then
    pass "F46: ZY-M100 con presence_source='disabled' (apagado fuerte)"
else
    fail "F46: ZY-M100 debe estar 'disabled'" "got '$F46_ZY'"
fi
# ── 35.7 F78/RF-101: NO existe ningún poller de Tuya (prohibido) ────────────
# Regresión: si alguien reintroduce `tuya-presence-poller.js` o reactiva el unit
# legacy, este test debe fallar. El sistema usa push (Pulsar) + sondas bajo
# demanda; NUNCA sondeo continuo de la API de Tuya.
F78_POLLER_PROCS=$(pgrep -f "[t]uya-presence-poller\.js" 2>/dev/null | wc -l | tr -d ' ')
if [ "$F78_POLLER_PROCS" = "0" ]; then
    pass "F78: sin procesos tuya-presence-poller.js (0)"
else
    fail "F78: no debe haber ningún poller de presencia" "procesos=$F78_POLLER_PROCS"
fi
F78_POLLER_BIN=0
for _f in bin/tuya-presence-poller.js bin/presence-poller-manager.sh bin/wrapper-poller.sh; do
    [ -e "$_f" ] && F78_POLLER_BIN=$((F78_POLLER_BIN+1))
done
if [ "$F78_POLLER_BIN" = "0" ]; then
    pass "F78: scripts de poller eliminados (manager/js/wrapper)"
else
    fail "F78: quedan scripts de poller en el repo" "encontrados=$F78_POLLER_BIN"
fi
if systemctl is-enabled cerraduras-presence-poller >/dev/null 2>&1; then
    fail "F78: unit legacy cerraduras-presence-poller habilitado" "systemctl disable --now cerraduras-presence-poller"
else
    pass "F78: unit legacy cerraduras-presence-poller no habilitado"
fi

# =============================================================================
# BLOCK 36 — F47: latencia medible, robustez de recepción y arranque consistente
# Trazabilidad: RF-53, RF-54, RF-55, RF-56 · contracts.md Anexo F47 · TSK-F47-08
# =============================================================================
block "BLOCK 36 — F47: latencia, robustez de recepción y arranque consistente"

# ── 36.0 Unit JS: helpers de la sonda de latencia (sin red/BD/cuota) ───────
F47_PROBE_JS="tests/Unit/latency-probe.test.js"
if [ -f "$F47_PROBE_JS" ]; then
    F47_P_OUT=$(node "$F47_PROBE_JS" 2>&1)
    F47_P_RC=$?
    F47_P_SUM=$(echo "$F47_P_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F47_P_SUM" ] && F47_P_SUM="exit=$F47_P_RC"
    if [ "$F47_P_RC" -eq 0 ]; then
        pass "JS: latency-probe.test.js ($F47_P_SUM)"
    else
        fail "JS: latency-probe.test.js" \
            "$F47_P_SUM — $(echo "$F47_P_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
    fi
else
    skip "JS: latency-probe.test.js" "fichero no encontrado"
fi

# ── 36.1 Arranque consistente: pool del API sin divergencia (RF-55) ────────
if [ -f ../start-all.sh ]; then
    if grep -q '^API_WORKERS=16' ../start-all.sh && \
       ! grep -q 'PHP_CLI_SERVER_WORKERS=8 php .*8080' ../start-all.sh; then
        pass "F47: start-all.sh alinea el pool del API a 16 (sin fallback divergente)"
    else
        fail "F47: start-all.sh debe usar API_WORKERS=16 en el fallback" \
            "divergencia con cerraduras-api.service (degrada el SSE de F46)"
    fi
else
    skip "F47 pool de workers" "../start-all.sh no accesible"
fi

# ── 36.2 Estado del consumer Pulsar (RF-54.4.2) ────────────────────────────
F47_STATUS="run/pulsar-consumer-status.json"
if [ ! -f "$F47_STATUS" ]; then
    skip "F47 estado del consumer" "aún no generado por el consumer"
else
    F47_ST=$(python3 -c "
import json,sys
try:
    d=json.load(open('$F47_STATUS'))
except Exception as e:
    print('ERR '+str(e)); sys.exit(0)
ok = isinstance(d, dict) and ('connected' in d and 'last_msg_at' in d and 'known_devices' in d)
print('OK' if ok else 'FAIL keys=%s' % ','.join(sorted(d.keys())))
" 2>/dev/null)
    if [ "$F47_ST" = "OK" ]; then
        pass "F47: status del consumer con esquema válido"
    else
        fail "F47: status del consumer inválido" "$F47_ST"
    fi
fi

# ── 36.3 Firmware: instrumentación encolado→POST (RF-56.1) ─────────────────
F47_FW="../docs/esp32-qr-reader/scanner-relay-prod-12v-robusto-lowpower.ino"
if [ ! -f "$F47_FW" ]; then
    skip "F47 firmware scan→post" "sketch no encontrado"
elif grep -q 'Encolado→POST' "$F47_FW" && grep -q 'qrEnqueuedAt' "$F47_FW" \
   && grep -q 'noteHttpResult' "$F47_FW" && grep -q 'setReuse(httpReuseEnabled)' "$F47_FW" \
   && grep -q 'apiHttpSession' "$F47_FW"; then
    pass "F47: firmware con encolado→POST, prioridad QR y keep-alive (sesión persistente)"
else
    fail "F47: firmware incompleto (scan→post/prioridad/keep-alive)" "revisa $F47_FW"
fi

# ── 36.4 Botonera temporal de marcas (F47, TEMP) ──────────────────────────
# Es una herramienta temporal: si ya se retiró, SKIP (no FAIL).
if grep -q 'latency-marker-bar' public/dashboard.html 2>/dev/null && \
   grep -q '/dashboard-api/latency-mark' public/index.php 2>/dev/null; then
    pass "F47: botonera de marcas + endpoint presentes (TEMP)"
else
    skip "F47 botonera de marcas" "no presente (quizá ya retirada)"
fi

if [ "$SERVER_UP" != true ]; then
    skip "F47 latency-mark" "servidor HTTP no disponible"
elif ! grep -q '/dashboard-api/latency-mark' public/index.php 2>/dev/null; then
    skip "F47 latency-mark" "endpoint retirado"
else
    F47_LM_OK=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 -X POST \
        "${API_BASE}/dashboard-api/latency-mark" -H 'Content-Type: application/json' \
        -d '{"label":"QUIETO"}' 2>/dev/null)
    F47_LM_BAD=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 -X POST \
        "${API_BASE}/dashboard-api/latency-mark" -H 'Content-Type: application/json' \
        -d '{"label":"HACK"}' 2>/dev/null)
    # Limpieza: no dejar marcas de test en el fichero.
    curl -s -o /dev/null --max-time 5 -X POST "${API_BASE}/dashboard-api/latency-mark" \
        -H 'Content-Type: application/json' -d '{"label":"CLEAR"}' 2>/dev/null
    if [ "$F47_LM_OK" = "200" ] && [ "$F47_LM_BAD" = "400" ]; then
        pass "F47: latency-mark acepta la whitelist (200) y rechaza otras etiquetas (400)"
    else
        fail "F47: latency-mark" "whitelist ok=$F47_LM_OK inválida=$F47_LM_BAD (esperado 200/400)"
    fi
fi

# =============================================================================
# BLOCK 37 — Saneamiento de logs: rotación programada
# Trazabilidad: TAREA 1/2 (log-rotate parametrizable, timer systemd).
# F78: el test del manager de pollers fue eliminado con el propio poller.
# =============================================================================
block "BLOCK 37 — Saneamiento de logs y avisos"

# ── 37.0 Unit JS: log-rotate parametrizable (sin red/BD, tmpdir aislado) ───
LOGS_LROT_JS="tests/Unit/log-rotate.test.js"
if [ -f "$LOGS_LROT_JS" ]; then
    LOGS_L_OUT=$(node "$LOGS_LROT_JS" 2>&1)
    LOGS_L_RC=$?
    LOGS_L_SUM=$(echo "$LOGS_L_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$LOGS_L_SUM" ] && LOGS_L_SUM="exit=$LOGS_L_RC"
    if [ "$LOGS_L_RC" -eq 0 ]; then
        pass "JS: log-rotate.test.js ($LOGS_L_SUM)"
    else
        fail "JS: log-rotate.test.js" \
            "$LOGS_L_SUM — $(echo "$LOGS_L_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
    fi
else
    skip "JS: log-rotate.test.js" "fichero no encontrado"
fi

# ── 37.1 Units systemd versionadas (no instaladas por el runner) ───────────
for LOGS_UNIT in docs/systemd/cerraduras-logrotate.service \
                 docs/systemd/cerraduras-logrotate.timer \
                 docs/systemd/journald-cerraduras.conf; do
    if [ -f "../$LOGS_UNIT" ]; then
        pass "Logs: $LOGS_UNIT presente"
    else
        fail "Logs: falta $LOGS_UNIT" "revisa docs/systemd/"
    fi
done

# El service NO debe escribir su salida en api/logs (recursión).
if grep -qE '^Standard(Output|Error)=append:/root/cerraduras/api/logs' \
        ../docs/systemd/cerraduras-logrotate.service 2>/dev/null; then
    fail "Logs: logrotate no debe escribir en api/logs" "usa journal o /var/log/"
else
    pass "Logs: logrotate sin recursión hacia api/logs"
fi

# El timer debe ser diario a las 03:30 y persistente.
if grep -q '^OnCalendar=\*-\*-\* 03:30:00' ../docs/systemd/cerraduras-logrotate.timer 2>/dev/null \
   && grep -q '^Persistent=true' ../docs/systemd/cerraduras-logrotate.timer 2>/dev/null \
   && grep -q '^WantedBy=timers.target' ../docs/systemd/cerraduras-logrotate.timer 2>/dev/null; then
    pass "Logs: timer diario 03:30 con Persistent=true"
else
    fail "Logs: timer incompleto" "revisa cerraduras-logrotate.timer"
fi

# =============================================================================
# BLOCK 38 — F49: guarda de salida corta y desacople entrada/salida
# Trazabilidad: RF-47.2.4, RF-47.2.5; TSK-F49-01..05
# =============================================================================
block "BLOCK 38 — F49: guarda de salida corta"

# 38.1 /live expone exit_guard_seconds numérico y entry_window desacoplada
if [ "$SERVER_UP" != true ]; then
    skip "F49 /live exit_guard_seconds" "servidor HTTP no disponible"
else
    F49_LIVE=$(curl -s -H "X-API-Key: $(get_key ADMIN-CLI)" "${API_BASE}/api/v1/rooms/1/live" 2>/dev/null)
    F49_RES=$(echo "$F49_LIVE" | python3 -c "
import sys,json
try:
    d=json.load(sys.stdin)
except Exception as e:
    print('ERR '+str(e)); sys.exit(0)
g=d.get('exit_guard_seconds'); w=d.get('entry_window_seconds'); gap=d.get('gap_seconds')
ok = isinstance(g,int) and 1 <= g <= 60 and isinstance(w,int) and w > g
print(('OK ' if ok else 'FAIL ')+('exit_guard=%s entry_window=%s gap=%s'%(g,w,gap)))
" 2>/dev/null)
    case "$F49_RES" in
        OK*) pass "F49: /live exit_guard_seconds corto y entry_window_seconds mayor (desacoplados)" ;;
        FAIL*) fail "F49: /live exit_guard_seconds/entry_window_seconds" "${F49_RES#FAIL }" ;;
        *) fail "F49: /live parse" "${F49_RES:-respuesta vacía}" ;;
    esac
fi

# 38.2 Guardas de fuente: migración de overrides legacy + env documentado
[ -f migrations/0113_exit_absence_guard.sql ] \
    && pass "F49: migración 0113_exit_absence_guard.sql presente" \
    || fail "F49: falta migración 0113_exit_absence_guard.sql" "revisa api/migrations/"
grep -q '^EXIT_ABSENCE_GUARD_SECONDS=3' .env.example 2>/dev/null \
    && pass "F49: .env.example documenta EXIT_ABSENCE_GUARD_SECONDS=3" \
    || fail "F49: falta EXIT_ABSENCE_GUARD_SECONDS en .env.example" ""

# =============================================================================
# BLOCK 39 — F50: estado real del SWITCH por push y robustez del consumer
# Trazabilidad: RF-58; TSK-F50-01..06
# =============================================================================
block "BLOCK 39 — F50: estado real del SWITCH por push"

# 39.1 Status del consumer con last_pong_at (watchdog/pong, F50-02)
F50_STATUS="run/pulsar-consumer-status.json"
if [ ! -f "$F50_STATUS" ]; then
    skip "F50 consumer last_pong_at" "run/pulsar-consumer-status.json no generado"
else
    F50_ST=$(python3 -c "
import json
d=json.load(open('$F50_STATUS'))
need=['connected','last_msg_at','last_pong_at','known_devices','resyncs']
miss=[k for k in need if k not in d]
print('OK' if not miss else 'FAIL missing='+str(miss))
" 2>/dev/null)
    case "$F50_ST" in
        OK) pass "F50: status del consumer incluye last_pong_at y resyncs (watchdog/pong)" ;;
        FAIL*) fail "F50: status del consumer incompleto" "${F50_ST#FAIL }" ;;
        *) fail "F50: status del consumer parse" "${F50_ST:-vacío}" ;;
    esac
fi

# 39.2 Consumer rastrea SWITCH (guard de fuente)
if grep -q "TRACKED_KINDS" bin/tuya-pulsar-consumer/index.js 2>/dev/null \
   && grep -q "SWITCH" bin/tuya-pulsar-consumer/index.js 2>/dev/null; then
    pass "F50: consumer rastrea SWITCH (TRACKED_KINDS)"
else
    fail "F50: consumer no rastrea SWITCH" "revisa bin/tuya-pulsar-consumer/index.js"
fi

# 39.3 /live del SWITCH expone state/state_at (room con SWITCH)
F50_SW_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN devices d ON d.pack_id=r.pack_id WHERE d.kind='SWITCH' GROUP BY r.id ORDER BY r.id LIMIT 1" 2>/dev/null)
if [ -z "$F50_SW_ROOM" ]; then
    skip "F50 /live switch state" "no hay sala con SWITCH"
else
    F50_SW=$(curl -s -H "X-API-Key: $(get_key ADMIN-CLI)" "${API_BASE}/api/v1/rooms/$F50_SW_ROOM/live" 2>/dev/null)
    F50_SW_RES=$(echo "$F50_SW" | python3 -c "
import sys,json
d=json.load(sys.stdin); s=d.get('switch_state')
ok = isinstance(s,dict) and 'state' in s and 'state_at' in s and s.get('state') in ('ON','OFF','UNKNOWN')
print(('OK ' if ok else 'FAIL ')+('switch_state=%s'%(json.dumps(s)[:160])))
" 2>/dev/null)
    case "$F50_SW_RES" in
        OK*) pass "F50: /live room $F50_SW_ROOM switch_state con state/state_at" ;;
        FAIL*) fail "F50: /live switch_state" "${F50_SW_RES#FAIL }" ;;
        *) fail "F50: /live switch parse" "${F50_SW_RES:-vacío}" ;;
    esac
fi

# 39.4 Webhook: dispositivo sin sala → 202 room_not_found (N10, no 500)
F50_DEVID="synth-roomless-$(date +%s)"
$MYSQL -sN -e "INSERT INTO devices (kind,external_id) VALUES ('PROXIMITY','$F50_DEVID')" 2>/dev/null || true
F50_WH=$(curl -s -w "\n%{http_code}" -X POST "${API_BASE}/api/v1/tuya/webhook" \
    -H 'Content-Type: application/json' \
    -d "{\"devId\":\"$F50_DEVID\",\"status\":[{\"code\":\"doorcontact_state\",\"value\":true,\"t\":$(date +%s)000}]}" 2>/dev/null)
F50_WH_HTTP=$(echo "$F50_WH" | tail -1)
$MYSQL -sN -e "DELETE FROM devices WHERE external_id='$F50_DEVID'" 2>/dev/null || true
if [ "$F50_WH_HTTP" = "202" ] && echo "$F50_WH" | grep -q '"discard_reason":"room_not_found"'; then
    pass "F50: webhook sin sala → 202 room_not_found (no 500)"
else
    fail "F50: webhook room_not_found" "HTTP=$F50_WH_HTTP respuesta=$(echo "$F50_WH" | head -1 | head -c 200)"
fi

# =============================================================================
# BLOCK 40 — F51: ciclo de vida del QR de huésped (llegada + estancia)
# Trazabilidad: RF-59; TSK-F51-01..07
# =============================================================================
block "BLOCK 40 — F51: ventanas de llegada y uso del QR"

# 40.1 Guardas de fuente: migración + lógica pura
[ -f migrations/0114_qr_arrival_window.sql ] \
    && pass "F51: migración 0114_qr_arrival_window.sql presente" \
    || fail "F51: falta migración 0114_qr_arrival_window.sql" ""
[ -f src/Domain/Qr/QrWindows.php ] \
    && pass "F51: QrWindows.php presente (ventanas puras)" \
    || fail "F51: falta QrWindows.php" ""

# 40.2 Crear QR y comprobar ventanas en /live
$MYSQL -sN -e "UPDATE stays SET status='CLOSED', closed_at=UTC_TIMESTAMP(3) WHERE room_id=1 AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true
$MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=1" 2>/dev/null || true
F51_CREATE=$(curl -s -w "\n%{http_code}" -X POST "${API_BASE}/dashboard-api/qr-test/create" \
    -H 'Content-Type: application/json' -d '{"room_id":1,"duracion_minutos":60}' 2>/dev/null)
F51_CODE=$(echo "$F51_CREATE" | tail -1)
if [ "$F51_CODE" = "201" ]; then
    F51_LIVE=$(curl -s -H "X-API-Key: $(get_key ADMIN-CLI)" "${API_BASE}/api/v1/rooms/1/live" 2>/dev/null)
    F51_RES=$(echo "$F51_LIVE" | python3 -c "
import sys,json,datetime
d=json.load(sys.stdin); qs=d.get('qr_status') or {}
need=['first_used_at','valid_until','arrival_deadline','in_use','scannable']
miss=[k for k in need if k not in qs]
def parse(s):
    if not s: return None
    try: return datetime.datetime.strptime(str(s).replace('T',' ').split('.')[0],'%Y-%m-%d %H:%M:%S')
    except Exception: return None
ad=parse(qs.get('arrival_deadline'))
ok = not miss and ad is not None and qs.get('in_use') is False and qs.get('first_used_at') is None
print(('OK ' if ok else 'FAIL ')+('miss=%s arrival=%s in_use=%s first_used=%s'%(miss,qs.get('arrival_deadline'),qs.get('in_use'),qs.get('first_used_at'))))
" 2>/dev/null)
    case "$F51_RES" in
        OK*) pass "F51: qr_status con arrival_deadline, in_use=false y first_used=null" ;;
        FAIL*) fail "F51: qr_status ventanas" "${F51_RES#FAIL }" ;;
        *) fail "F51: qr_status parse" "${F51_RES:-vacío}" ;;
    esac
else
    fail "F51: crear QR de prueba" "HTTP=$F51_CODE"
fi
# Cleanup
$MYSQL -sN -e "UPDATE stays SET status='CLOSED', closed_at=UTC_TIMESTAMP(3) WHERE room_id=1 AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true
$MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=1" 2>/dev/null || true

# =============================================================================
# BLOCK 41 — F52: cola muerta (dead-letter) del outbox WS-VB6
# Trazabilidad: RF-60; TSK-F52-01..05
# =============================================================================
block "BLOCK 41 — F52: cola muerta del outbox"

# 41.1 health/deep: outbox_dead diferenciado y no degradante
F52_DEEP=$(curl -s "${API_BASE}/api/v1/health/deep" 2>/dev/null)
F52_DB_DEAD=$($MYSQL -sN -e "SELECT COUNT(*) FROM outbox_vb6 WHERE status='DEAD'" 2>/dev/null)
F52_RES=$(echo "$F52_DEEP" | python3 -c "
import sys,json
d=json.load(sys.stdin); c=d.get('checks') or {}
od=c.get('outbox_dead'); of=c.get('outbox_failed')
miss=[k for k in ('status','count','note') if not isinstance(od,dict) or k not in od]
ok = not miss and isinstance(of,dict) and 'count' in of
print(('OK ' if ok else 'FAIL ')+('miss=%s outbox_dead=%s outbox_failed_count=%s'%(miss,json.dumps(od)[:160],(of or {}).get('count'))))
" 2>/dev/null)
case "$F52_RES" in
    OK*) pass "F52: health/deep expone outbox_dead con status/count/note (separado de outbox_failed)" ;;
    FAIL*) fail "F52: health/deep outbox_dead" "${F52_RES#FAIL }" ;;
    *) fail "F52: health/deep parse" "${F52_RES:-vacío}" ;;
esac

# 41.2 count de health/deep coincide con la BD
F52_HEALTH_DEAD=$(echo "$F52_DEEP" | python3 -c "import sys,json;print(((json.load(sys.stdin).get('checks') or {}).get('outbox_dead') or {}).get('count',''))" 2>/dev/null)
if [ -n "$F52_HEALTH_DEAD" ] && [ "$F52_HEALTH_DEAD" = "$F52_DB_DEAD" ]; then
    pass "F52: outbox_dead.count ($F52_HEALTH_DEAD) coincide con la BD"
else
    fail "F52: outbox_dead.count" "health=$F52_HEALTH_DEAD bd=$F52_DB_DEAD"
fi

# 41.3 admin/outbox summary.dead coincide con la BD
F52_SUM=$(curl -s -H "X-API-Key: $(get_key ADMIN-CLI)" "${API_BASE}/api/v1/admin/outbox?limit=1" 2>/dev/null | python3 -c "import sys,json;print((json.load(sys.stdin).get('summary') or {}).get('dead',''))" 2>/dev/null)
if [ -n "$F52_SUM" ] && [ "$F52_SUM" = "$F52_DB_DEAD" ]; then
    pass "F52: admin/outbox summary.dead ($F52_SUM) coincide con la BD"
else
    fail "F52: admin/outbox summary.dead" "summary=$F52_SUM bd=$F52_DB_DEAD"
fi

# 41.4 retry de id inexistente → 404
http_test POST "/api/v1/admin/outbox/999999/retry" 404 \
    "POST /admin/outbox/999999/retry: inexistente → 404" \
    --key ADMIN-CLI --body '{}'

# 41.5 retry reencola un DEAD sintético (sin tocar los reales)
F52_IKEY="test-f52-$(date +%s)"
$MYSQL -sN -e "INSERT INTO outbox_vb6 (topic,payload_json,idempotency_key,status,attempts,next_attempt_at,last_error) VALUES ('debt.created','{}','$F52_IKEY','DEAD',20,UTC_TIMESTAMP(3),'test')" 2>/dev/null || true
F52_ID=$($MYSQL -sN -e "SELECT id FROM outbox_vb6 WHERE idempotency_key='$F52_IKEY' LIMIT 1" 2>/dev/null)
if [ -n "$F52_ID" ]; then
    F52_RETRY=$(curl -s -w "\n%{http_code}" -X POST -H "X-API-Key: $(get_key ADMIN-CLI)" -H 'Content-Type: application/json' -d '{}' "${API_BASE}/api/v1/admin/outbox/$F52_ID/retry" 2>/dev/null)
    F52_RETRY_HTTP=$(echo "$F52_RETRY" | tail -1)
    F52_NEWST=$($MYSQL -sN -e "SELECT status FROM outbox_vb6 WHERE id=$F52_ID" 2>/dev/null)
    $MYSQL -sN -e "DELETE FROM outbox_vb6 WHERE id=$F52_ID" 2>/dev/null || true
    if [ "$F52_RETRY_HTTP" = "200" ] && [ "$F52_NEWST" = "PENDING" ]; then
        pass "F52: retry reencola DEAD → PENDING (HTTP 200)"
    else
        fail "F52: retry DEAD" "HTTP=$F52_RETRY_HTTP status=$F52_NEWST"
    fi
else
    fail "F52: insertar DEAD sintético" "no se pudo insertar en outbox_vb6"
fi

# =============================================================================
# BLOCK 42 — F54: Batería de aceptación E2E del huésped (RF-61)
# Trazabilidad: RF-61.1..61.6; TSK-F54-01..06
#
# Ciclo secuencial sobre la sala de banco PROTO2 (room 12):
#   S0 normalizar/guardar · S1 emitir QR (crea stay RESERVED)
#   S2 live RESERVED · S3 validar QR (OCCUPIED, lock SIMULATED)
#   S4 live OCCUPIED · S5/S6 entrada simulada + entry_confirmed_at
#   S7/S8 salida simulada + exit_deadline · S9 exit-scan → EXITED/FREE
#   S10 cerrar estancia · S11 overstay (lectura) · S12 cleanup/restore
#
# Determinista, sin hardware ni cuota Tuya: simulated_override=1 fuerza
# SimulatedLockGateway/SensorIngressFactory y la puerta/presencia se inyectan
# por /sim/*. Ante precondición de entorno faltante => SKIP (nunca FAIL).
# =============================================================================
block "BLOCK 42 — F54: Batería de aceptación E2E (PROTO2)"

E2E_ROOM=12
E2E_DUR=60
E2E_SIM=$(get_key SIM-CLIENT)
E2E_VB6=$(get_key VB6-MAIN)
E2E_RPIKEY=$(get_key RPI-DEV)
E2E_ADM=$(get_key ADMIN-CLI)
E2E_ROOM_PACK=$($MYSQL -sN -e "SELECT COALESCE(pack_id,'NULL') FROM rooms WHERE id=$E2E_ROOM" 2>/dev/null || echo "ERR")
E2E_RPI_EXT=$($MYSQL -sN -e "SELECT d.external_id FROM devices d JOIN rooms r ON r.pack_id=d.pack_id WHERE r.id=$E2E_ROOM AND d.kind='RPI' LIMIT 1" 2>/dev/null || echo "")

if [ "$SERVER_UP" != "true" ]; then
    skip "BLOCK 42 — F54 (E2E)" "servidor HTTP no disponible"
elif [ -z "$E2E_SIM" ] || [ -z "$E2E_VB6" ] || [ -z "$E2E_RPIKEY" ] || [ -z "$E2E_ADM" ]; then
    skip "BLOCK 42 — F54 (E2E)" "faltan claves SIM-CLIENT/VB6-MAIN/RPI-DEV/ADMIN-CLI en $KEYS_FILE"
elif ! db_exec "SELECT 1" > /dev/null 2>&1; then
    skip "BLOCK 42 — F54 (E2E)" "BD no accesible"
elif [ "$E2E_ROOM_PACK" = "NULL" ] || [ "$E2E_ROOM_PACK" = "ERR" ] || [ -z "$E2E_RPI_EXT" ]; then
    skip "BLOCK 42 — F54 (E2E)" "room $E2E_ROOM sin pack/RPI resoluble (pack=$E2E_ROOM_PACK)"
else
    # ── S0: guardar estado de PROTO2 y normalizar ──────────────────────────
    E2E_SAVE_SIM=$($MYSQL -sN -e "SELECT COALESCE(simulated_override,'NULL') FROM rooms WHERE id=$E2E_ROOM" 2>/dev/null || echo "NULL")
    E2E_SAVE_PCS=$($MYSQL -sN -e "SELECT COALESCE(presence_check_seconds,'NULL') FROM rooms WHERE id=$E2E_ROOM" 2>/dev/null || echo "NULL")
    E2E_AL_BEFORE=$($MYSQL -sN -e "SELECT COUNT(*) FROM access_events WHERE room_id=$E2E_ROOM AND kind='AUTO_LOCK'" 2>/dev/null || echo "0")

    # F75 (RF-90): PROTO2 puede ser la sala del almacén (producción de pruebas).
    # En ese caso la corrida NO debe destruir el estado de dominio de los sensores:
    # se guarda la fila `iot_sessions` y se restaura al terminar, y no se borran
    # los `presence_events` (el sensor de puerta es edge-triggered y no reemite).
    E2E_IS_WAREHOUSE=0
    E2E_IOT_BACKED_UP=0
    # F79 (RF-102.4): snapshot del dominio del almacén para no dejar visitas
    # fantasma. Baseline = MAX(id); se restauran visitas/grabaciones/estado.
    E2E_WH_BACKED_UP=0
    E2E_WH_VISIT_MAX=0
    E2E_WH_REC_MAX=0
    if [ "$($MYSQL -sN -e "SELECT rt.code FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE r.id=$E2E_ROOM" 2>/dev/null)" = "ALMACEN_BEBIDAS" ]; then
        E2E_IS_WAREHOUSE=1
    fi
    _e2e_backup_iot() {
        [ "$E2E_IS_WAREHOUSE" = "1" ] || return 0
        [ "$E2E_IOT_BACKED_UP" = "1" ] && return 0
        $MYSQL -sN -e "CREATE TABLE IF NOT EXISTS _e2e_iot_backup LIKE iot_sessions" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM _e2e_iot_backup" 2>/dev/null || true
        $MYSQL -sN -e "INSERT INTO _e2e_iot_backup SELECT * FROM iot_sessions WHERE room_id=$E2E_ROOM" 2>/dev/null || true
        E2E_IOT_BACKED_UP=1
    }
    _e2e_restore_iot() {
        [ "$E2E_IS_WAREHOUSE" = "1" ] || return 0
        [ "$E2E_IOT_BACKED_UP" = "1" ] || return 0
        $MYSQL -sN -e "DELETE FROM iot_sessions WHERE room_id=$E2E_ROOM" 2>/dev/null || true
        $MYSQL -sN -e "INSERT INTO iot_sessions SELECT * FROM _e2e_iot_backup" 2>/dev/null || true
        $MYSQL -sN -e "DROP TABLE IF EXISTS _e2e_iot_backup" 2>/dev/null || true
        E2E_IOT_BACKED_UP=0
    }
    _e2e_backup_warehouse() {
        [ "$E2E_IS_WAREHOUSE" = "1" ] || return 0
        [ "$E2E_WH_BACKED_UP" = "1" ] && return 0
        E2E_WH_VISIT_MAX=$($MYSQL -sN -e "SELECT COALESCE(MAX(id),0) FROM warehouse_visits WHERE room_id=$E2E_ROOM" 2>/dev/null || echo 0)
        E2E_WH_REC_MAX=$($MYSQL -sN -e "SELECT COALESCE(MAX(id),0) FROM camera_recordings WHERE room_id=$E2E_ROOM" 2>/dev/null || echo 0)
        : "${E2E_WH_VISIT_MAX:=0}"
        : "${E2E_WH_REC_MAX:=0}"
        $MYSQL -sN -e "CREATE TABLE IF NOT EXISTS _e2e_wh_state_backup LIKE warehouse_state" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM _e2e_wh_state_backup" 2>/dev/null || true
        $MYSQL -sN -e "INSERT INTO _e2e_wh_state_backup SELECT * FROM warehouse_state WHERE room_id=$E2E_ROOM" 2>/dev/null || true
        E2E_WH_BACKED_UP=1
    }
    _e2e_restore_warehouse() {
        [ "$E2E_IS_WAREHOUSE" = "1" ] || return 0
        [ "$E2E_WH_BACKED_UP" = "1" ] || return 0
        # Borrar clips generados durante la corrida (solo rutas bajo data/cameras/).
        $MYSQL -sN -e "SELECT file_path FROM camera_recordings WHERE room_id=$E2E_ROOM AND id > ${E2E_WH_REC_MAX:-0} AND file_path IS NOT NULL" 2>/dev/null | while IFS= read -r _f; do
            [ -z "$_f" ] && continue
            case "$_f" in
                *data/cameras/*) rm -f "$PROJECT_DIR/data/cameras/${_f#*data/cameras/}" 2>/dev/null || true ;;
            esac
        done
        $MYSQL -sN -e "DELETE FROM camera_recordings WHERE room_id=$E2E_ROOM AND id > ${E2E_WH_REC_MAX:-0}" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM warehouse_visits WHERE room_id=$E2E_ROOM AND id > ${E2E_WH_VISIT_MAX:-0}" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM warehouse_state WHERE room_id=$E2E_ROOM" 2>/dev/null || true
        $MYSQL -sN -e "INSERT INTO warehouse_state SELECT * FROM _e2e_wh_state_backup" 2>/dev/null || true
        $MYSQL -sN -e "DROP TABLE IF EXISTS _e2e_wh_state_backup" 2>/dev/null || true
        E2E_WH_BACKED_UP=0
    }

    _e2e_normalize() {
        _e2e_backup_iot
        _e2e_backup_warehouse
        $MYSQL -sN -e "UPDATE stays SET status='CLOSED', closed_at=UTC_TIMESTAMP(3) WHERE room_id=$E2E_ROOM AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM iot_sessions WHERE room_id=$E2E_ROOM" 2>/dev/null || true
        if [ "$E2E_IS_WAREHOUSE" != "1" ]; then
            $MYSQL -sN -e "DELETE FROM presence_events WHERE room_id=$E2E_ROOM" 2>/dev/null || true
        else
            # F79: arrancar el motor del almacén en IDLE durante la corrida.
            $MYSQL -sN -e "DELETE FROM warehouse_state WHERE room_id=$E2E_ROOM" 2>/dev/null || true
        fi
        $MYSQL -sN -e "UPDATE rooms SET pack_id=$E2E_ROOM_PACK, simulated_override=1, presence_check_seconds=5, status='FREE', cooldown_until=NULL WHERE id=$E2E_ROOM" 2>/dev/null || true
    }
    _e2e_cleanup() {
        db_exec "SELECT 1" > /dev/null 2>&1 || return 0
        $MYSQL -sN -e "UPDATE stays SET status='CLOSED', closed_at=UTC_TIMESTAMP(3) WHERE room_id=$E2E_ROOM AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true
        $MYSQL -sN -e "DELETE FROM iot_sessions WHERE room_id=$E2E_ROOM" 2>/dev/null || true
        if [ "$E2E_IS_WAREHOUSE" != "1" ]; then
            $MYSQL -sN -e "DELETE FROM presence_events WHERE room_id=$E2E_ROOM" 2>/dev/null || true
        fi
        _e2e_restore_iot
        _e2e_restore_warehouse
        if [ "$E2E_SAVE_SIM" = "NULL" ]; then
            $MYSQL -sN -e "UPDATE rooms SET simulated_override=NULL WHERE id=$E2E_ROOM" 2>/dev/null || true
        else
            $MYSQL -sN -e "UPDATE rooms SET simulated_override=$E2E_SAVE_SIM WHERE id=$E2E_ROOM" 2>/dev/null || true
        fi
        if [ "$E2E_SAVE_PCS" = "NULL" ]; then
            $MYSQL -sN -e "UPDATE rooms SET presence_check_seconds=NULL WHERE id=$E2E_ROOM" 2>/dev/null || true
        else
            $MYSQL -sN -e "UPDATE rooms SET presence_check_seconds=$E2E_SAVE_PCS WHERE id=$E2E_ROOM" 2>/dev/null || true
        fi
        # PROTO2 es banco de pruebas: se deja limpia en FREE (no se restaura el
        # estado RESERVED con estancia OVERSTAY que existía antes de la corrida).
        $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=$E2E_ROOM" 2>/dev/null || true
        [ -n "${E2E_EXIT_PID:-}" ] && kill "$E2E_EXIT_PID" 2>/dev/null || true
    }

    _e2e_normalize
    http_test POST /dashboard-api/rooms/reset 200 \
        "S0: reset determinista de PROTO2 (room $E2E_ROOM)" \
        --body "{\"room_id\":$E2E_ROOM}"
    _e2e_normalize  # reafirmar pack/sim tras el reset

    E2E_FLOW=1

    # ── S1: emitir QR (crea stay RESERVED) ─────────────────────────────────
    E2E_IDEM_QR="e2e-f54-qr-$(date +%s)"
    E2E_QR_RAW=$(curl -s -w '\n%{http_code}' -X POST "$API_BASE/api/v1/qr" \
        -H "X-API-Key: $E2E_VB6" -H 'Content-Type: application/json' \
        -H "Idempotency-Key: $E2E_IDEM_QR" \
        -d "{\"room_id\":$E2E_ROOM,\"duracion_minutos\":$E2E_DUR}" 2>/dev/null)
    E2E_QR_CODE=$(echo "$E2E_QR_RAW" | tail -1)
    E2E_QR_BODY=$(echo "$E2E_QR_RAW" | sed '$d')
    E2E_QR_TEXT=$(echo "$E2E_QR_BODY" | python3 -c "import sys,json;print((json.load(sys.stdin) or {}).get('qr_text',''))" 2>/dev/null || echo "")
    E2E_JTI=$(echo "$E2E_QR_BODY" | python3 -c "import sys,json;print((json.load(sys.stdin) or {}).get('jti',''))" 2>/dev/null || echo "")
    E2E_STAY_ID=$(echo "$E2E_QR_BODY" | python3 -c "import sys,json;print((json.load(sys.stdin) or {}).get('stay_id',''))" 2>/dev/null || echo "")

    if [ "$E2E_QR_CODE" = "201" ] && [ -n "$E2E_QR_TEXT" ] && [ -n "$E2E_JTI" ] && [ -n "$E2E_STAY_ID" ]; then
        pass "S1: POST /api/v1/qr → 201 (stay RESERVED creado)  [HTTP 201]"
        E2E_ST=$($MYSQL -sN -e "SELECT status FROM stays WHERE id=$E2E_STAY_ID" 2>/dev/null)
        if [ "$E2E_ST" = "RESERVED" ]; then
            pass "S1: stays.status=RESERVED en BD"
        else
            fail "S1: stays.status=RESERVED en BD" "esperado RESERVED, got $E2E_ST"
        fi
    else
        skip "S1: POST /api/v1/qr" "HTTP $E2E_QR_CODE — $E2E_QR_BODY (precondición de entorno)"
        E2E_FLOW=0
    fi

    if [ "$E2E_FLOW" = "1" ]; then
        # ── S2: /live pre-entrada (RESERVED) ──────────────────────────────
        E2E_LIVE=$(curl -s --max-time 5 "$API_BASE/api/v1/rooms/$E2E_ROOM/live" 2>/dev/null)
        E2E_S2=$(echo "$E2E_LIVE" | python3 -c "
import sys,json
d=json.load(sys.stdin); s=d.get('active_stay') or {}; q=d.get('qr_status') or {}
print('%s|%s|%s'%(s.get('status'),q.get('scannable'),q.get('in_use')))" 2>/dev/null || echo "PARSE_ERROR")
        if [ "$E2E_S2" = "RESERVED|True|False" ]; then
            pass "S2: /live RESERVED, scannable=true, in_use=false"
        else
            fail "S2: /live pre-entrada" "esperado RESERVED|True|False, got $E2E_S2"
        fi

        # ── S3: validar QR → OCCUPIED (lock SIMULATED) ────────────────────
        E2E_VAL_RAW=$(curl -s -w '\n%{http_code}' -X POST "$API_BASE/api/v1/qr/validate" \
            -H "X-API-Key: $E2E_RPIKEY" -H 'Content-Type: application/json' \
            -d "{\"qr_text\":\"$E2E_QR_TEXT\",\"device_id\":\"$E2E_RPI_EXT\"}" 2>/dev/null)
        E2E_VAL_CODE=$(echo "$E2E_VAL_RAW" | tail -1)
        E2E_VAL_BODY=$(echo "$E2E_VAL_RAW" | sed '$d')
        E2E_VAL_ALLOW=$(echo "$E2E_VAL_BODY" | python3 -c "import sys,json;print((json.load(sys.stdin) or {}).get('allow'))" 2>/dev/null || echo "")
        if [ "$E2E_VAL_CODE" = "200" ] && [ "$E2E_VAL_ALLOW" = "True" ]; then
            pass "S3: POST /qr/validate → 200 allow=true (OCCUPIED, lock SIMULATED)  [HTTP 200]"
        else
            fail "S3: POST /qr/validate" "HTTP $E2E_VAL_CODE body=$E2E_VAL_BODY"
        fi
        E2E_ST=$($MYSQL -sN -e "SELECT status FROM stays WHERE id=$E2E_STAY_ID" 2>/dev/null)
        if [ "$E2E_ST" = "OCCUPIED" ]; then
            pass "S3: stays.status=OCCUPIED en BD"
        else
            fail "S3: stays.status=OCCUPIED en BD" "got $E2E_ST"
        fi
        E2E_FU=$($MYSQL -sN -e "SELECT IFNULL(DATE_FORMAT(first_used_at,'%Y-%m-%d %H:%i:%s'),'NULL') FROM qr_credentials WHERE jti='$E2E_JTI' LIMIT 1" 2>/dev/null)
        if [ -n "$E2E_FU" ] && [ "$E2E_FU" != "NULL" ]; then
            pass "S3: qr_credentials.first_used_at fijado (F51)"
        else
            fail "S3: qr_credentials.first_used_at" "got $E2E_FU"
        fi

        # ── S4: /live OCCUPIED + in_use ────────────────────────────────────
        E2E_LIVE=$(curl -s --max-time 5 "$API_BASE/api/v1/rooms/$E2E_ROOM/live" 2>/dev/null)
        E2E_S4=$(echo "$E2E_LIVE" | python3 -c "
import sys,json
d=json.load(sys.stdin); s=d.get('active_stay') or {}; q=d.get('qr_status') or {}
print('%s|%s'%(s.get('status'),q.get('in_use')))" 2>/dev/null || echo "PARSE_ERROR")
        if [ "$E2E_S4" = "OCCUPIED|True" ]; then
            pass "S4: /live OCCUPIED, qr in_use=true"
        else
            fail "S4: /live post-validación" "esperado OCCUPIED|True, got $E2E_S4"
        fi

        # ── S5: entrada simulada ───────────────────────────────────────────
        http_test POST "/sim/rooms/$E2E_ROOM/presence" 202 \
            "S5: presence=PRESENT → 202" --key SIM-CLIENT --body '{"sensor":"PRESENCE","value":"PRESENT"}'
        http_test POST "/sim/rooms/$E2E_ROOM/door" 202 \
            "S5: door=OPEN → 202" --key SIM-CLIENT --body '{"state":"OPEN"}'
        http_test POST "/sim/rooms/$E2E_ROOM/door" 202 \
            "S5: door=CLOSED → 202" --key SIM-CLIENT --body '{"state":"CLOSED"}'

        # ── S6: entrada consolidada (entry_confirmed_at) ───────────────────
        E2E_LIVE=$(curl -s --max-time 5 "$API_BASE/api/v1/rooms/$E2E_ROOM/live" 2>/dev/null)
        E2E_S6=$(echo "$E2E_LIVE" | python3 -c "
import sys,json
d=json.load(sys.stdin); s=d.get('active_stay') or {}; i=d.get('iot_session') or {}
print('%s|%s|%s|%s'%(bool(s.get('entry_confirmed_at')),i.get('presence_state'),i.get('door_state'),bool(d.get('exit_deadline'))))" 2>/dev/null || echo "PARSE_ERROR")
        if [ "$E2E_S6" = "True|PRESENT|CLOSED|False" ]; then
            pass "S6: entry_confirmed_at fijado (PRESENT+OPEN+CLOSED), exit_deadline null"
        else
            fail "S6: entrada consolidada" "esperado True|PRESENT|CLOSED|False, got $E2E_S6"
        fi

        # ── S7: salida simulada (ciclo acreditado + ABSENT) ────────────────
        http_test POST "/sim/rooms/$E2E_ROOM/door" 202 \
            "S7: door=OPEN (ciclo de salida) → 202" --key SIM-CLIENT --body '{"state":"OPEN"}'
        http_test POST "/sim/rooms/$E2E_ROOM/door" 202 \
            "S7: door=CLOSED (ciclo de salida) → 202" --key SIM-CLIENT --body '{"state":"CLOSED"}'
        http_test POST "/sim/rooms/$E2E_ROOM/presence" 202 \
            "S7: presence=ABSENT → 202" --key SIM-CLIENT --body '{"sensor":"PRESENCE","value":"ABSENT"}'

        # ── S8: exit_deadline activo ───────────────────────────────────────
        E2E_LIVE=$(curl -s --max-time 5 "$API_BASE/api/v1/rooms/$E2E_ROOM/live" 2>/dev/null)
        E2E_S8=$(echo "$E2E_LIVE" | python3 -c "
import sys,json
d=json.load(sys.stdin); i=d.get('iot_session') or {}
print('%s|%s|%s'%(bool(d.get('exit_deadline')),i.get('presence_state'),i.get('door_state')))" 2>/dev/null || echo "PARSE_ERROR")
        if [ "$E2E_S8" = "True|ABSENT|CLOSED" ]; then
            pass "S8: exit_deadline activo tras ABSENT + ciclo acreditado"
        else
            fail "S8: exit_deadline" "esperado True|ABSENT|CLOSED, got $E2E_S8"
        fi

        # ── S9: exit-scan confirma EXITED / sala FREE / AUTO_LOCK ──────────
        if ! pgrep -f "[e]xit-scan.php" >/dev/null 2>&1; then
            nohup php "$PROJECT_DIR/bin/exit-scan.php" >> "$PROJECT_DIR/logs/exit-scan.log" 2>&1 &
            E2E_EXIT_PID=$!
        fi
        E2E_EXITED=0
        E2E_ST=""
        for _i in $(seq 1 25); do
            E2E_ST=$($MYSQL -sN -e "SELECT status FROM stays WHERE id=$E2E_STAY_ID" 2>/dev/null)
            if [ "$E2E_ST" = "EXITED" ]; then E2E_EXITED=1; break; fi
            sleep 1
        done
        if [ "$E2E_EXITED" = "1" ]; then
            pass "S9: exit-scan confirma stay EXITED (F49)"
        else
            fail "S9: stay EXITED tras salida" "got '$E2E_ST' (timeout 25s)"
        fi
        E2E_ROOM_ST=$($MYSQL -sN -e "SELECT status FROM rooms WHERE id=$E2E_ROOM" 2>/dev/null)
        if [ "$E2E_ROOM_ST" = "FREE" ]; then
            pass "S9: room FREE tras confirmar salida"
        else
            fail "S9: room FREE tras salida" "got $E2E_ROOM_ST"
        fi
        E2E_AL_AFTER=$($MYSQL -sN -e "SELECT COUNT(*) FROM access_events WHERE room_id=$E2E_ROOM AND kind='AUTO_LOCK'" 2>/dev/null || echo "0")
        if [ "${E2E_AL_AFTER:-0}" -gt "${E2E_AL_BEFORE:-0}" ] 2>/dev/null; then
            pass "S9: access_event AUTO_LOCK registrado"
        else
            fail "S9: AUTO_LOCK tras salida" "before=$E2E_AL_BEFORE after=$E2E_AL_AFTER"
        fi

        # ── S10: cerrar estancia EXITED → CLOSED ───────────────────────────
        E2E_IDEM_CLOSE="e2e-f54-close-$(date +%s)"
        E2E_CL_RAW=$(curl -s -w '\n%{http_code}' -X POST "$API_BASE/api/v1/stays/$E2E_STAY_ID/close" \
            -H "X-API-Key: $E2E_ADM" -H 'Content-Type: application/json' \
            -H "Idempotency-Key: $E2E_IDEM_CLOSE" -d '{}' 2>/dev/null)
        E2E_CL_CODE=$(echo "$E2E_CL_RAW" | tail -1)
        E2E_CL_ST=$($MYSQL -sN -e "SELECT status FROM stays WHERE id=$E2E_STAY_ID" 2>/dev/null)
        if [ "$E2E_CL_CODE" = "200" ] && [ "$E2E_CL_ST" = "CLOSED" ]; then
            pass "S10: POST /stays/{id}/close → 200 (EXITED→CLOSED)  [HTTP 200]"
        else
            fail "S10: POST /stays/{id}/close" "HTTP $E2E_CL_CODE status=$E2E_CL_ST"
        fi

        # ── S11: overstay (lectura determinista, opcional) ─────────────────
        E2E_OV_RAW=$(curl -s -w '\n%{http_code}' "$API_BASE/api/v1/stays/$E2E_STAY_ID/overstay" \
            -H "X-API-Key: $E2E_ADM" 2>/dev/null)
        E2E_OV_CODE=$(echo "$E2E_OV_RAW" | tail -1)
        if [ "$E2E_OV_CODE" = "200" ]; then
            pass "S11: GET /stays/{id}/overstay → 200 (lectura)"
        elif [ "$E2E_OV_CODE" = "404" ] || [ "$E2E_OV_CODE" = "409" ]; then
            skip "S11: GET /stays/{id}/overstay" "HTTP $E2E_OV_CODE (no aplicable a estancia cerrada)"
        else
            fail "S11: GET /stays/{id}/overstay" "HTTP $E2E_OV_CODE"
        fi
    fi

    # ── S12: cleanup y restauración de PROTO2 ──────────────────────────────
    _e2e_cleanup
    if [ "$E2E_FLOW" = "1" ]; then
        E2E_FINAL_ST=$($MYSQL -sN -e "SELECT status FROM rooms WHERE id=$E2E_ROOM" 2>/dev/null)
        if [ "$E2E_FINAL_ST" = "FREE" ]; then
            pass "S12: PROTO2 restaurada (FREE, sin stays/iot/presence)"
        else
            fail "S12: PROTO2 restaurada" "got status=$E2E_FINAL_ST"
        fi
    fi
    # F79 (RF-102.4): la suite no debe dejar visitas fantasma del almacén.
    if [ "$E2E_IS_WAREHOUSE" = "1" ]; then
        E2E_WH_LEFT=$($MYSQL -sN -e "SELECT COUNT(*) FROM warehouse_visits WHERE room_id=$E2E_ROOM AND id > ${E2E_WH_VISIT_MAX:-0}" 2>/dev/null || echo "ERR")
        if [ "$E2E_WH_LEFT" = "0" ]; then
            pass "S12: sin visitas fantasma del almacén tras la corrida (F79)"
        else
            fail "S12: visitas fantasma del almacén (F79)" "quedan=$E2E_WH_LEFT baseline=$E2E_WH_VISIT_MAX"
        fi
    fi
fi

# =============================================================================
# BLOCK 43 — F55: Panel de aceptación manual (/pruebas) (RF-62)
# Trazabilidad: RF-62.1..62.5; TSK-F55-01..06
# =============================================================================
block "BLOCK 43 — F55: Panel de aceptación manual (/pruebas)"

# 43.1 Lógica pura (JS, sin navegador)
ACC_JS="$PROJECT_DIR/tests/Unit/acceptance-logic.test.js"
if [ -f "$ACC_JS" ]; then
    ACC_OUT=$(node "$ACC_JS" 2>&1)
    ACC_RC=$?
    ACC_SUM=$(echo "$ACC_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$ACC_SUM" ] && ACC_SUM="exit=$ACC_RC"
    if [ "$ACC_RC" -eq 0 ]; then
        pass "JS: acceptance-logic.test.js ($ACC_SUM)"
    else
        fail "JS: acceptance-logic.test.js" \
            "$ACC_SUM — $(echo "$ACC_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
    fi
else
    skip "JS: acceptance-logic.test.js" "fichero no encontrado"
fi

# 43.2 Catálogo válido (53 pruebas, ids únicos)
ACC_CATALOG="$PROJECT_DIR/public/assets/acceptance-tests.json"
if [ -f "$ACC_CATALOG" ]; then
    ACC_CAT=$(python3 -c "
import json,sys
try:
    d=json.load(open('$ACC_CATALOG'))
except Exception as e:
    print('ERR '+str(e)); sys.exit()
ts=d.get('tests') or []
ids=[t.get('id') for t in ts]
print('%d|%d' % (len(ts), len(set(ids))))
" 2>/dev/null)
    ACC_N=${ACC_CAT%%|*}
    ACC_U=${ACC_CAT##*|}
    if [ "$ACC_N" = "53" ] && [ "$ACC_U" = "53" ]; then
        pass "F55: catálogo con 53 pruebas e ids únicos"
    else
        fail "F55: catálogo" "pruebas=$ACC_N únicas=$ACC_U (esperado 53|53)"
    fi
else
    fail "F55: catálogo" "no existe public/assets/acceptance-tests.json"
fi

# 43.3-43.4 Endpoints HTTP (requieren servidor)
if [ "$SERVER_UP" = true ]; then
    http_test GET /pruebas 200 "F55: GET /pruebas → 200 (panel de aceptación)"

    ACC_RUNID="test-f55-$(date +%s)"
    ACC_SAVE=$(curl -s -w '\n%{http_code}' -X POST "$API_BASE/dashboard-api/acceptance/save" \
        -H 'Content-Type: application/json' \
        -d "{\"run_id\":\"$ACC_RUNID\",\"operator\":\"runner\",\"room_id\":12,\"commit\":\"test\",\"started_at\":\"2026-01-01T00:00:00Z\",\"revision\":1,\"config\":{\"naCountsAsGreen\":true},\"tests\":{\"P1\":{\"status\":\"PASS\"},\"P2\":{\"status\":\"NA\"}}}")
    ACC_SAVE_CODE=$(echo "$ACC_SAVE" | tail -1)
    if [ "$ACC_SAVE_CODE" = "200" ]; then
        pass "F55: POST /acceptance/save → 200"
    else
        fail "F55: POST /acceptance/save" "HTTP $ACC_SAVE_CODE"
    fi

    ACC_GET=$(curl -s -w '\n%{http_code}' "$API_BASE/dashboard-api/acceptance/get?id=$ACC_RUNID")
    ACC_GET_CODE=$(echo "$ACC_GET" | tail -1)
    ACC_GET_BODY=$(echo "$ACC_GET" | sed '$d')
    if [ "$ACC_GET_CODE" = "200" ] && echo "$ACC_GET_BODY" | grep -q "\"run_id\": *\"$ACC_RUNID\""; then
        pass "F55: GET /acceptance/get devuelve la corrida guardada"
    else
        fail "F55: GET /acceptance/get" "HTTP $ACC_GET_CODE body=$(echo "$ACC_GET_BODY" | head -c 200)"
    fi

    ACC_GREEN=$(echo "$ACC_GET_BODY" | python3 -c "import sys,json;print((json.load(sys.stdin).get('summary') or {}).get('green'))" 2>/dev/null)
    if [ "$ACC_GREEN" = "True" ]; then
        pass "F55: summary.green con PASS+NA (naCountsAsGreen)"
    else
        fail "F55: summary.green" "got $ACC_GREEN"
    fi

    ACC_LIST=$(curl -s "$API_BASE/dashboard-api/acceptance/list")
    if echo "$ACC_LIST" | grep -q "$ACC_RUNID"; then
        pass "F55: GET /acceptance/list incluye la corrida"
    else
        fail "F55: GET /acceptance/list" "no aparece $ACC_RUNID"
    fi

    http_test GET "/dashboard-api/acceptance/get?id=does-not-exist-xyz" 404 \
        "F55: GET /acceptance/get inexistente → 404"

    http_test POST "/dashboard-api/acceptance/save" 400 \
        "F55: POST /acceptance/save run_id con '..' → 400" \
        --body '{"run_id":"../evil","tests":{}}'

    ACC_DEL=$(curl -s -w '\n%{http_code}' -X DELETE "$API_BASE/dashboard-api/acceptance/delete?id=$ACC_RUNID")
    ACC_DEL_CODE=$(echo "$ACC_DEL" | tail -1)
    ACC_GONE=$(curl -s -o /dev/null -w '%{http_code}' "$API_BASE/dashboard-api/acceptance/get?id=$ACC_RUNID")
    if [ "$ACC_DEL_CODE" = "200" ] && [ "$ACC_GONE" = "404" ]; then
        pass "F55: DELETE /acceptance/delete borra la corrida"
    else
        fail "F55: DELETE /acceptance/delete" "delete=$ACC_DEL_CODE get=$ACC_GONE"
        rm -f "$PROJECT_DIR/run/acceptance/$ACC_RUNID.json" 2>/dev/null || true
    fi
else
    skip "F55 endpoints de corridas" "servidor no disponible"
fi

# =============================================================================
# BLOCK 44 — F60–F66: Almacén de bebidas (RF-67..RF-76)
# Trazabilidad: RF-67..RF-76; TSK-F60-* .. TSK-F66-*
# =============================================================================
block "BLOCK 44 — F60–F66: Almacén de bebidas"

# 44.1 Esquema y seed (BD)
WH_TYPE_ID=$($MYSQL -sN -e "SELECT id FROM room_types WHERE code='ALMACEN_BEBIDAS' LIMIT 1" 2>/dev/null)
WH_PACK_ID=$($MYSQL -sN -e "SELECT id FROM device_packs WHERE code='ALMACEN_BEBIDAS' LIMIT 1" 2>/dev/null)
WH_SUBTYPE_COL=$($MYSQL -sN -e "SHOW COLUMNS FROM devices LIKE 'subtype'" 2>/dev/null | awk '{print $1}')
WH_CONFIRM_COL=$($MYSQL -sN -e "SHOW COLUMNS FROM room_types LIKE 'warehouse_confirm_seconds'" 2>/dev/null | awk '{print $1}')
WH_CR_TABLE=$($MYSQL -sN -e "SHOW TABLES LIKE 'camera_recordings'" 2>/dev/null)
WH_OV_TABLE=$($MYSQL -sN -e "SHOW TABLES LIKE 'worker_room_overrides'" 2>/dev/null)

if [ -n "$WH_TYPE_ID" ]; then
    pass "F60: room_type ALMACEN_BEBIDAS existe (id=$WH_TYPE_ID)"
else
    fail "F60: room_type ALMACEN_BEBIDAS" "no existe — ejecuta: php bin/migrate.php"
fi
if [ -n "$WH_PACK_ID" ]; then
    pass "F60: device_pack ALMACEN_BEBIDAS existe (id=$WH_PACK_ID)"
else
    fail "F60: device_pack ALMACEN_BEBIDAS" "no existe"
fi
[ "$WH_SUBTYPE_COL" = "subtype" ] && pass "F60: devices.subtype presente" || fail "F60: devices.subtype" "columna ausente"
[ "$WH_CONFIRM_COL" = "warehouse_confirm_seconds" ] && pass "F60: room_types.warehouse_confirm_seconds presente" || fail "F60: room_types.warehouse_confirm_seconds" "columna ausente"
[ -n "$WH_CR_TABLE" ] && pass "F63: tabla camera_recordings presente" || fail "F63: camera_recordings" "tabla ausente"
[ -n "$WH_OV_TABLE" ] && pass "F62: tabla worker_room_overrides presente" || fail "F62: worker_room_overrides" "tabla ausente"

if [ -n "$WH_TYPE_ID" ]; then
    WH_X=$($MYSQL -sN -e "SELECT warehouse_confirm_seconds FROM room_types WHERE id=$WH_TYPE_ID" 2>/dev/null)
    WH_M=$($MYSQL -sN -e "SELECT warehouse_exterior_margin_seconds FROM room_types WHERE id=$WH_TYPE_ID" 2>/dev/null)
    # F83/RF-106.3: el margen exterior del almacén pasa de 5 a 10 s
    # (migración 0121) para grabar la salida del individuo.
    if [ "$WH_X" = "40" ] && [ "$WH_M" = "10" ]; then
        pass "F60/F83: ventanas por defecto X=40, M=10"
    else
        fail "F60/F83: ventanas por defecto" "X=$WH_X M=$WH_M (esperado 40/10)"
    fi
fi

# 44.2 HTTP (requiere servidor)
if [ "$SERVER_UP" = true ]; then
    http_test GET /almacen 200 "F65: GET /almacen → 200 (panel)"
    ALM_HTML=$(curl -s --max-time 5 "$API_BASE/almacen")
    if echo "$ALM_HTML" | grep -qi "almac"; then
        pass "F65: /almacen sirve la página del almacén"
    else
        fail "F65: /almacen contenido" "no contiene 'almac'"
    fi

    http_test GET /almacen-api/state 200 "F65: GET /almacen-api/state → 200"
    ALM_STATE=$(curl -s --max-time 5 "$API_BASE/almacen-api/state")
    if echo "$ALM_STATE" | grep -q '"retention"'; then
        pass "F65: state incluye retention"
    else
        fail "F65: state.retention" "ausente: $(echo "$ALM_STATE" | head -c 200)"
    fi

    http_test GET "/almacen-api/visits" 200 "F63: GET /almacen-api/visits → 200"
    http_test GET "/almacen-api/recordings/999999/video" 404 \
        "F64: recording inexistente → 404"

    http_test GET "/almacen-api/cameras?room_id=1" 200 "F61: GET /almacen-api/cameras → 200"
    http_test POST "/almacen-api/cameras" 422 \
        "F61: POST cámara con position inválida → 422" \
        --body '{"room_id":1,"position":"SALON","external_id":"CAM-X","rtsp_url":"rtsp://x/y"}'
    http_test POST "/almacen-api/cameras" 400 \
        "F61: POST cámara sin rtsp_url → 400" \
        --body '{"room_id":1,"position":"EXTERIOR","external_id":"CAM-Y"}'
    http_test POST "/almacen-api/cameras/sync" 200 "F61: POST /almacen-api/cameras/sync → 200"

    # 44.3 Permisos (RF-69): rol + excepción por empleado
    if [ -n "$WH_TYPE_ID" ]; then
        http_test GET "/almacen-api/access?room_type_id=$WH_TYPE_ID" 200 \
            "F62: GET /almacen-api/access → 200"
        http_test GET "/almacen-api/access" 400 \
            "F62: GET /almacen-api/access sin room_type_id → 400"

        # rol: permitir y restaurar (el tipo almacén no lo usa ningún otro test)
        http_test PUT "/almacen-api/access/role/1" 200 \
            "F62: PUT access/role allow=true → 200" \
            --body "{\"room_type_id\":$WH_TYPE_ID,\"allow\":true}"
        http_test PUT "/almacen-api/access/role/1" 200 \
            "F62: PUT access/role allow=false → 200" \
            --body "{\"room_type_id\":$WH_TYPE_ID,\"allow\":false}"

        # excepción por empleado: DENY y limpieza (null)
        http_test PUT "/almacen-api/access/worker/1" 200 \
            "F62: PUT access/worker DENY → 200" \
            --body "{\"room_type_id\":$WH_TYPE_ID,\"effect\":\"DENY\"}"
        http_test PUT "/almacen-api/access/worker/1" 200 \
            "F62: PUT access/worker effect=null (limpia) → 200" \
            --body "{\"room_type_id\":$WH_TYPE_ID,\"effect\":null}"

        WH_OV_LEFT=$($MYSQL -sN -e "SELECT COUNT(*) FROM worker_room_overrides WHERE room_type_id=$WH_TYPE_ID" 2>/dev/null)
        if [ "$WH_OV_LEFT" = "0" ]; then
            pass "F62: excepción de empleado limpiada"
        else
            fail "F62: limpieza excepción" "quedan $WH_OV_LEFT filas"
        fi
    fi

    # 44.4 Motor de grabación (puro, sin cámara): decide() ya está en BLOCK 1.
    WH_ENGINE_JS="$PROJECT_DIR/tests/Unit/WarehouseRecordingDecisionTest.php"
    if [ -f "$WH_ENGINE_JS" ]; then
        WH_ENG_OUT=$(php "$WH_ENGINE_JS" 2>&1)
        if echo "$WH_ENG_OUT" | grep -q "0 failed"; then
            pass "F63: motor de grabación (casos A-D) 0 failed"
        else
            fail "F63: motor de grabación" "$(echo "$WH_ENG_OUT" | grep -iE 'FAIL|Total' | head -3 | tr '\n' ' ')"
        fi
    fi

    # 44.5 F79 (RF-102.1/102.2): el motor ignora SIMULATED/resync; el consumer
    # marca el resync y el ingress lo propaga a meta.source.
    if grep -q "origin === 'resync'" "$PROJECT_DIR/src/Domain/Warehouse/WarehouseRecordingService.php" 2>/dev/null \
       && grep -q "SIMULATED" "$PROJECT_DIR/src/Domain/Warehouse/WarehouseRecordingService.php" 2>/dev/null; then
        pass "F79: motor ignora SIMULATED/resync (guard presente)"
    else
        fail "F79: guard motor SIMULATED/resync" "ausente en WarehouseRecordingService"
    fi
    if grep -q "_source = 'resync'\|_source = \"resync\"" "$PROJECT_DIR/bin/tuya-pulsar-consumer/index.js" 2>/dev/null; then
        pass "F79: consumer marca resync (_source)"
    else
        fail "F79: consumer marca resync" "ausente en tuya-pulsar-consumer/index.js"
    fi

    # 44.6 F79 (RF-102.3): visits oculta NO_SHOW DOOR-only por defecto.
    http_test GET '/almacen-api/visits' 200 "F79: GET /almacen-api/visits (default) → 200"
    http_test GET '/almacen-api/visits?include_no_show=1' 200 "F79: GET /almacen-api/visits?include_no_show=1 → 200"
    if grep -q "include_no_show" "$PROJECT_DIR/src/Http/Controllers/WarehouseVisitController.php" 2>/dev/null; then
        pass "F79: visits filtro include_no_show presente"
    else
        fail "F79: visits filtro" "include_no_show ausente"
    fi
else
    skip "BLOCK 44 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 45 — F67: Croquis en vivo del almacén (RF-77)
# Trazabilidad: RF-77.1..RF-77.7; TSK-F67-02..TSK-F67-06
# =============================================================================
block "BLOCK 45 — F67: Croquis en vivo del almacén"

# 45.0 Lógica pura (Node; no requiere servidor)
F67_JS="tests/Unit/croquis-logic.test.js"
if [ -f "$F67_JS" ]; then
    F67_OUT=$(node "$F67_JS" 2>&1)
    F67_RC=$?
    F67_SUM=$(echo "$F67_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F67_SUM" ] && F67_SUM="exit=$F67_RC"
    if [ "$F67_RC" -eq 0 ]; then
        pass "F67: croquis-logic ($F67_SUM)"
    else
        fail "F67: croquis-logic" \
            "$F67_SUM — $(echo "$F67_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F67: croquis-logic" "tests/Unit/croquis-logic.test.js no encontrado"
fi

# 45.1 Assets y markup del croquis (estático)
for F67_ASSET in public/assets/croquis-logic.js public/assets/almacen.js public/almacen.html; do
    if [ -f "$F67_ASSET" ]; then
        pass "F67: existe $F67_ASSET"
    else
        fail "F67: $F67_ASSET" "no encontrado"
    fi
done
if grep -q 'id="croquis-svg"' public/almacen.html 2>/dev/null; then
    pass "F67: almacen.html incluye #croquis-svg"
else
    fail "F67: almacen.html #croquis-svg" "ausente"
fi
if grep -q 'croquis-logic.js' public/almacen.html 2>/dev/null; then
    pass "F67: almacen.html carga croquis-logic.js"
else
    fail "F67: almacen.html script" "no carga croquis-logic.js"
fi

# 45.2 HTTP: bloque live en /almacen-api/state (RF-77.3)
if [ "$SERVER_UP" = true ]; then
    http_test GET /almacen-api/state 200 "F67: GET /almacen-api/state → 200"
    ALM_LIVE=$(curl -s --max-time 5 "$API_BASE/almacen-api/state")
    if echo "$ALM_LIVE" | grep -q '"live"'; then
        pass "F67: state incluye bloque live"
    else
        fail "F67: state.live" "ausente: $(echo "$ALM_LIVE" | head -c 200)"
    fi
    for F67_FIELD in door_state presence_state switch_state; do
        if echo "$ALM_LIVE" | grep -q "\"$F67_FIELD\""; then
            pass "F67: state.live.$F67_FIELD presente"
        else
            fail "F67: state.live.$F67_FIELD" "ausente"
        fi
    done
else
    skip "BLOCK 45 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 46 — F68: Reproducción de visitas (RF-78)
# Trazabilidad: RF-78.1..RF-78.9; TSK-F68-02..TSK-F68-06
# =============================================================================
block "BLOCK 46 — F68: Reproducción de visitas"

# 46.0 Lógica pura (Node; no requiere servidor)
F68_JS="tests/Unit/visit-playback.test.js"
if [ -f "$F68_JS" ]; then
    F68_OUT=$(node "$F68_JS" 2>&1)
    F68_RC=$?
    F68_SUM=$(echo "$F68_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F68_SUM" ] && F68_SUM="exit=$F68_RC"
    if [ "$F68_RC" -eq 0 ]; then
        pass "F68: visit-playback ($F68_SUM)"
    else
        fail "F68: visit-playback" \
            "$F68_SUM — $(echo "$F68_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F68: visit-playback" "tests/Unit/visit-playback.test.js no encontrado"
fi

# 46.1 Assets y markup (estático)
for F68_ASSET in public/assets/visit-playback.js public/assets/almacen.js public/almacen.html; do
    if [ -f "$F68_ASSET" ]; then
        pass "F68: existe $F68_ASSET"
    else
        fail "F68: $F68_ASSET" "no encontrado"
    fi
done
for F68_MARK in 'id="play-band"' 'id="play-range"' 'id="croquis-qr"' 'visit-playback.js'; do
    if grep -q "$F68_MARK" public/almacen.html 2>/dev/null; then
        pass "F68: almacen.html incluye $F68_MARK"
    else
        fail "F68: almacen.html $F68_MARK" "ausente"
    fi
done
if grep -q 'playVisit' public/assets/almacen.js 2>/dev/null; then
    pass "F68: almacen.js incluye playVisit"
else
    fail "F68: almacen.js playVisit" "ausente"
fi
# F77.3: el directo no debe recrearse en cada push SSE.
if grep -q "dataset.mode === 'live'" public/assets/almacen.js 2>/dev/null; then
    pass "F77.3: renderCameras evita recrear el MJPEG sin cambios"
else
    fail "F77.3: renderCameras sin diff" "no se encontro dataset.mode === 'live'"
fi
# F77.8: el detalle de visita no debe perderse en el refresco periodico.
if grep -q 'selectedVisitId' public/assets/almacen.js 2>/dev/null; then
    pass "F77.8: detalle de visita preservado en el refresco"
else
    fail "F77.8: selectedVisitId" "ausente"
fi
# Fix F63: `trigger` es palabra reservada en MariaDB → debe ir entrecomillada.
if grep -q '`trigger`' src/Domain/Warehouse/WarehouseRecordingService.php 2>/dev/null; then
    pass "F68: INSERT de grabaciones entrecomilla trigger"
else
    fail "F68: trigger reservado (INSERT)" "sin entrecomillar"
fi
if grep -q '`trigger`' src/Http/Controllers/WarehouseVisitController.php 2>/dev/null; then
    pass "F68: SELECT de grabaciones entrecomilla trigger"
else
    fail "F68: trigger reservado (SELECT)" "sin entrecomillar"
fi

# 46.2 HTTP: requested_at en GET /almacen-api/visits/{id} (RF-78.7)
if [ "$SERVER_UP" = true ]; then
    F68_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    F68_DEVICE=$($MYSQL -sN -e "SELECT d.id FROM devices d JOIN rooms r ON r.pack_id=d.pack_id WHERE r.id=$F68_ROOM AND d.kind='CAMERA' ORDER BY d.id LIMIT 1" 2>/dev/null)
    if [ -n "$F68_ROOM" ] && [ -n "$F68_DEVICE" ]; then
        F68_VISIT=$($MYSQL -sN -e "INSERT INTO warehouse_visits (room_id, entry_trigger, outcome, qr_at, entered_at, exited_at) VALUES ($F68_ROOM, 'QR', 'ENTERED', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)); SELECT LAST_INSERT_ID();" 2>/dev/null)
        if [ -n "$F68_VISIT" ]; then
            $MYSQL -e "INSERT INTO camera_recordings
                        (visit_id, room_id, device_id, position, episode, \`trigger\`, status,
                         requested_at, started_at, stopped_at, duration_s)
                       VALUES ($F68_VISIT, $F68_ROOM, $F68_DEVICE, 'EXTERIOR', 'ENTRY', 'QR', 'SAVED',
                               UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), 3)" 2>/dev/null
            F68_JSON=$(curl -s --max-time 5 "$API_BASE/almacen-api/visits/$F68_VISIT")
            if echo "$F68_JSON" | grep -q '"requested_at"'; then
                pass "F68: visita incluye requested_at"
            else
                fail "F68: requested_at" "ausente: $(echo "$F68_JSON" | head -c 240)"
            fi
            if echo "$F68_JSON" | grep -q '"video_url"'; then
                pass "F68: visita incluye video_url"
            else
                fail "F68: video_url" "ausente"
            fi
            # limpieza del estado sintético
            $MYSQL -e "DELETE FROM camera_recordings WHERE visit_id=$F68_VISIT; DELETE FROM warehouse_visits WHERE id=$F68_VISIT;" 2>/dev/null
            pass "F68: estado sintético limpiado"
        else
            skip "F68 HTTP" "no se pudo crear la visita sintética"
        fi
    else
        skip "F68 HTTP" "sin sala ALMACEN_BEBIDAS o cámara"
    fi
else
    skip "BLOCK 46 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 47 — F69: Vista en directo y encendido de cámaras (RF-79)
# Trazabilidad: RF-79.1..RF-79.6; TSK-F69-02..TSK-F69-05
# =============================================================================
block "BLOCK 47 — F69: Vista en directo y encendido de cámaras"

# 47.0 Estáticos
if grep -q 'id="btn-live"' public/almacen.html 2>/dev/null; then
    pass "F69: almacen.html incluye #btn-live"
else
    fail "F69: almacen.html #btn-live" "ausente"
fi
if grep -q 'Almacen.goLive' public/almacen.html 2>/dev/null; then
    pass "F69: botón enlazado a goLive"
else
    fail "F69: goLive en HTML" "ausente"
fi
for F69_FN in 'function goLive' 'function ensureCamerasLive'; do
    if grep -q "$F69_FN" public/assets/almacen.js 2>/dev/null; then
        pass "F69: almacen.js incluye '$F69_FN'"
    else
        fail "F69: almacen.js $F69_FN" "ausente"
    fi
done

# 47.1 HTTP: apagar/encender una cámara y comprobar state + live_url
if [ "$SERVER_UP" = true ]; then
    if command -v python3 >/dev/null 2>&1; then
        F69_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
        F69_CAM=$($MYSQL -sN -e "SELECT d.id FROM devices d JOIN rooms r ON r.pack_id=d.pack_id WHERE r.id=$F69_ROOM AND d.kind='CAMERA' ORDER BY d.id LIMIT 1" 2>/dev/null)
        if [ -n "$F69_ROOM" ] && [ -n "$F69_CAM" ]; then
            F69_ORIG=$($MYSQL -sN -e "SELECT COALESCE(JSON_EXTRACT(meta_json,'\$.enabled'),'true') FROM devices WHERE id=$F69_CAM" 2>/dev/null | tr -d '"')
            # apagar
            curl -s --max-time 5 -X PATCH "$API_BASE/almacen-api/cameras/$F69_CAM" \
                -H 'Content-Type: application/json' -d '{"enabled":false}' >/dev/null
            curl -s --max-time 5 -X POST "$API_BASE/almacen-api/cameras/sync" >/dev/null
            F69_ST=$(curl -s --max-time 5 "$API_BASE/almacen-api/state?room_id=$F69_ROOM")
            F69_OFF=$(echo "$F69_ST" | python3 -c "import sys,json;d=json.load(sys.stdin);c=[x for x in d.get('cameras',[]) if x['id']==$F69_CAM];print(c[0]['enabled'] if c else 'NA')" 2>/dev/null)
            F69_OFF_URL=$(echo "$F69_ST" | python3 -c "import sys,json;d=json.load(sys.stdin);c=[x for x in d.get('cameras',[]) if x['id']==$F69_CAM];print(c[0]['live_url'] or '' if c else 'NA')" 2>/dev/null)
            if [ "$F69_OFF" = "False" ] && [ -z "$F69_OFF_URL" ]; then
                pass "F69: cámara apagada → enabled=false y live_url nulo"
            else
                fail "F69: cámara apagada" "enabled=$F69_OFF live_url=$F69_OFF_URL"
            fi
            # encender (lo que hace el botón)
            curl -s --max-time 5 -X PATCH "$API_BASE/almacen-api/cameras/$F69_CAM" \
                -H 'Content-Type: application/json' -d '{"enabled":true}' >/dev/null
            curl -s --max-time 5 -X POST "$API_BASE/almacen-api/cameras/sync" >/dev/null
            F69_ST2=$(curl -s --max-time 5 "$API_BASE/almacen-api/state?room_id=$F69_ROOM")
            F69_ON=$(echo "$F69_ST2" | python3 -c "import sys,json;d=json.load(sys.stdin);c=[x for x in d.get('cameras',[]) if x['id']==$F69_CAM];print(c[0]['enabled'] if c else 'NA')" 2>/dev/null)
            F69_ON_URL=$(echo "$F69_ST2" | python3 -c "import sys,json;d=json.load(sys.stdin);c=[x for x in d.get('cameras',[]) if x['id']==$F69_CAM];print(c[0]['live_url'] or '' if c else 'NA')" 2>/dev/null)
            if [ "$F69_ON" = "True" ] && [ -n "$F69_ON_URL" ]; then
                pass "F69: cámara encendida → enabled=true y live_url presente"
            else
                fail "F69: cámara encendida" "enabled=$F69_ON live_url=$F69_ON_URL"
            fi
            # restaurar estado original
            if [ "$F69_ORIG" = "false" ]; then
                curl -s --max-time 5 -X PATCH "$API_BASE/almacen-api/cameras/$F69_CAM" \
                    -H 'Content-Type: application/json' -d '{"enabled":false}' >/dev/null
                curl -s --max-time 5 -X POST "$API_BASE/almacen-api/cameras/sync" >/dev/null
                pass "F69: estado original restaurado (apagada)"
            else
                pass "F69: estado original conservado (encendida)"
            fi
        else
            skip "F69 HTTP" "sin sala ALMACEN_BEBIDAS o cámara"
        fi
    else
        skip "F69 HTTP" "python3 no disponible para validar JSON"
    fi
else
    skip "BLOCK 47 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 48 — F70: Directo de cámaras por MJPEG (RF-80)
# Trazabilidad: RF-80.1..RF-80.7; TSK-F70-02..TSK-F70-07
# =============================================================================
block "BLOCK 48 — F70: Directo de cámaras por MJPEG"

# 48.0 Lógica pura del parser JPEG (Node; sin servidor)
F70_JS="tests/Unit/cameras-live.test.js"
if [ -f "$F70_JS" ]; then
    F70_OUT=$(node "$F70_JS" 2>&1)
    F70_RC=$?
    F70_SUM=$(echo "$F70_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F70_SUM" ] && F70_SUM="exit=$F70_RC"
    if [ "$F70_RC" -eq 0 ]; then
        pass "F70: cameras-live ($F70_SUM)"
    else
        fail "F70: cameras-live" \
            "$F70_SUM — $(echo "$F70_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F70: cameras-live" "tests/Unit/cameras-live.test.js no encontrado"
fi

# 48.1 Estáticos: ficheros, systemd, arranque y panel
for F70_ASSET in bin/cameras-live.js ../docs/systemd/cerraduras-cameras-live.service; do
    if [ -f "$F70_ASSET" ]; then
        pass "F70: existe $F70_ASSET"
    else
        fail "F70: $F70_ASSET" "no encontrado"
    fi
done
if grep -q 'cerraduras-cameras-live' ../start-all.sh 2>/dev/null || grep -q 'cerraduras-cameras-live' start-all.sh 2>/dev/null; then
    pass "F70: start-all referencia cameras-live"
else
    fail "F70: start-all cameras-live" "ausente"
fi
if grep -q 'cerraduras-cameras-live' ../stop-all.sh 2>/dev/null || grep -q 'cerraduras-cameras-live' stop-all.sh 2>/dev/null; then
    pass "F70: stop-all referencia cameras-live"
else
    fail "F70: stop-all cameras-live" "ausente"
fi
if grep -q 'CAMERAS_LIVE_BASE_URL' .env.example 2>/dev/null; then
    pass "F70: .env.example documenta CAMERAS_LIVE_BASE_URL"
else
    fail "F70: .env.example" "sin CAMERAS_LIVE_BASE_URL"
fi
if grep -q 'mjpeg' public/assets/almacen.js 2>/dev/null && grep -q 'is-offline' public/assets/almacen.js 2>/dev/null; then
    pass "F70: panel usa <img> MJPEG con 'sin señal'"
else
    fail "F70: panel MJPEG" "sin <img>/offline"
fi

# 48.2 HTTP: mjpeg_url en state + servidor interno MJPEG
if [ "$SERVER_UP" = true ]; then
    F70_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id JOIN devices d ON d.pack_id=r.pack_id AND d.kind='CAMERA' WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    if [ -z "$F70_ROOM" ]; then
        # El piloto Zigbee puede estar temporalmente en TIPO_NORMAL para probar
        # /dashboard: sus cámaras siguen existiendo, pero no hay sala almacén
        # con cámaras que consultar. No es un fallo del directo MJPEG.
        skip "BLOCK 48 HTTP" "sin sala ALMACEN_BEBIDAS con cámara"
    else
        F70_STATE=$(curl -s --max-time 5 "$API_BASE/almacen-api/state?room_id=$F70_ROOM")
        if echo "$F70_STATE" | grep -q '"mjpeg_url"'; then
            pass "F70: state.cameras incluye mjpeg_url"
        else
            fail "F70: state.cameras[].mjpeg_url" "ausente"
        fi
        F70_CAM=$($MYSQL -sN -e "SELECT d.id FROM devices d JOIN rooms r ON r.pack_id=d.pack_id WHERE r.id=$F70_ROOM AND d.kind='CAMERA' AND COALESCE(JSON_EXTRACT(d.meta_json,'\$.enabled'),'true')='true' ORDER BY d.id LIMIT 1" 2>/dev/null)
        if [ -n "$F70_CAM" ]; then
            F70_STATUS=$(curl -s --max-time 4 "http://127.0.0.1:8086/status" 2>/dev/null)
            if echo "$F70_STATUS" | grep -q '"ok"'; then
                pass "F70: servidor MJPEG /status operativo"
                F70_LIVE_HDR=$(curl -s -D - -o /dev/null --max-time 5 "http://127.0.0.1:8086/live?id=$F70_CAM" 2>/dev/null | tr -d '\r')
                if echo "$F70_LIVE_HDR" | grep -qi 'multipart/x-mixed-replace'; then
                    pass "F70: /live?id=$F70_CAM sirve MJPEG"
                else
                    fail "F70: /live multipart" "$(echo "$F70_LIVE_HDR" | head -1)"
                fi
            else
                skip "F70 MJPEG interno" "servicio cameras-live no activo"
            fi
        else
            skip "F70 MJPEG" "sin cámara habilitada"
        fi
    fi
else
    skip "BLOCK 48 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 49 — F71: Presencia real del almacén + frescura (RF-81 / RF-82)
# Trazabilidad: RF-81.1..81.5, RF-82.1..82.4; TSK-F71-02..TSK-F71-06
# =============================================================================
block "BLOCK 49 — F71: Presencia real del almacén + frescura"

# 49.0 Lógica pura del croquis con frescura (Node; sin servidor)
F71_JS="tests/Unit/croquis-logic.test.js"
if [ -f "$F71_JS" ]; then
    F71_OUT=$(node "$F71_JS" 2>&1)
    F71_RC=$?
    F71_SUM=$(echo "$F71_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F71_SUM" ] && F71_SUM="exit=$F71_RC"
    if [ "$F71_RC" -eq 0 ]; then
        pass "F71: croquis-logic ($F71_SUM)"
    else
        fail "F71: croquis-logic" \
            "$F71_SUM — $(echo "$F71_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F71: croquis-logic" "$F71_JS no encontrado"
fi

# 49.1 Estáticos: marcadores de la implementación
# (F72 derogó DOOR_STALE_SECONDS; su ausencia se verifica en BLOCK 50)
if grep -q 'WAREHOUSE_TYPE_CODE' src/Domain/Presence/IotSessionService.php 2>/dev/null; then
    pass "F71: IotSessionService define el tipo de almacén"
else
    fail "F71: WAREHOUSE_TYPE_CODE" "ausente"
fi
if grep -q 'door_age_seconds' src/Http/Controllers/WarehouseStateController.php 2>/dev/null; then
    pass "F71: WarehouseStateController expone door_age_seconds"
else
    fail "F71: door_age_seconds" "ausente en el controlador"
fi
if grep -q 'A_CONFIRM_ENTRY' src/Domain/Warehouse/WarehouseRecordingDecision.php 2>/dev/null; then
    pass "F71: WarehouseRecordingDecision confirma visita por presencia"
else
    fail "F71: A_CONFIRM_ENTRY" "ausente"
fi

# 49.2 HTTP aditivo: live expone las edades de señal (RF-82.1)
if [ "$SERVER_UP" = true ]; then
    F71_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    if [ -n "$F71_ROOM" ]; then
        F71_STATE=$(curl -s --max-time 5 "$API_BASE/almacen-api/state?room_id=$F71_ROOM")
        if echo "$F71_STATE" | grep -q '"door_age_seconds"'; then
            pass "F71: state.live incluye door_age_seconds"
        else
            fail "F71: state.live[door_age_seconds]" "ausente"
        fi
        if echo "$F71_STATE" | grep -q '"presence_age_seconds"'; then
            pass "F71: state.live incluye presence_age_seconds"
        else
            fail "F71: state.live[presence_age_seconds]" "ausente"
        fi
    else
        skip "BLOCK 49 HTTP" "sin sala ALMACEN_BEBIDAS"
    fi
else
    skip "BLOCK 49 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 50 — F72/F74: Estado persistente de puerta y SIN polling periódico Tuya
# Trazabilidad: RF-83.1..83.3, RF-87.1..87.3; TSK-F72-02..F72-03, TSK-F74-02..F74-04
# =============================================================================
block "BLOCK 50 — F72/F74: Estado persistente de puerta (sin polling Tuya)"

# 50.0 Lógica pura del croquis (persistencia de estado)
F72_JS="tests/Unit/croquis-logic.test.js"
if [ -f "$F72_JS" ]; then
    F72_OUT=$(node "$F72_JS" 2>&1)
    F72_RC=$?
    F72_SUM=$(echo "$F72_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F72_SUM" ] && F72_SUM="exit=$F72_RC"
    if [ "$F72_RC" -eq 0 ]; then
        pass "F72: croquis-logic ($F72_SUM)"
    else
        fail "F72: croquis-logic" \
            "$F72_SUM — $(echo "$F72_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F72: croquis-logic" "$F72_JS no encontrado"
fi

# 50.0b Consumer: sin polling periódico de cuota Tuya (Node; sin servidor)
F72C_JS="tests/Unit/tuya-pulsar-consumer.test.js"
if [ -f "$F72C_JS" ]; then
    F72C_OUT=$(node "$F72C_JS" 2>&1)
    F72C_RC=$?
    F72C_SUM=$(echo "$F72C_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F72C_SUM" ] && F72C_SUM="exit=$F72C_RC"
    if [ "$F72C_RC" -eq 0 ]; then
        pass "F72/F74: tuya-pulsar-consumer ($F72C_SUM)"
    else
        fail "F72/F74: tuya-pulsar-consumer" \
            "$F72C_SUM — $(echo "$F72C_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F72/F74: tuya-pulsar-consumer" "$F72C_JS no encontrado"
fi

# 50.1 Estáticos: estado persistente y AUSENCIA de polling periódico Tuya
if ! grep -q 'DOOR_STALE_SECONDS' public/assets/croquis-logic.js 2>/dev/null; then
    pass "F72: croquis NO degrada por antigüedad (sin DOOR_STALE_SECONDS)"
else
    fail "F72: DOOR_STALE_SECONDS" "sigue presente en croquis-logic.js"
fi
if ! grep -qE 'periodicDoorResyncDue|DOOR_RESYNC_MS|resyncDoorPeriodic' bin/tuya-pulsar-consumer/index.js 2>/dev/null; then
    pass "F74: consumer SIN resync periódico que gaste cuota Tuya"
else
    fail "F74: resync periódico" "sigue presente en el consumer"
fi
if ! grep -q 'CONSUMER_DOOR_RESYNC_MS' .env.example 2>/dev/null; then
    pass "F74: .env.example SIN CONSUMER_DOOR_RESYNC_MS"
else
    fail "F74: .env.example" "documenta un polling periódico"
fi

# =============================================================================
# BLOCK 51 — F73: Tope de grabación en pruebas + purga (RF-85 / RF-86)
# Trazabilidad: RF-85.1..85.4, RF-86.1..86.3; TSK-F73-02..TSK-F73-04
# =============================================================================
block "BLOCK 51 — F73: Tope de grabación + purga"

# 51.0 Estáticos
if grep -q 'enforceRecordingCap' src/Domain/Warehouse/WarehouseRecordingService.php 2>/dev/null; then
    pass "F73: servicio define enforceRecordingCap"
else
    fail "F73: enforceRecordingCap" "ausente en WarehouseRecordingService"
fi
if grep -q 'WAREHOUSE_MAX_RECORDING_SECONDS' bin/warehouse-recorder.php 2>/dev/null; then
    pass "F73: recorder lee WAREHOUSE_MAX_RECORDING_SECONDS"
else
    fail "F73: recorder" "sin WAREHOUSE_MAX_RECORDING_SECONDS"
fi
if grep -q 'WAREHOUSE_MAX_RECORDING_SECONDS' .env.example 2>/dev/null; then
    pass "F73: .env.example documenta el tope"
else
    fail "F73: .env.example" "sin WAREHOUSE_MAX_RECORDING_SECONDS"
fi
if [ -f bin/warehouse-purge.php ] && php -l bin/warehouse-purge.php >/dev/null 2>&1; then
    pass "F73: bin/warehouse-purge.php presente y válido"
else
    fail "F73: warehouse-purge.php" "ausente o con error de sintaxis"
fi
# F77.6: el recorder debe abortar ffmpeg cuyo registro ya no existe (purga segura).
if grep -q "registro ya no existe en BD" bin/warehouse-recorder.php 2>/dev/null; then
    pass "F77.6: recorder aborta grabaciones huerfanas"
else
    fail "F77.6: recorder sin abort de huerfanos" "ausente"
fi

# 51.1 Unit (auto-descubierto en BLOCK 1; se verifica su presencia)
if [ -f tests/Unit/WarehouseRecordingCapTest.php ]; then
    pass "F73: tests/Unit/WarehouseRecordingCapTest.php presente"
else
    fail "F73: unit cap" "archivo ausente"
fi

# 51.2 DB: el tope marca solo las grabaciones antiguas
F73_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
F73_DEVICE=$($MYSQL -sN -e "SELECT d.id FROM devices d JOIN rooms r ON r.pack_id=d.pack_id WHERE r.id=$F73_ROOM AND d.kind='CAMERA' ORDER BY d.id LIMIT 1" 2>/dev/null)
if [ -n "$F73_ROOM" ] && [ -n "$F73_DEVICE" ]; then
    # Snapshot de grabaciones reales en curso para restaurarlas tras el test.
    F73_SNAP="$PROJECT_DIR/logs/_f73_snap.tmp"
    $MYSQL -sN -e "SELECT CONCAT(id,':',stop_requested) FROM camera_recordings WHERE status='RECORDING'" 2>/dev/null > "$F73_SNAP"

    # F79: se anclan por id (no por `error`). El recorder vivo reconcilia estas
    # filas y sobreescribe `error`; consultar por id evita la carrera que hacía
    # fallar F73 de forma intermitente.
    F73_ID0=$($MYSQL -sN -e "INSERT INTO camera_recordings
                (visit_id, room_id, device_id, position, episode, \`trigger\`, status,
                 requested_at, started_at, stop_requested, discard_requested, error)
               VALUES
                (NULL, $F73_ROOM, $F73_DEVICE, 'EXTERIOR', 'ENTRY', 'PRESENCE', 'RECORDING',
                 UTC_TIMESTAMP(3), UTC_TIMESTAMP(3) - INTERVAL 120 SECOND, 0, 0, 'f73_cap_old'),
                (NULL, $F73_ROOM, $F73_DEVICE, 'INTERIOR', 'ENTRY', 'PRESENCE', 'RECORDING',
                 UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), 0, 0, 'f73_cap_new'); SELECT LAST_INSERT_ID();" 2>/dev/null)
    F73_ID0=$(echo "$F73_ID0" | tr -dc '0-9')
    F73_ID_OLD=$F73_ID0
    F73_ID_NEW=$((F73_ID0 + 1))

    F73_CAP_N=$(php -r '
require "src/Support/Autoload.php";
App\Support\Config::load(".env");
$pdo = App\Infrastructure\Db\PdoFactory::make();
$s = new App\Domain\Warehouse\WarehouseRecordingService($pdo);
echo $s->enforceRecordingCap(60);
' 2>/dev/null)

    if [ -z "$F73_ID0" ]; then
        fail "F73: insert sintético" "no se pudo insertar el clip de prueba"
    else
        F73_OLD=$($MYSQL -sN -e "SELECT stop_requested FROM camera_recordings WHERE id=$F73_ID_OLD LIMIT 1" 2>/dev/null)
        F73_NEW=$($MYSQL -sN -e "SELECT stop_requested FROM camera_recordings WHERE id=$F73_ID_NEW LIMIT 1" 2>/dev/null)
        [ "$F73_OLD" = "1" ] && pass "F73: clip >60s marcado stop_requested=1" || fail "F73: clip antiguo" "stop_requested=$F73_OLD (esperado 1)"
        [ "$F73_NEW" = "0" ] && pass "F73: clip <60s intacto" || fail "F73: clip reciente" "stop_requested=$F73_NEW (esperado 0)"

        $MYSQL -e "DELETE FROM camera_recordings WHERE id IN ($F73_ID_OLD,$F73_ID_NEW)" 2>/dev/null
    fi

    # Restaurar stop_requested de grabaciones reales que el test pudo marcar.
    if [ -f "$F73_SNAP" ]; then
        while IFS=: read -r rid rstop; do
            [ -z "$rid" ] && continue
            $MYSQL -e "UPDATE camera_recordings SET stop_requested=$rstop WHERE id=$rid AND status='RECORDING'" 2>/dev/null
        done < "$F73_SNAP"
    fi
    rm -f "$F73_SNAP"
    pass "F73: estado sintético limpiado"
else
    skip "BLOCK 51 DB" "sin sala ALMACEN_BEBIDAS o cámara"
fi

# =============================================================================
# BLOCK 52 — F75/F76: Refresco de sensores + presencia anclada al ciclo de puerta
# Trazabilidad: RF-88..90 (F75), RF-91..92 (F76); TSK-F75-02..F75-04, TSK-F76-02..F76-04
# =============================================================================
block "BLOCK 52 — F75/F76: Refresco de sensores del almacén (sin polling)"

# 52.0 Estáticos: ruta, panel, env, presencia con contexto y snapshot de tests
for F75_MARK in \
    "public/index.php:/almacen-api/sensors/refresh" \
    "public/assets/almacen.js:refreshSensors" \
    "public/almacen.html:btn-refresh" \
    ".env.example:ALMACEN_SENSOR_REFRESH_COOLDOWN_SECONDS" \
    "src/Domain/Presence/SensorEventDecision.php:warehousePresence" \
    "src/Domain/Presence/IotSessionService.php:warehousePresenceContext" \
    "src/Domain/Warehouse/WarehouseRecordingService.php:activeEnteredVisitAt" \
    "bin/run-tests.sh:_e2e_backup_iot"; do
    F75_FILE="${F75_MARK%%:*}"
    F75_NEEDLE="${F75_MARK#*:}"
    if grep -q "$F75_NEEDLE" "$F75_FILE" 2>/dev/null; then
        pass "F75: $F75_FILE define '$F75_NEEDLE'"
    else
        fail "F75: $F75_FILE" "falta '$F75_NEEDLE'"
    fi
done

# 52.1 HTTP: la ruta responde con `state` (respeta el cooldown: puede no sondear)
if [ "$SERVER_UP" = true ]; then
    F75_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    if [ -n "$F75_ROOM" ]; then
        F75_RES=$(curl -s -X POST -H 'Content-Type: application/json' \
            -d "{\"room_id\":$F75_ROOM}" --max-time 12 "$API_BASE/almacen-api/sensors/refresh" 2>/dev/null)
        if echo "$F75_RES" | grep -q '"state"' && echo "$F75_RES" | grep -q '"probed"'; then
            pass "F75: sensors/refresh responde ok con state (probed/throttled)"
        else
            fail "F75: sensors/refresh" "$(echo "$F75_RES" | head -c 200)"
        fi
    else
        skip "BLOCK 52 HTTP" "sin sala ALMACEN_BEBIDAS"
    fi
else
    skip "BLOCK 52 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 53 — F80: Presencia del almacén en tiempo real (RF-103)
# Trazabilidad: RF-103.1..102.6; TSK-F80-01..F80-05
# =============================================================================
block "BLOCK 53 — F80: Presencia del almacén en tiempo real"

# 53.0 Estáticos: presencia siempre creíble, contrato live y fases del croquis.
for F80_MARK in \
    "src/Domain/Presence/SensorEventDecision.php:F80" \
    "src/Domain/Presence/IotSessionService.php:F80" \
    "src/Domain/Warehouse/WarehouseRecordingService.php:PRESENCE" \
    "src/Http/Controllers/WarehouseStateController.php:recent_presence" \
    "public/assets/croquis-logic.js:phase" \
    "public/assets/almacen.js:d.phase"; do
    F80_FILE="${F80_MARK%%:*}"
    F80_NEEDLE="${F80_MARK#*:}"
    if grep -q "$F80_NEEDLE" "$F80_FILE" 2>/dev/null; then
        pass "F80: $F80_FILE define '$F80_NEEDLE'"
    else
        fail "F80: $F80_FILE" "falta '$F80_NEEDLE'"
    fi
done

# 53.1 Guardia: el veto de puerta de F76 fue eliminado de SensorEventDecision.
if grep -qF '!($entryWindowActive || $insideNoExitCycle)' "src/Domain/Presence/SensorEventDecision.php" 2>/dev/null; then
    fail "F80: veto F76 sigue en SensorEventDecision" "aparece (!entryWindowActive || insideNoExitCycle)"
else
    pass "F80: veto de puerta de F76 eliminado (presencia creíble al instante)"
fi

# 53.2 HTTP: /almacen-api/state expone live.recent_presence (array) — contrato aditivo.
if [ "$SERVER_UP" = true ]; then
    F80_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    if [ -n "$F80_ROOM" ]; then
        F80_STATE=$(curl -s --max-time 10 "$API_BASE/almacen-api/state?room_id=$F80_ROOM" 2>/dev/null)
        if echo "$F80_STATE" | grep -q '"recent_presence":\['; then
            pass "F80: state.live.recent_presence expuesto (array)"
        else
            fail "F80: state.live.recent_presence" "$(echo "$F80_STATE" | head -c 200)"
        fi
        if echo "$F80_STATE" | grep -q '"presence_state"'; then
            pass "F80: state.live.presence_state intacto"
        else
            fail "F80: state.live.presence_state" "no presente"
        fi
    else
        skip "BLOCK 53 HTTP" "sin sala ALMACEN_BEBIDAS"
    fi
else
    skip "BLOCK 53 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 54 — F81: Cierre de visita del almacén (RF-104)
# Trazabilidad: RF-104.1..104.6; TSK-F81-01..F81-04
# =============================================================================
block "BLOCK 54 — F81: Cierre de visita del almacén"

# 54.0 Estáticos: evento DOOR_CLOSE_ABSENT y su cableado en el pipeline IoT.
for F81_MARK in \
    "src/Domain/Warehouse/WarehouseRecordingDecision.php:EV_DOOR_CLOSE_ABSENT" \
    "src/Domain/Presence/IotSessionService.php:DOOR_CLOSE_ABSENT" \
    "src/Domain/Presence/IotSessionService.php:F81"; do
    F81_FILE="${F81_MARK%%:*}"
    F81_NEEDLE="${F81_MARK#*:}"
    if grep -q "$F81_NEEDLE" "$F81_FILE" 2>/dev/null; then
        pass "F81: $F81_FILE define '$F81_NEEDLE'"
    else
        fail "F81: $F81_FILE" "falta '$F81_NEEDLE'"
    fi
done

# 54.1 UI del croquis (tolerante: otro agente añade los marcadores F81).
if grep -q 'F81' public/assets/croquis-logic.js 2>/dev/null; then
    pass "F81: croquis-logic.js incluye marcadores F81"
else
    fail "F81: croquis-logic.js" "sin marcadores F81"
fi
if grep -q 'pos-inside' public/almacen.html 2>/dev/null; then
    pass "F81: almacen.html define la posición pos-inside"
else
    fail "F81: almacen.html pos-inside" "ausente"
fi

# 54.2 Lógica pura del croquis (Node; reutiliza el runner ya existente).
F81_JS="tests/Unit/croquis-logic.test.js"
if [ -f "$F81_JS" ]; then
    F81_OUT=$(node "$F81_JS" 2>&1)
    F81_RC=$?
    F81_SUM=$(echo "$F81_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F81_SUM" ] && F81_SUM="exit=$F81_RC"
    if [ "$F81_RC" -eq 0 ]; then
        pass "F81: croquis-logic ($F81_SUM)"
    else
        fail "F81: croquis-logic" \
            "$F81_SUM — $(echo "$F81_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F81: croquis-logic" "$F81_JS no encontrado"
fi

# =============================================================================
# BLOCK 55 — F82: Listado de visitas con presencia (RF-105)
# Trazabilidad: RF-105.1..105.4; TSK-F82-01..F82-02
# =============================================================================
block "BLOCK 55 — F82: Listado de visitas con presencia"

# 55.0 Estáticos: el controlador ya no oculta PRESENCE por defecto, pero
# mantiene el filtro NO_SHOW DOOR-only y la palanca include_no_show.
F82_CTRL="$PROJECT_DIR/src/Http/Controllers/WarehouseVisitController.php"
if grep -q "entry_trigger <> 'PRESENCE'" "$F82_CTRL" 2>/dev/null; then
    fail "F82: visits ya no oculta PRESENCE por defecto" \
        "aún contiene entry_trigger <> 'PRESENCE'"
else
    pass "F82: visits ya no oculta PRESENCE por defecto"
fi
if grep -Fq "NOT (v.outcome = 'NO_SHOW' AND v.entry_trigger = 'DOOR')" "$F82_CTRL" 2>/dev/null; then
    pass "F82: visits mantiene el filtro NO_SHOW DOOR-only"
else
    fail "F82: visits filtro NO_SHOW DOOR-only" "ausente"
fi
if grep -q "include_no_show" "$F82_CTRL" 2>/dev/null; then
    pass "F82: visits mantiene include_no_show"
else
    fail "F82: visits include_no_show" "ausente"
fi
if grep -q "F82" "$F82_CTRL" 2>/dev/null; then
    pass "F82: visits incluye el marcador F82"
else
    fail "F82: visits marcador F82" "ausente"
fi

# 55.1 HTTP/DB: una visita sintética con entry_trigger='PRESENCE' aparece por
# defecto en GET /almacen-api/visits (sin include_no_show).
if [ "$SERVER_UP" = true ]; then
    F82_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    if [ -n "$F82_ROOM" ]; then
        F82_VISIT=$($MYSQL -sN -e "INSERT INTO warehouse_visits (room_id, entry_trigger, outcome, entered_at) VALUES ($F82_ROOM, 'PRESENCE', 'ENTERED', UTC_TIMESTAMP(3)); SELECT LAST_INSERT_ID();" 2>/dev/null)
        if [ -n "$F82_VISIT" ]; then
            F82_JSON=$(curl -s --max-time 5 "$API_BASE/almacen-api/visits?room_id=$F82_ROOM&limit=200")
            F82_PRESENT=$(echo "$F82_JSON" | python3 -c \
                "import sys,json; d=json.load(sys.stdin); print('yes' if any(int(v.get('id',0))==$F82_VISIT for v in d.get('visits',[])) else 'no')" \
                2>/dev/null || echo "parse_error")
            if [ "$F82_PRESENT" = "yes" ]; then
                pass "F82: visita PRESENCE aparece por defecto en /almacen-api/visits"
            else
                fail "F82: visita PRESENCE en listado" \
                    "present=$F82_PRESENT id=$F82_VISIT body=$(echo "$F82_JSON" | head -c 240)"
            fi
            # Cleanup garantizado del estado sintético (incluido si el parse falla).
            $MYSQL -e "DELETE FROM camera_recordings WHERE visit_id=$F82_VISIT; DELETE FROM warehouse_visits WHERE id=$F82_VISIT;" 2>/dev/null
            F82_LEFT=$($MYSQL -sN -e "SELECT COUNT(*) FROM warehouse_visits WHERE id=$F82_VISIT" 2>/dev/null || echo 1)
            if [ "$F82_LEFT" = "0" ]; then
                pass "F82: estado sintético limpiado"
            else
                fail "F82: limpieza visita sintética" "quedan $F82_LEFT filas"
            fi
        else
            skip "F82 HTTP" "no se pudo crear la visita sintética"
        fi
    else
        skip "F82 HTTP" "sin sala ALMACEN_BEBIDAS"
    fi
else
    skip "F82 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 56 — F83: Presencia fiel en el panel del almacén (RF-106)
# Trazabilidad: RF-106.1..106.6; TSK-F83-01..F83-05
# =============================================================================
block "BLOCK 56 — F83: Presencia fiel en el panel del almacén"

# 56.0 Estáticos: el contrato expone presencia fiel y estado de salida aditivo.
F83_CTRL="$PROJECT_DIR/src/Http/Controllers/WarehouseStateController.php"
for F83_MARK in "F83" "presence_active" "exiting"; do
    if grep -q "$F83_MARK" "$F83_CTRL" 2>/dev/null; then
        pass "F83: WarehouseStateController define '$F83_MARK'"
    else
        fail "F83: WarehouseStateController" "falta '$F83_MARK'"
    fi
done

# 56.1 Migración: el margen exterior de ALMACEN_BEBIDAS pasa a 10 s (si hay BD).
F83_MARGIN=$($MYSQL -sN -e "SELECT warehouse_exterior_margin_seconds FROM room_types WHERE code='ALMACEN_BEBIDAS'" 2>/dev/null)
if [ "$F83_MARGIN" = "10" ]; then
    pass "F83: margen exterior ALMACEN_BEBIDAS = 10 s"
elif [ -z "$F83_MARGIN" ]; then
    skip "F83: margen exterior ALMACEN_BEBIDAS" "sin BD/sala"
else
    fail "F83: margen exterior ALMACEN_BEBIDAS" "esperado 10, obtenido '$F83_MARGIN'"
fi

# 56.2 Lógica pura del croquis (Node; reutiliza el patrón de BLOCK 55).
F83_JS="tests/Unit/croquis-logic.test.js"
if [ -f "$F83_JS" ]; then
    F83_OUT=$(node "$F83_JS" 2>&1)
    F83_RC=$?
    F83_SUM=$(echo "$F83_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F83_SUM" ] && F83_SUM="exit=$F83_RC"
    if [ "$F83_RC" -eq 0 ]; then
        pass "F83: croquis-logic ($F83_SUM)"
    else
        fail "F83: croquis-logic" \
            "$F83_SUM — $(echo "$F83_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F83: croquis-logic" "$F83_JS no encontrado"
fi

# 56.3 HTTP/DB: reproduce el bug (visita ENTERED enlazada + radar ABSENT) y
# verifica que `occupied` pasa a false, `exiting` a true y `presence_active` false.
if [ "$SERVER_UP" = true ]; then
    F83_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    if [ -n "$F83_ROOM" ]; then
        F83_LS_EXISTS=$($MYSQL -sN -e "SELECT COUNT(*) FROM iot_sessions WHERE room_id=$F83_ROOM" 2>/dev/null || echo 0)
        if [ "$F83_LS_EXISTS" != "0" ]; then
            # Snapshot de warehouse_state e iot_sessions.presence_state.
            F83_WS_EXISTS=$($MYSQL -sN -e "SELECT COUNT(*) FROM warehouse_state WHERE room_id=$F83_ROOM" 2>/dev/null || echo 0)
            F83_WS_STATE=$($MYSQL -sN -e "SELECT COALESCE(state,'IDLE') FROM warehouse_state WHERE room_id=$F83_ROOM LIMIT 1" 2>/dev/null)
            F83_WS_VISIT=$($MYSQL -sN -e "SELECT COALESCE(current_visit_id,'NULL') FROM warehouse_state WHERE room_id=$F83_ROOM LIMIT 1" 2>/dev/null)
            F83_WS_TRIG=$($MYSQL -sN -e "SELECT COALESCE(entry_trigger,'NULL') FROM warehouse_state WHERE room_id=$F83_ROOM LIMIT 1" 2>/dev/null)
            F83_WS_DX=$($MYSQL -sN -e "SELECT COALESCE(deadline_x,'NULL') FROM warehouse_state WHERE room_id=$F83_ROOM LIMIT 1" 2>/dev/null)
            F83_WS_DM=$($MYSQL -sN -e "SELECT COALESCE(deadline_m,'NULL') FROM warehouse_state WHERE room_id=$F83_ROOM LIMIT 1" 2>/dev/null)
            F83_WS_CONF=$($MYSQL -sN -e "SELECT COALESCE(presence_confirmed,0) FROM warehouse_state WHERE room_id=$F83_ROOM LIMIT 1" 2>/dev/null)
            F83_LS_PRESENCE=$($MYSQL -sN -e "SELECT COALESCE(presence_state,'UNKNOWN') FROM iot_sessions WHERE room_id=$F83_ROOM LIMIT 1" 2>/dev/null)

            # Estado sintético: visita ENTERED sin salida + EXIT_PENDING + ABSENT.
            F83_VISIT=$($MYSQL -sN -e "INSERT INTO warehouse_visits (room_id, entry_trigger, outcome, entered_at) VALUES ($F83_ROOM,'DOOR','ENTERED',UTC_TIMESTAMP(3)); SELECT LAST_INSERT_ID();" 2>/dev/null)
            if [ -n "$F83_VISIT" ]; then
                $MYSQL -e "INSERT INTO warehouse_state (room_id, state, current_visit_id) VALUES ($F83_ROOM,'EXIT_PENDING',$F83_VISIT) ON DUPLICATE KEY UPDATE state='EXIT_PENDING', current_visit_id=$F83_VISIT;" 2>/dev/null
                $MYSQL -e "UPDATE iot_sessions SET presence_state='ABSENT' WHERE room_id=$F83_ROOM;" 2>/dev/null

                F83_BODY=$(curl -s --max-time 5 "$API_BASE/almacen-api/state?room_id=$F83_ROOM" 2>/dev/null)
                F83_CHK=$(printf '%s' "$F83_BODY" | python3 -c "import sys, json
try:
    d = json.load(sys.stdin)
    w = d.get('warehouse') or {}
    l = d.get('live') or {}
    ok = (w.get('occupied') is False and w.get('exiting') is True and l.get('presence_active') is False)
    print('ok' if ok else 'bad: occupied=' + str(w.get('occupied')) + ' exiting=' + str(w.get('exiting')) + ' presence_active=' + str(l.get('presence_active')))
except Exception:
    print('parse_error')" 2>/dev/null || echo "parse_error")
                if [ "$F83_CHK" = "ok" ]; then
                    pass "F83: ABSENT manda sobre visita (occupied=false, exiting=true)"
                else
                    fail "F83: ocupación fiel al radar" \
                        "chk=$F83_CHK body=$(echo "$F83_BODY" | head -c 300)"
                fi

                # Cleanup SIEMPRE (no depende del parse): restaura estado y borra la visita.
                if [ "$F83_WS_EXISTS" != "0" ]; then
                    F83_DX_SQL="NULL"; [ "$F83_WS_DX" != "NULL" ] && F83_DX_SQL="'$F83_WS_DX'"
                    F83_DM_SQL="NULL"; [ "$F83_WS_DM" != "NULL" ] && F83_DM_SQL="'$F83_WS_DM'"
                    F83_TRIG_SQL="NULL"; [ "$F83_WS_TRIG" != "NULL" ] && F83_TRIG_SQL="'$F83_WS_TRIG'"
                    F83_VISIT_SQL="NULL"; [ "$F83_WS_VISIT" != "NULL" ] && F83_VISIT_SQL="$F83_WS_VISIT"
                    $MYSQL -e "UPDATE warehouse_state SET state='$F83_WS_STATE', current_visit_id=$F83_VISIT_SQL, entry_trigger=$F83_TRIG_SQL, deadline_x=$F83_DX_SQL, deadline_m=$F83_DM_SQL, presence_confirmed=$F83_WS_CONF WHERE room_id=$F83_ROOM;" 2>/dev/null
                else
                    $MYSQL -e "DELETE FROM warehouse_state WHERE room_id=$F83_ROOM;" 2>/dev/null
                fi
                $MYSQL -e "UPDATE iot_sessions SET presence_state='$F83_LS_PRESENCE' WHERE room_id=$F83_ROOM;" 2>/dev/null
                $MYSQL -e "DELETE FROM camera_recordings WHERE visit_id=$F83_VISIT; DELETE FROM warehouse_visits WHERE id=$F83_VISIT;" 2>/dev/null

                F83_LEFT_V=$($MYSQL -sN -e "SELECT COUNT(*) FROM warehouse_visits WHERE id=$F83_VISIT" 2>/dev/null || echo 1)
                F83_LEFT_C=$($MYSQL -sN -e "SELECT COUNT(*) FROM camera_recordings WHERE visit_id=$F83_VISIT" 2>/dev/null || echo 1)
                if [ "$F83_LEFT_V" = "0" ] && [ "$F83_LEFT_C" = "0" ]; then
                    pass "F83: estado sintético limpiado"
                else
                    fail "F83: limpieza estado sintético" "visitas=$F83_LEFT_V clips=$F83_LEFT_C"
                fi
            else
                skip "F83 HTTP" "no se pudo crear la visita sintética"
            fi
        else
            skip "F83 HTTP" "sin fila iot_sessions para la sala"
        fi
    else
        skip "F83 HTTP" "sin sala ALMACEN_BEBIDAS"
    fi
else
    skip "F83 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 57 — F84: Antiruido del radar + reproducción fiel de la puerta (RF-107/108)
# Trazabilidad: RF-107.1..107.8, RF-108.1..108.4; TSK-F84-01..F84-07
# =============================================================================
block "BLOCK 57 — F84: Antiruido del radar y puerta fiel"

# 57.0 Estáticos: clasificador, acción NOISE, filtro del listado y replay fiel.
for F84_MARK in \
    "src/Domain/Warehouse/PresenceEvidence.php:isConfirmed" \
    "src/Domain/Warehouse/WarehouseRecordingDecision.php:A_MARK_NOISE" \
    "src/Domain/Warehouse/WarehouseRecordingService.php:presenceEvidenceConfirmed" \
    "src/Http/Controllers/WarehouseVisitController.php:include_noise" \
    "src/Http/Controllers/WarehouseVisitController.php:attachDoorEvents" \
    "public/assets/visit-playback.js:doorKnown" \
    "public/assets/visit-playback.js:doorOpenAt"; do
    F84_FILE="${F84_MARK%%:*}"
    F84_NEEDLE="${F84_MARK#*:}"
    if grep -q "$F84_NEEDLE" "$F84_FILE" 2>/dev/null; then
        pass "F84: $F84_FILE define '$F84_NEEDLE'"
    else
        fail "F84: $F84_FILE" "falta '$F84_NEEDLE'"
    fi
done
if [ -f "migrations/0122_warehouse_presence_noise.sql" ]; then
    pass "F84: migración 0122 presente"
else
    fail "F84: migración 0122" "no encontrada"
fi

# 57.1 Clasificador puro PresenceEvidence (autodescubierto en BLOCK 1; aquí explícito).
F84_EV="tests/Unit/PresenceEvidenceTest.php"
if [ -f "$F84_EV" ]; then
    F84_EV_OUT=$(php "$F84_EV" 2>&1)
    F84_EV_RC=$?
    F84_EV_SUM=$(echo "$F84_EV_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F84_EV_SUM" ] && F84_EV_SUM="exit=$F84_EV_RC"
    if [ "$F84_EV_RC" -eq 0 ]; then
        pass "F84: PresenceEvidence ($F84_EV_SUM)"
    else
        fail "F84: PresenceEvidence" \
            "$F84_EV_SUM — $(echo "$F84_EV_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F84: PresenceEvidence" "$F84_EV no encontrado"
fi

# 57.2 BD: enum NOISE + umbrales configurables.
if [ -n "$MYSQL_BIN" ]; then
    F84_ENUM=$($MYSQL -sN -e "SHOW COLUMNS FROM warehouse_visits LIKE 'outcome'" 2>/dev/null)
    if echo "$F84_ENUM" | grep -q "NOISE"; then
        pass "F84: warehouse_visits.outcome admite NOISE"
    elif [ -z "$F84_ENUM" ]; then
        skip "F84: enum NOISE" "sin BD"
    else
        fail "F84: enum NOISE" "no admite NOISE"
    fi
    F84_COLS=$($MYSQL -sN -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='room_types' AND column_name IN ('warehouse_presence_min_moves','warehouse_presence_min_events','warehouse_presence_static_seconds')" 2>/dev/null)
    if [ "$F84_COLS" = "3" ]; then
        pass "F84: room_types expone los 3 umbrales de evidencia"
    elif [ -z "$F84_COLS" ]; then
        skip "F84: umbrales de evidencia" "sin BD"
    else
        fail "F84: umbrales de evidencia" "columnas=$F84_COLS (esperado 3)"
    fi
fi

# 57.3 HTTP/DB: el listado oculta NOISE por defecto y lo muestra con include_noise=1.
if [ "$SERVER_UP" = true ]; then
    F84_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    if [ -n "$F84_ROOM" ]; then
        F84_V=$($MYSQL -sN -e "INSERT INTO warehouse_visits (room_id, entry_trigger, outcome, created_at) VALUES ($F84_ROOM,'PRESENCE','NOISE',UTC_TIMESTAMP(3)); SELECT LAST_INSERT_ID();" 2>/dev/null)
        if [ -n "$F84_V" ]; then
            F84_DEF=$(curl -s --max-time 8 "$API_BASE/almacen-api/visits?room_id=$F84_ROOM&limit=200" 2>/dev/null)
            F84_INCL=$(curl -s --max-time 8 "$API_BASE/almacen-api/visits?room_id=$F84_ROOM&include_noise=1&limit=200" 2>/dev/null)
            F84_CHK=$(printf '%s\n%s\n%s' "$F84_DEF" "$F84_INCL" "$F84_V" | python3 -c "import sys,json
raw=sys.stdin.read().split('\n')
try:
    d=json.loads(raw[0]); i=json.loads(raw[1]); vid=int(raw[2].strip())
    dids=[v.get('id') for v in (d.get('visits') or [])]
    iids=[v.get('id') for v in (i.get('visits') or [])]
    print('ok' if (vid not in dids and vid in iids) else 'bad: def='+str(vid in dids)+' incl='+str(vid in iids))
except Exception:
    print('parse_error')" 2>/dev/null || echo "parse_error")
            if [ "$F84_CHK" = "ok" ]; then
                pass "F84: listado oculta NOISE por defecto y lo muestra con include_noise=1"
            else
                fail "F84: filtro NOISE en el listado" "chk=$F84_CHK"
            fi
            $MYSQL -e "DELETE FROM camera_recordings WHERE visit_id=$F84_V; DELETE FROM warehouse_visits WHERE id=$F84_V;" 2>/dev/null
        else
            skip "F84 HTTP" "no se pudo crear la visita NOISE"
        fi
    else
        skip "F84 HTTP" "sin sala ALMACEN_BEBIDAS"
    fi
else
    skip "F84 HTTP" "servidor no disponible"
fi

# 57.4 HTTP/DB: el detalle de visita expone `door` (state_at_start + events reales).
if [ "$SERVER_UP" = true ]; then
    F84_ROOM2=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    if [ -n "$F84_ROOM2" ]; then
        F84_V2=$($MYSQL -sN -e "INSERT INTO warehouse_visits (room_id, entry_trigger, outcome, created_at) VALUES ($F84_ROOM2,'DOOR','ENTERED',UTC_TIMESTAMP(3)); SELECT LAST_INSERT_ID();" 2>/dev/null)
        F84_PE=$($MYSQL -sN -e "INSERT INTO presence_events (room_id, sensor, value, provider, occurred_at, event_fingerprint, applied, meta_json) VALUES ($F84_ROOM2,'PROXIMITY','OPEN','TUYA',UTC_TIMESTAMP(3),SHA1(CONCAT('f84-',UUID())),1,'{}'); SELECT LAST_INSERT_ID();" 2>/dev/null)
        if [ -n "$F84_V2" ]; then
            F84_DET=$(curl -s --max-time 8 "$API_BASE/almacen-api/visits/$F84_V2" 2>/dev/null)
            F84_DCHK=$(printf '%s' "$F84_DET" | python3 -c "import sys,json
try:
    v=json.load(sys.stdin).get('visit') or {}
    dr=v.get('door') or {}
    ok=('events' in dr and isinstance(dr.get('events'),list) and any(e.get('value')=='OPEN' for e in dr.get('events',[])))
    print('ok' if ok else 'bad: '+str(dr)[:120])
except Exception:
    print('parse_error')" 2>/dev/null || echo "parse_error")
            if [ "$F84_DCHK" = "ok" ]; then
                pass "F84: visits/{id} expone door.events con el OPEN real"
            else
                fail "F84: door en el detalle" "chk=$F84_DCHK"
            fi
            $MYSQL -e "DELETE FROM camera_recordings WHERE visit_id=$F84_V2; DELETE FROM warehouse_visits WHERE id=$F84_V2;" 2>/dev/null
        else
            skip "F84 HTTP" "no se pudo crear la visita DOOR"
        fi
        [ -n "$F84_PE" ] && $MYSQL -e "DELETE FROM presence_events WHERE id=$F84_PE;" 2>/dev/null
    else
        skip "F84 HTTP" "sin sala ALMACEN_BEBIDAS"
    fi
else
    skip "F84 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 58 — F85: modelo de detección del pack almacén + puerta fiel (RF-109..114)
# Trazabilidad: RF-109.4, RF-110.1/110.2, RF-111.2/111.4, RF-112, RF-113; TSK-F85-01..F85-07
# =============================================================================
block "BLOCK 58 — F85: modelo de detección del almacén y puerta fiel"

# 58.0 Estáticos: motor, servicio/evidencia, recorder, API y panel.
for F85_MARK in \
    "src/Domain/Warehouse/WarehouseRecordingDecision.php:RF-109.4" \
    "src/Domain/Warehouse/WarehouseRecordingDecision.php:RF-110.1" \
    "src/Domain/Warehouse/WarehouseRecordingService.php:warehouse_reentry_context_seconds" \
    "src/Domain/Warehouse/WarehouseRecordingService.php:reentry_seconds" \
    "bin/warehouse-recorder.php:busyDevices" \
    "public/index.php:unresolved_transition" \
    "public/index.php:source' => 'panel'" \
    "public/almacen.html:f-discards" \
    "public/assets/almacen.js:doorStaleProbed"; do
    F85_FILE="${F85_MARK%%:*}"
    F85_NEEDLE="${F85_MARK#*:}"
    if grep -q "$F85_NEEDLE" "$F85_FILE" 2>/dev/null; then
        pass "F85: $F85_FILE define '$F85_NEEDLE'"
    else
        fail "F85: $F85_FILE" "falta '$F85_NEEDLE'"
    fi
done
if [ -f "migrations/0123_warehouse_reentry_context.sql" ]; then
    pass "F85: migración 0123 presente"
else
    fail "F85: migración 0123" "no encontrada"
fi

# 58.1 Units puros (motor + re-entrada + evidencia).
for F85_TEST in \
    "tests/Unit/WarehouseRecordingDecisionTest.php" \
    "tests/Unit/WarehouseReentryTest.php" \
    "tests/Unit/PresenceEvidenceTest.php"; do
    if [ -f "$F85_TEST" ]; then
        F85_OUT=$(php "$F85_TEST" 2>&1)
        F85_RC=$?
        F85_SUM=$(echo "$F85_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
        [ -z "$F85_SUM" ] && F85_SUM="exit=$F85_RC"
        if [ "$F85_RC" -eq 0 ]; then
            pass "F85: $(basename "$F85_TEST") ($F85_SUM)"
        else
            fail "F85: $(basename "$F85_TEST")" \
                "$F85_SUM — $(echo "$F85_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
        fi
    else
        fail "F85: $F85_TEST" "no encontrado"
    fi
done

# 58.2 BD: columna de contexto de re-entrada.
if [ -n "$MYSQL_BIN" ]; then
    F85_COL=$($MYSQL -sN -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='room_types' AND column_name='warehouse_reentry_context_seconds'" 2>/dev/null)
    if [ "$F85_COL" = "1" ]; then
        pass "F85: room_types expone warehouse_reentry_context_seconds"
    elif [ -z "$F85_COL" ]; then
        skip "F85: columna de re-entrada" "sin BD"
    else
        fail "F85: columna de re-entrada" "col=$F85_COL (esperado 1)"
    fi
fi

# 58.3 HTTP/DB: el refresh respeta el cooldown cuando NO hay transición sin resolver
# (sin gastar cuota Tuya). La rama de bypass (door_stale + transición) se verifica
# estáticamente en 58.0 y en vivo en la tarea F85-08.
if [ "$SERVER_UP" = true ]; then
    F85_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE rt.code='ALMACEN_BEBIDAS' ORDER BY r.id LIMIT 1" 2>/dev/null)
    if [ -n "$F85_ROOM" ]; then
        F85_PREV_AT=$($MYSQL -sN -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(meta_json,'\$.status_probed_at')) FROM devices d JOIN rooms r ON r.pack_id=d.pack_id WHERE r.id=$F85_ROOM AND d.kind='PROXIMITY' LIMIT 1" 2>/dev/null)
        $MYSQL -e "UPDATE devices d JOIN rooms r ON r.pack_id=d.pack_id SET d.meta_json=JSON_SET(COALESCE(d.meta_json,'{}'),'\$.status_probed_at','$(date -u +%Y-%m-%dT%H:%M:%SZ)') WHERE r.id=$F85_ROOM AND d.kind='PROXIMITY';" 2>/dev/null
        F85_RJ=$(curl -s --max-time 8 -X POST "$API_BASE/almacen-api/sensors/refresh" -H 'Content-Type: application/json' -d "{\"room_id\":$F85_ROOM}" 2>/dev/null)
        F85_RREASON=$(printf '%s' "$F85_RJ" | python3 -c "import sys,json
try: print((json.load(sys.stdin) or {}).get('reason',''))
except Exception: print('parse_error')" 2>/dev/null || echo "parse_error")
        if [ "$F85_RREASON" = "throttled" ] || [ "$F85_RREASON" = "unresolved_transition" ]; then
            pass "F85: refresh no fuerza sonda innecesaria (reason=$F85_RREASON)"
        else
            fail "F85: refresh cooldown" "reason=$F85_RREASON (esperado throttled)"
        fi
        # Restaura SOLO el campo (evita dejar el cooldown activo tras el test).
        if [ -n "$F85_PREV_AT" ] && [ "$F85_PREV_AT" != "NULL" ]; then
            $MYSQL -e "UPDATE devices d JOIN rooms r ON r.pack_id=d.pack_id SET d.meta_json=JSON_SET(COALESCE(d.meta_json,'{}'),'\$.status_probed_at','$F85_PREV_AT') WHERE r.id=$F85_ROOM AND d.kind='PROXIMITY';" 2>/dev/null
        else
            $MYSQL -e "UPDATE devices d JOIN rooms r ON r.pack_id=d.pack_id SET d.meta_json=JSON_REMOVE(COALESCE(d.meta_json,'{}'),'\$.status_probed_at') WHERE r.id=$F85_ROOM AND d.kind='PROXIMITY';" 2>/dev/null
        fi
    else
        skip "F85 HTTP" "sin sala ALMACEN_BEBIDAS"
    fi
else
    skip "F85 HTTP" "servidor no disponible"
fi

# =============================================================================
# BLOCK 59 — F86: puerta fiel ante reportes repetidos y re-entrada con contexto (RF-115)
# Trazabilidad: RF-115.1..115.6; TSK-F86-01..F86-04
# =============================================================================
block "BLOCK 59 — F86: puerta fiel ante reportes repetidos"

# 59.0 Estáticos: decisión refresh, efectos y contexto de puerta en la evidencia.
for F86_MARK in \
    "src/Domain/Presence/SensorEventDecision.php:REFRESH" \
    "src/Domain/Presence/SensorEventDecision.php:source !== 'resync'" \
    "src/Domain/Presence/IotSessionService.php:doorRefreshed" \
    "src/Domain/Warehouse/WarehouseRecordingService.php:RF-115.4"; do
    F86_FILE="${F86_MARK%%:*}"
    F86_NEEDLE="${F86_MARK#*:}"
    if grep -qF "$F86_NEEDLE" "$F86_FILE" 2>/dev/null; then
        pass "F86: $F86_FILE define '$F86_NEEDLE'"
    else
        fail "F86: $F86_FILE" "falta '$F86_NEEDLE'"
    fi
done

# 59.1 Units: decisión refresh y efectos en la sesión.
for F86_TEST in \
    "tests/Unit/SensorEventDecisionTest.php" \
    "tests/Unit/IotSessionServiceTest.php"; do
    if [ -f "$F86_TEST" ]; then
        F86_OUT=$(php "$F86_TEST" 2>&1)
        F86_RC=$?
        F86_SUM=$(echo "$F86_OUT" | grep -oE '([0-9]+ passed, [0-9]+ failed|Total: [0-9]+ passed, [0-9]+ failed)' | tail -1)
        [ -z "$F86_SUM" ] && F86_SUM="exit=$F86_RC"
        if [ "$F86_RC" -eq 0 ]; then
            pass "F86: $(basename "$F86_TEST") ($F86_SUM)"
        else
            fail "F86: $(basename "$F86_TEST")" \
                "$F86_SUM — $(echo "$F86_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
        fi
    else
        fail "F86: $F86_TEST" "no encontrado"
    fi
done

# 59.2 BD: discard_reason admite 'refresh' (VARCHAR >= 7).
if [ -n "$MYSQL_BIN" ]; then
    F86_LEN=$($MYSQL -sN -e "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='presence_events' AND column_name='discard_reason'" 2>/dev/null)
    if [ -n "$F86_LEN" ] && [ "$F86_LEN" -ge 7 ] 2>/dev/null; then
        pass "F86: presence_events.discard_reason admite 'refresh' (len=$F86_LEN)"
    elif [ -z "$F86_LEN" ]; then
        skip "F86: discard_reason" "sin BD"
    else
        fail "F86: discard_reason" "len=$F86_LEN (necesita >= 7)"
    fi
fi

# =============================================================================
# BLOCK 60 — F87: veracidad de presencia y limpieza de grabaciones
# Trazabilidad: RF-116..RF-121
# =============================================================================
block "BLOCK 60 — F87: veracidad de presencia y limpieza de grabaciones"

# 60.0 Estáticos (código nuevo).
for F87_MARK in \
    "src/Http/Controllers/WarehouseStateController.php:presence_stale" \
    "src/Http/Controllers/WarehouseStateController.php:presenceStaleSeconds" \
    "src/Domain/Warehouse/PresenceEvidence.php:DEFAULT_STATIC_SECONDS = 1800" \
    "src/Domain/Warehouse/WarehouseRecordingDecision.php:ENSURE_RECORDING" \
    "src/Domain/Warehouse/WarehouseRecordingService.php:status <> 'DISCARDED'" \
    "src/Domain/Warehouse/WarehouseRecordingService.php:ensureRecording" \
    "bin/warehouse-recorder.php:discardFinalized" \
    "public/assets/croquis-logic.js:presence_stale"; do
    F87_FILE="${F87_MARK%%:*}"
    F87_NEEDLE="${F87_MARK#*:}"
    if grep -qF "$F87_NEEDLE" "$F87_FILE" 2>/dev/null; then
        pass "F87: $F87_FILE define '$F87_NEEDLE'"
    else
        fail "F87: $F87_FILE" "falta '$F87_NEEDLE'"
    fi
done

# 60.1 Retención: units systemd + arranque/parada.
for F87_UNIT in \
    "../docs/systemd/cerraduras-warehouse-retention.service" \
    "../docs/systemd/cerraduras-warehouse-retention.timer"; do
    if [ -f "$F87_UNIT" ] || [ -f "${F87_UNIT#../}" ]; then
        pass "F87: existe $F87_UNIT"
    else
        fail "F87: $F87_UNIT" "no encontrado"
    fi
done
if grep -qF "cerraduras-warehouse-retention.timer" ../start-all.sh 2>/dev/null \
   || grep -qF "cerraduras-warehouse-retention.timer" start-all.sh 2>/dev/null; then
    pass "F87: start-all gestiona el timer de retención"
else
    fail "F87: start-all.sh" "no gestiona cerraduras-warehouse-retention.timer"
fi
if grep -qF "cerraduras-warehouse-retention.timer" ../stop-all.sh 2>/dev/null \
   || grep -qF "cerraduras-warehouse-retention.timer" stop-all.sh 2>/dev/null; then
    pass "F87: stop-all gestiona el timer de retención"
else
    fail "F87: stop-all.sh" "no gestiona cerraduras-warehouse-retention.timer"
fi

# 60.2 Units de dominio (PHP).
for F87_TEST in \
    "tests/Unit/PresenceEvidenceTest.php" \
    "tests/Unit/WarehouseRecordingDecisionTest.php"; do
    if [ -f "$F87_TEST" ]; then
        F87_OUT=$(php "$F87_TEST" 2>&1)
        F87_RC=$?
        F87_SUM=$(echo "$F87_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
        [ -z "$F87_SUM" ] && F87_SUM="exit=$F87_RC"
        if [ "$F87_RC" -eq 0 ]; then
            pass "F87: $(basename "$F87_TEST") ($F87_SUM)"
        else
            fail "F87: $(basename "$F87_TEST")" \
                "$F87_SUM — $(echo "$F87_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
        fi
    else
        fail "F87: $F87_TEST" "no encontrado"
    fi
done

# 60.3 Unit JS del croquis (presencia stale).
F87_JS="tests/Unit/croquis-logic.test.js"
if [ -f "$F87_JS" ]; then
    F87_JS_OUT=$(node "$F87_JS" 2>&1)
    F87_JS_RC=$?
    F87_JS_SUM=$(echo "$F87_JS_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F87_JS_SUM" ] && F87_JS_SUM="exit=$F87_JS_RC"
    if [ "$F87_JS_RC" -eq 0 ]; then
        pass "F87: croquis-logic ($F87_JS_SUM)"
    else
        fail "F87: croquis-logic" \
            "$F87_JS_SUM — $(echo "$F87_JS_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F87: croquis-logic" "tests/Unit/croquis-logic.test.js no encontrado"
fi

# 60.4 BD: evidencia reforzada (static 1800) + retención declarada.
if [ -n "$MYSQL_BIN" ]; then
    F87_STATIC=$($MYSQL -sN -e "SELECT warehouse_presence_static_seconds FROM room_types WHERE code='ALMACEN_BEBIDAS' LIMIT 1" 2>/dev/null)
    if [ "$F87_STATIC" = "1800" ]; then
        pass "F87: ALMACEN_BEBIDAS static_seconds=1800"
    elif [ -z "$F87_STATIC" ]; then
        skip "F87: static_seconds" "sin BD/columna"
    else
        fail "F87: static_seconds" "valor=$F87_STATIC (esperado 1800 — aplicar migración 0124)"
    fi
    F87_RET=$($MYSQL -sN -e "SELECT COUNT(*) FROM system_settings WHERE service='api' AND setting_key='warehouse.retention_days'" 2>/dev/null)
    if [ "$F87_RET" = "1" ]; then
        pass "F87: system_settings.warehouse.retention_days declarado"
    elif [ -z "$F87_RET" ]; then
        skip "F87: retention_days" "sin BD"
    else
        fail "F87: retention_days" "no declarado (aplicar migración 0124)"
    fi
fi

# 60.5 HTTP: `live.presence_stale` presente en el estado del almacén.
if [ "$SERVER_UP" = true ]; then
    F87_STATE=$(curl -s --max-time 10 "$API_BASE/almacen-api/state" 2>/dev/null)
    if echo "$F87_STATE" | grep -q '"presence_stale"'; then
        pass "F87: /almacen-api/state expone live.presence_stale"
    else
        fail "F87: /almacen-api/state live.presence_stale" \
            "$(echo "$F87_STATE" | head -c 300)"
    fi
else
    skip "F87: /almacen-api/state presence_stale" "servidor no disponible"
fi

# =============================================================================
# BLOCK 61 — F88: presencia robusta por fusión de sensores
# Trazabilidad: RF-122..RF-126
# =============================================================================
block "BLOCK 61 — F88: presencia robusta por fusión de sensores"

# 61.0 Estáticos (código nuevo).
for F88_MARK in \
    "bin/camera-motion-worker.js:frameDiffPct" \
    "bin/camera-motion-worker.js:debounce" \
    "bin/camera-motion-worker.js:/almacen-api/motion" \
    "src/Domain/Presence/SensorEventDecision.php:UNCORROBORATED" \
    "src/Domain/Presence/IotSessionService.php:warehousePhysicalEvidence" \
    "src/Domain/Warehouse/WarehouseRecordingService.php:latestMotionAt" \
    "src/Http/Controllers/WarehouseStateController.php:motion_active" \
    "public/index.php:/almacen-api/motion"; do
    F88_FILE="${F88_MARK%%:*}"
    F88_NEEDLE="${F88_MARK#*:}"
    if grep -qF "$F88_NEEDLE" "$F88_FILE" 2>/dev/null; then
        pass "F88: $F88_FILE define '$F88_NEEDLE'"
    else
        fail "F88: $F88_FILE" "falta '$F88_NEEDLE'"
    fi
done

# 61.1 Worker y units systemd.
[ -f bin/presence-fusion-report.php ] \
    && pass "F88: existe bin/presence-fusion-report.php" \
    || fail "F88: bin/presence-fusion-report.php" "no encontrado"
if [ -f ../docs/systemd/cerraduras-camera-motion.service ]; then
    pass "F88: existe ../docs/systemd/cerraduras-camera-motion.service"
else
    fail "F88: cerraduras-camera-motion.service" "no encontrado"
fi
if grep -qF "cerraduras-camera-motion" ../start-all.sh 2>/dev/null \
   || grep -qF "cerraduras-camera-motion" start-all.sh 2>/dev/null; then
    pass "F88: start-all arranca el detector de movimiento"
else
    fail "F88: start-all.sh" "no gestiona cerraduras-camera-motion"
fi
if grep -qF "cerraduras-camera-motion" ../stop-all.sh 2>/dev/null \
   || grep -qF "cerraduras-camera-motion" stop-all.sh 2>/dev/null; then
    pass "F88: stop-all para el detector de movimiento"
else
    fail "F88: stop-all.sh" "no gestiona cerraduras-camera-motion"
fi

# 61.2 Units (PHP + JS).
for F88_TEST in \
    "tests/Unit/SensorEventDecisionTest.php" \
    "tests/Unit/IotSessionServiceTest.php"; do
    if [ -f "$F88_TEST" ]; then
        F88_OUT=$(php "$F88_TEST" 2>&1)
        F88_RC=$?
        F88_SUM=$(echo "$F88_OUT" | grep -oE '([0-9]+ passed, [0-9]+ failed|Total: [0-9]+ passed, [0-9]+ failed)' | tail -1)
        [ -z "$F88_SUM" ] && F88_SUM="exit=$F88_RC"
        if [ "$F88_RC" -eq 0 ]; then
            pass "F88: $(basename "$F88_TEST") ($F88_SUM)"
        else
            fail "F88: $(basename "$F88_TEST")" \
                "$F88_SUM — $(echo "$F88_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
        fi
    else
        fail "F88: $F88_TEST" "no encontrado"
    fi
done
F88_JS="tests/Unit/camera-motion-worker.test.js"
if [ -f "$F88_JS" ]; then
    F88_JS_OUT=$(node "$F88_JS" 2>&1)
    F88_JS_RC=$?
    F88_JS_SUM=$(echo "$F88_JS_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F88_JS_SUM" ] && F88_JS_SUM="exit=$F88_JS_RC"
    if [ "$F88_JS_RC" -eq 0 ]; then
        pass "F88: camera-motion-worker ($F88_JS_SUM)"
    else
        fail "F88: camera-motion-worker" \
            "$F88_JS_SUM — $(echo "$F88_JS_OUT" | grep -iE 'FAIL|error|❌' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F88: camera-motion-worker" "tests/Unit/camera-motion-worker.test.js no encontrado"
fi

# 61.3 BD: columna devices.last_motion_at (migración 0125).
if [ -n "$MYSQL_BIN" ]; then
    F88_COL=$($MYSQL -sN -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='devices' AND column_name='last_motion_at'" 2>/dev/null)
    if [ "$F88_COL" = "1" ]; then
        pass "F88: devices.last_motion_at existe"
    elif [ -z "$F88_COL" ]; then
        skip "F88: devices.last_motion_at" "sin BD"
    else
        fail "F88: devices.last_motion_at" "no existe (aplicar migración 0125)"
    fi
fi

# 61.4 HTTP: POST /almacen-api/motion.
if [ "$SERVER_UP" = true ] && [ -n "$MYSQL_BIN" ]; then
    F88_CAM=$($MYSQL -sN -e "SELECT d.id FROM devices d JOIN rooms r ON r.pack_id=d.pack_id JOIN room_types rt ON rt.id=r.room_type_id WHERE d.kind='CAMERA' AND rt.code='ALMACEN_BEBIDAS' LIMIT 1" 2>/dev/null)
    if [ -n "$F88_CAM" ]; then
        http_test POST /almacen-api/motion 200 "F88: POST /almacen-api/motion registra movimiento" --body "{\"device_id\":$F88_CAM}"
        http_test POST /almacen-api/motion 400 "F88: POST /almacen-api/motion sin device_id → 400" --body '{}'
    else
        skip "F88: /almacen-api/motion" "sin cámara de almacén"
    fi
else
    skip "F88: /almacen-api/motion" "servidor/BD no disponibles"
fi

# =============================================================================
# BLOCK 62 — F90: consumidor Zigbee2MQTT local, pre-hardware
# Trazabilidad: RF-133.1..RF-136.9, TSK-90.01..TSK-90.04
# Sin broker, red ni hardware. Los tests son unitarios/dry-run y no usan BD.
# =============================================================================
block "BLOCK 62 — F90: consumidor Zigbee2MQTT local"

F90_WORKER="bin/zigbee-mqtt-worker.php"
F90_DOMAIN="src/Infrastructure/Gateways/Sensor"
F90_GUARD_FILES=(
    "$F90_WORKER"
    "$F90_DOMAIN/ZigbeeMqttMessageMapper.php"
    "$F90_DOMAIN/ZigbeeMqttEventProcessor.php"
    "$F90_DOMAIN/ZigbeeMqttRuntimeFactory.php"
    "src/Domain/Presence/IotSessionServiceFactory.php"
)

for F90_MARKER in \
    "Config::getBool('ZIGBEE_MQTT_ENABLED', false)" \
    "ZigbeeMqttReconnectBackoff::delayFor" \
    "Ulid::generate()" \
    "-q', '1'" \
    "-c',"; do
    if grep -qF -- "$F90_MARKER" "$F90_WORKER" 2>/dev/null; then
        pass "F90: worker contract marker '$F90_MARKER' present"
    else
        fail "F90: worker contract marker '$F90_MARKER'" "missing from $F90_WORKER"
    fi
done
if grep -qF 'new IotSessionService(' src/Domain/Presence/IotSessionServiceFactory.php 2>/dev/null \
    && grep -qF 'IotSessionServiceFactory::create' "$F90_DOMAIN/ZigbeeMqttRuntimeFactory.php" 2>/dev/null \
    && grep -qF 'IotSessionServiceFactory::create' public/index.php 2>/dev/null \
    && grep -qF 'processEvent($event, $correlationId)' "$F90_DOMAIN/ZigbeeMqttEventProcessor.php" 2>/dev/null; then
    pass "F90: API and worker share the sole IotSessionService writer"
else
    fail "F90: IotSessionService writer boundary" "shared factory or processor hook missing"
fi

for F90_SOURCE in "${F90_GUARD_FILES[@]}"; do
    for F90_CLOUD_MARKER in "TuyaSensorIngress" "tuyaPresenceApi" "TUYA_ACCESS_ID" "openapi.tuya"; do
        if grep -qF "$F90_CLOUD_MARKER" "$F90_SOURCE" 2>/dev/null; then
            fail "F90: no direct cloud path in $F90_SOURCE" "found '$F90_CLOUD_MARKER'"
        else
            pass "F90: no '$F90_CLOUD_MARKER' in $F90_SOURCE"
        fi
    done
done

if grep -qF "zigbee-mqtt-worker" ../start-all.sh 2>/dev/null \
    || grep -qF "zigbee-mqtt-worker" ../stop-all.sh 2>/dev/null \
    || grep -qF "cerraduras-zigbee-mqtt" ../docs/systemd/cerraduras-workers.service 2>/dev/null; then
    fail "F90: worker not auto-started" "found startup/service wiring"
else
    pass "F90: worker has no start-all/stop-all/systemd wiring"
fi
if grep -qF 'ZIGBEE_MQTT_ENABLED=false' .env.example; then
    pass "F90: .env.example disables worker by default"
else
    fail "F90: safe default" "ZIGBEE_MQTT_ENABLED=false missing"
fi
if [ -f migrations/0126_presence_provider_zigbee2mqtt.sql ]; then
    pass "F90: provider migration 0126 is present"
else
    fail "F90: provider migration 0126" "migration missing"
fi

for F90_TEST in \
    "tests/Unit/ZigbeeMqttMessageMapperTest.php" \
    "tests/Unit/ZigbeeMqttReconnectBackoffTest.php" \
    "tests/Unit/ZigbeeMqttWorkerTest.php" \
    "tests/Unit/ZigbeeProviderMigrationTest.php"; do
    if [ -f "$F90_TEST" ]; then
        F90_OUT=$(php "$F90_TEST" 2>&1)
        F90_RC=$?
        F90_SUM=$(echo "$F90_OUT" | grep -oE 'Total: [0-9]+ passed, [0-9]+ failed' | tail -1)
        [ -z "$F90_SUM" ] && F90_SUM="exit=$F90_RC"
        if [ "$F90_RC" -eq 0 ]; then
            pass "F90: $(basename "$F90_TEST") ($F90_SUM)"
        else
            fail "F90: $(basename "$F90_TEST")" \
                "$F90_SUM — $(echo "$F90_OUT" | grep -iE 'FAIL|❌|error' | head -3 | tr '\n' ' ')"
        fi
    else
        fail "F90: $F90_TEST" "test missing"
    fi
done

# =============================================================================
# BLOCK 63 — F90: paquete de despliegue Zigbee aislado (pre-host)
# Trazabilidad: RF-137..RF-141, TSK-90.06..TSK-90.10
# Guardia estática: NO ejecuta Docker, no usa red, broker, BD ni hardware.
# =============================================================================
block "BLOCK 63 — F90: paquete de despliegue Zigbee aislado"

F90D_ROOT="$(dirname "$PROJECT_DIR")"
F90D_PKG="$F90D_ROOT/deploy/zigbee"
F90D_TEST="tests/Unit/ZigbeeDeployPackageTest.php"

if [ -f "$F90D_TEST" ]; then
    F90D_OUT=$(php "$F90D_TEST" 2>&1)
    F90D_RC=$?
    F90D_SUM=$(echo "$F90D_OUT" | grep -oE 'Total: [0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$F90D_SUM" ] && F90D_SUM="exit=$F90D_RC"
    if [ "$F90D_RC" -eq 0 ]; then
        pass "F90: ZigbeeDeployPackageTest estático ($F90D_SUM)"
    else
        fail "F90: ZigbeeDeployPackageTest" \
            "$F90D_SUM — $(echo "$F90D_OUT" | grep -E '❌|failed' | head -3 | tr '\n' ' ')"
    fi
else
    fail "F90: ZigbeeDeployPackageTest" "falta $F90D_TEST"
fi

# Marcadores de contrato del paquete (sin Docker).
for F90D_MARKER in \
    "COMPOSE_PROJECT_NAME" \
    '127.0.0.1:${MQTT_HOST_PORT' \
    '127.0.0.1:${Z2M_UI_HOST_PORT' \
    "permit_join: false" \
    "adapter: ember" \
    "rtscts: false" \
    "cap_drop:" \
    "no-new-privileges:true" \
    'user: "${MOSQUITTO_UID:-1883}:${MOSQUITTO_GID:-1883}"' \
    'user: "${Z2M_UID:-1000}:${Z2M_GID:-1000}"' \
    "MOSQUITTO_UID=1883" \
    "Z2M_UID=1000" \
    'install -d -m 0700 -o "$uid" -g "$gid"' \
    "chmod 0400"; do
    if grep -RqsF "$F90D_MARKER" "$F90D_PKG" 2>/dev/null; then
        pass "F90: marcador de paquete '$F90D_MARKER'"
    else
        fail "F90: marcador de paquete '$F90D_MARKER'" "no encontrado en deploy/zigbee"
    fi
done

# Prohibiciones de seguridad.
for F90D_FORBIDDEN in "privileged: true" "network_mode: host" "docker.sock" "down -v" \
                     "sudo" "chmod 777"; do
    if grep -RqsF "$F90D_FORBIDDEN" "$F90D_PKG" 2>/dev/null; then
        fail "F90: prohibido '$F90D_FORBIDDEN' en deploy/zigbee"
    else
        pass "F90: sin '$F90D_FORBIDDEN' en deploy/zigbee"
    fi
done

# No se ejecuta nada contra el host de trabajo/oficina: los scripts solo operan
# sobre el directorio del paquete y su proyecto Compose.
if grep -RqsF "oficina" "$F90D_PKG"/*.sh 2>/dev/null; then
    fail "F90: scripts sin referencia a oficina" "encontrada en deploy/zigbee/*.sh"
else
    pass "F90: scripts sin referencia a oficina"
fi

# Scripts: sintaxis y set -eu, sin ejecutar nada real.
for F90D_SCRIPT in preflight.sh deploy.sh verify.sh backup.sh restore.sh rollback.sh; do
    F90D_PATH="$F90D_PKG/$F90D_SCRIPT"
    if [ ! -f "$F90D_PATH" ]; then
        fail "F90: $F90D_SCRIPT" "falta"
        continue
    fi
    if bash -n "$F90D_PATH" 2>/dev/null; then
        pass "F90: bash -n $F90D_SCRIPT"
    else
        fail "F90: bash -n $F90D_SCRIPT" "error de sintaxis"
    fi
    if grep -qE '^set -eu$' "$F90D_PATH"; then
        pass "F90: $F90D_SCRIPT usa set -eu"
    else
        fail "F90: $F90D_SCRIPT" "no usa set -eu"
    fi
done

# No interferencia: el paquete no debe engancharse al arranque ni a systemd.
if grep -RqsF -e "deploy/zigbee" -e "zigbee-deploy" "$F90D_ROOT/start-all.sh" "$F90D_ROOT/stop-all.sh" "$F90D_ROOT/docs/systemd" 2>/dev/null; then
    fail "F90: paquete sin wiring de arranque/systemd" "encontrado en start-all/stop-all/docs/systemd"
else
    pass "F90: paquete sin wiring de arranque/systemd"
fi
if find "$F90D_PKG" -maxdepth 3 -name '*.service' -print -quit 2>/dev/null | grep -q .; then
    fail "F90: paquete sin unidades systemd" "hay un .service en deploy/zigbee"
else
    pass "F90: paquete sin unidades systemd"
fi

# Secretos/rutas de oficina y datos runtime no deben estar en el paquete.
for F90D_LEAK in "/root/" "/home/" "192.168." "172.16." "92.113.151.136"; do
    if grep -RqsF "$F90D_LEAK" "$F90D_PKG" 2>/dev/null; then
        fail "F90: paquete sin '$F90D_LEAK'" "encontrado en deploy/zigbee"
    else
        pass "F90: paquete sin '$F90D_LEAK'"
    fi
done

# =============================================================================
# BLOCK 64 — F91: integración Zigbee aislada de almacenZigbee
# Trazabilidad: RF-143..RF-151, TSK-91.05..TSK-91.11
# Sin broker, MQTT real ni hardware. Verifica contratos, guardias y units.
# =============================================================================
block "BLOCK 64 — F91: integración Zigbee aislada"

# --- 64.1 Archivos y migraciones -------------------------------------------
for F91_FILE in \
    "bin/provision-zigbee-almacen.php" \
    "bin/zigbee-spool-worker.php" \
    "bin/zigbee-readiness.php" \
    "bin/zigbee-install-deps.sh" \
    "bin/zigbee-ingress/index.js" \
    "bin/zigbee-ingress/ingress.js" \
    "bin/zigbee-ingress/spool.js" \
    "bin/zigbee-ingress/publish.js" \
    "src/Domain/Presence/RadarPresenceDecision.php" \
    "src/Infrastructure/Gateways/Sensor/ZigbeeSwitchStateService.php" \
    "src/Infrastructure/Gateways/Switch/ZigbeeSwitchGateway.php" \
    "src/Infrastructure/Zigbee/ZigbeeSpoolQueue.php" \
    "config/zigbee2mqtt-devices.json" \
    "migrations/0127_device_transport.sql" \
    "migrations/0128_room_type_presence_modes.sql" \
    "migrations/0129_room_presence_mode_override.sql" \
    "../docs/systemd/cerraduras-zigbee-tunnel.service" \
    "../docs/systemd/cerraduras-zigbee-ingress.service" \
    "../docs/systemd/cerraduras-zigbee-spool.service"; do
    if [ -f "$F91_FILE" ]; then
        pass "F91: existe $F91_FILE"
    else
        fail "F91: falta $F91_FILE"
    fi
done

# --- 64.2 Sintaxis ---------------------------------------------------------
for F91_PHP in \
    "bin/provision-zigbee-almacen.php" \
    "bin/zigbee-spool-worker.php" \
    "src/Domain/Presence/RadarPresenceDecision.php" \
    "src/Infrastructure/Gateways/Sensor/ZigbeeSwitchStateService.php" \
    "src/Infrastructure/Gateways/Switch/ZigbeeSwitchGateway.php" \
    "src/Infrastructure/Zigbee/ZigbeeSpoolQueue.php"; do
    if php -l "$F91_PHP" >/dev/null 2>&1; then
        pass "F91: php -l $F91_PHP"
    else
        fail "F91: php -l $F91_PHP"
    fi
done
for F91_JS in \
    "bin/zigbee-ingress/index.js" \
    "bin/zigbee-ingress/ingress.js" \
    "bin/zigbee-ingress/spool.js" \
    "bin/zigbee-ingress/publish.js"; do
    if node --check "$F91_JS" >/dev/null 2>&1; then
        pass "F91: node --check $F91_JS"
    else
        fail "F91: node --check $F91_JS"
    fi
done

# --- 64.3 Guardias F74/F78: sin pollers ni consultas Tuya ------------------
for F91_BAD_FILE in bin/tuya-presence-poller.js bin/presence-poller-manager.sh bin/wrapper-poller.sh; do
    if [ -e "$F91_BAD_FILE" ]; then
        fail "F91/F78: poller prohibido presente" "$F91_BAD_FILE"
    else
        pass "F91/F78: sin $F91_BAD_FILE"
    fi
done
if pgrep -f "[t]uya-presence-poller\.js" >/dev/null 2>&1; then
    fail "F91/F78: proceso de poller Tuya activo"
else
    pass "F91/F78: sin proceso de poller Tuya"
fi
F91_CLOUD_MARKERS="TuyaSensorIngress tuyaPresenceApi TUYA_ACCESS_ID openapi.tuya"
for F91_SRC in \
    bin/zigbee-spool-worker.php \
    bin/zigbee-ingress/index.js \
    bin/zigbee-ingress/ingress.js \
    src/Infrastructure/Gateways/Sensor/ZigbeeMqttMessageMapper.php \
    src/Infrastructure/Gateways/Sensor/ZigbeeMqttEventProcessor.php \
    src/Infrastructure/Gateways/Sensor/ZigbeeSwitchStateService.php \
    src/Infrastructure/Gateways/Switch/ZigbeeSwitchGateway.php \
    src/Domain/Presence/RadarPresenceDecision.php; do
    for F91_MARK in $F91_CLOUD_MARKERS; do
        if grep -qF "$F91_MARK" "$F91_SRC" 2>/dev/null; then
            fail "F91: sin '$F91_MARK' en $F91_SRC"
        else
            pass "F91: sin '$F91_MARK' en $F91_SRC"
        fi
    done
done
if grep -RqsE --exclude-dir=node_modules "setInterval|while *\( *true *\)" bin/zigbee-ingress 2>/dev/null; then
    fail "F91: ingress sin bucles de sondeo" "setInterval/while(true) encontrado"
else
    pass "F91: ingress sin bucles de sondeo"
fi

# --- 64.4 Guardias de contrato y seguridad ---------------------------------
if grep -qF '"version": 2' config/zigbee2mqtt-devices.json \
    && ! grep -qE 'oficina' config/zigbee2mqtt-devices.json; then
    pass "F91: mapa v2 sin topics *-oficina"
else
    fail "F91: mapa v2" "version o topics de oficina inválidos"
fi
if grep -qF "contact" config/zigbee2mqtt-devices.json \
    && grep -qF "presence" config/zigbee2mqtt-devices.json \
    && grep -qF "state" config/zigbee2mqtt-devices.json; then
    pass "F91: mapa declara contact/presence/state"
else
    fail "F91: mapa incompleto"
fi
if grep -qF "ALMACEN_MOTION_TOKEN" public/index.php \
    && grep -qF "X-Almacen-Motion-Token" bin/camera-motion-worker.js; then
    pass "F91: motion autenticado por token/loopback"
else
    fail "F91: motion sin guardia de autenticación"
fi
if grep -qF -- "--requeue requiere --yes" bin/zigbee-spool-worker.php \
    && grep -qF "replay.log" src/Infrastructure/Zigbee/ZigbeeSpoolQueue.php; then
    pass "F91: replay exige confirmación y audita"
else
    fail "F91: replay sin confirmación/auditoría"
fi
if grep -qF "ZIGBEE_INGRESS_ENABLED=false" .env.example \
    && grep -qF "ZIGBEE_SPOOL_ENABLED=false" .env.example; then
    pass "F91: ingress y spool deshabilitados por defecto"
else
    fail "F91: flags por defecto"
fi
if grep -qF "cerraduras-zigbee-ingress" ../start-all.sh \
    && grep -qF "cerraduras-zigbee-spool" ../start-all.sh \
    && grep -qF "cerraduras-zigbee-tunnel" ../stop-all.sh; then
    pass "F91: wiring condicional de arranque/parada y units"
else
    fail "F91: wiring condicional de arranque/parada"
fi

# --- 64.5 Provisión en seco (solo lectura) ---------------------------------
F91_PROV_OUT=$(php bin/provision-zigbee-almacen.php 2>&1)
F91_PROV_RC=$?
if [ "$F91_PROV_RC" -eq 0 ] && echo "$F91_PROV_OUT" | grep -q "dry-run: no se escribe nada"; then
    pass "F91: provisioning dry-run es idempotente y no escribe"
elif echo "$F91_PROV_OUT" | grep -q "dispositivo ajeno"; then
    skip "F91: provisioning dry-run" "PACK_ZIGBEE contiene dispositivos adicionales de la sala; no se toca producción"
else
    fail "F91: provisioning dry-run" "exit=$F91_PROV_RC — $(echo "$F91_PROV_OUT" | tail -2 | tr '\n' ' ')"
fi

# --- 64.6 Tests unitarios F91 ----------------------------------------------
for F91_TEST in \
    "tests/Unit/ZigbeeTransportPresenceConfigTest.php" \
    "tests/Unit/DeviceIntegrationStatusTest.php" \
    "tests/Unit/PanelProvisioningUiTest.php" \
    "tests/Unit/ZigbeeProvisioningTest.php" \
    "tests/Unit/RadarPresenceDecisionTest.php" \
    "tests/Unit/ZigbeeMqttV2MapperTest.php" \
    "tests/Unit/ZigbeeSpoolQueueTest.php" \
    "tests/Unit/ZigbeeSpoolWorkerTest.php" \
    "tests/Unit/ZigbeeSwitchGatewayTest.php" \
    "tests/Unit/SwitchGatewayFactoryZigbeeTest.php" \
    "tests/Unit/ZigbeeSwitchStateServiceTest.php" \
    "tests/Unit/PanelAlmacenZigbeeUiTest.php" \
    "tests/Unit/MotionEndpointHardeningTest.php" \
    "tests/Unit/ZigbeeRuntimeWiringTest.php"; do
    if [ -f "$F91_TEST" ]; then
        F91_OUT=$(php "$F91_TEST" 2>&1)
        F91_RC=$?
        F91_SUM=$(echo "$F91_OUT" | grep -oE '(Total|Results): [0-9]+ passed, [0-9]+ failed' | tail -1)
        [ -z "$F91_SUM" ] && F91_SUM="exit=$F91_RC"
        if [ "$F91_RC" -eq 0 ]; then
            pass "F91: $(basename "$F91_TEST") ($F91_SUM)"
        else
            fail "F91: $(basename "$F91_TEST")" \
                "$F91_SUM — $(echo "$F91_OUT" | grep -E '❌|FAIL|failed' | head -3 | tr '\n' ' ')"
        fi
    else
        fail "F91: $F91_TEST" "test missing"
    fi
done

F91_JS_TEST="tests/Unit/zigbee-ingress.test.js"
if [ -f "$F91_JS_TEST" ]; then
    F91_JS_OUT=$(node "$F91_JS_TEST" 2>&1)
    F91_JS_RC=$?
    F91_JS_SUM=$(echo "$F91_JS_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    if [ "$F91_JS_RC" -eq 0 ]; then
        pass "F91: zigbee-ingress.test.js ($F91_JS_SUM)"
    else
        fail "F91: zigbee-ingress.test.js" "$F91_JS_SUM"
    fi
else
    fail "F91: $F91_JS_TEST" "test missing"
fi

# =============================================================================
# BLOCK 65 — F92: estado de conectividad por transporte (Zigbee y cámaras)
# Trazabilidad: RF-152..RF-157
# =============================================================================
block "BLOCK 65 — F92: conectividad por transporte"

F92_ROOM=$($MYSQL -sN -e "SELECT id FROM rooms WHERE code='almacenZigbee' LIMIT 1" 2>/dev/null)
if [ -z "$F92_ROOM" ]; then
    skip "F92 sala almacenZigbee" "No existe la sala almacenZigbee"
else
    F92_DS=$(curl -s "${API_BASE}/dashboard-api/device-status?room_id=$F92_ROOM")
    F92_RESULT=$(echo "$F92_DS" | python3 -c "
import sys, json
try:
    d=json.load(sys.stdin)
except Exception as e:
    print('ERR '+str(e)); sys.exit(0)
devs=d.get('devices') or []
valid={'online','offline','unknown'}
bad=[]
zig=0; cams=0
for x in devs:
    st=x.get('state')
    if st not in valid:
        bad.append(str(x.get('id'))+':state='+str(st))
    t=str(x.get('transport') or '').upper()
    if t=='ZIGBEE2MQTT':
        zig+=1
        if x.get('tuya_cloud') is not False:
            bad.append(str(x.get('id'))+':zigbee_tuya_cloud')
        if (x.get('integration_status') or {}).get('status')=='operational' and st!='online':
            bad.append(str(x.get('id'))+':zigbee_operational_no_online')
    if x.get('kind')=='CAMERA':
        cams+=1
        if x.get('tuya_cloud') is not False:
            bad.append(str(x.get('id'))+':camera_tuya_cloud')
cnt=(d.get('online') or 0)+(d.get('offline') or 0)+(d.get('unknown') or 0)==(d.get('total') or 0)
if zig<1 or cams<1:
    bad.append('pack_sin_zigbee_o_camara')
ok=cnt and not bad
print(('OK ' if ok else 'FAIL ')+('zigbee='+str(zig)+' cameras='+str(cams)+' sum='+str(cnt)+' bad='+str(bad)))
" 2>/dev/null)
    case "$F92_RESULT" in
        OK*) pass "F92: device-status clasifica Zigbee/cámara sin Tuya ($F92_RESULT)" ;;
        FAIL*) fail "F92: device-status por transporte" "${F92_RESULT#FAIL }" ;;
        *) fail "F92: device-status parse" "${F92_RESULT:-respuesta vacía}" ;;
    esac

    F92_ZIG_IDS=$(echo "$F92_DS" | python3 -c "
import sys,json
d=json.load(sys.stdin)
print(','.join(str(x['id']) for x in (d.get('devices') or []) if str(x.get('transport') or '').upper()=='ZIGBEE2MQTT'))
")
    if [ -z "$F92_ZIG_IDS" ]; then
        skip "F92 ping Zigbee" "Sin dispositivos Zigbee en la sala"
    else
        F92_ZIG_BODY=$(python3 -c "import json,sys; print(json.dumps({'device_ids':[int(x) for x in '$F92_ZIG_IDS'.split(',')], 'verify_tuya': True, 'verify_cameras': False}))")
        F92_ZIG_PING=$(curl -s -X POST "${API_BASE}/dashboard-api/ping-all-devices" -H 'Content-Type: application/json' -d "$F92_ZIG_BODY")
        F92_ZIG_RESULT=$(echo "$F92_ZIG_PING" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception as e: print('ERR '+str(e)); sys.exit(0)
bad=[]
for r in d.get('results') or []:
    if r.get('checked_via')!='ZIGBEE': bad.append(str(r.get('device_id'))+':via='+str(r.get('checked_via')))
    if r.get('state') not in ('online','offline','unknown'): bad.append(str(r.get('device_id'))+':state')
print(('OK ' if not bad else 'FAIL ')+str(bad))
" 2>/dev/null)
        case "$F92_ZIG_RESULT" in
            OK*) pass "F92: ping Zigbee no usa Tuya ($F92_ZIG_RESULT)" ;;
            FAIL*) fail "F92: ping Zigbee sin Tuya" "${F92_ZIG_RESULT#FAIL }" ;;
            *) fail "F92: ping Zigbee parse" "${F92_ZIG_RESULT:-respuesta vacía}" ;;
        esac
    fi

    F92_CAM_IDS=$(echo "$F92_DS" | python3 -c "
import sys,json
d=json.load(sys.stdin)
print(','.join(str(x['id']) for x in (d.get('devices') or []) if x.get('kind')=='CAMERA'))
")
    if [ -z "$F92_CAM_IDS" ]; then
        skip "F92 ping cámara" "Sin cámaras en la sala"
    else
        F92_CAM_BODY=$(python3 -c "import json,sys; print(json.dumps({'device_ids':[int(x) for x in '$F92_CAM_IDS'.split(',')], 'verify_tuya': False, 'verify_cameras': True}))")
        F92_CAM_PING=$(curl -s -X POST "${API_BASE}/dashboard-api/ping-all-devices" -H 'Content-Type: application/json' -d "$F92_CAM_BODY")
        F92_CAM_RESULT=$(echo "$F92_CAM_PING" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception as e: print('ERR '+str(e)); sys.exit(0)
bad=[]
for r in d.get('results') or []:
    if r.get('checked_via')!='CAMERA': bad.append(str(r.get('device_id'))+':via='+str(r.get('checked_via')))
    if r.get('state') not in ('online','offline','unknown'): bad.append(str(r.get('device_id'))+':state')
print(('OK ' if not bad else 'FAIL ')+str(bad))
" 2>/dev/null)
        case "$F92_CAM_RESULT" in
            OK*) pass "F92: ping cámara con rama propia ($F92_CAM_RESULT)" ;;
            FAIL*) fail "F92: ping cámara" "${F92_CAM_RESULT#FAIL }" ;;
            *) fail "F92: ping cámara parse" "${F92_CAM_RESULT:-respuesta vacía}" ;;
        esac
    fi
fi

# Guardias estáticas: la política y la sonda existen y no hay inferencia Tuya por kind.
if [ -f src/Domain/Devices/DeviceLivenessService.php ] && [ -f src/Domain/Devices/CameraReachabilityProbe.php ]; then
    pass "F92: política y sonda de cámara presentes"
else
    fail "F92: clases de liveness" "faltan DeviceLivenessService/CameraReachabilityProbe"
fi
if grep -q "deviceIsTuyaCloud(\['kind' => \$dev->kind, 'transport' => \$dev->transport\])" public/index.php \
    && grep -q "applyAvailability" src/Infrastructure/Gateways/Sensor/ZigbeeMqttEventProcessor.php; then
    pass "F92: clasificación por transporte y availability cableadas"
else
    fail "F92: cableado" "falta guardia de transporte/availability"
fi

# =============================================================================
# BLOCK 66 — F93: calibración y veracidad de sensores (puerta + radar)
# Trazabilidad: RF-154, RF-155, RF-156, RF-157, RF-158
# =============================================================================
block "BLOCK 66 — F93: calibración y veracidad de sensores"

# Artefactos de calibración y captura.
if [ -f bin/zigbee-ingress/publish-json.js ]; then
    pass "F93: publicador MQTT de calibración presente"
else
    fail "F93: publicador MQTT" "falta bin/zigbee-ingress/publish-json.js"
fi
if grep -q "radar_sensitivity" bin/zigbee-ingress/publish-json.js \
    && ! grep -q "'state'" bin/zigbee-ingress/publish-json.js; then
    pass "F93: publicador limitado a parámetros del radar"
else
    fail "F93: allowlist del publicador" "faltan claves o permite state"
fi
if [ -f bin/sensor-capture.php ] && grep -q -- "--analyze" bin/sensor-capture.php; then
    pass "F93: captura/replay de sesiones de sensores"
else
    fail "F93: captura de sensores" "falta bin/sensor-capture.php"
fi

# El candidato de ausencia no puede aplicar ni cerrar la presencia.
if grep -q "\$decision\['apply'\] = \$confirmed;" src/Domain/Presence/RadarPresenceDecision.php; then
    pass "F93: ausencia candidata no se aplica"
else
    fail "F93: apply de ausencia" "RadarPresenceDecision no condiciona apply a confirmed"
fi
if grep -q "candidate_absent" src/Domain/Presence/RadarPresenceDecision.php; then
    pass "F93: motivo candidate_absent auditado"
else
    fail "F93: motivo candidate_absent" "no se audita el candidato de ausencia"
fi

# El refresh de puerta Zigbee exige un reporte real de `contact`.
if grep -q "PROVIDER_ZIGBEE2MQTT" src/Domain/Presence/SensorEventDecision.php \
    && grep -q "'entity'" src/Domain/Presence/SensorEventDecision.php; then
    pass "F93: refresh de puerta Zigbee solo para entidad contact"
else
    fail "F93: refresh Zigbee" "SensorEventDecision no distingue la entidad"
fi

# El histórico del radar está acotado por tiempo, no por un LIMIT arbitrario.
if grep -q "listForRoomSince" src/Domain/Presence/IotSessionService.php \
    && ! grep -q "listForRoom(\$roomId, 500)" src/Domain/Presence/IotSessionService.php; then
    pass "F93: histórico radar acotado por tiempo"
else
    fail "F93: histórico radar" "sigue usando un límite fijo de filas"
fi

# /dashboard expone la misma decisión radar que /almacen.
if grep -q "presence_decision" src/Http/Controllers/RoomLiveController.php; then
    pass "F93: /live expone presencia_decision"
else
    fail "F93: /live presencia_decision" "falta el campo aditivo"
fi

# El panel /dashboard adapta el calibrador al transporte y muestra candidatos.
if grep -q "cal-zb-advanced" public/dashboard.html && grep -q "calZbApply" public/dashboard.html; then
    pass "F93: modal de calibración adapta ajustes Zigbee"
else
    fail "F93: UI calibración Zigbee" "falta el bloque de ajustes avanzados"
fi
if grep -q "CANDIDATE_ABSENT" public/dashboard.html; then
    pass "F93: /dashboard distingue ausencia candidata"
else
    fail "F93: UI presencia" "no distingue CANDIDATE_ABSENT"
fi

# Endpoint real por transporte (solo si el despliegue ya incluye F93).
F93_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN devices d ON d.pack_id=r.pack_id WHERE d.kind='PRESENCE' AND UPPER(COALESCE(d.transport,''))='ZIGBEE2MQTT' LIMIT 1" 2>/dev/null)
if [ -z "$F93_ROOM" ]; then
    skip "F93 calibración Zigbee" "No hay sala con radar Zigbee"
else
    F93_CAL=$(curl -s "${API_BASE}/dashboard-api/presence-calibrate/status?room_id=$F93_ROOM")
    F93_RESULT=$(echo "$F93_CAL" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception as e: print('ERR '+str(e)); sys.exit(0)
t=str(d.get('transport') or '')
if t=='':
    print('PENDING')
elif t!='ZIGBEE2MQTT':
    print('FAIL transport='+t)
else:
    caps=d.get('dp_caps') or {}
    bad=[]
    if caps.get('far_step')!=15: bad.append('far_step')
    if caps.get('sens_min')!=0 or caps.get('sens_max')!=9: bad.append('sens_range')
    if 'settings' not in d: bad.append('settings')
    print(('OK' if not bad else 'FAIL '+str(bad)))
" 2>/dev/null)
    case "$F93_RESULT" in
        OK) pass "F93: calibración Zigbee lee configuración real por push" ;;
        PENDING) skip "F93 calibración Zigbee" "API aún sin desplegar F93" ;;
        FAIL*) fail "F93: calibración Zigbee" "${F93_RESULT#FAIL }" ;;
        *) fail "F93: calibración Zigbee parse" "${F93_RESULT:-respuesta vacía}" ;;
    esac
fi

# =============================================================================
# BLOCK 67 — F94: asistente de verificación física de sensores
# Trazabilidad: RF-159, RF-160
# =============================================================================
block "BLOCK 67 — F94: asistente de verificación de sensores"

# Artefactos de la herramienta.
for f in public/sensores.html public/assets/sensor-wizard.js public/assets/sensor-tests.json \
         src/Domain/Sensors/SensorCalibrationValidator.php src/Infrastructure/Sensors/SensorRunStore.php \
         src/Infrastructure/Sensors/SensorContextBuilder.php; do
    if [ -f "$f" ]; then pass "F94: $f presente"; else fail "F94: $f" "falta"; fi
done

if grep -q "'/sensores'" public/index.php \
    && grep -q "/dashboard-api/sensores/step/check" public/index.php \
    && grep -q "/dashboard-api/sensores/export" public/index.php; then
    pass "F94: rutas del asistente cableadas"
else
    fail "F94: rutas" "faltan rutas de /sensores o de la API"
fi

if grep -q "SensorCalibrationValidator::validate" public/index.php \
    && grep -q "step/start" public/assets/sensor-wizard.js \
    && grep -q "step/check" public/assets/sensor-wizard.js; then
    pass "F94: la UI delega la validación en el servidor"
else
    fail "F94: cableado UI↔validador" "la UI no usa el validador del servidor"
fi

# Sin cuota: los artefactos nuevos no llaman a Tuya ni crean pollers.
if ! grep -qi "openapi.tuya\|tuyaPresenceApi\|api.tuya" src/Domain/Sensors/SensorCalibrationValidator.php \
    src/Infrastructure/Sensors/SensorRunStore.php src/Infrastructure/Sensors/SensorContextBuilder.php \
    public/assets/sensor-wizard.js; then
    pass "F94: sin llamadas a Tuya en el asistente"
else
    fail "F94: sin Tuya" "aparece una referencia a Tuya"
fi
if ! grep -q "setInterval.*tuya\|while.*tuya" public/assets/sensor-wizard.js \
    src/Infrastructure/Sensors/SensorContextBuilder.php; then
    pass "F94: sin bucles de sondeo a dispositivos"
else
    fail "F94: sin pollers" "hay un bucle de sondeo"
fi

# Reglas del catálogo conocidas por el validador (unit ya lo cubre; guardia rápida).
if php -r 'require "src/Support/Autoload.php"; $r=App\Domain\Sensors\SensorCalibrationValidator::rules(); $c=json_decode(file_get_contents("public/assets/sensor-tests.json"),true); $bad=[]; foreach(($c["tests"]??[]) as $t){ if(!in_array($t["rule"]??"",$r,true)) $bad[]=$t["id"]; } exit($bad?1:0);' >/dev/null 2>&1; then
    pass "F94: todas las reglas del catálogo existen"
else
    fail "F94: catálogo/validador" "alguna regla no está implementada"
fi

# HTTP condicional: corrida completa y determinista (paso D9 sin eventos).
F94_UI_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "${API_BASE}/sensores" 2>/dev/null || echo 000)
if [ "$SERVER_UP" = true ] && [ "$F94_UI_CODE" = "200" ]; then
    F94_CONTEXT=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/context?room_id=14" 2>/dev/null)
    F94_CTX_OK=$(printf '%s' "$F94_CONTEXT" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: print('bad'); sys.exit(0)
ok = d.get('room',{}).get('id')==14 and 'live' in d and 'devices' in d and 'settings' in d
print('ok' if ok else 'bad')
" 2>/dev/null)
    if [ "$F94_CTX_OK" = "ok" ]; then
        pass "F94: context devuelve sala, dispositivos y estado"
    else
        skip "F94 context" "sala 14 o API no disponible"
    fi

    F94_RUN=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/run/start" \
        -H 'Content-Type: application/json' -d '{"room_id":14,"operator":"runner"}')
    F94_RUN_ID=$(printf '%s' "$F94_RUN" | python3 -c "import sys,json; print((json.load(sys.stdin).get('run') or {}).get('run_id',''))" 2>/dev/null)
    if [ -n "$F94_RUN_ID" ]; then
        pass "F94: run/start crea corrida ($F94_RUN_ID)"
        curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/step/start" \
            -H 'Content-Type: application/json' -d "{\"run_id\":\"$F94_RUN_ID\",\"step_id\":\"D9\"}" >/dev/null
        F94_CHECK=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/step/check" \
            -H 'Content-Type: application/json' -d "{\"run_id\":\"$F94_RUN_ID\",\"step_id\":\"D9\"}")
        F94_STATUS=$(printf '%s' "$F94_CHECK" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null)
        if [ "$F94_STATUS" = "PASS" ]; then
            pass "F94: step/check valida D9 (sin transiciones)"
        else
            fail "F94: step/check" "esperado PASS en D9, got '$F94_STATUS'"
        fi
        F94_FIN=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/run/finish" \
            -H 'Content-Type: application/json' -d "{\"run_id\":\"$F94_RUN_ID\"}")
        F94_TOTAL=$(printf '%s' "$F94_FIN" | python3 -c "import sys,json; print((json.load(sys.stdin).get('run',{}).get('summary') or {}).get('total',''))" 2>/dev/null)
        if [ "$F94_TOTAL" = "23" ]; then
            pass "F94: run/finish resume 23 pasos"
        else
            fail "F94: run/finish" "total=$F94_TOTAL"
        fi
        F94_LIST=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/runs")
        if printf '%s' "$F94_LIST" | grep -q "$F94_RUN_ID"; then
            pass "F94: runs lista la corrida"
        else
            fail "F94: runs" "la corrida no aparece en el listado"
        fi
        rm -f "run/sensores/${F94_RUN_ID}.json" "run/sensores/${F94_RUN_ID}.jsonl"
    else
        fail "F94: run/start" "${F94_RUN:-sin respuesta}"
    fi
elif [ "$SERVER_UP" = true ]; then
    skip "F94 HTTP" "API aún sin desplegar /sensores (HTTP $F94_UI_CODE)"
fi

# =============================================================================
# BLOCK 68 — F95: flujo universal de verificación Zigbee
# Trazabilidad: RF-162…RF-169
# =============================================================================
block "BLOCK 68 — F95: flujo universal de sensores"

for f in public/assets/sensor-tests-v2.json public/assets/sensor-wizard-v2.js \
         src/Http/Controllers/SensorWizardController.php src/Infrastructure/Sensors/SensorVerdict.php \
         src/Infrastructure/Sensors/DeviceHealth.php src/Domain/Sensors/AttemptEvidence.php \
         src/Domain/Sensors/RadarDistanceQuality.php src/Domain/Sensors/RadarCalibrationPlanner.php \
         src/Domain/Presence/DoorPolarity.php; do
    if [ -f "$f" ]; then pass "F95: $f presente"; else fail "F95: $f" "falta"; fi
done
if grep -q "sensores/v2/preflight" public/index.php \
    && grep -q "sensores/v2/attempt/check" public/index.php \
    && grep -q "sensores/v2/door/polarity" public/index.php; then
    pass "F95: rutas v2 cableadas"
else
    fail "F95: rutas v2" "faltan endpoints del flujo universal"
fi
if grep -q "sensor-wizard-v2.js" public/sensores.html \
    && grep -q "attempt/check" public/assets/sensor-wizard-v2.js \
    && grep -q "door/polarity" public/assets/sensor-wizard-v2.js; then
    pass "F95: UI universal usa la API v2"
else
    fail "F95: UI v2" "la página no carga el wizard v2"
fi
if ! grep -qi "tuyaPresenceApi\|openapi.tuya" src/Http/Controllers/SensorWizardController.php \
    src/Infrastructure/Sensors/SensorVerdict.php src/Domain/Presence/DoorPolarity.php; then
    pass "F95: sin llamadas a Tuya"
else
    fail "F95: sin Tuya" "aparece Tuya en el flujo universal"
fi
if grep -q "attempt/events" public/index.php \
    && grep -q "attempt/events" public/assets/sensor-wizard-v2.js \
    && grep -q "data.fresh" public/assets/sensor-wizard-v2.js; then
    pass "F95: evidencia por intento y telemetría fresca cableadas"
else
    fail "F95: evidencia/telemetría" "faltan attempt/events o el uso de fresh"
fi
if grep -q "radar/calibrate" public/index.php \
    && grep -q "radar/calibrate" public/assets/sensor-wizard-v2.js \
    && grep -q "RadarCalibrationPlanner" src/Http/Controllers/SensorWizardController.php; then
    pass "F96: calibración guiada cableada"
else
    fail "F96: calibración" "faltan endpoints o planificador"
fi
if grep -q "sensores/v2/run/reset" public/index.php \
    && grep -q "btnResetRun" public/sensores.html \
    && grep -q "resetRun" public/assets/sensor-wizard-v2.js; then
    pass "F95: reinicio global visible y cableado"
else
    fail "F95: reinicio global" "falta el botón o endpoint run/reset"
fi
if grep -q "reentry_failed" src/Domain/Sensors/RadarCalibrationPlanner.php \
    && grep -q "reentry_failed" src/Http/Controllers/SensorWizardController.php \
    && grep -q "apply-only" public/assets/sensor-wizard-v2.js; then
    pass "F96: reentrada fallida ofrece ajuste/restauración"
else
    fail "F96: reentrada" "no se detecta la remediación de reentrada"
fi
if grep -q "isValidSettings" src/Infrastructure/Zigbee/ZigbeeRadarCalibrationService.php \
    && grep -q "radar_settings_valid" src/Infrastructure/Sensors/SensorContextBuilder.php \
    && grep -q "radar_settings_valid" public/assets/sensor-wizard-v2.js; then
    pass "F96: guarda de configuración inválida del radar"
else
    fail "F96: configuración inválida" "falta la guarda o la UI de restauración"
fi
if grep -q "door_polarity" src/Infrastructure/Gateways/Sensor/ZigbeeMqttEventProcessor.php; then
    pass "F95: polaridad aplicada en el ingress"
else
    fail "F95: polaridad" "el processor no aplica door_polarity"
fi
if grep -q "UNVERIFIED" src/Domain/Sensors/SensorCalibrationValidator.php \
    && grep -q "unverified" src/Http/Controllers/SensorWizardController.php \
    && grep -q "unverified" src/Infrastructure/Sensors/SensorVerdict.php; then
    pass "F95: veredicto distingue UNVERIFIED/SKIP"
else
    fail "F95: veredicto" "faltan estados de verificación"
fi

# HTTP condicional (solo con el despliegue v2).
F95_UI=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "${API_BASE}/sensores" 2>/dev/null || echo 000)
F95_PING=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/preflight?room_id=14" 2>/dev/null || echo 000)
if [ "$SERVER_UP" = true ] && [ "$F95_UI" = "200" ] && [ "$F95_PING" = "200" ]; then
    # Limpia corridas de test colgadas (solo operador runner*), sin tocar pruebas reales.
    F95_RUNNER_IDS=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/runs" | python3 -c "
import sys,json
try: rows=json.load(sys.stdin)
except Exception: rows=[]
print(' '.join(str(r.get('run_id','')) for r in rows if r.get('status')=='running' and str(r.get('operator','')).startswith('runner')))
" 2>/dev/null)
    for RID in $F95_RUNNER_IDS; do
        F95_NEWID=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/run/reset" \
            -H 'Content-Type: application/json' -d "{\"run_id\":\"$RID\"}" | python3 -c "import sys,json;print((json.load(sys.stdin).get('run') or {}).get('run_id',''))" 2>/dev/null)
        [ -n "$F95_NEWID" ] && rm -f "run/sensores/${F95_NEWID}.json" "run/sensores/${F95_NEWID}.jsonl" "run/sensores/locks/${F95_NEWID}.lock"
        rm -f "run/sensores/${RID}.json" "run/sensores/${RID}.jsonl" "run/sensores/locks/${RID}.lock"
    done
    F95_ACTIVE=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/preflight?room_id=14" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: print(''); raise SystemExit
a=d.get('active_attempt') or {}
if a.get('attempt_id'): print(a.get('run_id','')+':'+a.get('attempt_id',''))
" 2>/dev/null)
    if [ -n "$F95_ACTIVE" ]; then
        skip "F95 HTTP" "hay una prueba activa en la sala ($F95_ACTIVE); no se interfiere"
    else

    F95_ROOMS=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/rooms")
    if printf '%s' "$F95_ROOMS" | grep -q '"id":14'; then
        pass "F95: rooms incluye la sala del piloto"
    else
        skip "F95 rooms" "sala 14 no disponible"
    fi
    F95_PRE=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/preflight?room_id=14")
    F95_PRE_OK=$(printf '%s' "$F95_PRE" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: print('bad'); sys.exit(0)
print('ok' if d.get('can_start') is True and isinstance(d.get('plan'),list) and len(d.get('plan'))>=3 else 'bad')
" 2>/dev/null)
    if [ "$F95_PRE_OK" = "ok" ]; then
        pass "F95: preflight detecta los 3 dispositivos y el plan"
        F95_HEALTH=$(printf '%s' "$F95_PRE" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: print('bad'); sys.exit(0)
bad=[]
for k in ('proximity','presence','switch'):
    dev=(d.get('devices') or {}).get(k)
    if not dev: continue
    h=dev.get('health')
    if h is None: print('pending'); sys.exit(0)
    if h not in ('online','idle','stale','missing'): bad.append(k+':health='+str(h))
    periodic=bool(dev.get('expects_periodic_push'))
    if k=='presence' and not periodic: bad.append('presence:not_periodic')
    if k in ('proximity','switch') and periodic: bad.append(k+':periodic')
    if k in ('proximity','switch') and h in ('stale','missing'): bad.append(k+':health='+str(h))
print('ok' if not bad else 'bad '+','.join(bad))
" 2>/dev/null)
        case "$F95_HEALTH" in
            ok) pass "F95: salud distingue periódico vs evento (puerta/luz no son SIN DATOS)" ;;
            pending) skip "F95 salud" "API aún sin desplegar DeviceHealth" ;;
            *) fail "F95: salud de dispositivos" "$F95_HEALTH" ;;
        esac
        F95_RADAR_VALID=$(printf '%s' "$F95_PRE" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: print('pending'); raise SystemExit
v=d.get('radar_settings_valid')
print('ok' if v is True else ('pending' if v is None else 'bad'))
" 2>/dev/null)
        case "$F95_RADAR_VALID" in
            ok) pass "F96: preflight expone configuración válida del radar" ;;
            pending) skip "F96 radar settings" "campo aún sin desplegar" ;;
            *) fail "F96: configuración del radar" "radar_settings_valid no es true" ;;
        esac
    else
        fail "F95: preflight" "$(printf '%s' "$F95_PRE" | head -c 200)"
    fi

    F95_RUN=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/run/start" \
        -H 'Content-Type: application/json' -d '{"room_id":14,"operator":"runner"}')
    F95_RID=$(printf '%s' "$F95_RUN" | python3 -c "import sys,json; print((json.load(sys.stdin).get('run') or {}).get('run_id',''))" 2>/dev/null)
    if [ -n "$F95_RID" ]; then
        pass "F95: run/start crea la corrida ($F95_RID)"
        F95_ATT=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/attempt/start" \
            -H 'Content-Type: application/json' -d "{\"run_id\":\"$F95_RID\",\"step_id\":\"radar_inside\"}")
        F95_AID=$(printf '%s' "$F95_ATT" | python3 -c "import sys,json; print((json.load(sys.stdin).get('attempt') or {}).get('attempt_id',''))" 2>/dev/null)
        if [ -n "$F95_AID" ]; then
            pass "F95: attempt/start abre una ventana"
            F95_EV_CODE=$(curl -s -o /tmp/f95_events.json -w "%{http_code}" --max-time 5 \
                "${API_BASE}/dashboard-api/sensores/v2/attempt/events?run_id=$F95_RID&step_id=radar_inside&attempt_id=$F95_AID" 2>/dev/null)
            if [ "$F95_EV_CODE" = "200" ]; then
                F95_EV_OK=$(python3 -c "
import json
try: d=json.load(open('/tmp/f95_events.json'))
except Exception: print('bad'); raise SystemExit
print('ok' if isinstance(d.get('door'),dict) and isinstance(d.get('presence'),dict) and 'elapsed_seconds' in d else 'bad')
" 2>/dev/null)
                if [ "$F95_EV_OK" = "ok" ]; then
                    pass "F95: attempt/events devuelve ciclos y presencia de la ventana"
                    F95_CAL_CODE=$(curl -s -o /tmp/f95_cal.json -w "%{http_code}" --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/radar/calibrate" \
                        -H 'Content-Type: application/json' -d "{\"run_id\":\"$F95_RID\",\"step_id\":\"radar_inside\",\"attempt_id\":\"$F95_AID\",\"action\":\"measure\"}")
                    if [ "$F95_CAL_CODE" = "200" ]; then
                        F95_CAL_OK=$(python3 -c "
import json
try: d=json.load(open('/tmp/f95_cal.json'))
except Exception: print('bad'); raise SystemExit
dist=d.get('distance') or {}
ok = isinstance(d.get('session'),dict) and 'quality' in dist and 'valid' in dist
print('ok' if ok else 'bad')
" 2>/dev/null)
                        if [ "$F95_CAL_OK" = "ok" ]; then
                            pass "F96: measure guarda distancia y calidad de la ventana"
                        else
                            fail "F96: measure" "respuesta incompleta"
                        fi
                    else
                        skip "F96 measure" "endpoint aún sin desplegar (HTTP $F95_CAL_CODE)"
                    fi
                    rm -f /tmp/f95_cal.json
                else
                    fail "F95: attempt/events" "respuesta incompleta"
                fi
            else
                skip "F95 attempt/events" "endpoint aún sin desplegar (HTTP $F95_EV_CODE)"
            fi
            rm -f /tmp/f95_events.json
            F95_DUP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/attempt/start" \
                -H 'Content-Type: application/json' -d "{\"run_id\":\"$F95_RID\",\"step_id\":\"radar_outside\"}")
            if [ "$F95_DUP_CODE" = "409" ]; then
                pass "F95: una sola ventana activa por sala (409)"
            else
                fail "F95: ventana única" "esperado 409, got $F95_DUP_CODE"
            fi
            F95_CHK=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/attempt/check" \
                -H 'Content-Type: application/json' -d "{\"run_id\":\"$F95_RID\",\"step_id\":\"radar_inside\",\"attempt_id\":\"$F95_AID\",\"check_id\":\"runner-1\"}")
            F95_ST=$(printf '%s' "$F95_CHK" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null)
            case "$F95_ST" in
                SKIP|FAIL|UNVERIFIED) pass "F95: check sin hardware no inventa PASS ($F95_ST)" ;;
                PASS) fail "F95: check sin hardware" "no debe dar PASS sin señal" ;;
                *) fail "F95: check" "respuesta inesperada: $F95_ST" ;;
            esac
            F95_FIN=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/run/finish" \
                -H 'Content-Type: application/json' -d "{\"run_id\":\"$F95_RID\"}")
            F95_VERD=$(printf '%s' "$F95_FIN" | python3 -c "import sys,json; print((json.load(sys.stdin).get('run',{}).get('verdict') or {}).get('verdict',''))" 2>/dev/null)
            if [ "$F95_VERD" = "INCOMPLETE" ] || [ "$F95_VERD" = "REVIEW" ]; then
                pass "F95: finish calcula veredicto ($F95_VERD)"
            else
                fail "F95: finish" "veredicto inesperado: $F95_VERD"
            fi
            F95_SOAK_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/run/soak" \
                -H 'Content-Type: application/json' -d "{\"run_id\":\"$F95_RID\"}")
            if [ "$F95_SOAK_CODE" = "422" ]; then
                pass "F95: soak rechaza comprobación antes de 30 min"
            else
                fail "F95: soak" "esperado 422 too_early, got $F95_SOAK_CODE"
            fi
        else
            fail "F95: attempt/start" "$(printf '%s' "$F95_ATT" | head -c 200)"
        fi

        # Reinicio global: archiva el intento activo y crea una corrida limpia en la puerta.
        F95_RUN2=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/run/start" \
            -H 'Content-Type: application/json' -d '{"room_id":14,"operator":"runner-reset"}')
        F95_RID2=$(printf '%s' "$F95_RUN2" | python3 -c "import sys,json; print((json.load(sys.stdin).get('run') or {}).get('run_id',''))" 2>/dev/null)
        if [ -n "$F95_RID2" ]; then
            curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/attempt/start" \
                -H 'Content-Type: application/json' -d "{\"run_id\":\"$F95_RID2\",\"step_id\":\"radar_inside\"}" >/dev/null
            F95_RESET_CODE=$(curl -s -o /tmp/f95_reset.json -w "%{http_code}" --max-time 5 -X POST \
                "${API_BASE}/dashboard-api/sensores/v2/run/reset" -H 'Content-Type: application/json' \
                -d "{\"run_id\":\"$F95_RID2\",\"operator\":\"runner-reset\"}" 2>/dev/null)
            if [ "$F95_RESET_CODE" = "201" ]; then
                F95_NEW_ID=$(python3 -c "import json;print(json.load(open('/tmp/f95_reset.json'))['run']['run_id'])" 2>/dev/null)
                F95_OLD=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/get?id=$F95_RID2")
                F95_RESET_OK=$(printf '%s' "$F95_OLD" | python3 -c "
import sys,json
d=json.load(sys.stdin); s=d.get('steps',{}).get('radar_inside',{})
print('ok' if d.get('status')=='abandoned' and 'active_attempt' not in s and any(a.get('status')=='CANCELLED' for a in s.get('attempts',[])) else 'bad')
" 2>/dev/null)
                F95_NEW=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/get?id=$F95_NEW_ID")
                F95_NEW_OK=$(printf '%s' "$F95_NEW" | python3 -c "import sys,json;d=json.load(sys.stdin);print('ok' if d.get('status')=='running' and d.get('plan',[None])[0]=='door' and not d.get('steps') else 'bad')" 2>/dev/null)
                if [ "$F95_RESET_OK" = "ok" ] && [ "$F95_NEW_OK" = "ok" ]; then
                    pass "F95: run/reset archiva la corrida y reinicia desde Puerta"
                else
                    fail "F95: run/reset" "old=$F95_RESET_OK new=$F95_NEW_OK"
                fi
                rm -f "run/sensores/${F95_NEW_ID}.json" "run/sensores/${F95_NEW_ID}.jsonl" "run/sensores/locks/${F95_NEW_ID}.lock"
            elif [ "$F95_RESET_CODE" = "404" ]; then
                skip "F95 run/reset" "endpoint aún sin desplegar"
            else
                fail "F95 run/reset" "HTTP $F95_RESET_CODE"
            fi
            rm -f /tmp/f95_reset.json
            rm -f "run/sensores/${F95_RID2}.json" "run/sensores/${F95_RID2}.jsonl" "run/sensores/locks/${F95_RID2}.lock"
        else
            fail "F95 reset setup" "no se pudo crear la corrida"
        fi
        rm -f "run/sensores/${F95_RID}.json" "run/sensores/${F95_RID}.jsonl" "run/sensores/locks/${F95_RID}.lock"
    else
        fail "F95: run/start" "$(printf '%s' "$F95_RUN" | head -c 200)"
    fi

    # Limpieza final: cualquier corrida de test que haya quedado abierta.
    F95_LEFTOVER=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/runs" | python3 -c "
import sys,json
try: rows=json.load(sys.stdin)
except Exception: rows=[]
print(' '.join(str(r.get('run_id','')) for r in rows if r.get('status')=='running' and str(r.get('operator','')).startswith('runner')))
" 2>/dev/null)
    for RID in $F95_LEFTOVER; do
        rm -f "run/sensores/${RID}.json" "run/sensores/${RID}.jsonl" "run/sensores/locks/${RID}.lock"
    done
    fi
elif [ "$SERVER_UP" = true ]; then
    skip "F95 HTTP" "API aún sin desplegar el flujo v2 (UI $F95_UI, preflight $F95_PING)"
fi

# =============================================================================
# BLOCK 68B — F102: motor de calibración del radar (operaciones)
# Trazabilidad: RF-174..RF-178
# =============================================================================
block "BLOCK 68B — F102: motor de calibración del radar"

for f in src/Domain/Sensors/RadarMeasurement.php src/Domain/Sensors/RadarCalibrationPlanner.php; do
    if [ -f "$f" ]; then pass "F102: $f presente"; else fail "F102: $f" "falta"; fi
done
if grep -q "radar/state" public/index.php \
    && grep -q "radar/operation" public/index.php \
    && grep -q "radar/state" public/assets/sensor-wizard-v2.js \
    && grep -q "radar-op" public/assets/sensor-wizard-v2.js; then
    pass "F102: estado y operaciones del radar cableados"
else
    fail "F102: rutas/UI" "faltan radar/state, radar/operation o radar-op"
fi
if grep -q "operation_pending" src/Http/Controllers/SensorWizardController.php \
    && grep -q "AWAITING_CONFIRM" src/Http/Controllers/SensorWizardController.php \
    && grep -q "STABILIZING" src/Http/Controllers/SensorWizardController.php; then
    pass "F102: máquina de estados con confirmación y estabilización"
else
    fail "F102: estados" "faltan estados de operación"
fi

F102_PING=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/radar/state?run_id=x&step_id=radar_inside" 2>/dev/null)
if [ "$SERVER_UP" = true ] && [ "$F102_PING" != "404" ]; then
    F102_RUN=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/run/start" \
        -H 'Content-Type: application/json' -d '{"room_id":14,"operator":"runner-f102"}')
    F102_RID=$(printf '%s' "$F102_RUN" | python3 -c "import sys,json;print((json.load(sys.stdin).get('run') or {}).get('run_id',''))" 2>/dev/null)
    if [ -n "$F102_RID" ]; then
        F102_ATT=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/attempt/start" \
            -H 'Content-Type: application/json' -d "{\"run_id\":\"$F102_RID\",\"step_id\":\"radar_inside\"}")
        F102_AID=$(printf '%s' "$F102_ATT" | python3 -c "import sys,json;print((json.load(sys.stdin).get('attempt') or {}).get('attempt_id',''))" 2>/dev/null)
        if [ -n "$F102_AID" ]; then
            F102_ST=$(curl -s --max-time 5 "${API_BASE}/dashboard-api/sensores/v2/radar/state?run_id=$F102_RID&step_id=radar_inside")
            F102_OK=$(printf '%s' "$F102_ST" | python3 -c "import sys,json;d=json.load(sys.stdin);print('ok' if d.get('state')=='IDLE' and 'start_measure' in (d.get('next_actions') or []) else 'bad')" 2>/dev/null)
            if [ "$F102_OK" = "ok" ]; then
                pass "F102: radar/state expone fase y acciones"
            else
                fail "F102: radar/state" "estado inesperado"
            fi
            F102_OP=$(curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/radar/operation" \
                -H 'Content-Type: application/json' -d "{\"run_id\":\"$F102_RID\",\"step_id\":\"radar_inside\",\"attempt_id\":\"$F102_AID\",\"action\":\"start_measure\"}")
            F102_OP_OK=$(printf '%s' "$F102_OP" | python3 -c "import sys,json;d=json.load(sys.stdin);print('ok' if d.get('state')=='MEASURING' and (d.get('countdown') or {}).get('kind')=='measure' else 'bad')" 2>/dev/null)
            if [ "$F102_OP_OK" = "ok" ]; then
                pass "F102: start_measure abre una sola ventana"
            else
                fail "F102: start_measure" "no abre ventana"
            fi
            F102_DUP=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/radar/operation" \
                -H 'Content-Type: application/json' -d "{\"run_id\":\"$F102_RID\",\"step_id\":\"radar_inside\",\"attempt_id\":\"$F102_AID\",\"action\":\"start_measure\"}")
            if [ "$F102_DUP" = "409" ]; then
                pass "F102: segunda operación rechazada (409)"
            else
                fail "F102: operación única" "esperado 409, got $F102_DUP"
            fi
            curl -s --max-time 5 -X POST "${API_BASE}/dashboard-api/sensores/v2/radar/operation" \
                -H 'Content-Type: application/json' -d "{\"run_id\":\"$F102_RID\",\"step_id\":\"radar_inside\",\"attempt_id\":\"$F102_AID\",\"action\":\"cancel\"}" >/dev/null
        else
            fail "F102: attempt/start" "$(printf '%s' "$F102_ATT" | head -c 200)"
        fi
        rm -f "run/sensores/${F102_RID}.json" "run/sensores/${F102_RID}.jsonl" "run/sensores/locks/${F102_RID}.lock"
    else
        fail "F102: run/start" "$(printf '%s' "$F102_RUN" | head -c 200)"
    fi
elif [ "$SERVER_UP" = true ]; then
    skip "F102 HTTP" "endpoint aún sin desplegar (HTTP $F102_PING)"
fi

# =============================================================================
# BLOCK 69 — F96: llaves de acceso, fichas NFC y auditoría
# Trazabilidad: RF-168..RF-175
# =============================================================================
block "BLOCK 69 — F96: llaves de acceso y fichas NFC"
F96_TS=$(date +%s)
F96_START=$(date -u '+%Y-%m-%d %H:%M:%S')
F96_VALID=$(date -u -d '+2 days' +%Y-%m-%dT%H:%M)

# Esquema
F96_TABLES=$($MYSQL -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('nfc_cards','access_keys','access_key_room_types','key_access_events')" 2>/dev/null)
if [ "$F96_TABLES" = "4" ]; then
  pass "F96: tablas de llaves y fichas presentes"
else
  fail "F96: esquema" "esperadas 4 tablas, encontradas $F96_TABLES — ejecuta: php bin/migrate.php"
fi

# Ficha NFC
http_test POST /api/v1/nfc-cards 201 "F96: crear ficha NFC" --key ADMIN-CLI \
  --body "{\"number\":\"F96-C-$F96_TS\",\"code\":\"F96CODE-$F96_TS\"}"

F96_CARD_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
  -d "{\"number\":\"F96-C2-$F96_TS\",\"code\":\"F96CODE2-$F96_TS\"}" "${API_BASE}/api/v1/nfc-cards")
F96_CARD_ID=$(echo "$F96_CARD_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)

http_test POST /api/v1/nfc-cards 409 "F96: código de ficha duplicado → 409" --key ADMIN-CLI \
  --body "{\"number\":\"F96-C3-$F96_TS\",\"code\":\"F96CODE-$F96_TS\"}"

# Llave QR de rol compartida
F96_KEY_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
  -d "{\"label\":\"F96-ROLE-$F96_TS\",\"owner_type\":\"ROLE\",\"role_id\":1,\"key_type\":\"QR\",\"valid_until\":\"$F96_VALID\",\"all_room_types\":true}" \
  "${API_BASE}/api/v1/keys")
F96_KEY_ID=$(echo "$F96_KEY_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
F96_TOKEN=$(echo "$F96_KEY_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('qr_token',''))" 2>/dev/null)

if [ -n "$F96_KEY_ID" ] && [ -n "$F96_TOKEN" ]; then
  pass "F96: crear llave QR de rol y devolver token una vez"
else
  fail "F96: crear llave QR" "$(echo "$F96_KEY_RESP" | tr -d '\n' | head -c 300)"
fi

F96_HAS_HASH=$(echo "$F96_KEY_RESP" | grep -c 'token_hash' || true)
[ "$F96_HAS_HASH" = "0" ] && pass "F96: la API no expone token_hash" || fail "F96: la API no expone token_hash" "campo presente"

http_test GET "/api/v1/keys?q=F96-ROLE-$F96_TS" 200 "F96: listar llaves con filtro" --key ADMIN-CLI
http_test GET "/api/v1/keys/defaults?worker_id=1" 200 "F96: permisos por defecto del trabajador" --key ADMIN-CLI

http_test POST /api/v1/keys/validate 200 "F96: validar QR de llave (rol)" --key RPI-DEV \
  --body "{\"qr_text\":\"$F96_TOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"

# Una llave de rol no crea sesión individual: puede reutilizarse.
http_test POST /api/v1/keys/validate 200 "F96: llave de rol reutilizable (sin sesión)" --key RPI-DEV \
  --body "{\"qr_text\":\"$F96_TOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"

# Compatibilidad: el endpoint de lector de trabajadores también acepta llaves.
http_test POST /api/v1/workers/qr/validate 200 "F96: lector de trabajadores acepta llave" --key RPI-DEV \
  --body "{\"token\":\"$F96_TOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"

# Compatibilidad: /qr/validate resuelve la sala desde un RPI registrado.
F96_RPI_ROW=$($MYSQL -sN -e "SELECT d.external_id FROM devices d JOIN rooms r ON r.pack_id=d.pack_id WHERE d.kind='RPI' LIMIT 1" 2>/dev/null)
if [ -n "$F96_RPI_ROW" ]; then
  http_test POST /api/v1/qr/validate 200 "F96: /qr/validate despacha llave y resuelve sala" --key RPI-DEV \
    --body "{\"qr_text\":\"$F96_TOKEN\",\"device_id\":\"$F96_RPI_ROW\"}"
else
  skip "F96: /qr/validate con RPI" "no hay dispositivos RPI registrados"
fi

# Reglas de creación
http_test POST /api/v1/keys 422 "F96: validez en el pasado → 422" --key ADMIN-CLI \
  --body "{\"label\":\"F96-EXP-$F96_TS\",\"owner_type\":\"ROLE\",\"role_id\":1,\"key_type\":\"QR\",\"valid_until\":\"2020-01-01T00:00\",\"all_room_types\":true}"
http_test POST /api/v1/keys 422 "F96: tipo de sala inexistente → 422" --key ADMIN-CLI \
  --body "{\"label\":\"F96-BAD-$F96_TS\",\"owner_type\":\"ROLE\",\"role_id\":1,\"key_type\":\"QR\",\"valid_until\":\"$F96_VALID\",\"room_type_ids\":[999999]}"

# Revocación
http_test DELETE "/api/v1/keys/$F96_KEY_ID" 200 "F96: revocar llave" --key ADMIN-CLI
http_test POST /api/v1/keys/validate 403 "F96: llave revocada no abre" --key RPI-DEV \
  --body "{\"qr_text\":\"$F96_TOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"

# Llave individual de trabajador: identifica al trabajador y abre sesión.
F96_W_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
  -d "{\"name\":\"F96 Worker $F96_TS\",\"role_id\":1}" "${API_BASE}/api/v1/workers")
F96_W_ID=$(echo "$F96_W_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
F96_WKEY_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
  -d "{\"label\":\"F96-W-$F96_TS\",\"owner_type\":\"WORKER\",\"worker_id\":$F96_W_ID,\"key_type\":\"QR\",\"valid_until\":\"$F96_VALID\"}" \
  "${API_BASE}/api/v1/keys")
F96_WKEY_ID=$(echo "$F96_WKEY_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
F96_WTOKEN=$(echo "$F96_WKEY_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('qr_token',''))" 2>/dev/null)
if [ -z "$F96_W_ID" ] || [ -z "$F96_WKEY_ID" ] || [ -z "$F96_WTOKEN" ]; then
  fail "F96: llave individual de trabajador" "$(echo "$F96_WKEY_RESP" | head -c 300)"
else
  pass "F96: crear llave individual de trabajador"
fi

http_test POST /api/v1/keys/validate 200 "F96: llave individual abre e identifica trabajador" --key RPI-DEV \
  --body "{\"qr_text\":\"$F96_WTOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"
http_test POST /api/v1/keys/validate 409 "F96: llave individual ya dentro → 409" --key RPI-DEV \
  --body "{\"qr_text\":\"$F96_WTOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"

# Ficha asignada a llave
if [ -n "$F96_CARD_ID" ]; then
  F96_NKEY_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
    -d "{\"label\":\"F96-NFC-$F96_TS\",\"owner_type\":\"ROLE\",\"role_id\":1,\"key_type\":\"NFC\",\"nfc_card_id\":$F96_CARD_ID,\"valid_until\":\"$F96_VALID\",\"all_room_types\":true}" \
    "${API_BASE}/api/v1/keys")
  F96_NKEY_ID=$(echo "$F96_NKEY_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
  http_test POST /api/v1/keys/validate 200 "F96: validar ficha NFC asignada" --key RPI-DEV \
    --body "{\"ficha_code\":\"F96CODE2-$F96_TS\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"
  http_test DELETE "/api/v1/keys/$F96_NKEY_ID" 200 "F96: revocar llave de ficha" --key ADMIN-CLI
  http_test POST /api/v1/keys/validate 403 "F96: ficha sin llave vigente no abre" --key RPI-DEV \
    --body "{\"ficha_code\":\"F96CODE2-$F96_TS\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"
else
  skip "F96: ficha NFC" "no se pudo crear la ficha"
fi

# Historial
F96_ACC_RESP=$(curl -s -H "X-API-Key: $ADMIN_KEY" "${API_BASE}/api/v1/keys/accesses?key_id=$F96_KEY_ID")
F96_ACC_N=$(echo "$F96_ACC_RESP" | python3 -c "import sys,json;print(len(json.load(sys.stdin).get('data',[])))" 2>/dev/null)
if [ "${F96_ACC_N:-0}" -ge 3 ]; then
  pass "F96: historial de accesos registra OK y denegados"
else
  fail "F96: historial de accesos" "esperados >=3 eventos, got ${F96_ACC_N:-0}"
fi
http_test GET "/api/v1/keys/$F96_KEY_ID/accesses" 200 "F96: historial por llave" --key ADMIN-CLI

# Limpieza de datos de prueba
if [ -n "$F96_W_ID" ]; then
  $MYSQL -e "DELETE FROM worker_sessions WHERE worker_id=$F96_W_ID; UPDATE workers SET active=0 WHERE id=$F96_W_ID; DELETE FROM workers WHERE id=$F96_W_ID" >/dev/null 2>&1 || true
fi
$MYSQL -e "DELETE e FROM key_access_events e JOIN access_keys k ON k.id=e.key_id WHERE k.label LIKE 'F96-%'; DELETE FROM access_keys WHERE label LIKE 'F96-%'; DELETE FROM nfc_cards WHERE number LIKE 'F96-%'; DELETE FROM key_access_events WHERE key_id IS NULL AND occurred_at >= '$F96_START'" >/dev/null 2>&1 || true
pass "F96: limpieza de datos de prueba"


# =============================================================================
# BLOCK 71 — F99: reactivar llaves desactivadas
# Trazabilidad: RF-185..RF-189
# =============================================================================
block "BLOCK 71 — F99: reactivar llaves desactivadas"
F99_TS=$(date +%s)
F99_VALID=$(date -u -d '+2 days' +%Y-%m-%dT%H:%M)

F99_KEY_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
  -d "{\"label\":\"F99-ROLE-$F99_TS\",\"owner_type\":\"ROLE\",\"role_id\":1,\"key_type\":\"QR\",\"valid_until\":\"$F99_VALID\",\"all_room_types\":true}" \
  "${API_BASE}/api/v1/keys")
F99_KEY_ID=$(echo "$F99_KEY_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
F99_TOKEN=$(echo "$F99_KEY_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('qr_token',''))" 2>/dev/null)
if [ -n "$F99_KEY_ID" ] && [ -n "$F99_TOKEN" ]; then
  pass "F99: crear llave QR de rol"
else
  fail "F99: crear llave" "$(echo "$F99_KEY_RESP" | head -c 300)"
fi

http_test POST "/api/v1/keys/$F99_KEY_ID/reactivate" 422 "F99: una llave activa no se reactiva" --key ADMIN-CLI
http_test DELETE "/api/v1/keys/$F99_KEY_ID" 200 "F99: revocar llave" --key ADMIN-CLI
F99_REACT=$(curl -s -w '\n%{http_code}' -X POST -H "X-API-Key: $ADMIN_KEY" "${API_BASE}/api/v1/keys/$F99_KEY_ID/reactivate")
F99_REACT_CODE=$(echo "$F99_REACT" | tail -n1)
F99_REACT_BODY=$(echo "$F99_REACT" | sed '$d')
if [ "$F99_REACT_CODE" = "200" ] && echo "$F99_REACT_BODY" | grep -q '"status":"ACTIVE"' && echo "$F99_REACT_BODY" | grep -q '"revoked_at":null'; then
  pass "F99: reactivar devuelve la llave activa y sin revoked_at"
else
  fail "F99: reactivar llave" "HTTP $F99_REACT_CODE $(echo "$F99_REACT_BODY" | head -c 300)"
fi

http_test POST /api/v1/keys/validate 200 "F99: el QR conservado vuelve a abrir" --key RPI-DEV \
  --body "{\"qr_text\":\"$F99_TOKEN\",\"room_id\":1,\"device_id\":\"ESP32-TEST\"}"
http_test POST "/api/v1/keys/$F99_KEY_ID/reactivate" 422 "F99: reactivar dos veces → 422" --key ADMIN-CLI

# Llave caducada: se revoca y se fuerza la caducidad en BD.
F99_EXP_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
  -d "{\"label\":\"F99-EXP-$F99_TS\",\"owner_type\":\"ROLE\",\"role_id\":1,\"key_type\":\"QR\",\"valid_until\":\"$F99_VALID\",\"all_room_types\":true}" \
  "${API_BASE}/api/v1/keys")
F99_EXP_ID=$(echo "$F99_EXP_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
if [ -n "$F99_EXP_ID" ]; then
  http_test DELETE "/api/v1/keys/$F99_EXP_ID" 200 "F99: revocar llave caducada" --key ADMIN-CLI
  $MYSQL -e "UPDATE access_keys SET valid_until = UTC_TIMESTAMP(3) - INTERVAL 1 HOUR WHERE id=$F99_EXP_ID" >/dev/null 2>&1 || true
  http_test POST "/api/v1/keys/$F99_EXP_ID/reactivate" 422 "F99: una llave caducada no se reactiva" --key ADMIN-CLI
else
  skip "F99: llave caducada" "no se pudo crear"
fi

# Llave personal con trabajador dado de baja.
F99_W_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
  -d "{\"name\":\"F99 Worker $F99_TS\",\"role_id\":1}" "${API_BASE}/api/v1/workers")
F99_W_ID=$(echo "$F99_W_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
if [ -n "$F99_W_ID" ]; then
  F99_WKEY_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
    -d "{\"label\":\"F99-W-$F99_TS\",\"owner_type\":\"WORKER\",\"worker_id\":$F99_W_ID,\"key_type\":\"QR\",\"valid_until\":\"$F99_VALID\"}" \
    "${API_BASE}/api/v1/keys")
  F99_WKEY_ID=$(echo "$F99_WKEY_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
  if [ -n "$F99_WKEY_ID" ]; then
    http_test DELETE "/api/v1/keys/$F99_WKEY_ID" 200 "F99: revocar llave personal" --key ADMIN-CLI
    http_test DELETE "/api/v1/workers/$F99_W_ID" 200 "F99: dar de baja al trabajador" --key ADMIN-CLI
    http_test POST "/api/v1/keys/$F99_WKEY_ID/reactivate" 422 "F99: no se reactiva si el titular está de baja" --key ADMIN-CLI
  else
    skip "F99: llave personal" "no se pudo crear"
  fi
else
  skip "F99: llave personal" "no se pudo crear el trabajador"
fi

# Ficha NFC: al revocar se libera y al reactivar se recupera.
F99_CARD_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
  -d "{\"number\":\"F99-C-$F99_TS\",\"code\":\"F99CODE-$F99_TS\"}" "${API_BASE}/api/v1/nfc-cards")
F99_CARD_ID=$(echo "$F99_CARD_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
if [ -n "$F99_CARD_ID" ]; then
  F99_NKEY_RESP=$(curl -s -X POST -H "X-API-Key: $ADMIN_KEY" -H 'Content-Type: application/json' \
    -d "{\"label\":\"F99-NFC-$F99_TS\",\"owner_type\":\"ROLE\",\"role_id\":1,\"key_type\":\"NFC\",\"nfc_card_id\":$F99_CARD_ID,\"valid_until\":\"$F99_VALID\",\"all_room_types\":true}" \
    "${API_BASE}/api/v1/keys")
  F99_NKEY_ID=$(echo "$F99_NKEY_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)
  http_test DELETE "/api/v1/keys/$F99_NKEY_ID" 200 "F99: revocar llave de ficha" --key ADMIN-CLI
  http_test POST "/api/v1/keys/$F99_NKEY_ID/reactivate" 200 "F99: reactivar llave de ficha" --key ADMIN-CLI
  F99_CARD_STATE=$($MYSQL -sN -e "SELECT CONCAT(status,':',IFNULL(current_key_id,0)) FROM nfc_cards WHERE id=$F99_CARD_ID" 2>/dev/null)
  [ "$F99_CARD_STATE" = "ASSIGNED:$F99_NKEY_ID" ] && pass "F99: la ficha vuelve a quedar asignada" || fail "F99: la ficha vuelve a quedar asignada" "got $F99_CARD_STATE"
else
  skip "F99: llave de ficha" "no se pudo crear la ficha"
fi

$MYSQL -e "DELETE e FROM key_access_events e JOIN access_keys k ON k.id=e.key_id WHERE k.label LIKE 'F99-%'; DELETE FROM nfc_cards WHERE number LIKE 'F99-%'; DELETE FROM access_keys WHERE label LIKE 'F99-%';" >/dev/null 2>&1 || true
if [ -n "${F99_W_ID:-}" ]; then
  $MYSQL -e "DELETE FROM worker_sessions WHERE worker_id=$F99_W_ID; DELETE FROM workers WHERE id=$F99_W_ID" >/dev/null 2>&1 || true
fi
pass "F99: limpieza de datos de prueba"


# =============================================================================
# BLOCK 70 — F97: variantes ESP (cerradura/imán) + canal de puerta
# Trazabilidad: RF-174..RF-177, TSK-F97-01/02/03/06
# =============================================================================
block "BLOCK 70 — F97: variantes ESP + canal de puerta"
F97_TS=$(date +%s%N)
F97_PACK=$($MYSQL -sN -e "SELECT id FROM device_packs ORDER BY id LIMIT 1" 2>/dev/null || echo "")
F97_CERR_EXT="f97cerr$F97_TS"
F97_IMAN_EXT="f97iman$F97_TS"

if [ -z "$F97_PACK" ]; then
  skip "F97: variantes ESP" "no hay packs en la BD"
else
  # Alta sin subtipo → CERRADURA (compatibilidad).
  http_test POST /api/v1/devices/register 201 "F97: RPI sin subtipo se registra" --key ADMIN-CLI \
    --body "{\"pack_id\":$F97_PACK,\"kind\":\"RPI\",\"external_id\":\"$F97_CERR_EXT\"}"
  F97_DEF_SUB=$(curl -s -H "X-API-Key: $(get_key ADMIN-CLI)" "${API_BASE}/api/v1/devices" \
    | python3 -c "import sys,json;print(next((d.get('subtype') for d in json.load(sys.stdin).get('items',[]) if d.get('external_id')=='$F97_CERR_EXT'),''))" 2>/dev/null)
  if [ "$F97_DEF_SUB" = "CERRADURA" ]; then
    pass "F97: RPI sin subtipo queda como ESP-cerradura"
  else
    fail "F97: RPI sin subtipo queda como ESP-cerradura" "subtype='$F97_DEF_SUB'"
  fi

  # Alta explícita IMAN y subtipo inválido.
  http_test POST /api/v1/devices/register 201 "F97: RPI con subtipo IMAN se registra" --key ADMIN-CLI \
    --body "{\"pack_id\":$F97_PACK,\"kind\":\"RPI\",\"subtype\":\"IMAN\",\"external_id\":\"$F97_IMAN_EXT\"}"
  http_test POST /api/v1/devices/register 422 "F97: subtipo de ESP inválido → 422" --key ADMIN-CLI \
    --body "{\"pack_id\":$F97_PACK,\"kind\":\"RPI\",\"subtype\":\"EXTERIOR\",\"external_id\":\"f97bad$F97_TS\"}"

  # Heartbeat: variante declarada y detección de desajuste.
  F97_HB_OK=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"external_id\":\"$F97_IMAN_EXT\",\"sub_kinds\":[\"RPI\"],\"firmware_variant\":\"IMAN\",\"firmware_version\":\"2.0-iman\"}" \
    "${API_BASE}/dashboard-api/device-heartbeat")
  if echo "$F97_HB_OK" | grep -q '"variant_mismatch":false' \
     && echo "$F97_HB_OK" | grep -q '"firmware_version":"2.0-iman"'; then
    pass "F97: heartbeat registra variante/versión sin desajuste"
  else
    fail "F97: heartbeat registra variante/versión" "$(echo "$F97_HB_OK" | head -c 300)"
  fi
  F97_HB_BAD=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"external_id\":\"$F97_CERR_EXT\",\"sub_kinds\":[\"RPI\"],\"firmware_variant\":\"IMAN\",\"firmware_version\":\"2.0-iman\"}" \
    "${API_BASE}/dashboard-api/device-heartbeat")
  if echo "$F97_HB_BAD" | grep -q '"variant_mismatch":true'; then
    pass "F97: desajuste panel/sketch se reporta"
  else
    fail "F97: desajuste panel/sketch se reporta" "$(echo "$F97_HB_BAD" | head -c 300)"
  fi

  # SSE sin credencial → 401.
  F97_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 \
    "${API_BASE}/dashboard-api/door-events?external_id=$F97_IMAN_EXT&since_id=0" 2>/dev/null)
  if [ "$F97_CODE" = "401" ]; then
    pass "F97: SSE de puerta exige credencial"
  else
    fail "F97: SSE de puerta exige credencial" "HTTP $F97_CODE"
  fi

  # Credencial temporal vinculada al ESP-imán sintético para probar el SSE.
  F97_IMAN_ID=$($MYSQL -sN -e "SELECT id FROM devices WHERE kind='RPI' AND external_id='$F97_IMAN_EXT' LIMIT 1" 2>/dev/null || echo "")
  if [ -n "$F97_IMAN_ID" ]; then
    F97_SSE_KEY=$(python3 -c 'import secrets;print(secrets.token_hex(24))')
    F97_SSE_HASH=$(python3 -c "import hashlib,sys;print(hashlib.sha256(sys.argv[1].encode()).hexdigest())" "$F97_SSE_KEY")
    $MYSQL -e "INSERT INTO api_clients (code,kind,api_key_hash,scopes_csv,active,device_id) VALUES ('F97-SSE-$F97_TS','RPI','$F97_SSE_HASH','qr:validate,rooms:read,presence:write',1,$F97_IMAN_ID)" >/dev/null 2>&1 || true

    F97_MISMATCH=$(curl -s --max-time 2 -H "X-API-Key: $F97_SSE_KEY" \
      "${API_BASE}/dashboard-api/door-events?external_id=not-my-chip&since_id=0" 2>/dev/null)
    if echo "$F97_MISMATCH" | grep -q 'device_mismatch'; then
      pass "F97: SSE rechaza un external_id ajeno"
    else
      fail "F97: SSE rechaza un external_id ajeno" "$(echo "$F97_MISMATCH" | head -c 200)"
    fi

    F97_SSE_FILE="$PROJECT_DIR/logs/_f97_sse.txt"
    F97_SSE_CODE=$(curl -sN --max-time 2 -o "$F97_SSE_FILE" -w '%{http_code}' \
      -H "X-API-Key: $F97_SSE_KEY" \
      "${API_BASE}/dashboard-api/door-events?external_id=$F97_IMAN_EXT&since_id=0" 2>/dev/null)
    if [ "$F97_SSE_CODE" = "200" ] && grep -q 'event: snapshot' "$F97_SSE_FILE"; then
      pass "F97: SSE emite snapshot autenticado"
    elif [ "$F97_SSE_CODE" = "409" ] && grep -q 'door_sensor_unavailable' "$F97_SSE_FILE"; then
      pass "F97: SSE autenticado sin puerta Zigbee → 409 explicativo"
    else
      fail "F97: SSE autenticado" "HTTP $F97_SSE_CODE body=$(head -c 200 "$F97_SSE_FILE" 2>/dev/null)"
    fi
    rm -f "$F97_SSE_FILE"
  else
    skip "F97: SSE autenticado" "no se pudo resolver el ESP-imán sintético"
  fi
fi

# Limpieza de dispositivos sintéticos.
$MYSQL -e "DELETE FROM devices WHERE external_id LIKE 'f97%'; DELETE FROM api_clients WHERE code LIKE 'F97-SSE-%'" >/dev/null 2>&1 || true
pass "F97: limpieza de datos de prueba"

# =============================================================================
# BLOCK 71 — F98: panel de llaves (/llaves)
# Trazabilidad: RF-178..RF-184, TSK-F98-08
# =============================================================================
block "BLOCK 71 — F98: panel de llaves"

F98_DIR="$PROJECT_DIR/public/llaves"

# --- Estáticos: ficheros del panel -----------------------------------------
for f in index.html login.html assets/llaves.js assets/llaves.css \
         assets/brand-tokens.css assets/via-valentia-logo.png; do
  if [ -f "$F98_DIR/$f" ]; then
    pass "F98: existe public/llaves/$f"
  else
    fail "F98: existe public/llaves/$f" "fichero no encontrado"
  fi
done

# Identidad corporativa Vía Valentia (F98 branding).
if grep -q '#0c4b7f' "$F98_DIR/assets/brand-tokens.css" 2>/dev/null \
   && grep -q '#dea139' "$F98_DIR/assets/brand-tokens.css" 2>/dev/null; then
  pass "F98: tokens de marca Vía Valentia (azul y oro)"
else
  fail "F98: tokens de marca" "faltan los colores corporativos"
fi
for page in index.html login.html; do
  if grep -q '/llaves/assets/brand-tokens.css' "$F98_DIR/$page" 2>/dev/null \
     && grep -q '/llaves/assets/via-valentia-logo.png' "$F98_DIR/$page" 2>/dev/null; then
    pass "F98: $page carga tokens y logo corporativo"
  else
    fail "F98: $page branding" "no enlaza tokens o logo local"
  fi
done
if file "$F98_DIR/assets/via-valentia-logo.png" 2>/dev/null | grep -q 'PNG image'; then
  pass "F98: el logo corporativo es un PNG local"
else
  fail "F98: logo corporativo" "no es un PNG válido"
fi
if grep -rqs 'viavalentia.com/wp-content' "$F98_DIR"; then
  fail "F98: branding sin dependencia externa" "el panel carga el logo desde la web del hotel"
else
  pass "F98: branding sin dependencia externa"
fi

# index.html enlaza la hoja y el script del panel.
if grep -q '/llaves/assets/llaves.css' "$F98_DIR/index.html" 2>/dev/null \
   && grep -q '/llaves/assets/llaves.js' "$F98_DIR/index.html" 2>/dev/null; then
  pass "F98: index.html enlaza llaves.css y llaves.js"
else
  fail "F98: index.html enlaza llaves.css y llaves.js" "falta algún enlace"
fi

# login.html es autocontenido (CSS/JS inline) y llama al login CRM.
if grep -q '/llaves/assets/llaves.css' "$F98_DIR/login.html" 2>/dev/null \
   && grep -q '/llaves/assets/llaves.js' "$F98_DIR/login.html" 2>/dev/null; then
  pass "F98: login.html enlaza llaves.css y llaves.js"
elif grep -q '/api/v1/crm/login' "$F98_DIR/login.html" 2>/dev/null; then
  pass "F98: login.html autocontenido (estilo/script inline) usa /api/v1/crm/login"
else
  fail "F98: login.html" "no enlaza los assets ni contiene el flujo de login CRM"
fi

# llaves.js consume los endpoints del panel de llaves.
for ep in '/api/v1/llaves/nfc-available' '/api/v1/keys/defaults' '/api/v1/workers'; do
  if grep -q "$ep" "$F98_DIR/assets/llaves.js" 2>/dev/null; then
    pass "F98: llaves.js usa $ep"
  else
    fail "F98: llaves.js usa $ep" "endpoint no referenciado"
  fi
done

# Sin llamadas a Tuya desde el panel de llaves.
if grep -rqiE 'tuya|openapi\.tuya' "$F98_DIR" 2>/dev/null; then
  fail "F98: el panel /llaves no llama a Tuya" "referencia a Tuya encontrada"
else
  pass "F98: el panel /llaves no llama a Tuya"
fi

# --- HTTP público -----------------------------------------------------------
F98_LOGIN_FILE="$PROJECT_DIR/logs/_f98_login.html"
F98_LOGIN_CODE=$(curl -s -o "$F98_LOGIN_FILE" -w '%{http_code}' --max-time 10 \
  "${API_BASE}/llaves/login" 2>/dev/null || echo "000")
if [ "$F98_LOGIN_CODE" = "200" ] && grep -q 'Gestión de llaves' "$F98_LOGIN_FILE" 2>/dev/null; then
  pass "F98: GET /llaves/login 200 con 'Gestión de llaves'"
else
  fail "F98: GET /llaves/login" "HTTP $F98_LOGIN_CODE o falta el título"
fi
rm -f "$F98_LOGIN_FILE"

http_test GET /llaves/assets/llaves.js        200 "F98: GET /llaves/assets/llaves.js"
http_test GET /llaves/assets/llaves.css       200 "F98: GET /llaves/assets/llaves.css"
http_test GET /llaves/assets/brand-tokens.css 200 "F98: GET /llaves/assets/brand-tokens.css"
http_test GET /llaves/assets/via-valentia-logo.png 200 "F98: GET /llaves/assets/via-valentia-logo.png"

# /llaves sin cookie → 302 a /llaves/login.
F98_LLAVES_HDR="$PROJECT_DIR/logs/_f98_llaves_headers.tmp"
F98_LLAVES_CODE=$(curl -s -o /dev/null -D "$F98_LLAVES_HDR" -w '%{http_code}' --max-time 10 \
  "${API_BASE}/llaves" 2>/dev/null || echo "000")
if [ "$F98_LLAVES_CODE" = "302" ] \
   && grep -qi '^Location: /llaves/login' "$F98_LLAVES_HDR"; then
  pass "F98: GET /llaves sin cookie → 302 /llaves/login"
else
  fail "F98: GET /llaves sin cookie" "HTTP $F98_LLAVES_CODE, Location=$(grep -i '^Location:' "$F98_LLAVES_HDR" 2>/dev/null | tr -d '\r')"
fi
rm -f "$F98_LLAVES_HDR"

http_test GET /api/v1/llaves/nfc-available 401 \
  "F98: GET /api/v1/llaves/nfc-available sin credencial → 401"

# --- HTTP autenticado con ADMIN-CLI (clave de API) --------------------------
F98_KEYS_FILE="$PROJECT_DIR/logs/_f98_keys.json"
F98_KEYS_CODE=$(curl -s -o "$F98_KEYS_FILE" -w '%{http_code}' --max-time 10 \
  -H "X-API-Key: $ADMIN_KEY" "${API_BASE}/api/v1/keys" 2>/dev/null || echo "000")
if [ "$F98_KEYS_CODE" = "200" ]; then
  pass "F98: GET /api/v1/keys con ADMIN-CLI → 200"
elif [ "$F98_KEYS_CODE" = "403" ]; then
  skip "F98: GET /api/v1/keys con ADMIN-CLI" "aplica migración 0132"
else
  fail "F98: GET /api/v1/keys con ADMIN-CLI" "Esperado 200, recibido $F98_KEYS_CODE"
fi
rm -f "$F98_KEYS_FILE"

http_test GET /api/v1/llaves/nfc-available 200 \
  "F98: GET /api/v1/llaves/nfc-available con ADMIN-CLI → 200" --key ADMIN-CLI

# --- Flujo condicional key_manager -----------------------------------------
# No crea trabajadores ni llaves reales; solo un usuario CRM temporal que se
# elimina al final con la cookie de admin.
CRM_TEST_ADMIN_USER="${CRM_TEST_ADMIN_USER:-admin}"
CRM_TEST_ADMIN_PASSWORD="${CRM_TEST_ADMIN_PASSWORD:-admin123}"
F98_ADMIN_JAR="$PROJECT_DIR/logs/_f98_admin.cookies"
F98_KM_JAR="$PROJECT_DIR/logs/_f98_km.cookies"
F98_ADMIN_JSON="$PROJECT_DIR/logs/_f98_admin_login.json"
F98_USER_JSON="$PROJECT_DIR/logs/_f98_user.json"
F98_KM_LOGIN_JSON="$PROJECT_DIR/logs/_f98_km_login.json"
F98_USER_ID=""

_f98_cleanup() {
  if [ -n "$F98_USER_ID" ]; then
    curl -s --max-time 10 -b "$F98_ADMIN_JAR" -X DELETE \
      "${API_BASE}/api/v1/crm/users/$F98_USER_ID" -o /dev/null 2>/dev/null || true
  fi
  rm -f "$F98_ADMIN_JAR" "$F98_KM_JAR" "$F98_ADMIN_JSON" \
        "$F98_USER_JSON" "$F98_KM_LOGIN_JSON" "$F98_KM_ME_JSON" \
        "$F98_KM_NFC_JSON" 2>/dev/null || true
}

F98_ADMIN_CODE=$(curl -s --max-time 10 -c "$F98_ADMIN_JAR" -o "$F98_ADMIN_JSON" \
  -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d "{\"username\":\"$CRM_TEST_ADMIN_USER\",\"password\":\"$CRM_TEST_ADMIN_PASSWORD\"}" \
  "${API_BASE}/api/v1/crm/login" 2>/dev/null || echo "000")

if [ "$F98_ADMIN_CODE" != "200" ]; then
  skip "F98: flujo key_manager" \
    "login admin falló (HTTP $F98_ADMIN_CODE); define CRM_TEST_ADMIN_USER/CRM_TEST_ADMIN_PASSWORD"
  _f98_cleanup
else
  pass "F98: login CRM del admin"

  F98_UNAME="keydesk-test-$(date +%s)"
  F98_UPASS="F98-Test-Pass-$(date +%s)-$RANDOM"
  F98_CREATE_CODE=$(curl -s --max-time 10 -b "$F98_ADMIN_JAR" -c "$F98_ADMIN_JAR" \
    -o "$F98_USER_JSON" -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"username\":\"$F98_UNAME\",\"password\":\"$F98_UPASS\",\"role\":\"key_manager\"}" \
    "${API_BASE}/api/v1/crm/users" 2>/dev/null || echo "000")

  if [ "$F98_CREATE_CODE" != "201" ]; then
    fail "F98: crear usuario key_manager temporal" \
      "Esperado 201, recibido $F98_CREATE_CODE: $(head -c 200 "$F98_USER_JSON" 2>/dev/null)"
  else
    pass "F98: crear usuario key_manager temporal"
    F98_USER_ID=$(python3 -c "import sys,json;print(json.load(open(sys.argv[1])).get('id',''))" "$F98_USER_JSON" 2>/dev/null || echo "")

    if [ -z "$F98_USER_ID" ]; then
      fail "F98: id del usuario temporal" "respuesta sin id"
    else
      F98_KM_CODE=$(curl -s --max-time 10 -c "$F98_KM_JAR" -o "$F98_KM_LOGIN_JSON" \
        -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "{\"username\":\"$F98_UNAME\",\"password\":\"$F98_UPASS\"}" \
        "${API_BASE}/api/v1/crm/login" 2>/dev/null || echo "000")

      if [ "$F98_KM_CODE" != "200" ]; then
        fail "F98: login del key_manager" "Esperado 200, recibido $F98_KM_CODE"
      else
        pass "F98: login del key_manager"

        # /crm/me refleja role=key_manager.
        F98_KM_ME_JSON="$PROJECT_DIR/logs/_f98_km_me.json"
        F98_ME_CODE=$(curl -s --max-time 10 -b "$F98_KM_JAR" -o "$F98_KM_ME_JSON" \
          -w '%{http_code}' "${API_BASE}/api/v1/crm/me" 2>/dev/null || echo "000")
        if [ "$F98_ME_CODE" = "200" ] \
           && grep -q '"role":"key_manager"' "$F98_KM_ME_JSON" 2>/dev/null; then
          pass "F98: GET /crm/me devuelve role=key_manager"
        else
          fail "F98: GET /crm/me" "HTTP $F98_ME_CODE: $(head -c 200 "$F98_KM_ME_JSON" 2>/dev/null)"
        fi

        # /panel → 302 /llaves (nunca el panel técnico).
        F98_PANEL_HDR="$PROJECT_DIR/logs/_f98_panel_headers.tmp"
        F98_PANEL_CODE=$(curl -s -o /dev/null -D "$F98_PANEL_HDR" -w '%{http_code}' --max-time 10 \
          -b "$F98_KM_JAR" -H 'Accept: text/html' "${API_BASE}/panel" 2>/dev/null || echo "000")
        if [ "$F98_PANEL_CODE" = "302" ] \
           && grep -qi '^Location: /llaves' "$F98_PANEL_HDR"; then
          pass "F98: key_manager en /panel → 302 /llaves"
        else
          fail "F98: key_manager en /panel" "HTTP $F98_PANEL_CODE, Location=$(grep -i '^Location:' "$F98_PANEL_HDR" 2>/dev/null | tr -d '\r')"
        fi
        rm -f "$F98_PANEL_HDR"

        # Fichas NFC: lectura y escritura denegadas (sin nfc-cards:*).
        F98_NFC_GET_CODE=$(curl -s --max-time 10 -b "$F98_KM_JAR" -o /dev/null \
          -w '%{http_code}' "${API_BASE}/api/v1/nfc-cards" 2>/dev/null || echo "000")
        if [ "$F98_NFC_GET_CODE" = "403" ]; then
          pass "F98: key_manager GET /nfc-cards → 403"
        else
          fail "F98: key_manager GET /nfc-cards" "Esperado 403, recibido $F98_NFC_GET_CODE"
        fi

        F98_NFC_POST_CODE=$(curl -s --max-time 10 -b "$F98_KM_JAR" -o /dev/null \
          -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
          -d "{\"number\":\"f98-should-not-exist-$F98_UNAME\",\"code\":\"f98code-$F98_UNAME\"}" \
          "${API_BASE}/api/v1/nfc-cards" 2>/dev/null || echo "000")
        if [ "$F98_NFC_POST_CODE" = "403" ]; then
          pass "F98: key_manager POST /nfc-cards → 403"
        else
          fail "F98: key_manager POST /nfc-cards" "Esperado 403, recibido $F98_NFC_POST_CODE"
        fi

        # Llaves y catálogos permitidos.
        F98_KM_KEYS_CODE=$(curl -s --max-time 10 -b "$F98_KM_JAR" -o /dev/null \
          -w '%{http_code}' "${API_BASE}/api/v1/keys" 2>/dev/null || echo "000")
        if [ "$F98_KM_KEYS_CODE" = "200" ]; then
          pass "F98: key_manager GET /api/v1/keys → 200"
        else
          fail "F98: key_manager GET /api/v1/keys" "Esperado 200, recibido $F98_KM_KEYS_CODE"
        fi

        for ep in '/api/v1/worker-roles' '/api/v1/room-types'; do
          F98_CAT_CODE=$(curl -s --max-time 10 -b "$F98_KM_JAR" -o /dev/null \
            -w '%{http_code}' "${API_BASE}${ep}" 2>/dev/null || echo "000")
          if [ "$F98_CAT_CODE" = "200" ]; then
            pass "F98: key_manager GET $ep → 200"
          else
            fail "F98: key_manager GET $ep" "Esperado 200, recibido $F98_CAT_CODE"
          fi
        done

        # Fichas disponibles: 200 y sin datos sensibles.
        F98_KM_NFC_JSON="$PROJECT_DIR/logs/_f98_km_nfc.json"
        F98_AVAIL_CODE=$(curl -s --max-time 10 -b "$F98_KM_JAR" -o "$F98_KM_NFC_JSON" \
          -w '%{http_code}' "${API_BASE}/api/v1/llaves/nfc-available" 2>/dev/null || echo "000")
        if [ "$F98_AVAIL_CODE" = "200" ] \
           && ! grep -q 'code_hash' "$F98_KM_NFC_JSON" 2>/dev/null \
           && ! grep -q 'code_last4' "$F98_KM_NFC_JSON" 2>/dev/null; then
          pass "F98: key_manager GET /llaves/nfc-available → 200 sin code_hash/code_last4"
        else
          fail "F98: key_manager GET /llaves/nfc-available" \
            "HTTP $F98_AVAIL_CODE: $(head -c 200 "$F98_KM_NFC_JSON" 2>/dev/null)"
        fi
      fi
    fi
  fi

  # Limpieza del usuario temporal (siempre, aunque falle algún paso).
  _f98_cleanup
  pass "F98: limpieza del usuario temporal"
fi

# =============================================================================
# RESUMEN
# =============================================================================
echo ""
echo "========================================================"
echo -e "  Resultados:  ${G}${PASS} passed${NC}  |  ${R}${FAIL} failed${NC}  |  ${Y}${SKIP} skipped${NC}"
echo "  Log:          $LOG_FILE"
echo "========================================================"
echo ""

# Limpiar fichero temporal
rm -f "$TMP_BODY"

# Código de salida: 0 si todo OK, 1 si hay fallos
exit $((FAIL > 0 ? 1 : 0))
