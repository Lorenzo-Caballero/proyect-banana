-- Acceso de autoservicio para quienes compran solo la landing y el alta.
-- El vendedor crea el espacio y entrega un acceso inicial; el cliente configura
-- sus credenciales de Ganamos y WhatsApp desde /replica/configurar.html.
USE goldpaw_control;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS producto ENUM('crm','landing') NOT NULL DEFAULT 'crm'
    COMMENT 'crm = ecosistema completo; landing = alta automática y derivación a WhatsApp',
  ADD COLUMN IF NOT EXISTS landing_portal_usuario VARCHAR(80) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS landing_portal_password_hash VARCHAR(255) NULL DEFAULT NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_clientes_landing_portal_usuario (landing_portal_usuario);
