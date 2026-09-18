-- ---------------------------------------------------------------------------
-- Migración 74: archivar una landing que ya no se usa.
--
-- EL PEDIDO (Nahuel, 18/09/2026): *"quiero que apliques la opción de archivar,
-- para que no salgan visibles ahí y moleste"*.
--
-- Borrar no alcanzaba, y a propósito: la que tiene historia NO se puede borrar
-- (su `slug` vive en `altas.origen` y en `gasto_diario`, y borrarla dejaría
-- esos registros sin dueño en Publicidad — ver landings_borrar). Así que las
-- que más molestan, que son justamente las viejas que ya trajeron gente, no
-- tenían forma de salir de la lista.
--
-- Pausar tampoco servía para eso: una landing pausada sigue mostrándose, solo
-- deja de funcionar el link. Son dos cosas distintas y hacían falta las dos:
--
--   pausada    el link deja de andar; se sigue viendo en la lista
--   archivada  sale de la lista; el historial queda intacto en Publicidad
--
-- Archivar IMPLICA pausar: una landing fuera de la vista que siga creando
-- cuentas es la peor combinación posible — nadie la mira y nadie la controla.
-- Eso lo hace landings_archivar(), no esta migración.
-- ---------------------------------------------------------------------------

ALTER TABLE landings
  ADD COLUMN IF NOT EXISTS archivada    TINYINT(1) NOT NULL DEFAULT 0
       COMMENT 'Fuera de la lista del CRM. El historial no se toca.',
  ADD COLUMN IF NOT EXISTS archivada_en DATETIME NULL DEFAULT NULL;
