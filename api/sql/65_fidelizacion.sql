-- 65: Campaña de fidelización — avisos por escalón de inactividad.
--
-- El motor (fidelizacion_lib.php, disparado por cron via fidelizacion.php)
-- recorre a los jugadores inactivos y, al CRUZAR cada escalón configurado
-- (2 días -> 20%, 3 -> 25%... editable en el CRM), les manda push + mensaje
-- del chat y les arma el bono porcentual pendiente (bonos_pendientes, que ya
-- se auto-aplica al acreditarse la próxima recarga).
--
-- Esta tabla es EL CANDADO y a la vez la estadística: una fila por
-- (jugador, escalón, racha). La racha se identifica con actividad_ref = el
-- ultima_actividad del jugador al momento del aviso: si vuelve a jugar,
-- ultima_actividad cambia, y la próxima inactividad es OTRA racha -> los
-- escalones pueden avisar de nuevo. El INSERT IGNORE contra el UNIQUE se
-- hace ANTES de avisar (mismo patrón que ruleta_recordatorios): dos corridas
-- del cron pisándose no duplican ni el aviso ni el bono.

CREATE TABLE IF NOT EXISTS fidelizacion_avisos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario       VARCHAR(50)  NOT NULL,
  dias          INT          NOT NULL,               -- el escalón que disparó
  pct           INT          NOT NULL,               -- % prometido en ese aviso
  ruleta        TINYINT(1)   NOT NULL DEFAULT 0,     -- ¿incluyó giro de cortesía?
  actividad_ref DATETIME     NOT NULL,               -- ultima_actividad al avisar (la racha)
  bono_id       BIGINT       NULL,                   -- bonos_pendientes.id creado/mejorado
  enviado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fid (usuario, dias, actividad_ref),
  KEY ix_fid_enviado (enviado_en)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
