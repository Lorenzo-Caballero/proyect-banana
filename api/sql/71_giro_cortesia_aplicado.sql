-- ---------------------------------------------------------------------------
-- Migración 71: los giros de cortesía USADOS dejan de figurar como bono
-- pendiente.
--
-- EL BUG (auditoría del 18/09/2026): al usar un giro de cortesía,
-- ruleta_giros_cortesia pasaba a 'usado' pero su fila madre en
-- `bonos_pendientes` (tipo 'giro') quedaba 'pendiente' para siempre — nadie
-- la marcaba: el único UPDATE a 'aplicado' filtra tipo IN ('fichas','pct').
-- La ficha del jugador sumaba "1×giro" pendiente por cada giro YA usado, que
-- es el "muestra cualquier cosa como bono pendiente" que reportó el dueño.
--
-- ruleta.php ya marca la fila al usar el giro (dentro de la misma
-- transacción); esto repara las que quedaron de antes. Idempotente: la
-- segunda pasada no encuentra filas.
-- ---------------------------------------------------------------------------

UPDATE bonos_pendientes b
  JOIN ruleta_giros_cortesia g ON g.bono_pendiente_id = b.id
   SET b.estado = 'aplicado',
       b.aplicado_en = COALESCE(g.usado_en, NOW())
 WHERE b.tipo = 'giro'
   AND b.estado = 'pendiente'
   AND g.estado = 'usado';
