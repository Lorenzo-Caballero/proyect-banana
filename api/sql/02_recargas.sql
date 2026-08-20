-- ---------------------------------------------------------------------------
-- Migracion 02: recargas de coins + pagos capturados del mail.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 02_recargas.sql
-- ---------------------------------------------------------------------------

-- Saldo de coins del jugador (el "monedero" del juego de drones).
ALTER TABLE jugadores
  ADD COLUMN coins BIGINT NOT NULL DEFAULT 0;


-- ---------------------------------------------------------------------------
-- recargas: cada pedido de carga que hace un usuario desde el chatbot.
--
-- La clave del match automatico es monto_pedido: al monto en pesos se le suman
-- centavos UNICOS (01..99) entre las recargas pendientes del mismo monto, asi
-- el importe exacto de la transferencia identifica a que recarga corresponde.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recargas (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  referencia     VARCHAR(12)  NOT NULL,           -- codigo corto para el usuario
  usuario        VARCHAR(50)  NOT NULL,           -- nombre_usuario del jugador
  coins          BIGINT       NOT NULL,           -- coins a acreditar
  monto_base     DECIMAL(12,2) NOT NULL,          -- pesos (coins / coins_por_peso)
  monto_pedido   DECIMAL(12,2) NOT NULL,          -- base + centavos unicos
  centavos       SMALLINT     NULL,               -- 1..99, la parte que hace unico
  estado         ENUM('pendiente','acreditada','vencida','cancelada','revision')
                 NOT NULL DEFAULT 'pendiente',
  pago_id        VARCHAR(64)  NULL,               -- id_unico del pago que la cerro
  mensaje        VARCHAR(300) NULL,
  creada_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vence_en       DATETIME     NOT NULL,
  acreditada_en  DATETIME     NULL,
  UNIQUE KEY uq_ref (referencia),
  KEY ix_estado_monto (estado, monto_pedido),
  KEY ix_usuario (usuario, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- pagos: transferencias capturadas de los mails por el colector.
-- id_unico = nro de transaccion (o un hash). UNIQUE para no procesar dos veces.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pagos (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_unico         VARCHAR(64)   NOT NULL,
  monto            DECIMAL(12,2) NULL,
  remitente        VARCHAR(160)  NULL,
  cuit             VARCHAR(20)   NULL,
  cbu_origen       VARCHAR(34)   NULL,
  nro_transaccion  VARCHAR(60)   NULL,
  entidad          VARCHAR(120)  NULL,
  fecha_operacion  VARCHAR(40)   NULL,
  dkim_pass        TINYINT(1)    NOT NULL DEFAULT 0,
  mail_de          VARCHAR(160)  NULL,
  estado           ENUM('pendiente','usado','revision') NOT NULL DEFAULT 'pendiente',
  recarga_id       BIGINT        NULL,
  capturado_en     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pago (id_unico),
  KEY ix_estado_monto (estado, monto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
