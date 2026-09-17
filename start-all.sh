#!/usr/bin/env bash
# =============================================================================
# start-all.sh — Arranca API + WS-VB6 + workers de fondo (instancia única)
#
# Uso:
#   bash /root/cerraduras/start-all.sh
#
# Requisitos (solo una vez):
#   sudo ufw allow 8080/tcp
#   sudo ufw allow 8081/tcp
#
# URLs:
#   API:            http://92.113.151.136:8080/api/v1/health
#   WS-VB6:         http://92.113.151.136:8081/ws-vb6/v1/health
#   Dashboard:      http://92.113.151.136:8080/dashboard?room=1
#
# Fase 41 / TSK-F41-16:
#   - Llama primero a stop-all.sh (parada determinista) para garantizar que NO
#     quedan wrappers huérfanos ni instancias duplicadas.
#   - Cada worker corre en UN wrapper supervisado (`setsid`) que escribe su PID
#     (= PGID) en api/run/<worker>.pid. Si el PID ya vive, no se relanza.
#   - Incluye anomaly-scanner y overstay-scan.
#   - La parada se documenta en stop-all.sh (nunca toca `php -S`).
# =============================================================================
set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
API_DIR="$SCRIPT_DIR/api"
WS_DIR="$SCRIPT_DIR/ws-vb6"
LOG_DIR="$API_DIR/logs"
RUN_DIR="$API_DIR/run"
mkdir -p "$LOG_DIR" "$RUN_DIR"

# F47 (RF-55): fuente única del pool de workers. DEBE coincidir con los units
# systemd; si diverge, el fallback manual reintroduce la degradación SSE de F46
# (pool agotado por SSE zombie → panel a polling lento).
#   cerraduras-api.service  → PHP_CLI_SERVER_WORKERS=16
#   cerraduras-wsvb6.service → PHP_CLI_SERVER_WORKERS=8
API_WORKERS=16
WSVB6_WORKERS=8
API_FALLBACK_PID=""

echo "=== Cerraduras Hotel — Start All ==="

# ── PID helpers (instancia única) ────────────────────────────────────────────
pid_alive() { [ -n "${1:-}" ] && kill -0 "$1" 2>/dev/null; }

pid_running() {
  local pf="$1" marker="$2" pid
  [ -f "$pf" ] || return 1
  pid="$(cat "$pf" 2>/dev/null || true)"
  [ -n "$pid" ] || return 1
  pid_alive "$pid" || { rm -f "$pf"; return 1; }
  # El PID debe ser realmente nuestro wrapper (evita reutilización de PID).
  grep -qa -- "$marker" "/proc/$pid/cmdline" 2>/dev/null || return 1
  return 0
}

worker_pid() { cat "$RUN_DIR/$1.pid" 2>/dev/null || true; }

# ── Lanza un worker dentro de UN wrapper supervisado con PID file ────────────
#   launch <nombre> <comando> <tick_seg> <logfile>
#   El wrapper corre con cwd=API_DIR, así que las rutas pueden ser relativas.
launch() {
  local name="$1" cmd="$2" tick="$3" logfile="$4"
  local pf="$RUN_DIR/$name.pid"
  if pid_running "$pf" "$name"; then
    echo "       $name: ya corriendo (pid $(worker_pid "$name")) — skip"
    return 0
  fi
  # setsid → nueva sesión; $$ == PID == PGID. El trap permite parada limpia.
  setsid bash -c "echo \$\$ > '$pf'; trap 'exit 0' TERM INT; while true; do $cmd >> '$logfile' 2>&1; sleep $tick; done" >/dev/null 2>&1 &
  sleep 0.3
  echo "       $name: pid $(worker_pid "$name")"
}

echo "[1/8] Parada determinista previa (stop-all.sh)..."
if [ -f "$SCRIPT_DIR/stop-all.sh" ]; then
  bash "$SCRIPT_DIR/stop-all.sh" || echo "       (stop-all.sh reportó residuales — revisar)"
else
  echo "       (stop-all.sh no encontrado — se omite la parada determinista)"
fi

echo "[2/8] API + WS-VB6 via systemd..."
systemctl restart cerraduras-api cerraduras-wsvb6 2>/dev/null || {
  # Fallback: systemd units not installed — launch manually.
  # F47 (RF-55.1.2): mismos valores que los units (API=$API_WORKERS, WS-VB6=$WSVB6_WORKERS).
  echo "       (systemd units missing — starting manually; pool API=$API_WORKERS, WS-VB6=$WSVB6_WORKERS)"
  cd "$API_DIR"
  mkdir -p /tmp/opcache-cache
  PHP_CLI_SERVER_WORKERS=$API_WORKERS php -d opcache.file_cache=/tmp/opcache-cache -d opcache.file_cache_only=1 -S 0.0.0.0:8080 -t public public/index.php >> logs/php-server.log 2>&1 &
  API_FALLBACK_PID="$!"
  echo "       API PID: $API_FALLBACK_PID"
  cd "$WS_DIR"
  PHP_CLI_SERVER_WORKERS=$WSVB6_WORKERS php -d opcache.file_cache=/tmp/opcache-cache -d opcache.file_cache_only=1 -S 0.0.0.0:8081 -t public public/index.php >> logs/php-server.log 2>&1 &
  echo "       WS-VB6 PID: $!"
}

cd "$API_DIR"

echo "[3/8] Tuya Pulsar consumer (systemd — instancia única, F44)..."
# F44 (RF-50.2): el consumer Pulsar tiene UN único dueño: el unit systemd
# `cerraduras-pulsar-consumer.service`. NO se lanza un wrapper aquí para no
# duplicar la conexión Reader ni reenviar cada evento dos veces al webhook.
if systemctl restart cerraduras-pulsar-consumer 2>/dev/null; then
  echo "       tuya-pulsar-consumer: systemd (pid $(systemctl show -p MainPID --value cerraduras-pulsar-consumer 2>/dev/null))"
else
  echo "       (unit cerraduras-pulsar-consumer ausente — NO se lanza wrapper: evita duplicado)"
fi

echo "[4/8] Tuya Presence Poller manager (systemd — instancia única)..."
# F46: el poller de presencia pasa a systemd (Restart=always). Antes era un
# wrapper setsid sin supervisión: si moría, la presencia dejaba de muestrearse
# en silencio (el panel "no detectaba" al huésped).
if systemctl restart cerraduras-presence-poller 2>/dev/null; then
  echo "       presence-poller: systemd (pid $(systemctl show -p MainPID --value cerraduras-presence-poller 2>/dev/null))"
else
  echo "       (unit cerraduras-presence-poller ausente — wrapper de respaldo)"
  launch "presence-poller-manager" "bash bin/presence-poller-manager.sh" 60 "$LOG_DIR/presence-poller.log"
fi

echo "[5/8] Workers de fondo (systemd: exit-scan, overstay-scan, outbox-worker, anomaly-scanner)..."
if systemctl restart cerraduras-worker@exit-scan cerraduras-worker@overstay-scan \
     cerraduras-worker@outbox-worker cerraduras-worker@anomaly-scanner 2>/dev/null; then
  echo "       workers: systemd arrancados (Restart=always)"
else
  echo "       (units cerraduras-worker@ ausentes — wrappers de respaldo)"
  launch "exit-scan" "php bin/exit-scan.php" 2 "$LOG_DIR/exit-scan.log"
  launch "overstay-scan" "php bin/overstay-scan.php" 60 "$LOG_DIR/overstay-scan.log"
  launch "outbox-worker" "php bin/outbox-worker.php" 30 "$LOG_DIR/outbox-worker.log"
  launch "anomaly-scanner" "php bin/anomaly-scanner.php" 5 "$LOG_DIR/anomaly-scanner.log"
fi

sleep 2

echo "Health checks:"
echo -n "  API:     "
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/api/v1/health && echo " OK" || echo " FAIL"
echo -n "  WS-VB6:  "
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8081/ws-vb6/v1/health && echo " OK" || echo " FAIL"
echo -n "  Dashboard: "
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/dashboard?room=1 && echo " OK" || echo " FAIL"

# F47 (RF-55.2.1): verificación del pool real del API. Cuenta los hijos del
# proceso maestro (systemd MainPID, o el PID del fallback manual). El maestro
# PHP_CLI_SERVER_WORKERS=16 forka exactamente 16 workers.
echo -n "  Pool API: "
api_main="$(systemctl show -p MainPID --value cerraduras-api 2>/dev/null || true)"
if [ -z "$api_main" ] || [ "$api_main" = "0" ]; then
  api_main="$API_FALLBACK_PID"
fi
if [ -n "$api_main" ] && [ "$api_main" != "0" ]; then
  api_pool="$(pgrep -P "$api_main" 2>/dev/null | wc -l | tr -d ' ')"
else
  api_pool="?"
fi
if [ "$api_pool" = "$API_WORKERS" ]; then
  echo "$api_pool/$API_WORKERS OK"
else
  echo "AVISO: pool API=$api_pool (esperado $API_WORKERS) — revisa cerraduras-api.service (F46/F47)"
fi

echo ""
echo "=== Done ==="
echo "  API:            http://92.113.151.136:8080/api/v1/health"
echo "  Dashboard:      http://92.113.151.136:8080/dashboard?room=1"
echo "  WS-VB6:         http://92.113.151.136:8081/ws-vb6/v1/health"
echo ""
echo "Para parar (determinista, garantiza 0 huérfanos):"
echo "  bash $SCRIPT_DIR/stop-all.sh"
