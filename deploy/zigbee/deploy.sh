#!/usr/bin/env bash
# Despliegue idempotente del proyecto aislado. Solo toca deploy/zigbee y su proyecto Compose.
set -eu
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$ROOT/.env"

usage() {
  cat <<'USAGE'
Uso: deploy.sh [--env-file RUTA]

Valida .env y plantillas, ejecuta preflight, crea directorios de datos del proyecto
con permisos restrictivos y levanta únicamente este proyecto Compose.
No sobrescribe configuración ni secretos existentes y no imprime secretos.
USAGE
}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

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

[ -f "$ENV_FILE" ] || die "no existe $ENV_FILE. Copia .env.example a .env y fija los valores."
load_env

for var in COMPOSE_PROJECT_NAME MOSQUITTO_IMAGE Z2M_IMAGE MQTT_HOST_PORT Z2M_UI_HOST_PORT \
           MQTT_USERNAME MQTT_PASSWORD ZIGBEE_ADAPTER_HOST ZIGBEE_ADAPTER_PORT \
           MOSQUITTO_UID MOSQUITTO_GID Z2M_UID Z2M_GID; do
  [ -n "$(value_of "$var")" ] || die "falta la variable obligatoria $var"
done

# Los contenedores corren sin privilegios: 0/root no es un valor válido.
validate_nonroot_id() {
  local name="$1" value
  value="$(value_of "$name")"
  case "$value" in
    ''|*[!0-9]*) die "$name debe ser un UID/GID numérico (valor: '$value')" ;;
  esac
  [ "$value" -ne 0 ] || die "$name no puede ser 0: los contenedores no deben correr como root"
}
for var in MOSQUITTO_UID MOSQUITTO_GID Z2M_UID Z2M_GID; do
  validate_nonroot_id "$var"
done

PROJECT="$(value_of COMPOSE_PROJECT_NAME)"
case "$PROJECT" in
  *[!a-z0-9_-]*|[!a-z0-9]*) die "COMPOSE_PROJECT_NAME inválido: usa minúsculas, números, guion y guion bajo" ;;
esac
for pair in "MQTT_HOST_PORT:${MQTT_HOST_PORT}" "Z2M_UI_HOST_PORT:${Z2M_UI_HOST_PORT}" \
            "ZIGBEE_ADAPTER_PORT:${ZIGBEE_ADAPTER_PORT}"; do
  name="${pair%%:*}"
  value="${pair#*:}"
  case "$value" in
    ''|*[!0-9]*) die "$name debe ser un puerto numérico" ;;
  esac
  [ "$value" -ge 1 ] && [ "$value" -le 65535 ] || die "$name fuera de rango 1-65535"
done
[ "$MQTT_HOST_PORT" != "$Z2M_UI_HOST_PORT" ] || die "MQTT_HOST_PORT y Z2M_UI_HOST_PORT deben ser distintos"

for var in MOSQUITTO_IMAGE Z2M_IMAGE; do
  value="$(value_of "$var")"
  case "$value" in
    *:latest) die "$var no puede usar latest; fija un tag o digest" ;;
    *__REQUIRED_*|*CHANGE_ME*) die "$var sigue siendo un placeholder" ;;
    *@sha256:*) : ;;
    *:*) : ;;
    *) die "$var debe incluir un tag o digest fijo" ;;
  esac
done

is_placeholder "$MQTT_PASSWORD" && die "MQTT_PASSWORD sigue siendo el placeholder; provisiona un secreto real fuera de Git"
is_placeholder "$ZIGBEE_ADAPTER_HOST" && die "ZIGBEE_ADAPTER_HOST sigue siendo el placeholder"
case "$ZIGBEE_ADAPTER_HOST" in
  *[!A-Za-z0-9._-]*) die "ZIGBEE_ADAPTER_HOST contiene caracteres no permitidos (hostname o IPv4)" ;;
esac

for file in docker-compose.yml mosquitto/mosquitto.conf.example zigbee2mqtt/configuration.yaml.example; do
  [ -f "$ROOT/$file" ] || die "falta la plantilla requerida: $file"
done

# Crea (como root) o valida (sin root) un directorio de datos para un UID/GID
# no-root. Sin root NUNCA se intenta chown: se aborta con instrucciones.
ensure_data_dir() {
  local dir="$1" uid="$2" gid="$3" owner group
  if [ "$(id -u)" -eq 0 ]; then
    install -d -m 0700 -o "$uid" -g "$gid" "$dir"
    # install -d no garantiza reajustar un directorio ya existente.
    chown "$uid:$gid" "$dir"
    chmod 0700 "$dir"
    return 0
  fi
  [ -d "$dir" ] || die "no existe $dir y deploy.sh no corre como root. Crea $dir con propietario $uid:$gid y modo 0700 antes de desplegar; no se usa elevación automática."
  owner="$(stat -c '%u' "$dir" 2>/dev/null)" || die "no se puede leer el propietario de $dir"
  group="$(stat -c '%g' "$dir" 2>/dev/null)" || die "no se puede leer el grupo de $dir"
  if [ "$owner" != "$uid" ] || [ "$group" != "$gid" ]; then
    die "$dir pertenece a $owner:$group; se espera $uid:$gid. Ejecuta deploy.sh como root una vez o reasigna el propietario manualmente como administrador del host."
  fi
}

# El secreto debe poder leerlo el UID de Mosquitto, no root.
ensure_passwd_owned() {
  local file="$1" owner group mode
  if [ "$(id -u)" -eq 0 ]; then
    chown "$MOSQUITTO_UID:$MOSQUITTO_GID" "$file"
    chmod 0400 "$file"
    return 0
  fi
  owner="$(stat -c '%u' "$file" 2>/dev/null)" || die "no se puede leer el propietario de $file"
  group="$(stat -c '%g' "$file" 2>/dev/null)" || die "no se puede leer el grupo de $file"
  mode="$(stat -c '%a' "$file" 2>/dev/null)" || die "no se puede leer el modo de $file"
  if [ "$owner" != "$MOSQUITTO_UID" ] || [ "$group" != "$MOSQUITTO_GID" ]; then
    die "$file pertenece a $owner:$group; Mosquitto ($MOSQUITTO_UID:$MOSQUITTO_GID) no podrá leerlo. Ejecuta deploy.sh como root una vez o ajusta el propietario manualmente."
  fi
  case "$mode" in
    [4-7][0-7][0-7]) : ;;
    *) die "$file no concede lectura al propietario (modo $mode); se requiere 0400 y propietario $MOSQUITTO_UID:$MOSQUITTO_GID" ;;
  esac
}

# Zigbee2MQTT reescribe su configuration.yaml: debe pertenecer a su UID/GID.
ensure_z2m_config_owned() {
  local file="$1" owner group mode
  if [ "$(id -u)" -eq 0 ]; then
    chown "$Z2M_UID:$Z2M_GID" "$file"
    chmod 0600 "$file"
    return 0
  fi
  owner="$(stat -c '%u' "$file" 2>/dev/null)" || die "no se puede leer el propietario de $file"
  group="$(stat -c '%g' "$file" 2>/dev/null)" || die "no se puede leer el grupo de $file"
  mode="$(stat -c '%a' "$file" 2>/dev/null)" || die "no se puede leer el modo de $file"
  if [ "$owner" != "$Z2M_UID" ] || [ "$group" != "$Z2M_GID" ]; then
    die "$file pertenece a $owner:$group; Zigbee2MQTT ($Z2M_UID:$Z2M_GID) no podrá escribirlo. Ejecuta deploy.sh como root una vez o ajusta el propietario manualmente."
  fi
  case "$mode" in
    [2-7][0-7][0-7]) : ;;
    *) die "$file no concede escritura al propietario (modo $mode); se requiere 0600 y propietario $Z2M_UID:$Z2M_GID" ;;
  esac
}

echo "Ejecutando preflight (solo lectura)..."
"$ROOT/preflight.sh" --env-file "$ENV_FILE"

umask 077
ensure_data_dir "$ROOT/data/mosquitto/config" "$MOSQUITTO_UID" "$MOSQUITTO_GID"
ensure_data_dir "$ROOT/data/mosquitto/data" "$MOSQUITTO_UID" "$MOSQUITTO_GID"
ensure_data_dir "$ROOT/data/zigbee2mqtt" "$Z2M_UID" "$Z2M_GID"

PASSWD_FILE="$ROOT/data/mosquitto/config/passwd"
if [ ! -f "$PASSWD_FILE" ]; then
  echo "ERROR: falta $PASSWD_FILE." >&2
  echo "Provisiona la contraseña MQTT FUERA de Git y vuelve a ejecutar deploy.sh:" >&2
  echo "  docker run --rm -it --entrypoint mosquitto_passwd \\" >&2
  echo "    -v \"$ROOT/data/mosquitto/config:/mosquitto/config\" \\" >&2
  echo "    \"$MOSQUITTO_IMAGE\" -c /mosquitto/config/passwd \"$MQTT_USERNAME\"" >&2
  echo "Después, ejecuta deploy.sh como root para fijar propietario $MOSQUITTO_UID:$MOSQUITTO_GID y modo 0400," >&2
  echo "o ajusta manualmente el propietario y permisos como administrador del host." >&2
  echo "El fichero no se crea ni se sobrescribe automáticamente." >&2
  exit 1
fi
ensure_passwd_owned "$PASSWD_FILE"

MOSQ_CONF="$ROOT/data/mosquitto/config/mosquitto.conf"
if [ ! -e "$MOSQ_CONF" ]; then
  install -m 0644 "$ROOT/mosquitto/mosquitto.conf.example" "$MOSQ_CONF"
  echo "Configuración Mosquitto inicial creada (no se sobrescribe en futuras ejecuciones)."
else
  echo "Configuración Mosquitto existente conservada."
fi

Z2M_CONF="$ROOT/data/zigbee2mqtt/configuration.yaml"
if [ ! -e "$Z2M_CONF" ]; then
  sed \
    -e "s|\${ZIGBEE_ADAPTER_HOST}|$ZIGBEE_ADAPTER_HOST|g" \
    -e "s|\${ZIGBEE_ADAPTER_PORT}|$ZIGBEE_ADAPTER_PORT|g" \
    "$ROOT/zigbee2mqtt/configuration.yaml.example" > "$Z2M_CONF"
  chmod 0600 "$Z2M_CONF"
  echo "Configuración Zigbee2MQTT inicial renderizada (no se sobrescribe)."
else
  echo "Configuración Zigbee2MQTT existente conservada."
fi
ensure_z2m_config_owned "$Z2M_CONF"

echo "Levantando el proyecto Compose '$PROJECT' (solo este proyecto)..."
docker compose \
  --env-file "$ENV_FILE" \
  --project-directory "$ROOT" \
  -p "$PROJECT" \
  -f "$ROOT/docker-compose.yml" \
  up -d

echo
echo "Desplegado el proyecto '$PROJECT'. Siguiente paso: ./verify.sh --env-file \"$ENV_FILE\""
echo "No se ha tocado ningún otro proyecto, servicio, red ni firewall."
