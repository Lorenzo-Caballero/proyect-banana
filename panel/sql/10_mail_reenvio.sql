-- ---------------------------------------------------------------------------
-- Migración 10 del control: conectar la casilla SIN pedir contraseñas.
--
-- DE DÓNDE SALE (Nahuel, 22/09/2026): *"hagamos eso sin pedirle tantas
-- contraseñas al cliente, así es más intuitivo y amigable todo"*.
--
-- LOS DOS CAMINOS, y por qué el nuevo es mejor para los dos lados:
--
--   'reenvio' (default)  El cliente configura en SU correo un reenvío
--                        automático de los avisos de su banco a una dirección
--                        nuestra, única por cliente:
--
--                            pagos+<slug>@<nuestra casilla>
--
--                        Nosotros leemos NUESTRA casilla —la que ya leíamos— y
--                        sabemos de quién es cada mail por la dirección a la
--                        que llegó. NO nos da ninguna credencial, no necesita
--                        activar la verificación en dos pasos, y si se
--                        arrepiente borra el reenvío y listo.
--
--   'imap'               Le pedimos servidor, usuario y una contraseña de
--                        aplicación y entramos a su casilla (migración 09).
--                        Queda para el que prefiera no tocar reenvíos.
--
-- EL TRADE-OFF, dicho sin maquillar: leyendo su casilla vemos el mail ORIGINAL,
-- con la firma DKIM del banco intacta, así que se puede verificar que el aviso
-- vino de verdad del banco. Reenviado, el mail nos llega desde SU casilla y esa
-- verificación se debilita: alguien con acceso a su correo podría reenviarnos
-- un aviso falso y el sistema acreditaría fichas sin plata real.
--
-- Ese riesgo es DEL CLIENTE, no nuestro: las fichas se acreditan a SUS
-- jugadores contra SU cuenta bancaria. El que se estafaría es él. Por eso se
-- lo puede ofrecer, y por eso el default es el camino que no pide contraseñas.
--
-- La dirección NO se guarda: se deriva del slug, que ya es único
-- (`clientes.slug UNIQUE`). Guardarla sería un segundo lugar donde puede
-- quedar desincronizada de lo que el colector espera.
-- ---------------------------------------------------------------------------

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS mail_modo ENUM('reenvio','imap') NOT NULL DEFAULT 'reenvio'
    AFTER mail_activo;

-- Los que ya tenían una casilla IMAP cargada siguen como estaban: cambiarles
-- el modo por un default los dejaría sin leer de un día para el otro.
UPDATE clientes
   SET mail_modo = 'imap'
 WHERE mail_usuario IS NOT NULL AND mail_usuario <> '';
