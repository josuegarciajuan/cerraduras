#!/usr/bin/env bash
# =============================================================================
# stop-all.sh — Parada determinista y acotada de workers (Fase 41 / TSK-F41-16)
#
# Trazabilidad: RF-48.1, RF-48.4 · design.md §7.1
#
# Problema que resuelve:
#   start-all.sh arrancaba cada worker con `bash -c "while true; do php bin/..."`.
#   El antiguo `pkill -f "php.*bin/exit-scan.php"` mataba al hijo `php` pero NO al
#   wrapper `while true`, que lo relanzaba; y cada reinicio añadía otro wrapper.
#   Resultado: 9 `exit-scan.php` huérfanos (PPID 1).
#
# Orden de parada (importante):
#   1) Wrappers/supervisores primero  → evita que relancen a sus hijos.
#      - wrappers `bash -c ... bin/<worker>.php` (exit-scan, overstay-scan,
#        outbox-worker, anomaly-scanner, tuya-pulsar-consumer)
#      - `bin/presence-poller-manager.sh` (supervisor de pollers de presencia)
#   2) Hijos después → procesos `php bin/<worker>.php`, `node ...tuya-presence-poller.js`
#      y el `node ...bin/tuya-pulsar-consumer/index.js` del consumer Pulsar
#      (por patrón y, para huérfanos antiguos, por cwd `.../bin/tuya-pulsar-consumer`).
#   3) Escalado acotado TERM → espera (STOP_WAIT_SECS) → KILL.
#   4) Borrado de PID files en api/run/.
#
# LÍMITES DE SEGURIDAD:
#   - TODOS los patrones están acotados a `bin/` y a nombres de worker del proyecto.
#   - NUNCA se toca el servidor PHP (`php -S ...`) ni `node .*index.js` genérico.
#   - El node del consumer Pulsar se acota además por su cwd real (readlink /proc).
#
# Uso:
#   bash /root/cerraduras/stop-all.sh
#   STOP_WAIT_SECS=10 bash /root/cerraduras/stop-all.sh   # espera más antes de KILL
# =============================================================================
set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
API_DIR="$SCRIPT_DIR/api"
RUN_DIR="$API_DIR/run"
WAIT_SECS="${STOP_WAIT_SECS:-6}"

log() { echo "[stop-all] $*"; }

# ── Workers gestionados (nombre = nombre del PID file en api/run) ─────────────
# F44 (RF-50.2): `tuya-pulsar-consumer` ya NO se gestiona aquí con PID file; su
# único dueño es el unit systemd `cerraduras-pulsar-consumer.service`.
WORKERS="exit-scan overstay-scan outbox-worker anomaly-scanner presence-poller-manager"

# ── Patrón 1: wrappers `bash -c ... bin/<worker>` (mueren PRIMERO) ────────────
#    Comentario por patrón: cada uno acota al wrapper de UN worker concreto.
WRAPPER_PATTERNS=(
  'bash -c .*bin/exit-scan\.php'          # wrapper while-true de exit-scan
  'bash -c .*bin/overstay-scan\.php'      # wrapper while-true de overstay-scan
  'bash -c .*bin/outbox-worker\.php'      # wrapper while-true de outbox-worker
  'bash -c .*bin/anomaly-scanner\.php'    # wrapper while-true de anomaly-scanner
  'bash -c .*bin/tuya-pulsar-consumer'    # wrapper while-true del consumer Pulsar
  'bin/presence-poller-manager\.sh'       # supervisor de pollers (relanza pollers)
)

# ── Patrón 2: hijos directos (mueren DESPUÉS de los wrappers) ────────────────
#    Comentario por patrón: `php`/`node` + ruta relativa o absoluta bajo `bin/`.
#    El `node .*bin/tuya-pulsar-consumer` solo limpia wrappers LEGADO (invocación
#    con ruta relativa); el proceso systemd usa `index.js` y NO coincide aquí.
CHILD_PATTERNS=(
  'php .*bin/exit-scan\.php'              # hijo php de exit-scan
  'php .*bin/overstay-scan\.php'          # hijo php de overstay-scan
  'php .*bin/outbox-worker\.php'          # hijo php de outbox-worker
  'php .*bin/anomaly-scanner\.php'        # hijo php de anomaly-scanner
  'node .*bin/tuya-presence-poller\.js'   # pollers de presencia (hijos del manager)
  'node .*bin/tuya-pulsar-consumer'       # consumer LEGADO con ruta relativa
)

pid_alive() { [ -n "${1:-}" ] && kill -0 "$1" 2>/dev/null; }

# ── ¿Hay un worker vivo detrás de este PID file? (PID vivo + cmdline esperado) ─
pid_running() {
  local pf="$1" marker="$2" pid
  [ -f "$pf" ] || return 1
  pid="$(cat "$pf" 2>/dev/null || true)"
  [ -n "$pid" ] || return 1
  pid_alive "$pid" || { rm -f "$pf"; return 1; }
  # Verifica que el PID sea realmente nuestro wrapper (no un PID reutilizado).
  grep -qa -- "$marker" "/proc/$pid/cmdline" 2>/dev/null || return 1
  return 0
}

# ── Para un worker por PID file: mata su grupo de proceso (setsid → PGID=PID) ─
stop_by_pidfile() {
  local name="$1" pf="$RUN_DIR/$name.pid" pid
  [ -f "$pf" ] || return 0
  pid="$(cat "$pf" 2>/dev/null || true)"
  if [ -n "$pid" ] && pid_alive "$pid"; then
    log "deteniendo $name (pid/grupo $pid)"
    # Matar el grupo entero lleva por delante wrapper + hijos del mismo setsid.
    kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
  fi
}

# ── Barrido por patrón acotado ────────────────────────────────────────────────
sweep_patterns() {
  local sig="$1"; shift
  local pat
  for pat in "$@"; do
    pkill -"$sig" -f "$pat" 2>/dev/null || true
  done
}

# ── Consumer Pulsar (F44): vive bajo systemd, no se mata por patrón/cwd ───────
#    Se detiene con `systemctl stop` (abajo) para no confundirlo con huérfanos.
stop_pulsar_service() {
  systemctl stop cerraduras-pulsar-consumer 2>/dev/null || true
}

# ── PIDs que aún quedan (wrappers + hijos legacy) ────────────────────────────
remaining_pids() {
  local pat
  for pat in "${WRAPPER_PATTERNS[@]}" "${CHILD_PATTERNS[@]}"; do
    pgrep -f "$pat" 2>/dev/null || true
  done
}

echo "=== Cerraduras Hotel — Stop All (workers) ==="
log "API_DIR=$API_DIR  RUN_DIR=$RUN_DIR"

# 0) Consumer Pulsar (systemd, dueño único F44) ───────────────────────────────
stop_pulsar_service

# 0b) Workers de fondo bajo systemd (F46) — parada por SERVICIO, no por patrón.
#     Si se matan por patrón con Restart=always, systemd los relanzaría.
systemctl stop cerraduras-presence-poller 2>/dev/null || true
for _w in exit-scan overstay-scan outbox-worker anomaly-scanner; do
  systemctl stop "cerraduras-worker@$_w" 2>/dev/null || true
done

# 1) Wrappers/supervisores por PID file (grupo de proceso) ────────────────────
for name in $WORKERS; do
  stop_by_pidfile "$name"
done
# ...y también wrappers heredados sin PID file (p.ej. huérfanos de PPID 1).
sweep_patterns TERM "${WRAPPER_PATTERNS[@]}"

# 2) Hijos ────────────────────────────────────────────────────────────────────
sweep_patterns TERM "${CHILD_PATTERNS[@]}"

# 3) Espera acotada; si persisten, KILL ──────────────────────────────────────
deadline=$((SECONDS + WAIT_SECS))
while [ "$SECONDS" -lt "$deadline" ]; do
  [ -z "$(remaining_pids)" ] && break
  sleep 1
done

left="$(remaining_pids)"
if [ -n "$left" ]; then
  log "quedan procesos tras ${WAIT_SECS}s — escalando a KILL"
  sweep_patterns KILL "${WRAPPER_PATTERNS[@]}"
  sweep_patterns KILL "${CHILD_PATTERNS[@]}"
  sleep 1
fi

# 4) Limpieza de PID files ────────────────────────────────────────────────────
for name in $WORKERS; do
  rm -f "$RUN_DIR/$name.pid"
done
# F44: PID file legado del consumer Pulsar (ahora gestionado por systemd).
rm -f "$RUN_DIR/tuya-pulsar-consumer.pid"

left="$(remaining_pids)"
if [ -n "$left" ]; then
  log "ADVERTENCIA: aún quedan procesos:"
  for p in $left; do
    log "  pid $p: $(tr '\0' ' ' < "/proc/$p/cmdline" 2>/dev/null || true)"
  done
  exit 1
fi

log "workers detenidos — 0 procesos residuales"
