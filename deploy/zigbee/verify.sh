#!/usr/bin/env bash
# Verificación de solo lectura del proyecto. Hace una prueba MQTT acotada y no
# reinicia ni modifica servicios del sistema.
set -eu
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$ROOT/.env"
PASS=0
FAIL=0
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

usage() {
  cat <<'USAGE'
Uso: verify.sh [--env-file RUTA]

Comprueba contenedores, salud, listeners loopback, una publicación/suscripción
MQTT temporal y que los logs recientes no contengan la contraseña MQTT.
No modifica el sistema ni reinicia servicios.
USAGE
}

while [ $# -gt 0 ]; do
  case "$1" in
    --env-file)
      [ $# -ge 2 ] || { echo "ERROR: --env-file requiere una ruta" >&2; exit 2; }
      ENV_FILE="$2"
      shift 2
      ;;
    -h|--help) usage; exit 0 ;;
    *) echo "ERROR: argumento desconocido: $1" >&2; usage >&2; exit 2 ;;
  esac
done

load_env() {
  local line key value
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    case "$line" in ''|'#'*) continue ;; *=*) ;; *) continue ;; esac
    key="${line%%=*}"; value="${line#*=}"
    case "$key" in ''|*[!A-Za-z0-9_]*) continue ;; esac
    case "$value" in
      \"*\") value="${value#\"}"; value="${value%\"}" ;;
      \'*\') value="${value#\'}"; value="${value%\'}" ;;
    esac
    export "$key=$value"
  done < "$ENV_FILE"
}

pass() { printf 'OK: %s\n' "$*"; PASS=$((PASS + 1)); }
fail() { printf 'FALLO: %s\n' "$*" >&2; FAIL=$((FAIL + 1)); }

[ -f "$ENV_FILE" ] || { echo "ERROR: no existe $ENV_FILE" >&2; exit 1; }
load_env
PROJECT="${COMPOSE_PROJECT_NAME:?falta COMPOSE_PROJECT_NAME}"
[ -n "${MQTT_USERNAME:-}" ] && [ -n "${MQTT_PASSWORD:-}" ] || {
  echo "ERROR: faltan credenciales MQTT en $ENV_FILE" >&2
  exit 1
}

compose() {
  docker compose \
    --env-file "$ENV_FILE" \
    --project-directory "$ROOT" \
    -p "$PROJECT" \
    -f "$ROOT/docker-compose.yml" \
    "$@"
}

echo "Verificación del proyecto '$PROJECT'"
echo "Directorio: $ROOT"
echo

RUNNING="$(compose ps --status running --services 2>/dev/null || true)"
for svc in mosquitto zigbee2mqtt; do
  if printf '%s\n' "$RUNNING" | grep -qx "$svc"; then
    pass "contenedor $svc en ejecución"
  else
    fail "contenedor $svc no está en ejecución"
  fi
  cid="$(compose ps -q "$svc" 2>/dev/null || true)"
  if [ -n "$cid" ]; then
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null || echo unknown)"
    case "$health" in
      healthy) pass "$svc health=healthy" ;;
      none) fail "$svc no define healthcheck" ;;
      *) fail "$svc health=$health" ;;
    esac
  fi
done

check_loopback_listener() {
  local name="$1" port="$2" lines
  if ! command -v ss >/dev/null 2>&1; then
    fail "ss no disponible para comprobar $name"
    return
  fi
  lines="$(ss -ltnH "sport = :$port" 2>/dev/null || true)"
  if [ -z "$lines" ]; then
    fail "$name no tiene listener en el puerto $port"
    return
  fi
  if printf '%s\n' "$lines" | grep -Eq '(^|[[:space:]])(0\.0\.0\.0|\*|\[::\]):'"$port"'([[:space:]]|$)'; then
    fail "$name escucha fuera de loopback en $port"
    return
  fi
  if printf '%s\n' "$lines" | grep -Eq '(127\.0\.0\.1|\[::1\]):'"$port"'([[:space:]]|$)'; then
    pass "$name escucha en loopback:$port"
  else
    fail "$name no muestra listener loopback en $port"
  fi
}
check_loopback_listener "MQTT" "${MQTT_HOST_PORT:-1883}"
check_loopback_listener "UI Zigbee2MQTT" "${Z2M_UI_HOST_PORT:-8080}"

# Prueba MQTT controlada: topic efímero, QoS 1, sin retención.
TOPIC="cerraduras/deploy-verify/$(date -u +%Y%m%dT%H%M%SZ)-$$"
MARK="verify-$$-$(date +%s)"
OUT="$TMP_DIR/mqtt.out"
(
  compose exec -T mosquitto sh -c \
    'mosquitto_sub -h 127.0.0.1 -p 1883 -u "$MQTT_USERNAME" -P "$MQTT_PASSWORD" -t "$1" -C 1 -W 8 -q 1' \
    sh "$TOPIC" > "$OUT" 2>"$TMP_DIR/mqtt.err"
) &
SUBPID=$!
sleep 1
if compose exec -T mosquitto sh -c \
    'mosquitto_pub -h 127.0.0.1 -p 1883 -u "$MQTT_USERNAME" -P "$MQTT_PASSWORD" -t "$1" -m "$2" -q 1' \
    sh "$TOPIC" "$MARK" >/dev/null 2>&1; then
  pass "publicación MQTT de verificación aceptada"
else
  fail "no se pudo publicar el mensaje MQTT de verificación"
fi
wait "$SUBPID" 2>/dev/null || true
if grep -qF "$MARK" "$OUT" 2>/dev/null; then
  pass "suscripción MQTT recibió el mensaje de verificación"
else
  fail "la suscripción MQTT no recibió el mensaje de verificación"
fi

# Nunca imprimir contenido de logs ni la contraseña.
LOG_OUT="$TMP_DIR/logs.txt"
compose logs --no-color --tail=500 > "$LOG_OUT" 2>&1 || true
if grep -qF -- "$MQTT_PASSWORD" "$LOG_OUT" 2>/dev/null; then
  fail "la contraseña MQTT aparece en los logs recientes"
else
  pass "los logs recientes no contienen la contraseña MQTT"
fi
if grep -Eq 'ZIGBEE2MQTT_CONFIG_MQTT_PASSWORD[[:space:]]*=' "$LOG_OUT" 2>/dev/null; then
  fail "los logs exponen la variable de contraseña MQTT"
else
  pass "los logs no exponen la variable de contraseña MQTT"
fi

echo
echo "Resultado: $PASS OK, $FAIL fallo(s)."
[ "$FAIL" -eq 0 ] || exit 1
echo "Verificación correcta. No se ha modificado el sistema."
