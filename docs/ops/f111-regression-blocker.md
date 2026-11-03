# F111 — estado de regresión antes de pruebas físicas

Fecha: 2026-11-03  
Base: `19ef12b`

## Resultado reproducible

La regresión no se ejecutó contra la BD viva. El worktree no contiene `.env` y
`php api/bin/migrate.php` termina de forma segura con:

```text
[migrate] ERROR: Missing required config key: DB_NAME
```

El único servidor MariaDB accesible desde este entorno expone únicamente
`cerraduras_db`, la BD de producción/pruebas viva. No se ha usado para migrate,
seed ni `run-tests.sh`: esos comandos pueden modificar estado compartido y no
hay una BD efímera o credenciales de una BD aislada disponibles. Por tanto, no
se afirma que los 133 fallos estén identificados ni se falsifica un resultado
de regresión.

## Comprobaciones aisladas realizadas

Sin BD ni hardware:

- `RadarExteriorPresenceEvaluatorTest.php`: **5 passed, 0 failed**.
- `RadarCalibrationTest.php`: **90 passed, 0 failed**.
- La rama BLOCK 83 de F111 requiere además servidor/API y no se ha maquillado
  como PASS.

## Desbloqueo seguro

1. Proporcionar un `.env` de pruebas con una BD dedicada (no `cerraduras_db`)
   y un usuario limitado a esa BD, o un dump sanitizado restaurado en una BD
   efímera.
2. Desde este worktree ejecutar, en este orden:

   ```bash
   cd api
   php bin/migrate.php
   php bin/seed.php
   bash bin/run-tests.sh
   ```

3. Guardar el resumen y el log (`api/logs/test-results.log`), clasificar cada
   fallo como harness/preparación o regresión real, y corregir solo los dos
   primeros tipos autorizados por F111.
4. Repetir la regresión completa hasta `0 failed`; después ejecutar el
   checklist físico de `docs/ops-sensores.md` §12 y el flujo F111 descrito en
   `docs/hardware/radar-presence-evidence-f111.md`.

## Checklist de campo (sin ejecutar aquí)

- [ ] Confirmar BD aislada, migraciones y seed; conservar log de 0 failures.
- [ ] Ejecutar `sensor-test-prepare.php`; verificar base F110 y pausa manual.
- [ ] Confirmar puerta cerrada y luz alcanzable (`reachable=true`).
- [ ] En el asistente, completar interior con lecturas DP9 nuevas.
- [ ] En el punto exterior, permanecer quieto 20–30 s; registrar DP1/presencia.
- [ ] Verificar exterior solo por presencia; no usar polling REST Tuya.
- [ ] Confirmar reentrada con presencia posterior y revisar versión de
  configuración.
- [ ] Si falla o queda inconcluso, exportar evidencia y restaurar la
  calibración; no repetir con una corrida vieja.
