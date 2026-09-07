-- ---------------------------------------------------------------------------
-- Migracion 54: visitas de las landings del CRM (lp.html).
--
-- POR QUE UN CONTADOR PROPIO Y NO META (7/9/2026): las "visitas de pagina" del
-- embudo salian solo de Meta Insights (meta_insights_pageviews), que consulta
-- una CUENTA DE ANUNCIOS por su pixel. Un publicista puede tener eso; una
-- landing del CRM no -- es una URL suelta, no una campaña. Asi que para las
-- landings el pageview no puede venir de Meta: lo contamos nosotros.
--
-- lp.html pega un ping a lp_visita.php al abrir; esa fila es una visita. El
-- embudo (crm_publicidad.php, segmento landing) las cuenta por fecha, igual
-- que cuenta los registros, y de ahi sale la conversion visita -> registro.
--
-- DEDUP: una visita = una persona que abrio la pagina, no cada F5. La UNIQUE
-- es (slug, visita_id, dia): el mismo navegador recargando el mismo dia no
-- suma; si vuelve al dia siguiente, si (es otra visita, de otro dia). El
-- INSERT es IGNORE, asi el ping repetido no es un error, simplemente no cuenta.
--
--   visita_id  id estable por navegador (localStorage). Si el navegador no
--              deja guardarlo (modo privado), lp.html manda uno por carga y en
--              el peor caso esa persona cuenta como varias -- nunca de menos.
--   dia        la fecha (no el timestamp) es lo que entra en la UNIQUE, para
--              que el dedup sea "una por dia". Se guarda aparte de creada_en
--              para no depender de funciones de fecha en cada consulta.
--
-- Mismo CHARSET/COLLATE que `landings` y `altas.origen`: el embudo compara
-- este slug con el de la landing y con altas.origen, y una collation distinta
-- rompe esas comparaciones (ya paso con otras tablas del CRM).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS landing_visitas (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(24)  NOT NULL,               -- la landing (lp.html?l=<slug>)
  visita_id  VARCHAR(40)  NOT NULL,               -- id por navegador (localStorage)
  dia        DATE         NOT NULL,               -- fecha de la visita (dedup por dia)
  creada_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_visita (slug, visita_id, dia),    -- una por navegador/landing/dia
  KEY ix_slug_dia (slug, dia)                     -- el embudo cuenta por esto
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
