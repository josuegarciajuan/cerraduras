#!/usr/bin/env python3
"""tuya_discover.py — ejecuta DISCOVER en TuyaTool (ESP32-CAM) y decodifica.

Se ejecuta en oficina (tiene tinytuya). 100% local, sin nube ni cuota.
Uso: python3 tuya_discover.py [serial]
"""
import serial, time, sys, re
import tinytuya

SER = sys.argv[1] if len(sys.argv) > 1 else '/dev/ttyUSB0'
ser = serial.Serial(SER, 115200, timeout=0.1)
ser.setDTR(False); ser.setRTS(False); time.sleep(0.2)
ser.setDTR(False); ser.setRTS(True); time.sleep(0.2); ser.setRTS(False)
time.sleep(0.5); ser.reset_input_buffer()

t = time.time(); ready = False
while time.time() - t < 12:
    line = ser.readline().decode('utf-8', 'replace').strip()
    if line:
        sys.stderr.write('ESP: %s\n' % line)
    if line == 'READY':
        ready = True
        break
if not ready:
    sys.stderr.write('ESP no READY\n'); sys.exit(1)

ser.write(b'DISCOVER\n')
deadline = time.time() + 20
seen = {}
while time.time() < deadline:
    line = ser.readline().decode('utf-8', 'replace').strip()
    if not line:
        continue
    if line == 'DISCOVER_DONE':
        break
    if line.startswith('D '):
        m = re.match(r'D (\S+) (\d+) len=(\d+) hex=(\w+)', line)
        if not m:
            continue
        ip, port, hexs = m.group(1), m.group(2), m.group(4)
        try:
            text = tinytuya.decrypt_udp(bytes.fromhex(hexs))
        except Exception as e:
            text = 'RAW %s (%r)' % (hexs, e)
        key = ip
        if key not in seen:
            seen[key] = True
            print('%s:%s %s' % (ip, port, text))
ser.close()
print('--- total %d ---' % len(seen))
