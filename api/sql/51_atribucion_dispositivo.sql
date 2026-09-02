-- ---------------------------------------------------------------------------
-- Migracion 51: guardar la IP y el navegador del JUGADOR para los eventos de
-- Meta.
--
-- EL PROBLEMA
-- meta_lib.php armaba el evento asi:
--
--     $ip = $_SERVER['REMOTE_ADDR'];
--     $ua = $_SERVER['HTTP_USER_AGENT'];
--
-- Eso esta bien cuando el evento se dispara dentro del pedido del jugador
-- (PageView, Lead, Contact). Pero los que MAS valen -- Purchase y
-- CompleteRegistration -- los dispara otra cosa:
--
--   · acciones_cola.php y peticiones_cola.php: los llama el BOT del VPS, con
--     API key. Ahi REMOTE_ADDR es la IP del propio servidor y el User-Agent es
--     el de Python.
--   · rl_asignar_manual(): lo llama un OPERADOR desde el CRM. Ahi la IP es la
--     del operador, no la del jugador.
--
-- O sea que a Meta le llegaban TODAS las conversiones con la misma IP de
-- datacenter y un navegador que no existe. Meta usa esos dos campos para
-- reconocer a la persona y ubicarla: mandarlos mal hunde el Event Match
-- Quality y le dice que todas las compras ocurren en un servidor.
--
-- LA SOLUCION
-- Se guardan una vez, cuando el jugador se registra de verdad desde su
-- telefono, y se releen despues -- exactamente el mismo mecanismo que ya se usa
-- para `fbp` y `fbc` (ver publicidad_atribucion_por_usuario).
--
-- No es dato personal nuevo: la IP ya quedaba en los logs del servidor y el
-- User-Agent lo manda el navegador en cada pedido. Lo que cambia es que ahora
-- se conserva atado al alta para poder reportar bien la conversion.
-- ---------------------------------------------------------------------------

ALTER TABLE altas
  -- 45 chars: entra una IPv6 completa (39) con margen.
  ADD COLUMN IF NOT EXISTS ip           VARCHAR(45)  NULL AFTER fbc,
  ADD COLUMN IF NOT EXISTS ua           VARCHAR(400) NULL AFTER ip,
  -- La URL donde se registro. Meta la pide (event_source_url) cuando el evento
  -- dice action_source='website', y hasta ahora no se mandaba nunca: el codigo
  -- lo soportaba pero ningun llamador la pasaba.
  ADD COLUMN IF NOT EXISTS url_landing  VARCHAR(255) NULL AFTER ua;
