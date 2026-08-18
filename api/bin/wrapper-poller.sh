#!/usr/bin/env bash
# wrapper-poller.sh — keeps the presence poller alive (auto-restart)
# Run: nohup bash /root/cerraduras/api/bin/wrapper-poller.sh & disown

LOG=/root/cerraduras/api/logs/presence-poller.log
NODE_SCRIPT=/root/cerraduras/api/bin/tuya-presence-poller.js

echo "[$(date)] Wrapper started" >> $LOG

while true; do
  node "$NODE_SCRIPT" >> "$LOG" 2>&1
  echo "[$(date)] Poller exited — restarting in 5s..." >> "$LOG"
  sleep 5
done
