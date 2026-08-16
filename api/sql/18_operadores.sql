-- ---------------------------------------------------------------------------
-- Migracion 18: operadores del CRM (Fase 0.5, ver CRM_DESIGN.md en la raiz).
--
-- Identidad de los agentes que usan crm.html/crm.php. No hay UI para crear
-- el primero: se hace con scripts/crear_operador.php (ver api/README.md),
-- porque no puede haber sesion sin que exista al menos un operador.
--
-- Permisos planos a proposito (sin columna de rol): con 2-3 operadores no
-- hace falta diferenciar todavia. Si en algun momento hace falta, es un
-- ALTER TABLE ADD COLUMN rol mas un chequeo en exigir_operador(), no un
-- rediseño (ver CRM_DESIGN.md, nota al final del documento).
--
-- Correr una sola vez: mysql -u USUARIO -p BASE < 18_operadores.sql
-- (o pegar en phpMyAdmin -> pestaña SQL)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS operadores (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nombre        VARCHAR(120) NULL,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login  DATETIME     NULL,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
