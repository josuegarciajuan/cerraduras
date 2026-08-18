#!/usr/bin/env bash
# =============================================================================
# bin/reset-test-room.sh — Reset completo de la habitación de pruebas
#
# Cada vez que se consume un QR de prueba (stay 45) y se quiere reescanear
# el mismo PNG físico, este script restaura el estado limpio:
#   - QR: consumed_at=NULL, revoked_at=NULL (listo para reescanear)
#   - Stay: RESERVED (próximo escaneo será primer uso → OCCUPIED + OPEN)
#   - Room: FREE, sin cooldown
#   - IoT: puerta CERRADA, presencia AUSENTE
#
# Asume QR_EXP_FROM_DB=true en .env para que la caducidad la controle
# qr_credentials.expires_at y no el exp sellado en el token.
#
# Uso:
#   bash bin/reset-test-room.sh
#
# Después de ejecutar: el dashboard mostrará FREE, puerta CLOSED, ausente.
# =============================================================================
set -e

cd "$(dirname "$0")/.."

# Leer credenciales de BD del .env
DB_HOST=$(grep "^DB_HOST=" .env | cut -d= -f2 | tr -d ' ')
DB_PORT=$(grep "^DB_PORT=" .env | cut -d= -f2 | tr -d ' ')
DB_NAME=$(grep "^DB_NAME=" .env | cut -d= -f2 | tr -d ' ')
DB_USER=$(grep "^DB_USER=" .env | cut -d= -f2 | tr -d ' ')
DB_PASS=$(grep "^DB_PASS=" .env | cut -d= -f2 | tr -d ' ')

MYSQL="mysql -u$DB_USER -p$DB_PASS -h$DB_HOST -P$DB_PORT $DB_NAME"

echo "=== Reset habitación de pruebas (stay 45) ==="

# 1. QR: marcar como no consumido
$MYSQL -e "UPDATE qr_credentials SET consumed_at=NULL, revoked_at=NULL WHERE id=45" 2>/dev/null
echo "  [1] QR stay 45 → consumed=NULL"

# 2. Stay 45: volver a RESERVED. Cerrar cualquier otra stay activa en room 1.
$MYSQL -e "UPDATE stays SET status='CLOSED', closed_at=UTC_TIMESTAMP(3) WHERE room_id=1 AND id != 45 AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')" 2>/dev/null
$MYSQL -e "UPDATE stays SET status='RESERVED', first_entry_at=NULL, closed_at=NULL WHERE id=45" 2>/dev/null
echo "  [2] Stay 45 → RESERVED (otras stays cerradas)"

# 3. Room: FREE, sin cooldown, sin simulated_override
$MYSQL -e "UPDATE rooms SET status='FREE', cooldown_until=NULL, simulated_override=NULL WHERE id=1" 2>/dev/null
echo "  [3] Room 1 → FREE"

# 4. IoT session: puerta cerrada, sin presencia
$MYSQL -e "UPDATE iot_sessions SET door_state='CLOSED', presence_state='ABSENT', last_open_at=NULL, last_absent_since=NULL, exit_evaluated_at=NULL WHERE room_id=1" 2>/dev/null
echo "  [4] IoT → door=CLOSED, presence=ABSENT"

# 5. Verificar
echo ""
echo "=== Estado final ==="
echo -n "  QR:   "; $MYSQL -sN -e "SELECT CONCAT('consumed=', IFNULL(consumed_at,'NULL'), ' expires=', expires_at) FROM qr_credentials WHERE id=45" 2>/dev/null
echo -n "  Stay: "; $MYSQL -sN -e "SELECT CONCAT('id=', id, ' status=', status) FROM stays WHERE id=45" 2>/dev/null
echo -n "  Room: "; $MYSQL -sN -e "SELECT CONCAT('status=', status) FROM rooms WHERE id=1" 2>/dev/null
echo -n "  IoT:  "; $MYSQL -sN -e "SELECT CONCAT('door=', door_state, ' presence=', presence_state) FROM iot_sessions WHERE room_id=1" 2>/dev/null
echo ""
echo "✅ Listo para reescanear el mismo PNG (stay 45)."
echo "   Dashboard: http://92.113.151.136:8080/dashboard?room=1"
