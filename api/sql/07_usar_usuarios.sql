-- ---------------------------------------------------------------------------
-- Migracion 07: unificar TODO en la tabla `usuarios`.
--
--   * Agrega a `usuarios`:  coins (FICHAS del juego) + datos del CRM.
--     (bonus ya existia -> son los BONOS; balance -> saldo real de ganamos)
--   * BORRA la tabla `jugadores` (se abandona el registro propio del sitio y la
--     cola del bot que creaba jugadores en el panel).
--
-- Antes de correr esto: si tenias fichas/bonos en `jugadores` que querés
-- conservar, migralos a `usuarios` (ver el bloque comentado del final).
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 07_usar_usuarios.sql
-- (o pegar en phpMyAdmin -> pestaña SQL)
-- ---------------------------------------------------------------------------

ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS coins            BIGINT     NOT NULL DEFAULT 0,  -- fichas
  ADD COLUMN IF NOT EXISTS tiene_app        TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS notificaciones   TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ultima_actividad DATETIME   NULL;

-- OPCIONAL: si querías conservar fichas/bonos que ya estaban en jugadores,
-- descomentá esto ANTES del DROP (matchea por nombre de usuario):
--
-- UPDATE usuarios u
--   JOIN jugadores j ON j.nombre_usuario = u.username COLLATE utf8mb4_unicode_ci
--   SET u.coins = u.coins + j.coins,
--       u.bonus = u.bonus + j.bonus;

-- Ya no se usa el registro propio del sitio ni la cola del bot.
DROP TABLE IF EXISTS jugadores;
