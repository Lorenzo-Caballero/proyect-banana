-- 60_recaudaciones.sql — Cola de pedidos de RECAUDACION de saldo inactivo.
--
-- El CRM no puede recaudar: recaudar es abrir el panel de agentes con un
-- navegador (Playwright) y eso vive en el bot del VPS. Igual que las altas y
-- las cargas, el CRM ENCOLA un pedido acá y el bot lo toma y ejecuta.
--
-- El agente toca «Recaudar» en el CRM -> una fila 'pendiente' con los topes
-- que eligio (dias de inactividad, paginas a saltear, max retiros, saldo
-- minimo, y si es prueba). El bot (bot_recaudar.py --demonio) la reclama,
-- corre, y deja el resultado acá para que el CRM lo muestre.
--
-- estado:
--   pendiente  -> esperando que el bot la tome
--   procesando -> el bot la esta ejecutando (reclamada; evita que dos la tomen)
--   hecha      -> termino (resultado en `resultado`, JSON con lo recaudado)
--   error      -> fallo (motivo en `mensaje`)
--
-- `dry_run` = fue una prueba (el bot lista a quien tocaria y NO retira). Es lo
-- que el boton manda por defecto: la primera vista siempre es inofensiva.
--
-- Idempotente. Requiere que exista al menos hasta la migracion 46
-- (usuarios.ultima_actividad, que usa inactivos.php).

CREATE TABLE IF NOT EXISTS recaudaciones (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  estado        ENUM('pendiente','procesando','hecha','error') NOT NULL DEFAULT 'pendiente',
  dry_run       TINYINT(1)   NOT NULL DEFAULT 1,
  dias          INT          NOT NULL DEFAULT 30,   -- inactividad minima
  saltar        INT          NOT NULL DEFAULT 4,    -- paginas a saltear (los de mas saldo)
  tope          INT          NOT NULL DEFAULT 10,   -- max retiros por corrida
  min_saldo     INT          NOT NULL DEFAULT 100,  -- no tocar saldos menores
  pedido_por    VARCHAR(60)  NULL,                  -- operador que la disparo
  tomada_en     DATETIME     NULL,                  -- cuando el bot la reclamo
  resultado     TEXT         NULL,                  -- JSON: {retirados, total, fallados, detalle:[...]}
  mensaje       VARCHAR(255) NULL,                  -- motivo si error
  creada_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizada_en DATETIME    NULL,
  PRIMARY KEY (id),
  KEY ix_recaud_estado (estado, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
