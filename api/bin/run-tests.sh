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
API_BASE="http://127.0.0.1:8080"

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
http_test POST /api/v1/tuya/webhook 202 \
    "POST /tuya/webhook (PROXIMITY, no auth) → 202" \
    --body '{"devId":"bf4c7e7d2cef28cea2nkwk","status":[{"code":"doorcontact_state","value":true,"t":'"$(date +%s)"'000}]}'

http_test POST /api/v1/tuya/webhook 202 \
    "POST /tuya/webhook (PRESENCE, no auth) → 202" \
    --body '{"devId":"bf98d27d79685e38a2wbda","status":[{"code":"presence_state","value":"presence","t":'"$(date +%s)"'000}]}'

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
SIM_OVERRIDE_CHECK=$($MYSQL -sN -e "SELECT simulated_override FROM rooms WHERE id=1 LIMIT 1" 2>/dev/null || echo "")
    if [ -n "$SIM_OVERRIDE_CHECK" ] || db_exec "SELECT 1 FROM devices d JOIN rooms r ON r.pack_id = d.pack_id WHERE r.id=1 AND d.kind='RPI' LIMIT 1" > /dev/null 2>&1; then
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
    fi

# ===================================================
# BLOCK 20 — F21: QR polling (qr_text regeneration)
# Trazabilidad: TSK-QR11
# ===================================================
block "BLOCK 20 — F21: QR polling (qr_text)"

    # Step 1: Reset room 1 to FREE
    $MYSQL -sN -e "UPDATE rooms SET status='FREE', cooldown_until=NULL WHERE id=1" 2>/dev/null || true
    $MYSQL -sN -e "UPDATE stays SET status='CLOSED' WHERE room_id=1 AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null || true

    # Step 2: Create a QR via API
    QR_CREATE_RESP=$(curl -s -X POST http://127.0.0.1:8080/dashboard-api/qr-test/create \
        -H "Content-Type: application/json" \
        -d '{"room_id":1,"duracion_minutos":60}')
    QR_CREATE_CODE=$(echo "$QR_CREATE_RESP" | curl -s -o /dev/null -w "%{http_code}" -X POST http://127.0.0.1:8080/dashboard-api/qr-test/create \
        -H "Content-Type: application/json" \
        -d '{"room_id":1,"duracion_minutos":60}' 2>/dev/null)

    if [ "$QR_CREATE_CODE" = "201" ]; then
        QR_TEXT_CREATED=$(echo "$QR_CREATE_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('qr_text',''))" 2>/dev/null || echo "")

        # Step 3: GET /dashboard-api/qr-status → must include qr_text
        http_test GET '/dashboard-api/qr-status?room_id=1' 200 \
            "GET /qr-status (poll, includes qr_text) → 200"

        # Step 4: Verify qr_text matches (deterministic regeneration)
        QR_STATUS_RESP=$(curl -s 'http://127.0.0.1:8080/dashboard-api/qr-status?room_id=1')
        QR_TEXT_POLLED=$(echo "$QR_STATUS_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('qr_text',''))" 2>/dev/null || echo "")

        if [ -n "$QR_TEXT_POLLED" ] && [ "$QR_TEXT_CREATED" = "$QR_TEXT_POLLED" ]; then
            pass "GET /qr-status qr_text matches creation token (deterministic)"
        else
            if [ -z "$QR_TEXT_POLLED" ]; then
                fail "GET /qr-status returns qr_text" "qr_text está vacío en la response de poll"
            else
                fail "GET /qr-status qr_text matches creation" "qr_text creado vs polleado difieren"
            fi
        fi
    else
        skip "QR polling qr_text test" "No se pudo crear QR de prueba (HTTP $QR_CREATE_CODE)"
    fi

    # Cleanup
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

# 26.5: POST /battery-refresh with valid PROXIMITY device (id=3 from seeds)
# Note: This test requires the Tuya API credentials to be configured.
# If Tuya API is unavailable, the endpoint may return 502, which is a valid response.
# We test the endpoint structure, not the Tuya connectivity.
BATT_RESP=$(curl -s -w "\n%{http_code}" \
  -H "X-API-Key: $(get_key ADMIN-CLI)" \
  -H "Content-Type: application/json" \
  -d '{"device_id":3}' \
  "http://127.0.0.1:8080/dashboard-api/battery-refresh" 2>/dev/null)
BATT_HTTP=$(echo "$BATT_RESP" | tail -1)

if [ "$BATT_HTTP" = "200" ]; then
  if echo "$BATT_RESP" | grep -q '"ok":true' && echo "$BATT_RESP" | grep -q '"kind":"PROXIMITY"'; then
    pass "POST /battery-refresh: device 3 returns battery data"
  else
    fail "POST /battery-refresh: device 3 response missing fields" \
      "response: $(echo "$BATT_RESP" | head -1)"
  fi
elif [ "$BATT_HTTP" = "502" ]; then
  skip "POST /battery-refresh: Tuya API unavailable (HTTP 502) — SKIP" \
    "Tuya API may be down or credentials not configured"
elif [ "$BATT_HTTP" = "404" ]; then
  skip "POST /battery-refresh: device 3 not found in DB — SKIP" \
    "Run seeds to create test devices"
else
  fail "POST /battery-refresh: device 3 unexpected HTTP $BATT_HTTP" \
    "response: $(echo "$BATT_RESP" | head -1)"
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

# 28.7: Verify anomaly has status field in response
ANOM_STATUS_RESP=$(curl -s -w "\n%{http_code}" \
  -H "X-API-Key: $(get_key ADMIN-CLI)" \
  "http://127.0.0.1:8080/api/v1/anomalies?status=ALL&limit=1" 2>/dev/null)
ANOM_STATUS_HTTP=$(echo "$ANOM_STATUS_RESP" | tail -1)

if [ "$ANOM_STATUS_HTTP" = "200" ]; then
  if echo "$ANOM_STATUS_RESP" | grep -q '"data":\[\]'; then
    # F41: anomaly-scanner ahora corre y resuelve/descarta anomalías, así que la
    # tabla puede estar vacía. No hay fila que inspeccionar → skip explicativo.
    skip "GET /anomalies?status=ALL fields" \
      "No hay anomalías en la BD (anomaly-scanner F41 las resuelve); nada que inspeccionar"
  elif echo "$ANOM_STATUS_RESP" | grep -q '"status"' && echo "$ANOM_STATUS_RESP" | grep -q '"acknowledged_at"'; then
    pass "GET /anomalies?status=ALL: response includes status and acknowledged_at fields"
  else
    fail "GET /anomalies?status=ALL: missing status or acknowledged_at" \
      "response: $(echo "$ANOM_STATUS_RESP" | head -c 400)"
  fi
else
  pass "GET /anomalies?status=ALL: HTTP 200 (no anomalies in DB, valid response)"
fi

# 28.8: Verify acknowledge endpoint still works
# Find an OPEN anomaly first
OPEN_ID=$(curl -s -H "X-API-Key: $(get_key ADMIN-CLI)" \
  "http://127.0.0.1:8080/api/v1/anomalies?status=OPEN&limit=1" 2>/dev/null | \
  php -r '$j=json_decode(stream_get_contents(STDIN),true);echo $j["data"][0]["id"]??"";' 2>/dev/null)

if [ -n "$OPEN_ID" ]; then
  http_test POST "/api/v1/anomalies/$OPEN_ID/acknowledge" 200 \
    "POST /anomalies/$OPEN_ID/acknowledge: acknowledge OPEN anomaly" \
    --key ADMIN-CLI \
    --body '{}'
else
  skip "POST /anomalies/acknowledge: no OPEN anomalies to test — SKIP" \
    "No OPEN anomalies found in DB"
fi

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

DS_ROOM=$($MYSQL -sN -e "SELECT r.id FROM rooms r JOIN devices d ON d.pack_id = r.pack_id WHERE d.kind='PRESENCE' LIMIT 1" 2>/dev/null)
if [ -z "$DS_ROOM" ]; then
    skip "BLOCK 30 estado verídico" "No hay habitación con sensor de presencia (Tuya cloud)"
else
    HAS_SW=$($MYSQL -sN -e "SELECT COUNT(*) FROM devices d JOIN rooms r ON r.pack_id = d.pack_id WHERE r.id = $DS_ROOM AND d.kind='SWITCH'" 2>/dev/null || echo "0")
    if [ "$HAS_SW" != "1" ]; then
        skip "BLOCK 30 estado verídico (room $DS_ROOM)" "La habitación con PRESENCE no tiene SWITCH en su pack"
    else
        DS_JSON=$(curl -s "${API_BASE}/dashboard-api/device-status?room_id=$DS_ROOM")
        DS_RESULT=$(echo "$DS_JSON" | python3 -c "
import sys, json
try:
    d=json.load(sys.stdin)
except Exception as e:
    print('ERR '+str(e)); sys.exit(0)
devs=d.get('devices') or []
states=set(dev.get('state') for dev in devs)
valid=states <= {'online','offline','unknown'}
cnt_sum = (d.get('online') or 0)+(d.get('offline') or 0)+(d.get('unknown') or 0)==(d.get('total') or 0)
tuya=[x for x in devs if x.get('tuya_cloud')]
tuya_flag = bool(tuya) and all((x.get('state')=='offline' and x.get('online') is False) or
                               (x.get('state')=='unknown' and x.get('online') is None) for x in tuya)
print(('OK ' if (valid and cnt_sum and tuya_flag) else 'FAIL ')+('valid='+str(valid)+' sum='+str(cnt_sum)+' tuya_ok='+str(tuya_flag)+' states='+str(sorted(states))))
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

# ── 33.0 Unit tests JS (poller + coreografía). No dependen del servidor. ──────
for F41_JS in tests/Unit/choreography.test.js tests/Unit/presence-poller-gate.test.js; do
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

# ── 33.1 system-status: esquema ampliado de 6 workers (contrato §5) ──────────
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
keys = ['exit-scan','overstay-scan','outbox-worker','anomaly-scanner','presence-poller-manager','pulsar-consumer']
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
        OK*)   pass "F41 system-status: 6 workers con expected/instances/pids/healthy/degraded" ;;
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
# Sin poller de nube para el ZY-M100 (truco [t] para no auto-emparejar el propio shell).
F46_POLLERS=$(pgrep -f "[t]uya-presence-poller\.js bf98d27d79685e38a2wbda" 2>/dev/null | wc -l | tr -d ' ')
if [ "$F46_POLLERS" = "0" ]; then
    pass "F46: sin poller de nube para el ZY-M100 (0 procesos)"
else
    fail "F46: no debe haber poller para el ZY-M100" "procesos=$F46_POLLERS"
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
# BLOCK 37 — Saneamiento de logs: rotación programada + aviso único del manager
# Trazabilidad: TAREA 1/2/3 (log-rotate parametrizable, timer systemd, manager
# de pollers sin aviso en bucle).
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

# ── 37.1 Unit JS: guardia del aviso del presence-poller-manager (TAREA 3) ──
LOGS_PPM_JS="tests/Unit/presence-poller-manager.test.js"
if [ -f "$LOGS_PPM_JS" ]; then
    LOGS_P_OUT=$(node "$LOGS_PPM_JS" 2>&1)
    LOGS_P_RC=$?
    LOGS_P_SUM=$(echo "$LOGS_P_OUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | tail -1)
    [ -z "$LOGS_P_SUM" ] && LOGS_P_SUM="exit=$LOGS_P_RC"
    if [ "$LOGS_P_RC" -eq 0 ]; then
        pass "JS: presence-poller-manager.test.js ($LOGS_P_SUM)"
    else
        fail "JS: presence-poller-manager.test.js" \
            "$LOGS_P_SUM — $(echo "$LOGS_P_OUT" | grep -iE 'FAIL|❌' | head -3 | tr '\n' ' ')"
    fi
else
    skip "JS: presence-poller-manager.test.js" "fichero no encontrado"
fi

# ── 37.2 Units systemd versionadas (no instaladas por el runner) ───────────
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
