-- ---------------------------------------------------------------------------
-- Migracion 42: plantillas de mensaje del CRM.
--
-- Respuestas enlatadas que el agente dispara con /comando en el composer o
-- con un atajo de teclado (F3, Ctrl+G...). Viven en la BASE y no en
-- localStorage a proposito: el equipo comparte las plantillas -- si "como
-- cargar" la escribe el admin, todos los agentes contestan lo mismo, en vez
-- de cinco versiones distintas del mismo instructivo.
--
-- `comando` es UNIQUE: /saludo tiene que significar UNA cosa. `atajo` no:
-- es una comodidad de teclado y si dos plantillas declaran F3 gana la
-- primera en orden -- molesto, no peligroso.
--
-- La corre panel/provisionar.php contra cada base de cliente.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS plantillas_mensaje (
  id         BIGINT       AUTO_INCREMENT PRIMARY KEY,
  comando    VARCHAR(30)  NOT NULL,          -- sin la barra: 'saludo'
  texto      TEXT         NOT NULL,
  atajo      VARCHAR(40)  NULL,              -- 'F3', 'Ctrl+G', 'Alt+1'...
  creado_por VARCHAR(60)  NULL,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_comando (comando)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
