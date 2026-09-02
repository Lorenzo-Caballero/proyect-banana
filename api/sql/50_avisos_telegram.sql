-- ---------------------------------------------------------------------------
-- Migracion 50: memoria de los avisos ya mandados por Telegram.
--
-- EL PROBLEMA QUE RESUELVE
-- Hay dos clases de aviso y solo una es puntual:
--
--   · Puntuales: "te derivaron una conversacion", "entro un pedido de retiro".
--     Pasan una vez y se avisan una vez. Estos no necesitan nada.
--
--   · PERSISTENTES: "el colector esta caido", "hay 3 comprobantes sin
--     resolver", "hace 8 horas que no hay actividad". Los detecta un cron que
--     corre CADA MINUTO, asi que mientras el problema siga sin resolverse
--     vuelve a detectarse en cada corrida. Sin memoria, eso es un mensaje de
--     Telegram por minuto: 1.440 por dia por problema.
--
-- Y el final de esa historia se sabe: el agente silencia el bot, y despues no
-- se entera de lo que si importaba. Un aviso que satura es peor que no tener
-- avisos, porque da la sensacion de estar cubierto.
--
-- Esta tabla guarda cuando se mando cada aviso, por CLAVE. tg_avisar_una_vez()
-- consulta aca y calla si el mismo aviso ya salio hace poco.
--
-- La clave la arma quien avisa y describe el PROBLEMA, no el momento:
--   'salud'            el resumen de lo que esta roto
--   'sin_actividad'    nadie escribe ni carga hace rato
--   'pago_revision:<id_unico>'   ese comprobante puntual
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tg_avisos (
  clave      VARCHAR(120) NOT NULL PRIMARY KEY,
  -- Huella del CONTENIDO. Si el problema cambia (de "1 comprobante" a "4"),
  -- se avisa de nuevo aunque no haya pasado el tiempo de espera: es
  -- informacion nueva, no una repeticion.
  huella     CHAR(32)     NULL,
  ultimo_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  veces      INT          NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada cuantos MINUTOS se puede repetir un aviso persistente que sigue igual.
-- 180 = si el colector sigue caido, vuelve a avisar cada 3 horas en vez de
-- cada minuto. Suficiente para que no se olvide, poco para que no moleste.
INSERT INTO config_crm (clave, valor)
VALUES ('tg_repetir_min', '180')
ON DUPLICATE KEY UPDATE clave = clave;

-- Horas sin NINGUNA actividad (ni mensajes, ni recargas) despues de las cuales
-- se avisa. '0' = no avisar por esto.
--
-- 6 por defecto y no menos: de madrugada no hay nadie jugando y eso es normal,
-- no una falla. Con un umbral corto, el aviso sonaria todas las noches y se
-- volveria ruido que se aprende a ignorar.
INSERT INTO config_crm (clave, valor)
VALUES ('tg_sin_actividad_hs', '6')
ON DUPLICATE KEY UPDATE clave = clave;
