-- Enrutamiento de altas por agente. Nuevos tenants usan su agente propio;
-- los valores ya existentes no cambian al correr esta migración.
USE goldpaw_control;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS altas_propias TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = registros y bot de altas usan el tenant y agente propios; 0 = circuito global legacy';

-- Si la columna ya existía, ADD COLUMN IF NOT EXISTS no cambia su default.
ALTER TABLE clientes ALTER COLUMN altas_propias SET DEFAULT 1;
