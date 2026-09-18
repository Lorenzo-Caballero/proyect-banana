-- ---------------------------------------------------------------------------
-- Migración 73: bloquear por IP al que no tiene cuenta.
--
-- EL PEDIDO (Nahuel, 18/09/2026): *"ver si se puede bloquear a un jugador
-- pesado por IP o algo así, para que no pueda hablar al chat ni siquiera, o
-- que no pueda hacer nada"*.
--
-- El bloqueo por USUARIO ya corta todo (chat incluido) y el corte por
-- multicuenta alcanza al anónimo por su aparato. Lo que faltaba es el que no
-- tiene cuenta NI app: llega por el navegador, molesta, borra el
-- `session_id` y vuelve. Contra ese, lo único que queda es la IP.
--
-- ============================================================================
-- ESTO NO SE PODÍA HACER AYER, Y VALE ENTENDER POR QUÉ
-- ============================================================================
-- Hasta el 18/09/2026 `REMOTE_ADDR` era el edge de Cloudflare: en `altas.ip`
-- no había NI UNA IP de jugador, y una sola "IP" tenía 124 cuentas. Bloquear
-- una habría sacado del chat a todos los jugadores que entraran por ese edge
-- --sin ningún error visible, solo gente que "no puede escribir"--. El arreglo
-- de `ip_cliente()` es lo que hace que esta tabla tenga sentido; medido después
-- del arreglo, las IPs son direcciones argentinas reales con 1 a 4 cuentas.
--
-- ============================================================================
-- POR QUÉ ES TEMPORAL POR DEFECTO
-- ============================================================================
-- Una IP no identifica a una persona: la comparten una familia, un WiFi, el
-- NAT de la telefónica. Por eso esta tabla NO la escribe nada automático --es
-- siempre una decisión de un operador-- y `hasta` existe para que el caso
-- normal ("este tipo está rompiendo las pelotas hoy") caduque solo. Un bloqueo
-- permanente se pone a mano, con `hasta` en NULL, sabiendo lo que se hace.
--
-- `conversaciones.ip` guarda la última IP vista en ese chat: sin eso el
-- operador no tiene qué bloquear cuando el que molesta no tiene cuenta.
-- ---------------------------------------------------------------------------

ALTER TABLE conversaciones
  ADD COLUMN IF NOT EXISTS ip          VARCHAR(45) NULL DEFAULT NULL
       COMMENT 'Ultima IP vista en este chat (ip_cliente, no REMOTE_ADDR)',
  ADD COLUMN IF NOT EXISTS ip_vista_en DATETIME NULL DEFAULT NULL;

ALTER TABLE conversaciones
  ADD INDEX IF NOT EXISTS idx_conversaciones_ip (ip);

CREATE TABLE IF NOT EXISTS bloqueos_ip (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip         VARCHAR(45)  NOT NULL,
  motivo     VARCHAR(255) NULL DEFAULT NULL,
  operador   VARCHAR(60)  NULL DEFAULT NULL,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- NULL = sin vencimiento. El caso normal lleva fecha: ver el encabezado.
  hasta      DATETIME     NULL DEFAULT NULL,
  levantado_en DATETIME   NULL DEFAULT NULL,
  levantado_por VARCHAR(60) NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bloqueos_ip (ip),
  KEY idx_bloqueos_ip_hasta (hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
