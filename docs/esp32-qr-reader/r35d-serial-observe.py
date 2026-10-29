#!/usr/bin/env python3
"""Observa por serie el diagnóstico r35d-tag-probe.

Uso en el ordenador de oficina:

    python3 docs/esp32-qr-reader/r35d-serial-observe.py

    # o con un puerto explícito:
    python3 docs/esp32-qr-reader/r35d-serial-observe.py \
        --port /dev/serial/by-id/usb-1a86_USB_Single_Serial_5B90126830-if00

No escribe en el dispositivo. Abre el puerto a 115200 con DTR/RTS en bajo
(la base puede quedar retenida en reset si se activan). Cierra con Ctrl-C.
"""

from __future__ import annotations

import argparse
import datetime as dt
import sys
import time

try:
    import serial
except ImportError:
    sys.stderr.write("Falta pyserial: python3 -m pip install pyserial\n")
    raise SystemExit(2)

DEFAULT_PORT = (
    "/dev/serial/by-id/"
    "usb-1a86_USB_Single_Serial_5B90126830-if00"
)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--port", default=DEFAULT_PORT, help="puerto serie del ESP32-S3")
    ap.add_argument("--baud", type=int, default=115200, help="baudios (def. 115200)")
    ap.add_argument("--log", default=None, help="fichero opcional donde guardar la salida")
    args = ap.parse_args()

    log = open(args.log, "a", encoding="utf-8", buffering=1) if args.log else None

    ser = serial.Serial()
    ser.port = args.port
    ser.baudrate = args.baud
    ser.timeout = 0.2
    ser.dtr = False
    ser.rts = False
    ser.open()

    def emit(text: str) -> None:
        stamp = dt.datetime.now().strftime("%H:%M:%S.%f")[:-3]
        line = f"{stamp} {text}"
        print(line, flush=True)
        if log is not None:
            log.write(line + "\n")

    emit(f"[observe] puerto={args.port} baud={args.baud} DTR=off RTS=off")
    try:
        while True:
            line = ser.readline()
            if not line:
                continue
            emit(line.decode("utf-8", "replace").rstrip("\r\n"))
    except KeyboardInterrupt:
        emit("[observe] detenido")
    finally:
        ser.close()
        if log is not None:
            log.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
