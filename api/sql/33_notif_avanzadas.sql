-- ---------------------------------------------------------------------------
-- Migracion 33: notificaciones avanzadas -- filtro por inactividad, presets
-- de filtro reutilizables, y bonos pendientes (fichas/porcentaje/giro de
-- ruleta) prometidos por notificacion y aplicados solos en la proxima carga.
--
-- filtro_usado + lote_id en `notificaciones`: un envio a jugadores inactivos
-- no puede ser una sola fila con usuario=NULL (a diferencia de "todos", el
-- sondeo del celular no puede evaluar "¿soy inactivo?" por si solo) -- se
-- crea una fila POR DESTINATARIO, todas con el mismo lote_id, para que el
-- historial del CRM las agrupe y muestre como un unico envio.
--
-- ruleta_giros_cortesia queda separada de ruleta_giros a proposito: no toca
-- el UNIQUE (session_id, dia) ni el limite de 1 giro normal por dia. Un
-- jugador puede tener su giro normal MAS uno de cortesia pendiente.
--
-- Correr una vez por cada base de cliente (lo hace panel/provisionar.php).
-- ---------------------------------------------------------------------------

ALTER TABLE notificaciones
  ADD COLUMN IF NOT EXISTS filtro_usado TEXT NULL AFTER programada_en,
  ADD COLUMN IF NOT EXISTS lote_id VARCHAR(36) NULL AFTER filtro_usado,
  ADD KEY IF NOT EXISTS ix_lote (lote_id);

CREATE TABLE IF NOT EXISTS notif_presets_filtro (
  id             BIGINT       AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(80)  NOT NULL,
  filtro_json    TEXT         NOT NULL,   -- {"modo":"inactivos","dias":30} | {"modo":"todos"} | {"modo":"usuario","usuario":"..."}
  creado_por     VARCHAR(60)  NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Promesa de bono atada a un jugador puntual (por notificacion o suelta).
-- tipo='fichas'|'pct' se aplican solos en rl_acreditar() (proxima recarga
-- acreditada, automatica o asignada a mano -- ver recargas_lib.php).
-- tipo='giro' no espera recarga: crmnotif_bono_crear() ya deja la fila
-- correspondiente en ruleta_giros_cortesia en el mismo momento.
CREATE TABLE IF NOT EXISTS bonos_pendientes (
  id              BIGINT       AUTO_INCREMENT PRIMARY KEY,
  usuario         VARCHAR(50)  NOT NULL,
  tipo            ENUM('fichas','pct','giro') NOT NULL,
  valor           INT          NOT NULL DEFAULT 0,   -- coins fijos o % segun tipo; ignorado si giro
  estado          ENUM('pendiente','aplicado','cancelado') NOT NULL DEFAULT 'pendiente',
  prometido_por   VARCHAR(60)  NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aplicado_en     DATETIME     NULL,
  recarga_id      BIGINT       NULL,
  notificacion_id BIGINT       NULL,
  KEY ix_usuario_estado (usuario, estado),
  KEY ix_notificacion (notificacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ruleta_giros_cortesia (
  id                BIGINT       AUTO_INCREMENT PRIMARY KEY,
  usuario           VARCHAR(50)  NOT NULL,
  estado            ENUM('pendiente','usado') NOT NULL DEFAULT 'pendiente',
  bono_pendiente_id BIGINT       NULL,
  creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usado_en          DATETIME     NULL,
  KEY ix_usuario_estado (usuario, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El calculo de inactividad ("no carga hace N dias") hace un NOT EXISTS
-- correlacionado por usuario contra recargas.acreditada_en: sin este indice
-- compuesto, cada evaluacion es un scan completo de la tabla.
ALTER TABLE recargas
  ADD KEY IF NOT EXISTS ix_usuario_estado_acreditada (usuario, estado, acreditada_en);
