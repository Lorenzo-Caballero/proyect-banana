-- ---------------------------------------------------------------------------
-- Migracion 40: Raspa y Gana + Tragamonedas 777.
--
-- config_crm NO se crea aca: ya existe (migracion 38) y es clave/valor a
-- proposito, para que un ajuste nuevo no necesite esquema nuevo. Los defaults
-- REALES viven en CFG_CRM_DEFAULTS (api/config_crm.php); estos INSERT solo
-- hacen que la pantalla de Configuracion muestre las filas la primera vez.
--
-- NO se toca ruleta_giros: esta en produccion con datos y su UNIQUE
-- (session_id, dia) sigue siendo el limite del giro. El limite POR USUARIO que
-- a la ruleta le falta se resuelve en juego_reclamos_dia, que es tabla nueva y
-- por lo tanto no puede fallar contra filas viejas.
--
-- Correr una vez por base:  mariadb -u USUARIO -p BASE < 40_juegos.sql
-- (la corre sola provisionar.php, que aplica api/sql/*.sql en orden)
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO config_crm (clave, valor) VALUES
  ('raspa_activo',   '0'),
  ('raspa_mensaje',  ''),
  ('raspa_tope_dia', '0'),
  ('slot_activo',    '0'),
  ('slot_mensaje',   ''),
  ('slot_tope_dia',  '0');

-- ---------------------------------------------------------------------------
-- El libro de lo que la casa pago hoy, y el limite indexado de los juegos de
-- uno-por-dia. La PK compuesta ES el limite: se INSERTA y se captura el 1062,
-- nunca se chequea antes -- un SELECT previo tiene condicion de carrera y con
-- dos pestanas abiertas se cobra dos veces.
--
-- No hace JOIN con `usuarios` (se busca por parametro literal): por eso no
-- necesita COLLATE explicito pese al uca1400 de esa tabla.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS juego_reclamos_dia (
  usuario   VARCHAR(50) NOT NULL,
  juego     VARCHAR(20) NOT NULL,        -- 'ruleta' | 'raspa' | 'slot'
  dia       DATE        NOT NULL,
  premio    BIGINT      NOT NULL DEFAULT 0,
  creado_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario, juego, dia),
  KEY ix_juego_dia (juego, dia, premio)  -- cubridor del SUM() del tope diario
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- RASPA Y GANA. Dos fases (crear -> raspar), pero por otro motivo que la
-- ruleta: aca la identidad ya esta verificada, y el token existe para que el
-- cobro sea idempotente ante un doble toque y para que recargar la pagina a
-- mitad del rascado recupere el MISMO carton.
--
-- dia_diario es el truco del limite: = CURDATE() en el carton GRATIS del dia y
-- NULL en los de ticket. MySQL no considera colision entre NULLs, asi que un
-- solo UNIQUE da "uno gratis por dia" sin bloquear los regalos del CRM y sin
-- necesitar una segunda tabla de cortesia.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS raspa_cartones (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario      VARCHAR(50) NOT NULL,
  dia          DATE        NOT NULL,     -- siempre CURDATE(): para reportes
  dia_diario   DATE        NULL,         -- = dia en el gratis; NULL en ticket
  indice       TINYINT     NOT NULL,     -- fila de RASPA_PREMIOS
  premio_bonus BIGINT      NOT NULL,     -- unica fuente de verdad del monto
  celdas       VARCHAR(48) NOT NULL,     -- "2,0,2,1,2,0" indices ya sorteados
  token        VARCHAR(64) NOT NULL,
  cobrado      TINYINT(1)  NOT NULL DEFAULT 0,
  ticket_id    BIGINT      NULL,
  ip           VARCHAR(45) NULL,
  creado_en    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cobrado_en   DATETIME    NULL,
  UNIQUE KEY uq_diario (usuario, dia_diario),
  UNIQUE KEY uq_token (token),
  KEY ix_usuario_dia (usuario, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- TRAGAMONEDAS 777. Una sola fase: la sesion esta verificada por JWT, asi que
-- no hay ventana anonima que atar con un token. La fila ES el consumo: se
-- INSERTA primero y recien despues se acredita, para que un corte pierda el
-- premio en vez de duplicarlo.
--
-- El limite de N por dia sale del UNIQUE, no de un contador: el server intenta
-- nro = 1, 2, 3 y captura el 1062. Las tiradas de ticket entran desde
-- nro = 1000 para no pelearse con el cupo diario.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS slot_tiradas (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario      VARCHAR(50) NOT NULL,
  dia          DATE        NOT NULL,
  nro          SMALLINT    NOT NULL,     -- 1..N del dia; >=1000 = de ticket
  indice       TINYINT     NOT NULL,     -- fila de SLOT_PAGOS
  rodillos     VARCHAR(16) NOT NULL,     -- "4,4,4" indices de SLOT_SIMBOLOS
  premio_bonus BIGINT      NOT NULL,
  ticket_id    BIGINT      NULL,
  ip           VARCHAR(45) NULL,
  creado_en    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuario_dia_nro (usuario, dia, nro),
  KEY ix_dia (dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Regalos del agente. Generalizado con la columna `juego` para no necesitar
-- una tabla por juego nuevo. El ENUM de dos valores + usado_en es lo que
-- permite el consumo atomico con un UPDATE condicional.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS juego_tickets (
  id                BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario           VARCHAR(50) NOT NULL,
  juego             VARCHAR(20) NOT NULL,   -- 'raspa' | 'slot'
  estado            ENUM('pendiente','usado') NOT NULL DEFAULT 'pendiente',
  bono_pendiente_id BIGINT   NULL,
  creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usado_en          DATETIME NULL,
  KEY ix_usuario_juego_estado (usuario, juego, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
