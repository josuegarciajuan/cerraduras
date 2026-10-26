#!/usr/bin/env bash
# Preflight de SOLO LECTURA para el proyecto aislado Mosquitto + Zigbee2MQTT.
# No instala, no reinicia, no crea contenedores y no modifica el sistema.
set -eu
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$ROOT/.env"
ERRORS=0
WARNINGS=0

usage() {
  cat <<'USAGE'
Uso: preflight.sh [--env-file RUTA]

Comprueba, sin modificar el host:
  - docker y docker compose disponibles y daemon accesible;
  - espacio libre mínimo en el directorio del proyecto;
  - puertos host libres;
  - TCP del adaptador Zigbee alcanzable;
  - ausencia de otro proyecto Compose con el mismo nombre.

Salida: 0 si todo es correcto, 1 si hay errores, 2 si el uso es incorrecto.
USAGE
}

while [ $# -gt 0 ]; do
  case "$1" in
    --env-file)
      [ $# -ge 2 ] || { echo "ERROR: --env-file requiere una ruta" >&2; exit 2; }
      ENV_FILE="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "ERROR: argumento desconocido: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

error() { printf 'ERROR: %s\n' "$*" >&2; ERRORS=$((ERRORS + 1)); }
warn()  { printf 'AVISO: %s\n' "$*" >&2; WARNINGS=$((WARNINGS + 1)); }
ok()    { printf 'OK: %s\n' "$*"; }

load_env() {
  local line key value
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    case "$line" in
      ''|'#'*) continue ;;
      *=*) ;;
      *) continue ;;
    esac
    key="${line%%=*}"
    value="${line#*=}"
    case "$key" in
      ''|*[!A-Za-z0-9_]*) continue ;;
    esac
    case "$value" in
      \"*\") value="${value#\"}"; value="${value%\"}" ;;
      \'*\') value="${value#\'}"; value="${value%\'}" ;;
    esac
    export "$key=$value"
  done < "$ENV_FILE"
}

is_placeholder() {
  case "${1:-}" in
    *__REQUIRED_*|*CHANGE_ME*) return 0 ;;
  esac
  return 1
}

value_of() {
  local name="$1"
  printf '%s' "${!name:-}"
}

if [ ! -f "$ENV_FILE" ]; then
  echo "ERROR: no existe $ENV_FILE. Copia .env.example a .env y fija los valores." >&2
  exit 1
fi

load_env

echo "Preflight del proyecto aislado Zigbee (solo lectura)"
echo "Directorio: $ROOT"
echo "Entorno:    $ENV_FILE"
echo

# 1. Docker / Compose / daemon.
if command -v docker >/dev/null 2>&1; then
  ok "docker disponible"
  if docker info >/dev/null 2>&1; then
    ok "daemon Docker accesible"
  else
    error "el daemon Docker no responde (no se intenta arrancar)"
  fi
  if docker compose version >/dev/null 2>&1; then
    ok "docker compose v2 disponible"
  else
    error "docker compose v2 no está disponible"
  fi
else
  error "docker no está instalado o no está en PATH"
fi

# 2. Variables mínimas del proyecto.
for var in COMPOSE_PROJECT_NAME MOSQUITTO_IMAGE Z2M_IMAGE MQTT_HOST_PORT Z2M_UI_HOST_PORT \
           MQTT_USERNAME MQTT_PASSWORD ZIGBEE_ADAPTER_HOST ZIGBEE_ADAPTER_PORT \
           MOSQUITTO_UID MOSQUITTO_GID Z2M_UID Z2M_GID; do
  if [ -z "$(value_of "$var")" ]; then
    error "falta la variable obligatoria $var en $ENV_FILE"
  fi
done

validate_nonroot_id() {
  local name="$1" value
  value="$(value_of "$name")"
  case "$value" in
    ''|*[!0-9]*) error "$name debe ser un UID/GID numérico (valor: '$value')" ;;
    *)
      if [ "$value" -eq 0 ]; then
        error "$name no puede ser 0: los contenedores no deben correr como root"
      fi
      ;;
  esac
}
for var in MOSQUITTO_UID MOSQUITTO_GID Z2M_UID Z2M_GID; do
  validate_nonroot_id "$var"
done

PROJECT="$(value_of COMPOSE_PROJECT_NAME)"
case "$PROJECT" in
  ''|*[!a-z0-9_-]*) error "COMPOSE_PROJECT_NAME debe usar solo minúsculas, números, guion y guion bajo" ;;
  [a-z0-9]*) ok "nombre de proyecto: $PROJECT" ;;
  *) error "COMPOSE_PROJECT_NAME debe empezar por letra o número" ;;
esac

for pair in "MQTT_HOST_PORT:${MQTT_HOST_PORT:-}" "Z2M_UI_HOST_PORT:${Z2M_UI_HOST_PORT:-}" \
            "ZIGBEE_ADAPTER_PORT:${ZIGBEE_ADAPTER_PORT:-}"; do
  name="${pair%%:*}"
  value="${pair#*:}"
  case "$value" in
    ''|*[!0-9]*) error "$name debe ser un puerto numérico" ;;
    *)
      if [ "$value" -lt 1 ] || [ "$value" -gt 65535 ]; then
        error "$name fuera de rango 1-65535"
      fi
      ;;
  esac
done
if [ "${MQTT_HOST_PORT:-}" = "${Z2M_UI_HOST_PORT:-}" ]; then
  error "MQTT_HOST_PORT y Z2M_UI_HOST_PORT deben ser distintos"
fi

for var in MOSQUITTO_IMAGE Z2M_IMAGE; do
  value="$(value_of "$var")"
  case "$value" in
    *:latest|*@*:) error "$var no puede usar latest; fija un tag o digest" ;;
    *__REQUIRED_*|*CHANGE_ME*) warn "$var sigue siendo un placeholder; deploy.sh fallará" ;;
    *:*) : ;;
    *) warn "$var no parece tener tag/digest fijo" ;;
  esac
done

if is_placeholder "$(value_of ZIGBEE_ADAPTER_HOST)"; then
  error "ZIGBEE_ADAPTER_HOST sigue siendo un placeholder; fija el host o IP del adaptador"
fi
if is_placeholder "$(value_of MQTT_PASSWORD)"; then
  warn "MQTT_PASSWORD sigue siendo un placeholder; deploy.sh fallará"
fi

# 3. Espacio libre.
MIN_MB="${MIN_FREE_DISK_MB:-2048}"
case "$MIN_MB" in
  ''|*[!0-9]*) error "MIN_FREE_DISK_MB debe ser un entero" ;;
  *)
    FREE_KB="$(df -Pk "$ROOT" | awk 'NR==2 {print $4}' | tr -d ' ' || true)"
    if [ -z "${FREE_KB:-}" ]; then
      error "no se pudo leer el espacio libre de $ROOT"
    else
      FREE_MB=$((FREE_KB / 1024))
      if [ "$FREE_MB" -lt "$MIN_MB" ]; then
        error "espacio libre insuficiente: ${FREE_MB} MB < ${MIN_MB} MB"
      else
        ok "espacio libre: ${FREE_MB} MB (mínimo ${MIN_MB} MB)"
      fi
    fi
    ;;
esac

# 4. Puertos host libres.
port_free() {
  local port="$1"
  if command -v ss >/dev/null 2>&1; then
    if ss -ltnH "sport = :$port" 2>/dev/null | grep -q .; then
      return 1
    fi
    return 0
  fi
  if command -v python3 >/dev/null 2>&1; then
    python3 - "$port" <<'PY'
import socket, sys
port = int(sys.argv[1])
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
try:
    s.bind(("127.0.0.1", port))
except OSError:
    sys.exit(1)
finally:
    s.close()
PY
    return $?
  fi
  return 2
}

port_owned_by_project() {
  local port="$1" ports
  command -v docker >/dev/null 2>&1 || return 1
  [ -n "${COMPOSE_PROJECT_NAME:-}" ] || return 1
  ports="$(docker ps --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME" --format '{{.Ports}}' 2>/dev/null || true)"
  printf '%s\n' "$ports" | grep -Eq "(^|[[:space:],])(127\.0\.0\.1|\[::1\]):$port->"
}

for pair in "MQTT_HOST_PORT:${MQTT_HOST_PORT:-}" "Z2M_UI_HOST_PORT:${Z2M_UI_HOST_PORT:-}"; do
  name="${pair%%:*}"
  port="${pair#*:}"
  case "$port" in ''|*[!0-9]*) continue ;; esac
  if port_free "$port"; then
    ok "$name=$port libre en el host"
  else
    rc=$?
    if [ "$rc" -eq 2 ]; then
      warn "no hay ss ni python3 para comprobar el puerto $port"
    elif port_owned_by_project "$port"; then
      ok "$name=$port ya publicado por este mismo proyecto (redeploy idempotente)"
    else
      error "$name=$port ya está en uso por otro proceso/proyecto; elige otro puerto"
    fi
  fi
done

# 5. TCP del adaptador Zigbee.
ADAPTER_HOST="$(value_of ZIGBEE_ADAPTER_HOST)"
ADAPTER_PORT="$(value_of ZIGBEE_ADAPTER_PORT)"
case "$ADAPTER_PORT" in ''|*[!0-9]*) : ;; *)
  if ! is_placeholder "$ADAPTER_HOST"; then
    if command -v timeout >/dev/null 2>&1; then
      if timeout 3 bash -c 'exec 3<>"/dev/tcp/$1/$2"' _ "$ADAPTER_HOST" "$ADAPTER_PORT" 2>/dev/null; then
        ok "TCP del adaptador alcanzable: ${ADAPTER_HOST}:${ADAPTER_PORT}"
      else
        error "no se puede conectar a ${ADAPTER_HOST}:${ADAPTER_PORT} (adaptador apagado, IP/puerto incorrectos o red distinta)"
      fi
    else
      warn "timeout no disponible; no se comprueba TCP del adaptador"
    fi
  fi
  ;;
esac

# 6. Colisión con otro proyecto Compose.
if command -v docker >/dev/null 2>&1 && [ -n "$PROJECT" ]; then
  IDS="$(docker ps -aq --filter "label=com.docker.compose.project=$PROJECT" 2>/dev/null || true)"
  NETS="$(docker network ls -q --filter "label=com.docker.compose.project=$PROJECT" 2>/dev/null || true)"
  ROOT_REAL="$(cd "$ROOT" && pwd -P)"
  if [ -n "$IDS" ]; then
    FOREIGN=0
    for cid in $IDS; do
      WD="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$cid" 2>/dev/null || true)"
      if [ -n "$WD" ] && [ -d "$WD" ]; then
        WD="$(cd "$WD" && pwd -P)"
      fi
      if [ "$WD" != "$ROOT_REAL" ]; then
        FOREIGN=1
        break
      fi
    done
    if [ "$FOREIGN" -eq 1 ]; then
      error "ya existe OTRO proyecto Compose llamado '$PROJECT' en otro directorio; elige otro nombre"
    else
      ok "proyecto propio '$PROJECT' ya presente; el despliegue puede ser idempotente"
    fi
  elif [ -n "$NETS" ]; then
    warn "hay una red del proyecto '$PROJECT' sin contenedores; se reutilizará o limpiará en el deploy"
  else
    ok "no hay otro proyecto Compose con el nombre '$PROJECT'"
  fi
fi

# 7. Avisos de riesgo y permisos.
PERMS="$(stat -c '%a' "$ENV_FILE" 2>/dev/null || echo '?')"
case "$PERMS" in
  ?00|600|400) ok "permisos de .env restrictivos ($PERMS)" ;;
  *) warn ".env tiene permisos $PERMS; se recomienda 600" ;;
esac

# Propietario esperado de los bind mounts (no se modifica nada).
check_owner() {
  local path="$1" uid="$2" gid="$3" label="$4" owner group
  if [ ! -e "$path" ]; then
    warn "no existe $label ($path); deploy.sh lo creará con propietario $uid:$gid y modo 0700 si corre como root"
    return
  fi
  owner="$(stat -c '%u' "$path" 2>/dev/null || echo '?')"
  group="$(stat -c '%g' "$path" 2>/dev/null || echo '?')"
  if [ "$owner" = "$uid" ] && [ "$group" = "$gid" ]; then
    ok "$label con propietario $uid:$gid"
  elif [ "$(id -u)" -eq 0 ]; then
    warn "$label ($path) pertenece a $owner:$group; deploy.sh lo reajustará a $uid:$gid"
  else
    error "$label ($path) pertenece a $owner:$group; se espera $uid:$gid (los contenedores no corren como root)"
  fi
}

check_owner "$ROOT/data/mosquitto/config" "$MOSQUITTO_UID" "$MOSQUITTO_GID" "data/mosquitto/config"
check_owner "$ROOT/data/mosquitto/data" "$MOSQUITTO_UID" "$MOSQUITTO_GID" "data/mosquitto/data"
check_owner "$ROOT/data/zigbee2mqtt" "$Z2M_UID" "$Z2M_GID" "data/zigbee2mqtt"

PASSWD_FILE="$ROOT/data/mosquitto/config/passwd"
if [ ! -f "$PASSWD_FILE" ]; then
  warn "no existe data/mosquitto/config/passwd; provisiona las credenciales MQTT antes de deploy.sh"
else
  check_owner "$PASSWD_FILE" "$MOSQUITTO_UID" "$MOSQUITTO_GID" "data/mosquitto/config/passwd"
  PASSWD_MODE="$(stat -c '%a' "$PASSWD_FILE" 2>/dev/null || echo '?')"
  case "$PASSWD_MODE" in
    [4-7][0-7][0-7]) ok "passwd concede lectura a su propietario (modo $PASSWD_MODE)" ;;
    *)
      if [ "$(id -u)" -eq 0 ]; then
        warn "passwd no concede lectura a su propietario (modo $PASSWD_MODE); deploy.sh lo reajustará a 0400 $MOSQUITTO_UID:$MOSQUITTO_GID"
      else
        error "passwd no concede lectura a su propietario (modo $PASSWD_MODE); se espera 0400 con propietario $MOSQUITTO_UID:$MOSQUITTO_GID"
      fi
      ;;
  esac
fi

if [ "$(id -u)" -eq 0 ]; then
  ok "preflight corre como root: deploy.sh podrá crear/ajustar los propietarios de data/"
else
  warn "preflight no corre como root: deploy.sh exigirá que data/ y passwd ya tengan propietario $MOSQUITTO_UID:$MOSQUITTO_GID / $Z2M_UID:$Z2M_GID; precrea los directorios o ejecuta el despliegue como root"
fi

warn "por defecto los puertos se publican solo en 127.0.0.1; no abras la UI a Internet sin TLS/ACL y firewall"
warn "adapter=ember, baudrate=115200 y rtscts=false son valores iniciales a validar con el hardware real"

echo
if [ "$ERRORS" -gt 0 ]; then
  echo "PREFLIGHT FALLIDO: $ERRORS error(es), $WARNINGS aviso(s). No se ha modificado nada." >&2
  exit 1
fi
echo "PREFLIGHT OK: $WARNINGS aviso(s). No se ha modificado nada."
