#!/usr/bin/env bash
# =============================================================================
# start-all.sh — Arranca API + WS-VB6 + Tuya Pulsar consumer
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
# =============================================================================
set -e

echo "=== Cerraduras Hotel — Start All ==="

echo "[1/8] Stopping old..."
pkill -f "node.*index.js" 2>/dev/null || true
pkill -f "node.*tuya-presence-poller" 2>/dev/null || true
pkill -f "presence-poller-manager" 2>/dev/null || true
pkill -f "php.*bin/exit-scan.php" 2>/dev/null || true
pkill -f "php.*bin/overstay-scan.php" 2>/dev/null || true
pkill -f "php.*bin/anomaly-scanner.php" 2>/dev/null || true
sleep 1

echo "[2/8] API + WS-VB6 via systemd..."
systemctl restart cerraduras-api cerraduras-wsvb6 2>/dev/null || {
  # Fallback: systemd units not installed — launch manually
  echo "       (systemd units missing — starting manually)"
  cd /root/cerraduras/api
  mkdir -p /tmp/opcache-cache
  PHP_CLI_SERVER_WORKERS=8 php -d opcache.file_cache=/tmp/opcache-cache -d opcache.file_cache_only=1 -S 0.0.0.0:8080 -t public public/index.php >> logs/php-server.log 2>&1 &
  echo "       API PID: $!"
  cd /root/cerraduras/ws-vb6
  PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:8081 -t public public/index.php >> logs/php-server.log 2>&1 &
  echo "       WS-VB6 PID: $!"
}

echo "[3/8] Tuya Pulsar consumer..."
cd /root/cerraduras/api/bin/tuya-pulsar-consumer
nohup bash -c "while true; do
  echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] Starting Tuya Pulsar consumer...\" >> /root/cerraduras/api/logs/pulsar-consumer.log
  node index.js >> /root/cerraduras/api/logs/pulsar-consumer.log 2>&1
  echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] Consumer exited — restarting in 60s...\" >> /root/cerraduras/api/logs/pulsar-consumer.log
  sleep 60
done" > /dev/null 2>&1 &
echo "       PID: $!"

echo "[4/8] Tuya Presence Poller manager (multi-sensor, pack-aware)..."
cd /root/cerraduras/api
nohup bash bin/presence-poller-manager.sh >> logs/presence-poller.log 2>&1 &
echo "       PID: $!"

echo "[5/8] Exit rule scanner (F28)..."
cd /root/cerraduras/api
nohup bash -c "while true; do php bin/exit-scan.php >> logs/exit-scan.log 2>&1; sleep 2; done" > /dev/null 2>&1 &
echo "       PID: $!"

echo "[6/8] Overstay scanner..."
cd /root/cerraduras/api
nohup bash -c "while true; do php bin/overstay-scan.php >> logs/overstay-scan.log 2>&1; sleep 60; done" > /dev/null 2>&1 &
echo "       PID: $!"

echo "[7/8] Outbox worker (F14)..."
cd /root/cerraduras/api
nohup bash -c "while true; do php bin/outbox-worker.php >> logs/outbox-worker.log 2>&1; sleep 30; done" > /dev/null 2>&1 &
echo "       PID: $!"

echo "[8/8] Anomaly scanner (F35)..."
cd /root/cerraduras/api
nohup bash -c "while true; do php bin/anomaly-scanner.php >> logs/anomaly-scanner.log 2>&1; sleep 5; done" > /dev/null 2>&1 &
echo "       PID: $!"

sleep 2

echo "Health checks:"
echo -n "  API:     "
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/api/v1/health && echo " OK" || echo " FAIL"
echo -n "  WS-VB6:  "
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8081/ws-vb6/v1/health && echo " OK" || echo " FAIL"
echo -n "  Dashboard: "
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/dashboard?room=1 && echo " OK" || echo " FAIL"

echo ""
echo "=== Done ==="
echo "  API:            http://92.113.151.136:8080/api/v1/health"
echo "  Dashboard:      http://92.113.151.136:8080/dashboard?room=1"
echo "  WS-VB6:         http://92.113.151.136:8081/ws-vb6/v1/health"
echo ""
echo "Para parar:"
echo "  pkill -f 'php -S.*8080' && pkill -f 'php -S.*8081' && pkill -f 'tuya-pulsar-consumer' && pkill -f 'presence-poller-manager' && pkill -f 'tuya-presence-poller' && pkill -f 'php.*bin/exit-scan' && pkill -f 'php.*bin/overstay-scan' && pkill -f 'php.*bin/outbox-worker' && pkill -f 'php.*bin/anomaly-scanner'"