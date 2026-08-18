#!/bin/bash
# Arranca el servidor de control de cerradura Tuya en puerto 8090
pkill -f "php.*8090" 2>/dev/null
sleep 1
nohup php -S 0.0.0.0:8090 /root/cerraduras/api/bin/tuya-lock-server.php > /tmp/tuya-lock-server.log 2>&1 &
echo "PID: $!"
sleep 2
ss -tlnp | grep 8090 || echo "ERROR: port 8090 not listening"
