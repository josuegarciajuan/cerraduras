#!/usr/bin/env python3
"""
tuya_bridge_client.py — cliente del puente TCP<->serie (PoC L1 / B1).

Se ejecuta en el host con el ESP32-CAM (TuyaBridge.ino) conectado por USB.
Hace de relé entre `tinytuya` (que conecta a 127.0.0.1:6668) y el serial, para
hablar el protocolo local Tuya TCP 6668 de los devices desde fuera de su LAN.
100% local: NO usa la nube Tuya ni cuota.

Requisitos: pyserial, tinytuya; /tmp/keys.json con {name: {device_id, local_key}}.

Uso:
    python3 tuya_bridge_client.py <name> <version> <status|on|off>

(name -> IP LAN mapeada abajo; ajustar según el descubrimiento).
"""
import serial, socket, threading, time, sys, json, os
from tinytuya import Device
import tinytuya
if os.environ.get('DEBUG'): tinytuya.set_debug(True)
LOGB = os.environ.get('BYTES') == '1'

SER = '/dev/ttyUSB0'
LPORT = 6668
LAN = {'24G': '192.168.31.109', 'EAWCBT-J': '192.168.31.249'}

ser = serial.Serial(SER, 115200, timeout=0.05)
ser.setDTR(False); ser.setRTS(False); time.sleep(0.2)

def log(dirn, d):
    if LOGB and d: sys.stderr.write('BYTES %s %s\n' % (dirn, d.hex()))

def drain_until(marker, timeout=10.0):
    t = time.time(); buf = b''
    while time.time() - t < timeout:
        d = ser.read(512)
        if d:
            buf += d
            if marker.encode() in buf: return buf
    return buf

def esp_reset():
    ser.setDTR(False); ser.setRTS(True); time.sleep(0.2); ser.setRTS(False)
    time.sleep(0.3); ser.reset_input_buffer()
    return b'WAIT' in drain_until('WAIT', 10.0)

def run(name, dev_id, key, ip, version, action):
    print('=== %s | v%s @ %s | %s ===' % (name, version, ip, action))
    if not esp_reset(): print('  ESP no ready'); return
    ser.write(("CONNECT %s %d\n" % (ip, 6668)).encode())
    r = drain_until('TCPOK', 8.0)
    if b'TCPOK' not in r: print('  TCPFAIL', r[:100]); return
    drain_until('BRIDGE', 3.0)
    srv = socket.socket(); srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(('127.0.0.1', LPORT)); srv.listen(1); srv.settimeout(10)
    stop = threading.Event(); box = {}
    def relay(conn):
        conn.settimeout(0.05)
        def s2c():
            while not stop.is_set():
                try: d = ser.read(512)
                except Exception: break
                if d:
                    log('DEV->HOST', d)
                    try: conn.sendall(d)
                    except Exception: break
        def c2s():
            while not stop.is_set():
                try: d = conn.recv(512)
                except socket.timeout: continue
                except Exception: break
                if not d: break
                log('HOST->DEV', d)
                ser.write(d)
        threading.Thread(target=s2c, daemon=True).start()
        threading.Thread(target=c2s, daemon=True).start()
    def acceptor():
        try:
            conn, _ = srv.accept(); box['conn'] = conn; relay(conn)
        except Exception as e: box['err'] = e
    threading.Thread(target=acceptor, daemon=True).start()
    try:
        d = Device(dev_id, '127.0.0.1', key, version=version, persist=False,
                   connection_timeout=8, port=LPORT)
        if action == 'status':   print('  status:', d.status())
        elif action == 'on':     print('  turn_on:', d.turn_on())
        elif action == 'off':    print('  turn_off:', d.turn_off())
        time.sleep(3.0)  # por si el device responde tarde
    except Exception as e:
        print('  error:', repr(e))
    stop.set()
    try: box['conn'].close()
    except Exception: pass
    try: srv.close()
    except Exception: pass

if __name__ == '__main__':
    keys = json.load(open('/tmp/keys.json'))
    name = sys.argv[1]; version = float(sys.argv[2])
    action = sys.argv[3] if len(sys.argv) > 3 else 'status'
    k = keys[name]
    run(name, k['device_id'], k['local_key'], LAN[name], version, action)
