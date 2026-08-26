-- ---------------------------------------------------------------------------
-- Migracion 38: ajustes del sitio, editables por el agente desde el CRM.
--
-- Hasta ahora todo lo configurable vivia en constantes de PHP (los premios de
-- la ruleta, el alias de cobro, los minimos de carga). Cambiar cualquier cosa
-- era editar un archivo y desplegar, o sea: el agente dependia de nosotros
-- para apagar la ruleta un dia que no queria darla.
--
-- Es a proposito clave/valor y no una tabla con una columna por ajuste: los
-- ajustes se agregan de a uno y con esta forma no hace falta una migracion
-- nueva cada vez. Lo que SI necesita cada uno es su default en el codigo (ver
-- cfg_crm() en api/config_crm.php), asi una fila que no existe no rompe nada.
--
-- Correr una sola vez:  mariadb -u USUARIO -p BASE < 38_config_crm.sql
-- (la corre sola provisionar.php, que aplica api/sql/*.sql en orden)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS config_crm (
  clave       VARCHAR(60)  NOT NULL,
  valor       TEXT         NULL,
  -- Para la auditoria: quien lo toco y cuando. El CRM no tiene login por
  -- usuario en todos lados, pero donde lo hay conviene dejar rastro.
  actualizado TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  operador    VARCHAR(60)  NULL,
  PRIMARY KEY (clave)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- Valores iniciales. INSERT IGNORE: si alguien ya los cargo a mano, no se
-- pisan. Los defaults reales viven en el codigo -- esto solo hace que la
-- pantalla de Configuracion muestre algo la primera vez.
INSERT IGNORE INTO config_crm (clave, valor) VALUES
  ('ruleta_activa',      '1'),
  ('ruleta_mensaje',     ''),
  ('chat_activo',        '1'),
  ('registro_activo',    '1');
