#!/usr/bin/env bash
# =============================================================================
# smoke-test.sh — Verificación rápida de todos los componentes del sistema.
#
# Uso:
#   bash /root/cerraduras/api/bin/smoke-test.sh
#
# Exit code 0 = todo OK, 1 = problemas detectados.
# =============================================================================
set -e

API="http://127.0.0.1:8080"
WSVB6="http://127.0.0.1:8081"
OK=0
ERR=0

green() { echo -e "\033[0;32m  OK \033[0m $1"; OK=$((OK+1)); }
red()   { echo -e "\033[0;31m FAIL\033[0m $1 — $2"; ERR=$((ERR+1)); }

echo "=== Cerraduras Hotel — Smoke Test ==="
echo ""

# 1. API basic health
CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$API/api/v1/health" 2>/dev/null || echo "000")
if [ "$CODE" = "200" ]; then green "API /health → HTTP 200"
else red "API /health → HTTP $CODE" "¿API corriendo?"; fi

# 2. API deep health
DEEP=$(curl -s --max-time 10 "$API/api/v1/health/deep" 2>/dev/null || echo '{"status":"error"}')
DEEP_STATUS=$(echo "$DEEP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('status','error'))" 2>/dev/null || echo "parse_error")
if [ "$DEEP_STATUS" = "healthy" ]; then green "API /health/deep → healthy"
elif [ "$DEEP_STATUS" = "degraded" ]; then red "API /health/deep → degraded" "Revisar componentes: curl $API/api/v1/health/deep"
else red "API /health/deep → $DEEP_STATUS" "¿API respondiendo?"; fi

# 3. WS-VB6 health
WS_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$WSVB6/ws-vb6/v1/health" 2>/dev/null || echo "000")
if [ "$WS_CODE" = "200" ]; then green "WS-VB6 /health → HTTP 200"
else red "WS-VB6 /health → HTTP $WS_CODE" "¿WS-VB6 corriendo?"; fi

# 4. MySQL ping
if mysql -u cerraduras_user -p'f83bdcfaf5fece29e91f968a' cerraduras_db -e "SELECT 1" >/dev/null 2>&1; then
  green "MySQL → conectado"
else
  red "MySQL → no responde" "¿mysqld corriendo?"
fi

# 5. Dashboard
DASH_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$API/dashboard?room=1" 2>/dev/null || echo "000")
if [ "$DASH_CODE" = "200" ]; then green "Dashboard → HTTP 200"
else red "Dashboard → HTTP $DASH_CODE" "¿dashboard.html existe?"; fi

# 6. CRM Panel
PANEL_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$API/panel/login.html" 2>/dev/null || echo "000")
if [ "$PANEL_CODE" = "200" ]; then green "CRM Panel → HTTP 200"
else red "CRM Panel → HTTP $DASH_CODE" "¿panel/login.html existe?"; fi

# 7. Workers — supervisados por systemd (F46). Se consulta el unit, no el proceso:
#    los workers de tick largo (overstay 60s, outbox 30s) pasan la mayor parte del
#    tiempo en `sleep` y pgrep los reportaba como caídos (falso negativo).
#    Nota (F78/RF-101): NO existe ningún poller de Tuya. La presencia llega por
#    push (consumer Pulsar) y los sensores se refrescan bajo demanda.
WORKER_UNITS="cerraduras-worker@exit-scan cerraduras-worker@overstay-scan cerraduras-worker@outbox-worker cerraduras-worker@anomaly-scanner cerraduras-pulsar-consumer"
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet cerraduras-api 2>/dev/null; then
  for u in $WORKER_UNITS; do
    if systemctl is-active --quiet "$u" 2>/dev/null; then green "Unit: $u → active"
    else red "Unit: $u → inactive" "systemctl status $u"; fi
  done
else
  # Fallback sin systemd: comprobar procesos (los de tick largo pueden estar en sleep)
  for w in "php.*bin/exit-scan" "php.*bin/overstay-scan" "php.*bin/anomaly-scanner" "php.*bin/outbox-worker"; do
    label=$(echo "$w" | sed 's/.*bin\///')
    if pgrep -f "$w" >/dev/null 2>&1; then green "Worker: $label → running"
    else red "Worker: $label → stopped" "¿start-all.sh ejecutado?"; fi
  done
fi

# 8. SSH hardening check (informative)
if systemctl is-active --quiet sshd 2>/dev/null; then green "SSH → activo"
else echo "  --  SSH → no verificado (systemd no disponible)"; fi

echo ""
echo "========================================"
echo "  Resultado: $OK OK, $ERR fallos"
echo "========================================"

exit $([ "$ERR" -eq 0 ] && echo 0 || echo 1)
