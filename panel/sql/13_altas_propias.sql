-- Enrutamiento opcional de altas y uso del agente propio por cliente.
-- El default preserva el circuito actual para los demás tenants.
USE goldpaw_control;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS altas_propias TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = registros y bot de altas usan el tenant y agente propios; 0 = circuito global existente';
