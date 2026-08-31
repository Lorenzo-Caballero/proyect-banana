-- ---------------------------------------------------------------------------
-- Migracion 48: espejo de las cargas pedidas desde el boton "Depositos" de la
-- plataforma, para poder aprobarlas solas cuando la transferencia ya entro.
--
-- EL PROBLEMA QUE RESUELVE
-- El jugador tiene dos formas de cargar fichas:
--
--   camino B (el nuestro)  chatbot -> alias -> transfiere -> mail -> pagos.php
--                          -> el matcher la casa contra la tabla `recargas`
--
--   camino A (el de ellos) boton "Depositos" DENTRO de la plataforma -> la
--                          solicitud queda en el panel de agentes -> transfiere
--                          -> un agente la aprueba a mano
--
-- El camino A no lo procesaba nadie. Y no es que tardara: los pagos de ese
-- camino NO PUEDEN CASAR NUNCA. El matcher cruza cada transferencia contra
-- `recargas`, y una carga pedida desde la plataforma no crea ninguna fila ahi
-- -- la solicitud vive del otro lado. Por construccion caen siempre en
-- `pagos.estado='revision'`, se acumulan y solo salen a mano.
--
-- POR QUE HACE FALTA UNA TABLA Y NO ALCANZA CON LEER EL PANEL
-- Dos cosas que no se pueden resolver en memoria entre vuelta y vuelta:
--
--   1. `primera_vez` -- el ancla de la regla de seguridad. Una transferencia
--      solo respalda una solicitud si entro DESPUES de que la solicitud
--      aparecio. Sin persistir ese momento, cada corrida del worker lo
--      reinicia a "ahora" y la regla no sirve para nada.
--   2. Idempotencia: saber que la #222789687 ya se aprobo, aunque el panel la
--      siga listando unos segundos mas.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS peticiones_carga (
  -- El id que le puso ganamos a la solicitud. PK a proposito: el worker hace
  -- upsert por ese id, asi que ver la misma solicitud diez veces no duplica.
  request_id     BIGINT        NOT NULL PRIMARY KEY,
  username       VARCHAR(60)   NOT NULL,
  -- item.name: el titular que el jugador declaro al pedir la carga. Es la
  -- misma señal que ya sabe leer rl_similitud_nombres().
  titular        VARCHAR(160)  NULL,
  monto          DECIMAL(12,2) NOT NULL,
  alias_destino  VARCHAR(120)  NULL,
  -- item.created_at, tal cual lo manda el panel. Texto y no DATETIME por el
  -- mismo motivo que pagos.fecha_operacion: viene de afuera, con su formato y
  -- su zona horaria, y parsearlo para guardarlo seria inventar precision.
  -- El reloj que manda para las decisiones es `primera_vez`, que es nuestro.
  creada_api     VARCHAR(40)   NULL,
  -- Cuando NOSOTROS vimos la solicitud por primera vez. Se fija al insertar y
  -- no se toca nunca mas: es el ancla de la regla de temporalidad.
  primera_vez    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado         ENUM('esperando','aprobada','revision','error','cerrada')
                 NOT NULL DEFAULT 'esperando',
  confianza      VARCHAR(10)   NULL COMMENT 'alta | media',
  -- Por que se aprobo o por que no. En castellano y para que lo lea el
  -- operador en el CRM: es justo lo que le falta al camino B, donde el motivo
  -- de la revision se devuelve por HTTP y se pierde.
  motivo         VARCHAR(255)  NULL,
  -- La transferencia que respalda esta solicitud (pagos.id_unico).
  pago_id_unico  VARCHAR(64)   NULL,
  actualizada_en DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,

  -- EL RECLAMO ATOMICO. Dos solicitudes no pueden quedarse con la misma
  -- transferencia: la segunda choca contra este UNIQUE y se queda esperando.
  -- Es la misma idea que el "reclamar antes de entregar" de acciones_cola.php,
  -- pero resuelta por la base en vez de por una transaccion nuestra.
  -- MySQL permite varios NULL en un UNIQUE, que es justo lo que hace falta:
  -- muchas solicitudes conviven sin pago asignado.
  UNIQUE KEY uq_pago (pago_id_unico),
  KEY ix_estado (estado),
  KEY ix_username (username)

-- SIN `COLLATE` EXPLICITO, A PROPOSITO. Esta tabla se JOINea con `pagos`
-- (pago_id_unico = pagos.id_unico), y `pagos` se creo asi, con charset y sin
-- collation (sql/02_recargas.sql:60). Declarar la misma clausula garantiza que
-- las dos resuelvan a la collation por defecto de utf8mb4 en ESE servidor, sea
-- cual sea, y que el JOIN no necesite castear.
--
-- Ponerle utf8mb4_unicode_ci (como tienen las tablas del CRM) la dejaria del
-- lado equivocado del choque de collations que documenta CLAUDE.md, y cada
-- consulta contra `pagos` o `usuarios` necesitaria COLLATE a mano.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Porcentaje de bono que se le suma a una carga aprobada por este camino.
-- Arranca en 0: sin bono, la carga entra por el monto exacto que transfirio el
-- jugador. Cada cliente lo sube desde Configuracion si su negocio lo usa.
--
-- La plataforma lo soporta nativo (el campo `bonus_percent` de la solicitud),
-- pero hay que fijarlo ANTES de aprobar: el bono se calcula en el momento de
-- la aprobacion y despues ya no se puede cargar.
INSERT INTO config_crm (clave, valor)
VALUES ('lim_bono_carga_pct', '0')
ON DUPLICATE KEY UPDATE clave = clave;
