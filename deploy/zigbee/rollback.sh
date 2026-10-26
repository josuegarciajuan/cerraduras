#!/usr/bin/env bash
# Detiene y elimina SOLO los contenedores/red del proyecto, conservando datos.
set -eu
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$ROOT/.env"
ASSUME_YES=false

usage() {
  cat <<'USAGE'
Uso: rollback.sh [--yes] [--env-file RUTA]

Ejecuta el down del proyecto Compose indicado en .env. Conserva volúmenes,
bind mounts y datos; la eliminación de datos no forma parte de este comando.
USAGE
}

while [ $# -gt 0 ]; do
  case "$1" in
    --yes) ASSUME_YES=true; shift ;;
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

[ -f "$ENV_FILE" ] || { echo "ERROR: no existe $ENV_FILE" >&2; exit 1; }
load_env
PROJECT="${COMPOSE_PROJECT_NAME:?falta COMPOSE_PROJECT_NAME}"

if [ "$ASSUME_YES" != true ]; then
  printf "Se parará el proyecto '%s' conservando todos los datos. Escribe ROLLBACK para confirmar: " "$PROJECT"
  read -r answer
  [ "$answer" = "ROLLBACK" ] || { echo "Cancelado." >&2; exit 1; }
fi

docker compose \
  --env-file "$ENV_FILE" \
  --project-directory "$ROOT" \
  -p "$PROJECT" \
  -f "$ROOT/docker-compose.yml" \
  down

echo "Proyecto '$PROJECT' detenido. Los directorios data/ y los volúmenes se conservan."
