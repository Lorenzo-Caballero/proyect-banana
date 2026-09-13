-- 61. Cuando se avisó por ÚLTIMA vez de una derivación.
--
-- POR QUÉ: `pasar_a_agente` solo marcaba (y solo avisaba por Telegram) cuando
-- `derivada_en IS NULL`, o sea UNA vez por derivación. En el chat del 13/9/2026
-- el jugador pidió hablar con alguien cuatro veces en 31 minutos -- 08:46,
-- 08:52, 09:08 y 09:17 -- y salió un único aviso, el primero. Se perdió, y el
-- jugador se quedó esperando con la plata ya transferida.
--
-- No alcanza con reusar `derivada_en` para el re-aviso: si se pisara con NOW()
-- en cada ping, el CRM mostraría "derivada recién" para alguien que espera hace
-- media hora, que es justo el dato que el operador necesita para priorizar. Por
-- eso van separadas: `derivada_en` es DESDE CUÁNDO espera (no se toca hasta que
-- lo atienden) y `derivada_aviso_en` es la última vez que sonó el teléfono.
--
-- Idempotente: se corre en cada deploy (panel/provisionar.php).
ALTER TABLE conversaciones
    ADD COLUMN IF NOT EXISTS derivada_aviso_en DATETIME NULL AFTER derivada_motivo;
