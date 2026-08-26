-- ---------------------------------------------------------------------------
-- Migracion 39: integracion con Meta Ads (Pixel + Conversions API).
--
-- Dos caminos para el MISMO evento, a proposito:
--
--   Pixel (navegador)  -> rapido, trae el fbp/fbc y el contexto del browser,
--                         pero lo bloquea cualquier adblocker y iOS le recorta
--                         la atribucion.
--   CAPI (servidor)    -> no lo bloquea nadie y sabe cosas que el navegador no
--                         (que la carga se acredito de verdad, por ejemplo).
--
-- Meta los junta por `event_id`: si el mismo evento llega por los dos lados con
-- el mismo id, cuenta UNO. Por eso la tabla de abajo guarda el event_id que
-- generamos -- sin eso, un Purchase se contaria dos veces y la campaña
-- optimizaria contra un numero inflado.
--
-- Correr una sola vez:  mariadb -u USUARIO -p BASE < 39_meta_ads.sql
-- (la corre sola provisionar.php)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS meta_eventos (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- El id que compartimos con el Pixel para deduplicar. UNIQUE: si algo
  -- reintenta (el sondeo del widget, un F5), el segundo INSERT choca y no se
  -- manda el evento de nuevo.
  event_id    VARCHAR(64)  NOT NULL,
  evento      VARCHAR(40)  NOT NULL,   -- Purchase, Contact, CompleteRegistration...

  usuario     VARCHAR(64)  NULL,       -- si se conoce
  valor       DECIMAL(12,2) NULL,      -- Purchase/InitiateCheckout
  moneda      VARCHAR(8)   NULL,

  -- Que contesto la API de Meta. Sirve para ver si el evento entro y con que
  -- calidad de match; sin esto, depurar atribucion es a ciegas.
  estado      ENUM('pendiente','enviado','error') NOT NULL DEFAULT 'pendiente',
  respuesta   TEXT         NULL,
  creado      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_event_id (event_id),
  KEY idx_evento_fecha (evento, creado)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- Ajustes. Van en config_crm (migracion 38) para que se editen desde la misma
-- pantalla de Configuracion y sean POR CLIENTE: cada agencia tiene su pixel.
INSERT IGNORE INTO config_crm (clave, valor) VALUES
  ('meta_activo',        '0'),
  ('meta_pixel_id',      ''),
  ('meta_capi_token',    ''),
  ('meta_test_code',     ''),
  ('meta_pageview_en',   'registro'),
  ('meta_ev_contact',    '1'),
  ('meta_ev_registro',   '1'),
  ('meta_ev_checkout',   '1'),
  ('meta_ev_purchase',   '1');
