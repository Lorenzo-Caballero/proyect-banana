-- ---------------------------------------------------------------------------
-- Migracion 43: HG Cash — columnas del lado del cliente.
--
-- El grueso de HG vive en goldpaw_control (panel/sql/05_hgcash.sql): libro
-- mayor, comisiones, mapeo webhook->cliente. Aca solo va lo que las tablas
-- del cliente necesitan para ATAR sus filas a las de HG:
--
--   recargas.metodo           'transferencia' (legacy) o 'hgcash'
--   recargas.hg_checkout_id   el checkout que paga esta recarga
--   recargas.hg_url           el link de pago que se le dio al jugador
--
--   acciones_saldo.hg_*       el cash-out que le paga este retiro al jugador
--   acciones_saldo.destino_*  a donde va la plata (CBU/CVU/alias + titular)
--
--   usuarios.cobro_*          el destino GUARDADO del jugador, para no
--                             pedirselo en cada retiro
--
-- Todo aditivo e idempotente. La corre panel/provisionar.php.
-- ---------------------------------------------------------------------------

ALTER TABLE recargas
  ADD COLUMN IF NOT EXISTS metodo         VARCHAR(20) NOT NULL DEFAULT 'transferencia',
  ADD COLUMN IF NOT EXISTS hg_checkout_id CHAR(36)    NULL,
  ADD COLUMN IF NOT EXISTS hg_url         VARCHAR(500) NULL;

-- El webhook busca la recarga por el checkout: sin indice es un table scan
-- por cada pago que entra.
ALTER TABLE recargas
  ADD KEY IF NOT EXISTS ix_hg_checkout (hg_checkout_id);

ALTER TABLE acciones_saldo
  ADD COLUMN IF NOT EXISTS hg_request_id  CHAR(36)     NULL,
  ADD COLUMN IF NOT EXISTS hg_estado      VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS destino        VARCHAR(64)  NULL,   -- CBU/CVU/alias que dio el jugador
  ADD COLUMN IF NOT EXISTS destino_nombre VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS destino_cuit   VARCHAR(20)  NULL;

ALTER TABLE acciones_saldo
  ADD KEY IF NOT EXISTS ix_hg_request (hg_request_id);

ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS cobro_destino VARCHAR(64)  NULL,
  ADD COLUMN IF NOT EXISTS cobro_nombre  VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS cobro_cuit    VARCHAR(20)  NULL;
