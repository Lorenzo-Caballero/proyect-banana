-- ---------------------------------------------------------------------------
-- Migración 72: «saltar páginas» pasa a ser «saltar jugadores».
--
-- EL PROBLEMA (Nahuel, 21/09/2026): *"en el screenshot está en la página 4
-- pero no coinciden los datos con los de la campaña de recaudar"*.
--
-- No coincidían porque «página» significa dos cosas distintas:
--
--   el PANEL       tiene un selector «Mostrar en la página» que el operador
--                  pone en 10, 25, 50… (en su captura estaba en 10), así que
--                  su «página 4» son los jugadores 31 a 40.
--   el BOT         usa el tamaño de página de la API (50 fijo), así que
--                  «saltar 4» salta los 200 de mayor saldo.
--
-- Mirando la página 4 del panel se ven saldos de $75 a $50; el bot, con el
-- mismo «4», estaba tocando al jugador 201 en adelante, con saldos de $17.
-- Los dos números eran correctos y hablaban de cosas distintas.
--
-- LA UNIDAD QUE NO CAMBIA DE SIGNIFICADO ES EL JUGADOR. «Saltear los 200 de
-- mayor saldo» quiere decir lo mismo en el panel, en el bot y en la cabeza
-- del que lo configura, sin depender de un selector de otra pantalla.
--
-- `saltar` (en páginas de 50) se conserva y se sigue mandando: el bot se
-- despliega aparte, y uno viejo que no conozca `saltar_jug` tiene que seguir
-- haciendo lo más parecido posible en vez de saltar 0 y tocar a los que más
-- saldo tienen. NULL = no se eligió en jugadores; el bot cae a `saltar * 50`.
-- ---------------------------------------------------------------------------

ALTER TABLE recaudaciones
  ADD COLUMN IF NOT EXISTS saltar_jug INT NULL AFTER saltar;

-- Las corridas viejas quedan con su equivalente, para que la pantalla muestre
-- el mismo criterio en todo el historial y no un hueco.
UPDATE recaudaciones
   SET saltar_jug = saltar * 50
 WHERE saltar_jug IS NULL;
