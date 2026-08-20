-- ---------------------------------------------------------------------------
-- Migracion 11: notificaciones push (bonos, fichas, promos).
--
-- No hay Firebase ni servicio de terceros: el APK sondea api/notificaciones.php
-- cada ~15 min con un WorkManager y muestra la notificacion local. El widget
-- hace lo mismo cada 25 s mientras la app esta abierta, y la muestra como
-- tarjeta adentro de la pantalla.
--
-- Tres tablas:
--   * dispositivos            -> quien puede recibir (celular <-> usuario)
--   * notificaciones          -> QUE se manda. usuario NULL = para todos.
--   * notificaciones_entregas -> a QUIEN ya se le mostro. Evita repetir y evita
--                                tener que duplicar una fila por jugador cuando
--                                se manda un aviso masivo.
--
-- OJO con las collations: `usuarios` quedo en uca1400 y estas tablas van en
-- utf8mb4_unicode_ci, igual que las del CRM. Por eso NINGUNA consulta de
-- notificaciones_lib.php hace JOIN contra `usuarios`: el nombre de usuario
-- viaja siempre como parametro.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 11_notificaciones.sql
-- ---------------------------------------------------------------------------

-- Un celular (o navegador) que puede recibir avisos. `device_id` lo genera el
-- widget y lo guarda en localStorage; el APK lo copia a SharedPreferences por
-- el puente JS para poder sondear aunque la pantalla este cerrada.
CREATE TABLE IF NOT EXISTS dispositivos (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id   VARCHAR(64)  NOT NULL,
  usuario     VARCHAR(50)  NULL,               -- = usuarios.username, NULL si todavia no entro
  plataforma  ENUM('android','web') NOT NULL DEFAULT 'web',
  modelo      VARCHAR(80)  NULL,
  version     VARCHAR(20)  NULL,
  permitido   TINYINT(1)   NOT NULL DEFAULT 1, -- dio permiso de notificaciones en Android
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  visto_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_device (device_id),
  KEY ix_usuario (usuario),
  KEY ix_visto (visto_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El aviso en si. Una sola fila aunque vaya a mil jugadores.
CREATE TABLE IF NOT EXISTS notificaciones (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario   VARCHAR(50)  NULL,                 -- NULL = para todos los que tengan la app
  titulo    VARCHAR(120) NOT NULL,
  cuerpo    VARCHAR(400) NOT NULL,
  tipo      ENUM('bono','fichas','recarga','ruleta','promo','aviso')
                         NOT NULL DEFAULT 'aviso',
  url       VARCHAR(300) NULL,                 -- adonde lleva al tocarla (opcional)
  origen    VARCHAR(20)  NOT NULL DEFAULT 'crm',
  creada_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en DATETIME     NULL,                 -- despues de esto ya no se entrega
  KEY ix_usuario (usuario, id),
  KEY ix_creada (creada_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Acuse por dispositivo. La PK compuesta es la que garantiza que un aviso se
-- muestre UNA sola vez: el sondeo inserta primero y solo entrega las filas que
-- logro insertar, asi el worker del APK y el widget no la muestran los dos.
CREATE TABLE IF NOT EXISTS notificaciones_entregas (
  notificacion_id BIGINT      NOT NULL,
  device_id       VARCHAR(64) NOT NULL,
  entregada_en    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  leida_en        DATETIME    NULL,            -- la toco / la abrio
  PRIMARY KEY (notificacion_id, device_id),
  KEY ix_device (device_id, entregada_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
