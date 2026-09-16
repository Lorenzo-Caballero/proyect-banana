-- ---------------------------------------------------------------------------
-- Migracion 69: bloquear a un jugador del lado nuestro, y poder ver cuando
-- varias cuentas son la misma persona.
--
-- DE DONDE SALE (Nahuel, 16/09/2026): "descubri que holasofito763, holajuan969
-- y holaleiva89 son la misma persona". Lo descubrio a mano. El sistema tenia
-- los datos para verlo y no los cruzaba.
--
-- ============================================================================
-- POR QUE UNA COLUMNA NUEVA Y NO `usuarios.is_banned`
-- ============================================================================
-- `is_banned` YA EXISTE (migracion 03) y es tentador usarla. Seria un error:
-- esa columna es el ESPEJO del baneo de la plataforma. `usuarios_sync.php` la
-- pisa con `is_banned = VALUES(is_banned)` en cada pasada, o sea cada 5
-- minutos. Un bloqueo nuestro escrito ahi dura hasta el proximo sync y
-- desaparece sin dejar rastro ni error.
--
-- Son dos cosas distintas y conviene tenerlas separadas:
--
--   is_banned   lo decide GANAMOS. Es lo que de verdad le impide jugar,
--               porque el juego corre del lado de ellos. Se hace en el panel.
--   bloqueado   lo decidimos NOSOTROS. No le saca el juego: le corta lo
--               nuestro (chat, recargas, bonos, ruleta) y --lo que mas
--               importa-- impide que se abra OTRA cuenta.
--
-- Esa ultima parte es el punto. Banear una cuenta en el panel no sirve de nada
-- si la persona se crea la siguiente en dos minutos: eso es exactamente lo que
-- ya paso tres veces. El freno al alta es lo unico que corta la cadena.
-- ============================================================================
--
-- Correr una vez por cada base de cliente (lo hace panel/provisionar.php).
-- ---------------------------------------------------------------------------

ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS bloqueado        TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS bloqueado_en     DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS bloqueado_por    VARCHAR(60)  NULL,
  ADD COLUMN IF NOT EXISTS bloqueado_motivo VARCHAR(300) NULL;

-- Se consulta en cada turno del chat y en cada alta: tiene que ser barato.
ALTER TABLE usuarios
  ADD KEY IF NOT EXISTS ix_bloqueado (bloqueado);


-- ---------------------------------------------------------------------------
-- dispositivos_usuarios: QUE CUENTAS PASARON POR CADA CELULAR.
--
-- `dispositivos` (migracion 11) no sirve para esto, y el motivo es su propia
-- clave: `UNIQUE KEY uq_device (device_id)` con una columna `usuario` que se
-- PISA cuando entra otra cuenta desde el mismo aparato. Para notificar esta
-- perfecto --se le avisa a quien esta usando el telefono ahora-- pero borra
-- justo el dato que hace falta acá: que ANTES pasaron otras dos.
--
-- Entonces esta tabla guarda el historial, una fila por par. `usos` y las
-- fechas sirven para pesar el indicio: tres cuentas que entraron una vez cada
-- una el mismo dia no es lo mismo que tres que se usan todas las semanas.
--
-- OJO CON LO QUE NO PRUEBA. Un telefono compartido existe: la pareja, el
-- hermano, el locutorio. Por eso esto ALIMENTA UN AVISO y nunca un bloqueo
-- automatico -- ver vinculos_lib.php.
--
-- Empieza vacia y se llena de acá en adelante: no hay historial que rescatar,
-- porque nunca se guardo. Los vinculos viejos salen por CUIT/CBU y por IP,
-- que sí estan.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dispositivos_usuarios (
  device_id    VARCHAR(64) NOT NULL,
  usuario      VARCHAR(50) NOT NULL,
  usos         INT         NOT NULL DEFAULT 1,
  primera_vez  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultima_vez   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,

  -- Un par (aparato, cuenta) es UNO. El upsert suma `usos` y no duplica.
  PRIMARY KEY (device_id, usuario),

  -- Las dos direcciones se consultan: "que cuentas usaron este aparato" al
  -- registrar, y "que aparatos uso esta cuenta" al abrir su ficha.
  KEY ix_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- El alta ya guarda la IP (`altas.ip`, migracion 13) pero sin indice: buscar
-- "otras altas desde esta IP" recorria la tabla entera. Se consulta en CADA
-- alta nueva, asi que el indice no es un lujo.
--
-- La IP es la señal MAS DEBIL de las tres y hay que tratarla como tal: un
-- barrio entero detras del mismo NAT del celular comparte IP, y una familia
-- con el mismo WiFi tambien. Nunca alcanza sola para bloquear nada.
-- ---------------------------------------------------------------------------
ALTER TABLE altas
  ADD KEY IF NOT EXISTS ix_ip (ip);
