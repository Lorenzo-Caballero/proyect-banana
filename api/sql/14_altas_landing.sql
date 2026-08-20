-- ---------------------------------------------------------------------------
-- Migracion 14: la cola de altas se abre al publico (landing crear-cuenta.html)
--
-- Hasta ahora a `altas` solo entraba algo con la BOT_API_KEY en la mano. Con la
-- landing enchufada, el que encola es cualquiera que abra una URL, y eso cambia
-- el problema: cada fila que entra hace que un bot abra Chromium y opere el
-- panel de agentes de verdad. Sin freno, alguien encola mil altas en un minuto
-- y te quema la cuenta de agente.
--
-- Por eso se guarda la IP: es lo unico que permite decir "este ya pidio 3 en la
-- ultima hora". No sirve para identificar a nadie ni se muestra en ningun lado.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 14_altas_landing.sql
-- (o pegarlo en la pestana SQL de phpMyAdmin)
-- ---------------------------------------------------------------------------

ALTER TABLE altas
  ADD COLUMN IF NOT EXISTS ip VARCHAR(45) NULL AFTER origen;

-- El indice es por el WHERE del limite, que corre en CADA alta de la landing:
-- "cuantas pidio esta IP desde tal hora".
ALTER TABLE altas
  ADD INDEX IF NOT EXISTS idx_ip_fecha (ip, pedido_en);
