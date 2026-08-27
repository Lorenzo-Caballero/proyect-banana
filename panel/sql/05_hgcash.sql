-- ---------------------------------------------------------------------------
-- Migracion 05 (goldpaw_control): HG Cash — libro mayor de la plataforma.
--
-- UNA tabla global y no una por cliente, a proposito: todos los clientes
-- cobran con EL MISMO token de HG (el del dueño de la plataforma), asi que
-- toda la plata entra y sale de UNA cuenta. Este libro es la unica fuente de
-- verdad de cuanto le corresponde a cada cliente en la liquidacion:
--
--   bruto     lo que pago el jugador (o lo que se le pago en un retiro)
--   comision  lo que se le cobra al cliente        (HG_COMISION_CLIENTE_PCT, 3.5%)
--   costo_hg  lo que HG le cobra a la plataforma   (HG_COSTO_HG_PCT, ~2%)
--   margen    comision - costo_hg = lo que queda para el dueño (~1.5%)
--   neto      bruto - comision    = lo que se le liquida al cliente
--
-- Los porcentajes se CONGELAN en la fila al crearla: si mañana cambia la
-- comision, las transacciones viejas se liquidan con el porcentaje que regia
-- cuando se hicieron, no con el nuevo.
--
-- Ademas es el mapa del webhook: HG postea a UNA URL para toda la
-- plataforma, y la fila (hg_id UNIQUE) dice a que cliente y a que base hay
-- que acreditarle.
--
-- Se corre UNA vez, a mano, contra goldpaw_control (provisionar.php no toca
-- esta carpeta):
--   mariadb goldpaw_control < /var/www/panel/sql/05_hgcash.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS hg_transacciones (
  id             BIGINT        AUTO_INCREMENT PRIMARY KEY,
  cliente_id     BIGINT        NOT NULL,
  db_nombre      VARCHAR(64)   NOT NULL,
  tipo           ENUM('deposito','retiro') NOT NULL,
  usuario        VARCHAR(50)   NULL,                -- jugador (username en la base del cliente)
  ref_tenant     VARCHAR(60)   NULL,                -- recargas.referencia o acciones_saldo.id
  hg_id          CHAR(36)      NOT NULL,            -- checkout id / transaction request id (UUID)
  monto          DECIMAL(14,2) NOT NULL,
  comision_pct   DECIMAL(5,2)  NOT NULL DEFAULT 3.50,
  costo_hg_pct   DECIMAL(5,2)  NOT NULL DEFAULT 2.00,
  comision       DECIMAL(14,2) NOT NULL DEFAULT 0,
  costo_hg       DECIMAL(14,2) NOT NULL DEFAULT 0,
  margen         DECIMAL(14,2) NOT NULL DEFAULT 0,
  neto           DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado         VARCHAR(32)   NOT NULL DEFAULT 'pendiente',
  checkout_url   VARCHAR(500)  NULL,
  detalle        TEXT          NULL,                -- ultimo payload/estado crudo, para pericia
  creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  acreditado_en  DATETIME      NULL,
  -- El UNIQUE es la idempotencia del webhook: HG reintenta hasta 4 veces y
  -- dos entregas del mismo evento no pueden acreditar dos veces.
  UNIQUE KEY uq_hg (hg_id),
  KEY ix_cliente (cliente_id, creado_en),
  KEY ix_estado  (estado, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
