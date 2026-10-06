-- ---------------------------------------------------------------------------
-- Migracion 78: que el CRM sepa cuando el jugador dice que ya transfirio.
--
-- Un comprobante IMAP apagado no debe convertir esa declaracion en un silencio:
-- conservamos la hora y el canal para mostrarla en "Cargas por transferencia".
-- Es solo una declaracion, nunca prueba de pago ni autorizacion de acreditacion.
-- ---------------------------------------------------------------------------

ALTER TABLE recargas
  ADD COLUMN IF NOT EXISTS pago_reportado_en DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS pago_reportado_origen VARCHAR(24) NULL DEFAULT NULL;
