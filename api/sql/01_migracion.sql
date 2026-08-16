
CREATE TABLE IF NOT EXISTS jugadores (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre_usuario  VARCHAR(50)  NOT NULL,
  contrasena      VARCHAR(255) NOT NULL,      -- hash password_hash()
  correo          VARCHAR(160) NOT NULL,
  nombre          VARCHAR(80)  NOT NULL,
  apellido        VARCHAR(80)  NOT NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_nombre_usuario (nombre_usuario),
  UNIQUE KEY uq_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


ALTER TABLE jugadores

  ADD COLUMN creado TINYINT(1) NOT NULL DEFAULT 0,

  ADD COLUMN panel_estado ENUM('pendiente','procesando','ok','error')
      NOT NULL DEFAULT 'pendiente',

  ADD COLUMN panel_intentos  TINYINT      NOT NULL DEFAULT 0,
  ADD COLUMN panel_mensaje   VARCHAR(500) NULL,
  ADD COLUMN panel_tomado_en DATETIME     NULL,
  ADD COLUMN panel_creado_en DATETIME     NULL,

  ADD COLUMN panel_password VARCHAR(255) NULL,

  ADD KEY idx_panel_cola (panel_estado, id),
  ADD KEY idx_creado (creado);


UPDATE jugadores
   SET panel_estado  = 'error',
       panel_mensaje = 'Sin password en claro: espera a que el jugador vuelva a entrar'
 WHERE panel_password IS NULL
   AND creado = 0;
