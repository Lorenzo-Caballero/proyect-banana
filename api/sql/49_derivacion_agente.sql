-- ---------------------------------------------------------------------------
-- Migracion 49: que "te paso con un agente" signifique algo.
--
-- EL BOT YA LO DECIA Y NO PASABA NADA. El contexto fijo tiene una seccion
-- entera ("CUANDO PASAS A UN AGENTE") con los seis casos en los que tiene que
-- derivar -- un pago que no cierra, plata que falta, la contrasena que no
-- sirve, cuando se lo piden. El bot los reconocia y contestaba "esto lo tiene
-- que ver un agente, ya se lo paso"... y ahi terminaba todo: no habia ninguna
-- herramienta detras, la conversacion quedaba igual que cualquier otra y el
-- agente se enteraba solo si la abria de casualidad. El jugador se quedaba
-- esperando a alguien que nunca fue avisado.
--
-- Estas dos columnas son la marca de que una conversacion FUE DERIVADA y sigue
-- sin atender. Con eso el CRM la puede destacar, contar en el badge del rail y
-- ordenarla primero, y el aviso de Telegram sabe que mandar.
--
-- POR QUE NO ALCANZA CON estado='pendiente'
-- `estado` lo mueve el agente a mano y significa "el ticket esta en algo".
-- Derivada es otra cosa: la pidio el BOT, tiene motivo, tiene hora, y se apaga
-- sola cuando alguien la contesta. Si lo metieramos en `estado`, la primera
-- vez que el agente cambia el estado se perderia el rastro de que el bot habia
-- pedido ayuda. Mismo criterio que `archivada` en la migracion 41.
-- ---------------------------------------------------------------------------

ALTER TABLE conversaciones
  ADD COLUMN IF NOT EXISTS derivada_en     DATETIME     NULL AFTER ia_activa,
  ADD COLUMN IF NOT EXISTS derivada_motivo VARCHAR(255) NULL AFTER derivada_en;

-- El CRM pregunta "cuantas hay sin atender" en cada refresco de la lista (cada
-- pocos segundos, con el CRM abierto todo el dia). Sin indice eso es un scan de
-- la tabla de conversaciones cada vez.
ALTER TABLE conversaciones
  ADD INDEX IF NOT EXISTS ix_derivada (derivada_en);
