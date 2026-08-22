-- ---------------------------------------------------------------------------
-- Migracion 04 (control): facturacion de la SUSCRIPCION del cliente a la
-- plataforma. Nada que ver con usuarios.balance/coins/bonus (eso es plata de
-- los JUGADORES, adentro de la base de cada cliente). Esto es lo que cada
-- cliente le paga al dueño de GOLDPAW por tener acceso al CRM.
--
-- saldo_usd se consume solo (ver panel/consumo_diario.php, corrido por cron)
-- y se recarga con MercadoPago (ver api/suscripcion.php + api/mp_webhook.php).
-- Sin saldo, el CRM de ESE cliente se bloquea (api/crm_auth.php); el resto de
-- su plataforma (bot, API de jugadores, chatbot) sigue andando.
--
-- A diferencia de api/sql/*.sql, esta carpeta NO la corre provisionar.php
-- (ese solo migra bases de CLIENTE). goldpaw_control es una sola base:
-- correr esto a mano, una vez, en el VPS:
--   sudo mariadb goldpaw_control < 04_facturacion.sql
-- ---------------------------------------------------------------------------

USE goldpaw_control;

-- suscripcion_estado va SEPARADO de clientes.estado (activo/pausado/baja): ese
-- lo controla el dueño a mano: un evento automatico del cron no debe pisarlo,
-- ni un ajuste manual de estado debe confundirse con "sin saldo".
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS saldo_usd          DECIMAL(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Saldo de suscripcion a la plataforma, en USD. No es plata de jugadores.',
  ADD COLUMN IF NOT EXISTS costo_diario_usd   DECIMAL(10,4) NOT NULL DEFAULT 63.3333
    COMMENT 'Prorrateo diario (~1900 USD/mes). Por-cliente para permitir excepciones.',
  ADD COLUMN IF NOT EXISTS suscripcion_estado ENUM('activa','sin_saldo','trial') NOT NULL DEFAULT 'trial'
    COMMENT 'sin_saldo bloquea el CRM. trial = cortesia inicial, el cron no descuenta.',
  ADD COLUMN IF NOT EXISTS trial_hasta        DATE DEFAULT NULL
    COMMENT 'Mientras hoy <= trial_hasta, el cron de consumo no descuenta ni bloquea.',
  ADD COLUMN IF NOT EXISTS ultimo_consumo     DATE DEFAULT NULL
    COMMENT 'Ultimo dia ya descontado por el cron -- evita descontar dos veces el mismo dia.';

-- Historial de pagos por MercadoPago. payment_id es UNIQUE pero admite
-- multiples NULL (varias preferences "pendientes" sin pagar todavia conviven
-- bien); el UNIQUE entra en juego recien cuando se completa al acreditar, que
-- es exactamente el punto a proteger contra doble acreditacion del webhook.
CREATE TABLE IF NOT EXISTS pagos_plataforma (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id     INT           NOT NULL,
  preference_id  VARCHAR(80)   NOT NULL,
  payment_id     VARCHAR(80)   DEFAULT NULL,
  plan           ENUM('quincena','mes') NOT NULL,
  monto_ars      DECIMAL(12,2) NOT NULL,
  monto_usd      DECIMAL(10,2) DEFAULT NULL,
  tipo_cambio    DECIMAL(10,4) DEFAULT NULL,
  estado         ENUM('pendiente','acreditado','rechazado','revision') NOT NULL DEFAULT 'pendiente',
  creado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acreditado_en  DATETIME DEFAULT NULL,
  raw_webhook    TEXT DEFAULT NULL,
  UNIQUE KEY uk_payment_id (payment_id),
  KEY idx_cliente (cliente_id),
  CONSTRAINT fk_pagos_plataforma_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Config de LA PLATAFORMA (no de un cliente puntual): el token de MercadoPago
-- es uno solo para todos los clientes, lo carga el dueno desde panel.html.
CREATE TABLE IF NOT EXISTS config_plataforma (
  clave  VARCHAR(60) PRIMARY KEY,
  valor  TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes manuales de saldo (cortesia, correccion de un error, etc.), con
-- motivo y quien lo hizo -- mismo espiritu que `movimientos` en la base de
-- cada cliente: no perder el rastro de por que cambio el saldo a mano.
CREATE TABLE IF NOT EXISTS ajustes_saldo_plataforma (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id  INT NOT NULL,
  delta_usd   DECIMAL(10,2) NOT NULL,
  motivo      VARCHAR(255) DEFAULT NULL,
  operador    VARCHAR(60)  DEFAULT NULL,
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ajustes_saldo_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
