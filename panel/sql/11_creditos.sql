-- ---------------------------------------------------------------------------
-- Migracion 11 (control): CREDITOS PREPAGOS POR TRANSACCION.
--
-- El modelo con el que se vende el servicio (decision del dueño, 29/09/2026):
-- el cajero carga creditos en pesos transfiriendo USDT, y de ese saldo se le
-- descuenta un 2% de cada carga que procesa con nuestro sistema.
--
-- NO REEMPLAZA a la suscripcion por dia de la migracion 04: convive. Un
-- cliente esta en UNO de los dos modelos segun `cobro_modelo`, y el default es
-- el viejo para que desplegar esto no le cambie la facturacion a nadie que ya
-- este andando.
--
-- Nada de esto es plata de jugadores: eso vive en la base de cada cliente
-- (usuarios.balance/coins/bonus). Esto es lo que el cajero nos paga a nosotros.
--
-- Correr a mano, una vez:  sudo mariadb goldpaw_control < 11_creditos.sql
-- (o dejarlo a scripts/migrar-control.php, que ya aplica esta carpeta en orden)
-- ---------------------------------------------------------------------------

USE goldpaw_control;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS cobro_modelo ENUM('suscripcion','transaccion') NOT NULL DEFAULT 'suscripcion'
    COMMENT 'suscripcion = el cron por dia de la migracion 04. transaccion = 2% por carga.',
  ADD COLUMN IF NOT EXISTS creditos_ars DECIMAL(14,2) NOT NULL DEFAULT 0.00
    COMMENT 'Creditos prepagos en PESOS. Baja con cada carga que procesa.',
  ADD COLUMN IF NOT EXISTS comision_pct DECIMAL(5,2) NOT NULL DEFAULT 2.00
    COMMENT 'Que porcentaje de cada carga se le cobra. Por-cliente para permitir excepciones.',
  ADD COLUMN IF NOT EXISTS aviso_umbral_ars DECIMAL(14,2) NOT NULL DEFAULT 10000.00
    COMMENT 'Debajo de esto se le avisa que se esta quedando sin creditos. 0 = no avisar.',
  ADD COLUMN IF NOT EXISTS creditos_desde DATETIME DEFAULT NULL
    COMMENT 'Desde cuando se le cobra por transaccion. LAS CARGAS ANTERIORES NO SE COBRAN: '
            'sin esto, activarle el modelo a un cliente con historial le vaciaria el saldo '
            'de una con cargas de hace meses que nunca acordo pagar.',
  ADD COLUMN IF NOT EXISTS aviso_saldo_en DATETIME DEFAULT NULL
    COMMENT 'Ultimo aviso de saldo bajo. Evita repetirlo en cada pasada del cron.';

-- ---------------------------------------------------------------------------
-- QUE SE LE COBRO Y POR QUE CARGA. Es el detalle que el cliente puede auditar
-- y el unico lugar donde se ve como se gasto el saldo.
--
-- EL UNIQUE ES LA GUARDA QUE IMPORTA. El cobro lo hace un cron que reenvia una
-- ventana de tiempo, no solo lo nuevo (asi una pasada perdida se recupera sola,
-- mismo criterio que el libro de operaciones). Sin la clave unica, cada pasada
-- volveria a cobrar las mismas cargas y le vaciaria el saldo al cliente en
-- horas. `via` + `referencia` es la identidad de una carga en
-- publicidad_sql_cargas(), que es la definicion canonica.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consumos_plataforma (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  cliente_id    INT           NOT NULL,
  via           VARCHAR(20)   NOT NULL COMMENT 'transferencia | juego',
  referencia    VARCHAR(100)  NOT NULL COMMENT 'recargas.referencia o el id del movimiento',
  usuario       VARCHAR(60)   DEFAULT NULL,
  monto_carga   DECIMAL(14,2) NOT NULL,
  comision_pct  DECIMAL(5,2)  NOT NULL,
  comision_ars  DECIMAL(14,2) NOT NULL,
  cuando        DATETIME      DEFAULT NULL COMMENT 'Cuando se acredito la carga (no cuando se cobro).',
  cobrado_en    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_carga (cliente_id, via, referencia),
  KEY idx_cliente_cuando (cliente_id, cuando),
  CONSTRAINT fk_consumos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- LAS RECARGAS DE CREDITOS POR USDT.
--
-- El cliente transfiere a NUESTRA billetera y pega el hash de la transaccion.
-- El sistema lo verifica contra la blockchain (destino, monto, confirmaciones)
-- y acredita. No custodiamos llaves ni generamos una direccion por cliente:
-- solo LEEMOS una cadena que es publica.
--
-- EL UNIQUE DE txid ES GLOBAL Y NO POR CLIENTE, a proposito: si fuera por
-- cliente, el mismo hash serviria para cargarle creditos a dos cuentas
-- distintas -- una transferencia, dos acreditaciones. Es la misma guarda que
-- `pagos.id_unico` en las recargas de jugadores.
--
-- Se guarda la COTIZACION APLICADA en la fila y no se recalcula nunca: el
-- dolar se mueve, y una recarga vieja tiene que poder explicarse con el numero
-- que regia ese dia.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recargas_usdt (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id     INT           NOT NULL,
  txid           VARCHAR(100)  NOT NULL COMMENT 'Hash de la transaccion en la cadena.',
  red            VARCHAR(20)   NOT NULL DEFAULT 'TRC20',
  monto_usdt     DECIMAL(16,6) DEFAULT NULL,
  cotizacion     DECIMAL(14,4) DEFAULT NULL COMMENT 'Pesos por USDT al momento de acreditar.',
  monto_ars      DECIMAL(14,2) DEFAULT NULL,
  estado         ENUM('pendiente','acreditada','rechazada','revision') NOT NULL DEFAULT 'pendiente',
  motivo         VARCHAR(255)  DEFAULT NULL COMMENT 'Por que se rechazo o quedo en revision.',
  destino        VARCHAR(80)   DEFAULT NULL COMMENT 'La billetera a la que llego, como vino de la cadena.',
  confirmaciones INT           DEFAULT NULL,
  raw            TEXT          DEFAULT NULL,
  creado         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acreditado_en  DATETIME      DEFAULT NULL,
  UNIQUE KEY uk_txid (txid),
  KEY idx_cliente (cliente_id, creado),
  CONSTRAINT fk_recargas_usdt_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La billetera y la cotizacion son de LA PLATAFORMA, no de un cliente: viven
-- en config_plataforma (migracion 04) y las carga el dueño desde su panel.
INSERT IGNORE INTO config_plataforma (clave, valor) VALUES
  ('usdt_red',            'TRC20'),
  ('usdt_wallet',         ''),
  ('usdt_cotizacion_ars', '0'),
  ('usdt_min_confirmaciones', '19');
