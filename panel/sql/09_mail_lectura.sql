-- ---------------------------------------------------------------------------
-- Migración 09 del control: la casilla de mail de cada cliente.
--
-- EL AGUJERO QUE CIERRA (verificado el 22/09/2026). La pantalla «Cómo cobro»
-- le ofrece al cliente el método Transferencia y le dice, textual:
--
--     "Con tu cuenta o billetera propia. El sistema lee tu casilla de mail y
--      acredita solo."
--
-- Eso era falso para cualquiera que no fuéramos nosotros. La configuración
-- IMAP vivía —y solo la nuestra— en `colector/config.json`, un archivo del
-- servidor: no había ningún campo en el CRM, ni nada por cliente en
-- provisionar.php. Un cliente que cargaba su billetera y elegía Transferencia
-- recibía las transferencias en SU cuenta, nadie leía SU casilla, y esas
-- recargas no se acreditaban nunca. Del lado nuestro no se veía nada roto.
--
-- POR QUÉ ACÁ Y NO EN LA BASE DEL CLIENTE. El lector de mails es UN proceso
-- (goldpaw-colector.service, un hilo por casilla) que tiene que levantar todas
-- las casillas de todos los clientes al arrancar. Con la config en cada base
-- de cliente tendría que abrir N conexiones para saber a quién escuchar, y
-- descubrir un cliente nuevo implicaría recorrerlas todas. Acá es una consulta.
-- Mismo criterio que `cobro_alias` y compañía, que también las carga el cliente
-- desde su CRM y viven acá.
--
-- POR QUÉ NO SE USA OAuth, que sería lo obvio. Gmail lo soporta para IMAP
-- (XOAUTH2), pero exige el scope `https://mail.google.com/`, que Google
-- clasifica como RESTRINGIDO: cualquier app que guarde o transmita esos datos
-- en sus propios servidores necesita una auditoría de seguridad hecha por un
-- asesor autorizado por Google. Miles de dólares por año y semanas de trámite.
-- Los scopes livianos de la API de Gmail dan encabezados, no el cuerpo, y el
-- cuerpo es donde está el monto. Queda la contraseña de aplicación.
-- ---------------------------------------------------------------------------

-- Host y puerto: casi siempre Gmail, pero un cliente puede usar otro proveedor.
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS mail_host VARCHAR(120) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS mail_puerto SMALLINT UNSIGNED NOT NULL DEFAULT 993,
  ADD COLUMN IF NOT EXISTS mail_usuario VARCHAR(190) NULL DEFAULT NULL;

-- LA CLAVE VA CIFRADA (api/cripto.php, AES-256-GCM), y conviene ser honesto
-- sobre cuánto compra eso: la llave vive en el mismo servidor, así que protege
-- contra un backup filtrado o una inyección SQL, NO contra alguien que ya entró
-- al VPS. Aun así es lo mínimo: es la contraseña de la casilla de otra persona.
--
-- No es su contraseña de Google real: es una CONTRASEÑA DE APLICACIÓN, que el
-- cliente genera para GOLDPAW y puede revocar cuando quiera sin tocar su
-- cuenta. Eso es lo que hace razonable pedírsela, y tiene que estar dicho en la
-- pantalla donde se la pedimos.
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS mail_clave TEXT NULL DEFAULT NULL;

-- EL FILTRO ES LO QUE HACE QUE ESTO SEA ACEPTABLE PARA EL CLIENTE. Nahuel lo
-- describió del modo en que lo hizo él: *"recibí un comprobante al mail y miré
-- la dirección del remitente. Luego configuré IMAP para que lea solo eso, así
-- leería todos los mails de ahí y no toda la casilla y los miles de mails que
-- tengo"*. El lector ya sabe filtrar por remitente, carpeta y asunto, y abre la
-- casilla en SOLO LECTURA (BODY.PEEK): nunca marca, mueve ni borra nada.
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS mail_carpeta VARCHAR(120) NOT NULL DEFAULT 'INBOX',
  ADD COLUMN IF NOT EXISTS mail_remitentes VARCHAR(400) NULL DEFAULT NULL;

-- `mail_activo` lo prende el cliente. Arranca apagado a propósito: una casilla
-- a medio configurar que se pone a escuchar sola es peor que ninguna.
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS mail_activo TINYINT(1) NOT NULL DEFAULT 0;

-- ESTAS DOS SON LAS QUE EVITAN EL FALLO SILENCIOSO, que es el que sale caro
-- acá. Si el cliente revoca la contraseña de aplicación, o Google la invalida,
-- o cambia el remitente de su banco, el efecto es que sus jugadores dejan de
-- cobrar -- y nada se rompe a la vista: el CRM abre, el chat contesta, las
-- recargas simplemente quedan pendientes para siempre.
--
-- `mail_visto_en` dice cuándo fue la última vez que esa casilla FUNCIONÓ (no
-- cuándo se intentó), y `mail_error` guarda el último motivo. Su CRM los
-- muestra, así el cliente se entera antes que sus jugadores.
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS mail_visto_en DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS mail_error VARCHAR(300) NULL DEFAULT NULL;

-- El colector pide "todas las casillas prendidas" en cada arranque y cada
-- tanto, para levantar las de los clientes nuevos sin reiniciar el servicio.
ALTER TABLE clientes
  ADD KEY IF NOT EXISTS ix_mail_activo (mail_activo);
