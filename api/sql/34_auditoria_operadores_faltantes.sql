-- ---------------------------------------------------------------------------
-- Migracion 34: reconstruye columnas de "Fase 0.5" que el código YA usa en
-- producción (recargas_lib.php, crm_recargas.php, crm_lib.php) pero cuya
-- migración original (rango 18-23) nunca llegó a subirse al repo.
--
-- Sin esto, el Módulo de Auditoría (crm_auditoria.php) no tiene forma real
-- de saber quién acreditó una recarga a mano vs. el matcher automático, ni
-- quién es dueño de una fila en la bitácora de acciones administrativas.
--
-- Las bases de clientes YA en producción (Hostinger, VPS) probablemente ya
-- tengan estas columnas aplicadas a mano (ver TODO_FASE_A.md, "Fase 0.5") --
-- por eso todo va con IF NOT EXISTS: correr esto ahí no debe romper nada.
--
-- Correr una vez por cada base de cliente (lo hace panel/provisionar.php).
-- ---------------------------------------------------------------------------

-- pagos.asignado_por/asignado_en: quién asignó MANUALMENTE un comprobante en
-- revisión a una recarga puntual (rl_asignar_manual(), recargas_lib.php).
-- NULL = lo acreditó el matcher automático solo (rl_matchear_y_acreditar()).
ALTER TABLE pagos
  ADD COLUMN IF NOT EXISTS asignado_por VARCHAR(60)  NULL AFTER recarga_id,
  ADD COLUMN IF NOT EXISTS asignado_en  DATETIME     NULL AFTER asignado_por;

-- recargas.cancelada_por/cancelada_en: qué operador canceló una recarga
-- pendiente desde el CRM (crm_recargas.php::cancelar).
ALTER TABLE recargas
  ADD COLUMN IF NOT EXISTS cancelada_por VARCHAR(60) NULL AFTER acreditada_en,
  ADD COLUMN IF NOT EXISTS cancelada_en  DATETIME    NULL AFTER cancelada_por;

-- movimientos.operador: qué operador cargó fichas/bono a mano desde el CRM
-- (crm_lib.php::crm_cargar(), crm.php acción cargar_fichas/cargar_bono).
-- NULL cuando el origen es 'ruleta'/'chatbot'/'sistema' (nadie humano lo
-- disparó), o en filas viejas insertadas antes de que esta columna
-- existiera -- crm_movimientos.php ya documentaba este bug de casing dando
-- por hecho que la columna estaba, pero su migración tampoco llegó al repo.
ALTER TABLE movimientos
  ADD COLUMN IF NOT EXISTS operador VARCHAR(60) NULL AFTER origen;

-- crm_bitacora: bitácora de acciones administrativas del CRM sin fila propia
-- donde anotar quién/cuándo (aprobar/liberar/reintentar retiros, cancelar
-- recargas, bonos_pendientes). Usada por crm_lib.php::crm_bitacora().
CREATE TABLE IF NOT EXISTS crm_bitacora (
  id         BIGINT       AUTO_INCREMENT PRIMARY KEY,
  operador   VARCHAR(60)  NOT NULL,
  accion     VARCHAR(60)  NOT NULL,
  detalle    VARCHAR(300) NULL,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_operador (operador, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- operadores: login propio de agentes/admins del CRM (crm_auth.php). Sin
-- esta tabla no hay sesión posible, así que en la práctica ya existe en
-- cualquier base con el CRM andando -- se recrea acá solo por completitud
-- del repo, con el schema mínimo que crm_auth.php necesita.
CREATE TABLE IF NOT EXISTS operadores (
  id             BIGINT       AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(60)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  rol            ENUM('admin','agente') NOT NULL DEFAULT 'admin',
  operador       VARCHAR(60)  NULL,
  ultimo_login   DATETIME     NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices para el UNION ALL de auditoría: filtra siempre por rango de fecha
-- y suele filtrar por estado. Sin esto, cada consulta de auditoría hace
-- table scan en las 3 fuentes a la vez.
ALTER TABLE recargas
  ADD KEY IF NOT EXISTS ix_estado_acreditada (estado, acreditada_en);
ALTER TABLE acciones_saldo
  ADD KEY IF NOT EXISTS ix_estado_ejecutada (estado, ejecutada_en);
ALTER TABLE movimientos
  ADD KEY IF NOT EXISTS ix_creado_en (creado_en);
