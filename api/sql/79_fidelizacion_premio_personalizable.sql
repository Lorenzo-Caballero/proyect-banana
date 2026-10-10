-- Fidelización: conserva en el historial qué tipo de premio se prometió.
-- El valor `pct` se mantiene para reportes y clientes antiguos.
ALTER TABLE fidelizacion_avisos
  ADD COLUMN IF NOT EXISTS premio_tipo ENUM('ninguno','pct','fichas') NOT NULL DEFAULT 'pct',
  ADD COLUMN IF NOT EXISTS premio_valor INT NOT NULL DEFAULT 0;

-- Compatibilidad con avisos anteriores: antes solo se registraba el porcentaje.
UPDATE fidelizacion_avisos
   SET premio_tipo = 'pct', premio_valor = pct
 WHERE premio_tipo = 'pct' AND premio_valor = 0 AND pct > 0;
