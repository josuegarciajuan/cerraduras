#!/usr/bin/env python3
"""tuya_relay.py — relé TCP<->serie (cliente primero) para la PoC L1.

Se ejecuta en oficina (con la ESP32-CAM en SER). Escucha en 127.0.0.1:PORT;
cuando un cliente (tinytuya/tuyapi, normalmente vía túnel SSH) se conecta,
entonces conecta la ESP al device TARGET:6668 y hace passthrough binario.
100% local, sin nube ni cuota.

Uso: TARGET=192.168.31.249 PORT=16668 python3 tuya_relay.py
"""
import serial, socket, threading, time, sys, os

SER = os.environ.get('SER', '/dev/ttyUSB0')
TARGET = os.environ.get('TARGET', '192.168.31.249')
PORT = int(os.environ.get('PORT', '16668'))
LOG = os.environ.get('LOG', '/tmp/tuya_relay.log')


def log(m):
    with open(LOG, 'a') as f:
        f.write('%s %s\n' % (time.strftime('%H:%M:%S'), m))


def wait_for(ser, marker, timeout):
    t = time.time(); buf = b''
    while time.time() - t < timeout:
        d = ser.read(256)
        if d:
            buf += d
            if marker.encode() in buf:
                return True
    return False


def connect_esp(ser):
    ser.setDTR(False); ser.setRTS(False); time.sleep(0.2)
    ser.setDTR(False); ser.setRTS(True); time.sleep(0.2); ser.setRTS(False)
    time.sleep(0.5); ser.reset_input_buffer()
    if not wait_for(ser, 'READY', 12):
        log('NO READY'); return False
    ser.write(('CONNECT %s 6668\n' % TARGET).encode())
    t = time.time(); buf = b''
    while time.time() - t < 8:
        d = ser.read(256)
        if d:
            buf += d
            if b'TCPFAIL' in buf:
                log('TCPFAIL %r' % buf[-60:]); return False
            if b'TCPOK' in buf and b'BRIDGE' in buf:
                time.sleep(0.1); ser.reset_input_buffer()
                return True
    log('NO TCPOK/BRIDGE %r' % buf[-80:]); return False


srv = socket.socket()
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(('127.0.0.1', PORT))
srv.listen(1)
log('listening on 127.0.0.1:%d (target %s)' % (PORT, TARGET))

while True:
    log('waiting client')
    conn, _ = srv.accept()
    conn.settimeout(0.05)
    log('client connected; connecting ESP')
    try:
        ser = serial.Serial(SER, 115200, timeout=0.05)
    except Exception as e:
        log('serial open err %r' % e); conn.close(); time.sleep(1); continue
    if not connect_esp(ser):
        log('ESP connect failed'); ser.close(); conn.close(); time.sleep(1); continue
    log('BRIDGE READY (binary)')
    stop = threading.Event()

    def s2c():
        while not stop.is_set():
            try:
                d = ser.read(512)
            except Exception as e:
                log('ser read err %r' % e); stop.set(); break
            if d:
                try:
                    conn.sendall(d)
                except Exception as e:
                    log('conn send err %r' % e); stop.set(); break

    def c2s():
        while not stop.is_set():
            try:
                d = conn.recv(512)
            except socket.timeout:
                continue
            except Exception as e:
                log('conn recv err %r' % e); stop.set(); break
            if not d:
                log('client closed'); stop.set(); break
            try:
                ser.write(d)
            except Exception as e:
                log('ser write err %r' % e); stop.set(); break

    threading.Thread(target=s2c, daemon=True).start()
    threading.Thread(target=c2s, daemon=True).start()
    stop.wait()
    try: conn.close()
    except Exception: pass
    try: ser.close()
    except Exception: pass
    log('session ended')
    time.sleep(0.5)
