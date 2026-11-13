-- F133 — identidad del episodio de presencia y evidencia DP9 correlacionada.
--
-- Columnas aditivas. Permiten que una DP9 positiva de otra trama del MISMO
-- episodio acredite un PRESENT todavía vigente, sin mezclar dispositivos,
-- configuraciones ni episodios distintos.
--
--   presence_episode_marker          marcador DP1 que abrió el episodio.
--   presence_episode_session         sesión del reporte DP1.
--   presence_episode_started_at      instante UTC del reporte DP1.
--   presence_episode_distance_marker DP9 usada para acreditar el episodio.

ALTER TABLE iot_sessions
  ADD COLUMN presence_episode_marker          VARCHAR(160) NULL DEFAULT NULL AFTER presence_outcome_json,
  ADD COLUMN presence_episode_session         VARCHAR(64)  NULL DEFAULT NULL AFTER presence_episode_marker,
  ADD COLUMN presence_episode_started_at      DATETIME(3)  NULL DEFAULT NULL AFTER presence_episode_session,
  ADD COLUMN presence_episode_distance_marker VARCHAR(160) NULL DEFAULT NULL AFTER presence_episode_started_at;
