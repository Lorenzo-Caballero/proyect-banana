-- ---------------------------------------------------------------------------
-- Migracion 22: bitacora generica de acciones administrativas del CRM sin
-- fila propia (Fase 0.5, ver CRM_DESIGN.md).
--
-- El caso que la origina: "liberar retiros trabados" (modulo Retiros
-- pendientes, Fase A) es una accion MASIVA sobre acciones_saldo -- libera
-- TODAS las filas en 'procesando' de una vez, no una en particular -- asi
-- que no hay una fila unica donde anotar quien la disparo y cuando. Queda
-- reusable para lo que venga despues con la misma forma (accion sin dueño
-- natural).
--
-- `accion` es texto libre (sin ENUM) a proposito: sumar un tipo de accion
-- nuevo no debe pedir una migracion de schema.
--
-- Correr una sola vez: mysql -u USUARIO -p BASE < 22_crm_bitacora.sql
-- (o pegar en phpMyAdmin -> pestaña SQL)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS crm_bitacora (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  operador   VARCHAR(60)  NOT NULL,
  accion     VARCHAR(60)  NOT NULL,
  detalle    VARCHAR(300) NULL,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_operador (operador, creado_en),
  KEY ix_accion (accion, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
