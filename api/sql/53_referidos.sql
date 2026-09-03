-- 53: Plan de referidos, estilo Temu.
--
-- Un cliente comparte SU link (bono.html?ref=<codigo>); el amigo se registra
-- por ahi y, cuando el amigo acredita su PRIMERA carga, al que lo trajo se le
-- suman bonos (monto editable en el CRM: ref_bono_monto en config_crm).
--
--   referidos_codigos  el codigo unico de cada cliente. Se genera la primera
--                      vez que hace falta (difusion del plan, o a pedido) y no
--                      cambia mas: el link compartido tiene que seguir valiendo.
--   altas.ref_codigo   con que codigo entro un alta. Es el rastro que une el
--                      registro con quien lo recomendo.
--   referidos          UN pago por amigo. La UNIQUE sobre `referido` ES el
--                      candado de pago (mismo criterio que pagos.id_unico y
--                      que el movimiento 'bono_bienvenida'): se inserta ANTES
--                      de acreditar, y si la fila ya esta, ya se pago.
--
-- Idempotente, como pide provisionar.php (corre contra todas las bases).

CREATE TABLE IF NOT EXISTS referidos_codigos (
  usuario    VARCHAR(80) NOT NULL,
  codigo     VARCHAR(16) NOT NULL,
  creado_en  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario),
  UNIQUE KEY uq_ref_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referidos (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  -- El amigo que entro por el link. UNIQUE: por cada jugador nuevo se paga
  -- UNA vez, a UNA persona, sin importar por cuantos caminos se acredite su
  -- primera carga (rl_acreditar, Comprobantes a mano, HG Cash).
  referido   VARCHAR(80)   NOT NULL,
  -- El cliente que lo trajo y cobro el bono.
  referidor  VARCHAR(80)   NOT NULL,
  bono       DECIMAL(12,2) NOT NULL DEFAULT 0,
  creado_en  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ref_referido (referido),
  KEY ix_ref_referidor (referidor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- En ALTER propio y con IF NOT EXISTS (leccion de la migracion 45: dos
-- columnas en un solo ALTER se caen juntas si una ya existe).
ALTER TABLE altas
  ADD COLUMN IF NOT EXISTS ref_codigo VARCHAR(16) NULL AFTER fbc;
