-- ---------------------------------------------------------------------------
-- Migracion 52: landings creadas desde el CRM.
--
-- Hasta aca habia UNA landing de promo (landing/bono.html) con el bono
-- hardcodeado en dos lugares (BONO_PCT en el HTML y RL_BONO_BIENVENIDA_PCT en
-- recargas_lib.php) que tenian que decir lo mismo a mano. Probar otra promo u
-- otra estetica significaba copiar el archivo y editarlo por FTP.
--
-- Esta tabla mueve eso a datos: cada fila es una landing publicable en
-- lp.html?l=<slug>, con su bono, sus colores, sus textos y sus imagenes. El
-- CRM (modulo "Landings") hace el CRUD; lp.html la lee por landing_publica.php.
--
-- POR QUE bono_pct ES COLUMNA Y NO PARTE DEL JSON: el bono es una promesa que
-- despues CUMPLE el server (rl_acreditar suma ese % en la primera recarga del
-- que entro por esta landing, ver recargas_lib.php). El resto del JSON es
-- estetica que solo le importa al navegador; el bono lo lee SQL/PHP en el
-- camino de la plata y no puede depender de parsear un JSON de estetica.
--
-- EL SLUG VIAJA EN altas.origen COMO 'lp:<slug>' (origen es VARCHAR(32):
-- 'lp:' + 24 = 27, entra). Por eso el slug esta limitado a 24 chars y es
-- inmutable una vez creada la fila: cambiarlo dejaria huerfanos los bonos
-- prometidos a los que ya se registraron.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS landings (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug      VARCHAR(24)  NOT NULL,                -- va en altas.origen como 'lp:<slug>'
  nombre    VARCHAR(80)  NOT NULL,                -- para la lista del CRM, no lo ve el jugador
  plantilla VARCHAR(30)  NOT NULL DEFAULT 'oro',  -- preset base (oro | neon | fuego)
  bono_pct  TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = sin bono de bienvenida
  activa    TINYINT(1)   NOT NULL DEFAULT 1,      -- pausada = deja de servirse y de dar de alta
  config    TEXT         NULL,                    -- JSON: colores, textos, imagenes
  creada_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- UNIQUE y no chequeo previo, mismo criterio que la ruleta: dos operadores
  -- guardando a la vez no pueden terminar con el mismo slug.
  UNIQUE KEY uq_landings_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
