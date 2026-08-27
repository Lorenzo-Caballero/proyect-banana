-- ---------------------------------------------------------------------------
-- Migracion 44: modulo Publicidad -- publicistas, gasto diario y el tramo del
-- embudo que faltaba para reportarlo a Meta y mostrarlo en el CRM.
--
-- CONTEXTO
-- Hoy Meta Ads (migracion 39) es UN pixel/token por cliente/agencia entero
-- (config_crm). Esta migracion agrega una capa por-debajo: un mismo cliente
-- puede repartir varias landings entre varios publicistas, cada uno con su
-- propio pixel/token, sin mezclar resultados. La landing sigue siendo la
-- MISMA registro.html -- lo que cambia es un ?pub=<slug> en la URL.
--
-- Requiere sql/38_config_crm.sql, sql/39_meta_ads.sql y sql/13_cola_altas.sql
-- ya corridas.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- publicistas: cada fila es una landing propia (registro.html?pub=slug) con
-- su propio pixel + token de CAPI. Si pixel_id/capi_token quedan vacios,
-- meta_evento() cae al pixel del cliente (config_crm) -- un publicista nuevo
-- sin configurar no deja de reportar, solo reporta con el pixel general hasta
-- que el operador le cargue el suyo.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS publicistas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(80)  NOT NULL,
  slug           VARCHAR(40)  NOT NULL,          -- va en la URL: ?pub=<slug>
  pixel_id       VARCHAR(40)  NULL,
  capi_token     TEXT         NULL,
  -- Access Token de la Marketing API (permiso ads_read) + la cuenta
  -- publicitaria (act_XXXXXXXXX) dueña de ese pixel. Es un token DISTINTO de
  -- capi_token: capi_token manda eventos (Conversions API), este solo LEE
  -- estadisticas del pixel (GET /{pixel_id}/stats) para traer "Visitas de
  -- pagina". Sin esto, el embudo funciona igual, solo que sin ese dato --
  -- ver meta_insights_pageviews() en meta_lib.php.
  insights_token       TEXT        NULL,
  insights_ad_account  VARCHAR(32) NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_publicista_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Por si esta migracion ya habia corrido antes de agregarse insights_token/
-- insights_ad_account (mismo criterio IF NOT EXISTS que el resto del repo).
ALTER TABLE publicistas
  ADD COLUMN IF NOT EXISTS insights_token TEXT NULL AFTER capi_token,
  ADD COLUMN IF NOT EXISTS insights_ad_account VARCHAR(32) NULL AFTER insights_token;

-- ---------------------------------------------------------------------------
-- gasto_diario: lo que el operador carga a mano por publicista y dia. Un
-- rango (7d, mes, custom) se resuelve sumando los dias que caen adentro --
-- no hace falta cargar un numero por cada preset de fecha que exista.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gasto_diario (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  publicista_id  INT UNSIGNED NOT NULL,
  fecha          DATE         NOT NULL,
  monto          DECIMAL(12,2) NOT NULL DEFAULT 0,
  operador       VARCHAR(60)  NULL,
  actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gasto_dia (publicista_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- altas: de que publicista vino el pedido, y los identificadores de Meta que
-- la landing capturo en el momento del click/registro. Todo NULLable porque
-- una alta que entra por CRM/chatbot (no por la landing publica) no tiene
-- nada de esto -- no es "publicidad".
-- ---------------------------------------------------------------------------
ALTER TABLE altas
  ADD COLUMN IF NOT EXISTS publicista_id INT UNSIGNED NULL AFTER ip,
  ADD COLUMN IF NOT EXISTS fbclid        VARCHAR(255) NULL AFTER publicista_id,
  ADD COLUMN IF NOT EXISTS fbp           VARCHAR(80)  NULL AFTER fbclid,
  ADD COLUMN IF NOT EXISTS fbc           VARCHAR(120) NULL AFTER fbp;

ALTER TABLE altas
  ADD KEY IF NOT EXISTS idx_publicista (publicista_id);

-- ---------------------------------------------------------------------------
-- recargas: si esta acreditacion es la PRIMERA del usuario. Se resuelve UNA
-- vez, en rl_acreditar() (un COUNT contra recargas previas del mismo
-- usuario), y se guarda aca -- asi el modulo de Publicidad hace SUM/COUNT
-- simples sobre esta columna en vez de una subquery de "primera recarga por
-- usuario" cada vez que el operador abre o filtra el reporte.
-- ---------------------------------------------------------------------------
ALTER TABLE recargas
  ADD COLUMN IF NOT EXISTS es_primera TINYINT(1) NULL AFTER acreditada_en;

-- Filtro tipico del reporte: recargas acreditadas de tal usuario en tal
-- rango, separando primeras de repetidas.
ALTER TABLE recargas
  ADD KEY IF NOT EXISTS idx_acreditada_primera (estado, acreditada_en, es_primera);
