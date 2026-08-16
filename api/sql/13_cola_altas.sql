-- ---------------------------------------------------------------------------
-- Migracion 13: vuelve la cola de altas, ahora separada de los jugadores.
--
-- La 07 borro `jugadores`, y con ella se llevo puesta la cola que alimentaba a
-- bot_crear_jugador.py: hoy cola_panel.php responde
--     SQLSTATE[42S02] Table '...jugadores' doesn't exist
-- y el bot sondea para siempre sin recibir trabajo.
--
-- Esta tabla NO revive `jugadores`. Aquella mezclaba dos cosas (el jugador y
-- el pedido de alta) y por eso tenia que borrar la clave en claro sobre el
-- mismo registro que despues consultaba todo el sitio. `altas` es solo la cola:
-- una fila es UN PEDIDO de alta, y cuando el bot lo cumple, el jugador real
-- baja por espejo con sync_usuarios.py a `usuarios`, que sigue siendo la unica
-- fuente de verdad. La fila queda como historial, sin la clave.
--
-- Sobre `password`: se guarda EN CLARO a proposito y no hay forma de evitarlo.
-- El bot no consume una API, TIPEA el formulario del panel como una persona;
-- un hash no se puede tipear. Por eso la clave vive lo minimo indispensable
-- (se borra sola en cuanto el alta se confirma) y este endpoint exige API key.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 13_cola_altas.sql
-- (o pegarlo en la pestana SQL de phpMyAdmin)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS altas (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Lo que el bot tipea en el formulario "Crear jugador".
  usuario   VARCHAR(64)  NOT NULL,
  password  VARCHAR(128) NULL,          -- en claro y temporal: NULL = ya se uso
  email     VARCHAR(190) NULL,
  nombre    VARCHAR(80)  NULL,          -- si viene vacio, el bot lo inventa
  apellido  VARCHAR(80)  NULL,

  -- Estado en la cola. 'ok' = ya existe en el panel de agentes.
  estado    ENUM('pendiente','procesando','ok','error') NOT NULL DEFAULT 'pendiente',
  intentos  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  tomado_en DATETIME NULL,              -- cuando lo reclamo el bot (zombies)
  hecho_en  DATETIME NULL,              -- cuando quedo creado en el panel
  mensaje   VARCHAR(500) NULL,          -- ultimo detalle que informo el bot

  origen    VARCHAR(32) NOT NULL DEFAULT 'crm',   -- crm | chatbot | landing
  pedido_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Que la base rechace el duplicado es lo unico confiable: un "si no existe,
  -- insertar" en PHP se pisa solo si el jugador toca dos veces el boton.
  UNIQUE KEY uq_alta_usuario (usuario),

  -- El indice que usa el bot en cada sondeo (cada 30s, para siempre).
  KEY idx_cola (estado, id)
)
ENGINE=InnoDB
-- Sin COLLATE explicito A PROPOSITO: asi cae en el default del servidor, que
-- es el mismo con el que nacio `usuarios` (utf8mb4_uca1400_ai_ci en esta base).
-- Si se fijara utf8mb4_unicode_ci como las tablas del CRM, comparar
-- altas.usuario con usuarios.username tiraria "Illegal mix of collations" y
-- habria que meter COLLATE en cada consulta.
DEFAULT CHARSET=utf8mb4;
