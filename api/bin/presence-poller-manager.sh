#!/usr/bin/env bash
# =============================================================================
# presence-poller-manager.sh — Supervisor multi-sensor del poller de presencia.
#
# Descubre TODOS los dispositivos `devices.kind='PRESENCE'` y mantiene vivo un
# proceso `tuya-presence-poller.js <external_id>` por cada sensor. Así el poller
# sigue automáticamente al sensor asignado a cada pack, sin device ids
# hardcodeados en el código (F43).
#
# Reconciliación cada RECONCILE_S segundos:
#   - sensor nuevo en BD          → arranca su poller
#   - sensor eliminado de BD       → detiene su poller
#   - poller caído                 → lo relanza
#
# Uso:
#   nohup bash /root/cerraduras/api/bin/presence-poller-manager.sh \
#         >> /root/cerraduras/api/logs/presence-poller.log 2>&1 &
#
# Logs por sensor: api/logs/presence-poller-<external_id>.log
# =============================================================================
set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
API_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$API_DIR/.env"
LOG_DIR="$API_DIR/logs"
MANAGER_LOG="$LOG_DIR/presence-poller.log"
POLLER_JS="$SCRIPT_DIR/tuya-presence-poller.js"
RECONCILE_S="${RECONCILE_S:-30}"

mkdir -p "$LOG_DIR"

# ── Read a single key from .env (never `source` it: contains non-shell values) ──
env_get() {
  [ -f "$ENV_FILE" ] || return 0
  grep -E "^[[:space:]]*$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^["'"'"']//' -e 's/["'"'"']$//'
}

DB_HOST="$(env_get DB_HOST)";  DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_get DB_PORT)";  DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(env_get DB_NAME)";  DB_NAME="${DB_NAME:-cerraduras_db}"
DB_USER="$(env_get DB_USER)";  DB_USER="${DB_USER:-cerraduras_user}"
DB_PASS="$(env_get DB_PASS)"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ── Discover PRESENCE external_ids from the DB (canonical device → pack) ──
# Devuelve los external_id PRESENCE, uno por línea. Sale con código != 0 si la
# consulta a la BD falla (para NO confundir "error" con "lista vacía": un fallo
# transitorio no debe provocar el reap de todos los pollers — F46).
discover_sensors() {
  MYSQL_PWD="$DB_PASS" mysql \
    -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -N -s \
    -e "SELECT external_id FROM devices WHERE kind='PRESENCE' ORDER BY id" 2>/dev/null
}

# ── Is a poller already running for this device id? (argv-carried id) ──
poller_pid_for() {
  pgrep -f "tuya-presence-poller\.js $1(\$| )" 2>/dev/null | head -1
}

# ── Any running poller whose device id is no longer assigned → stop it ──
reap_removed() {
  local pids args pid dev line
  pids="$(pgrep -f "tuya-presence-poller\.js " 2>/dev/null || true)"
  for pid in $pids; do
    line="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"
    case "$line" in
      *tuya-presence-poller.js*) ;;
      *) continue ;;
    esac
    dev="${line##*tuya-presence-poller.js }"
    dev="${dev%% *}"
    [ -z "$dev" ] && continue
    if ! printf '%s\n' "$ASSIGNED" | grep -qxF "$dev"; then
      log "🛑 sensor $dev ya no está asignado — deteniendo poller (pid $pid)"
      kill "$pid" 2>/dev/null || true
    fi
  done
}

log "═══ Presence poller manager started (reconcile ${RECONCILE_S}s) ═══"

while true; do
  if ! ASSIGNED="$(discover_sensors)"; then
    # Error de BD: no tocar los pollers vivos (evita matarlos por un fallo
    # transitorio que devuelve lista vacía).
    log "⚠ error consultando la BD — se omite el reap de este ciclo"
    sleep "$RECONCILE_S"
    continue
  fi
  if [ -z "$ASSIGNED" ]; then
    log "⚠ no hay dispositivos PRESENCE en la BD"
  fi

  # Start missing pollers
  while IFS= read -r dev; do
    [ -z "$dev" ] && continue
    if [ -n "$(poller_pid_for "$dev")" ]; then
      continue
    fi
    log "▶ arrancando poller para PRESENCE $dev"
    nohup env PRESENCE_DEVICE_ID="$dev" node "$POLLER_JS" "$dev" \
      >> "$LOG_DIR/presence-poller-$dev.log" 2>&1 &
  done <<EOF
$ASSIGNED
EOF

  reap_removed

  sleep "$RECONCILE_S"
done
