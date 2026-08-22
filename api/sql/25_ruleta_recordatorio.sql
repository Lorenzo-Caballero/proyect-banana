-- ---------------------------------------------------------------------------
-- Migracion 25: registro de recordatorios de ruleta ya enviados.
--
-- El cron ruleta_recordatorio.php avisa (push + mensaje del bot) al jugador
-- cuando vuelve a tener un giro disponible. Esta tabla es el guard para no
-- mandarle el MISMO aviso dos veces el mismo dia si el cron corre seguido.
--
-- La PK compuesta (usuario, dia) hace el anti-duplicado gratis: el INSERT del
-- segundo intento del dia choca contra la PK y no manda nada.
--
-- Correr una vez por cada base de cliente:
--   mariadb BASE < 25_ruleta_recordatorio.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ruleta_recordatorios (
  usuario    VARCHAR(50) NOT NULL,
  dia        DATE        NOT NULL,
  enviado_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
