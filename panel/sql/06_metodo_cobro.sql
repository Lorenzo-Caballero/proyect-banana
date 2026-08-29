-- ---------------------------------------------------------------------------
-- Migracion 06 (control): metodo de cobro por cliente -- transferencia
-- (cuenta/IMAP propios) o HG Cash con credenciales PROPIAS del cliente.
--
-- CONTEXTO
-- Hasta ahora habia un solo camino de verdad para "el jugador transfirio":
-- centavos unicos + colector de mails IMAP, leyendo la cuenta de la CASA
-- (clientes.cobro_alias/cobro_cbu/cobro_titular, cargada por Nahuel desde
-- panel.html). HG Cash (migracion 05) es OTRO camino que ya funciona, pero
-- con un solo token DE LA PLATAFORMA compartido por todos los clientes --
-- decision deliberada documentada en hgcash_lib.php ("DECISIONES QUE NO SE
-- NEGOCIAN"), que esta migracion NO toca ni revierte: ese modo queda intacto
-- pero INACTIVO, sin ofrecerse mas al cliente.
--
-- Lo que se agrega es un modelo distinto y nuevo: cada cliente ELIGE (desde
-- su propio CRM) entre transferencia con SU cuenta o HG Cash con SU PROPIO
-- token -- nunca comparten nada entre si, y ninguno de los dos pasa por la
-- cuenta de Nahuel. metodo_cobro es ese selector.
--
-- Se corre UNA vez, a mano, contra goldpaw_control (provisionar.php no toca
-- esta carpeta):
--   sudo mariadb goldpaw_control < 06_metodo_cobro.sql
-- ---------------------------------------------------------------------------

USE goldpaw_control;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS metodo_cobro ENUM('transferencia','hgcash') NOT NULL DEFAULT 'transferencia'
    COMMENT 'Como este cliente recibe las cargas automaticas de sus jugadores.';

-- Credenciales de HG Cash PROPIAS de este cliente -- prefijo hg_propio_* a
-- proposito, para no confundirlas ni por accidente con las de la plataforma
-- (HG_ACTIVO/HG_API_TOKEN/... en config_plataforma, usadas por el modo
-- "casa" que sigue existiendo pero ya no se ofrece). hg_cliente_actual() en
-- hgcash_lib.php sigue siendo el lookup del modo viejo; estas son nuevas
-- funciones aparte (hg_propio_*).
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS hg_propio_activo         TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT 'Este cliente usa HG Cash con SU PROPIO token (no el de la plataforma).',
  ADD COLUMN IF NOT EXISTS hg_propio_token           TEXT         DEFAULT NULL
    COMMENT 'Access token de la cuenta HG Cash de este cliente. El cliente NUNCA ve el de la plataforma, ni al reves.',
  ADD COLUMN IF NOT EXISTS hg_propio_account_id      VARCHAR(80)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS hg_propio_webhook_secret  VARCHAR(190) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS hg_propio_modo            ENUM('prod','dev') NOT NULL DEFAULT 'prod';

-- ---------------------------------------------------------------------------
-- cobro_cuentas: cuentas de transferencia ADICIONALES a la principal
-- (clientes.cobro_alias/cobro_cbu/cobro_titular, que sigue siendo la #1 y se
-- sigue editando igual que siempre desde panel.html -- no se toca ni se
-- migra nada de ahi). Un cliente con varias billeteras carga las demas aca.
--
-- rl_crear_recarga() elige al azar entre TODAS las cuentas activas (la
-- principal + las de esta tabla) para cada recarga nueva: reparte la carga
-- entre billeteras sin que el jugador note diferencia (siempre transfiere a
-- UNA cuenta concreta, nunca se le muestran varias opciones a la vez).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cobro_cuentas (
  id          INT     AUTO_INCREMENT PRIMARY KEY,
  -- BIGINT y no INT: clientes.id es BIGINT en la base real (igual que ya
  -- hizo hg_transacciones.cliente_id en la migracion 05) aunque 01_control.sql
  -- todavia diga INT -- ese archivo quedo desactualizado, no lo tocamos aca.
  cliente_id  BIGINT  NOT NULL,
  alias       VARCHAR(120)  DEFAULT NULL,
  cbu         VARCHAR(40)   NOT NULL,
  titular     VARCHAR(120)  DEFAULT NULL,
  activa      TINYINT(1)    NOT NULL DEFAULT 1,
  creado      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cliente_activa (cliente_id, activa),
  CONSTRAINT fk_cobro_cuentas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
