-- ---------------------------------------------------------------------------
-- Migracion 45: identificar una transferencia por el TITULAR declarado,
-- ademas del monto.
--
-- EL PROBLEMA
-- Hasta ahora una recarga se identificaba SOLO por el monto: rl_crear_recarga()
-- le suma centavos unicos (100.87) y el matcher casa por monto exacto, con un
-- respaldo por parte entera que exige que haya UNA SOLA candidata. Si dos
-- jugadores piden 1000 fichas al mismo tiempo y uno transfiere redondo, hay
-- dos candidatas y el pago se va a revision manual. Con publicidad corriendo
-- eso pasa seguido: mucha gente pide el mismo monto "lindo" a la vez.
--
-- LA PIEZA QUE FALTABA
-- Cuando el jugador pide la carga se le pregunta a nombre de QUIEN esta la
-- cuenta desde la que va a transferir. Eso se guarda en titular_declarado, y
-- al llegar el comprobante se compara contra el titular real que informa el
-- banco. Son dos nombres REALES con erratas en el medio ("NAHUER HERRRA" vs
-- "NAHUEL HERRERA"), y eso se resuelve con similitud (ver
-- rl_similitud_nombres en recargas_lib.php).
--
-- Antes no habia con que hacer esa comparacion: el unico nombre disponible era
-- el de usuario, que suele ser un apodo ("elkakas") y no se parece en nada al
-- titular de la cuenta. La landing tampoco pide nombre real -- solo el usuario.
--
-- Los centavos NO se sacan: siguen siendo el camino principal, porque son lo
-- unico que identifica la PRIMERA transferencia de alguien, cuando todavia no
-- hay ni huella ni historial. Lo que se agrega es como resolver cuando los
-- centavos no alcanzan (el jugador redondeo, o la billetera trunco decimales).
-- ---------------------------------------------------------------------------

ALTER TABLE recargas
  ADD COLUMN IF NOT EXISTS titular_declarado VARCHAR(120) NULL
    COMMENT 'A nombre de quien esta la cuenta desde la que el jugador va a transferir. Lo declara el al pedir la carga.',
  -- Por que se caso esta recarga con ese comprobante. No es adorno: cuando
  -- alguien reclama "pague y no me cargaron", esto dice si fue monto exacto,
  -- huella, nombre o a mano, y con cuanta confianza.
  ADD COLUMN IF NOT EXISTS match_confianza ENUM('alta','media','manual') NULL,
  ADD COLUMN IF NOT EXISTS match_motivo VARCHAR(200) NULL;

-- ---------------------------------------------------------------------------
-- huellas_pagador: desde que cuentas ya pago cada jugador.
--
-- Se aprende sola: cada vez que una recarga se acredita (automatica O a mano
-- desde el CRM) se guarda el CUIT/CBU del comprobante junto al usuario. La
-- proxima transferencia desde esa misma cuenta se identifica sin depender del
-- monto ni del nombre.
--
-- TRES COSAS QUE EL DISEÑO TIENE QUE RESPETAR, y por eso la PK es compuesta:
--
--  1. Un mismo jugador puede pagar desde VARIAS cuentas (su Mercado Pago hoy,
--     el banco manana). Cada combinacion es una fila propia, no un conflicto.
--  2. Puede pagar desde la cuenta de OTRA persona. Ahi el CUIT es de un
--     tercero, y esta bien que quede asociado a el: es la cuenta que usa.
--  3. Un mismo CUIT puede terminar asociado a VARIOS usuarios (el hermano que
--     le carga a dos jugadores). Por eso la huella NUNCA decide sola: solo
--     filtra candidatas que ya coincidieron en monto y ventana de tiempo, y
--     si quedan varias, sigue de largo a la comparacion por nombre.
--
-- Y sobre todo: la huella NO PUEDE SER UN REQUISITO. En la primera
-- transferencia de cualquier jugador no existe. Si no hay huella, no se
-- descarta nada -- se pasa a la capa siguiente.
--
-- cuit y cbu van NOT NULL DEFAULT '' y no NULL: forman parte de la clave
-- primaria, y en MySQL una PK no admite NULL (en SQLite si, por eso el
-- matcher.py original podia declararlos NULL).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS huellas_pagador (
  usuario     VARCHAR(50)  NOT NULL,
  cuit        VARCHAR(20)  NOT NULL DEFAULT '',
  cbu         VARCHAR(34)  NOT NULL DEFAULT '',
  nombre      VARCHAR(160) NULL COMMENT 'Titular que informo el banco la ultima vez.',
  usos        INT          NOT NULL DEFAULT 1,
  ultima_vez  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario, cuit, cbu),
  -- Los dos lookups del matcher: "quien pago alguna vez con este CUIT" y lo
  -- mismo por CBU. Van por separado porque un comprobante puede traer uno,
  -- el otro, o los dos.
  KEY ix_cuit (cuit),
  KEY ix_cbu  (cbu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
