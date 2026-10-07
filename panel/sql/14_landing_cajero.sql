-- 14_landing_cajero.sql — La landing de cada cliente, atada a su fila.
--
-- La landing NO vive acá: vive en la tabla `landings` de NUESTRA base de
-- cliente, que es justamente lo que hace que las cuentas se creen con
-- NUESTRAS credenciales de agente. Lo único que guarda el control es a quién
-- pertenece cada una.
--
-- Y TIENE QUE VIVIR DEL LADO DEL CONTROL, no adentro del JSON de la landing:
-- esa config pasa por la lista blanca de lp_config_sanear() cada vez que
-- alguien la edita desde el CRM, así que el vínculo con el cliente se
-- perdería en silencio la primera vez que se le tocara un color.
--
-- `landing_id` es con lo que el panel la ACTUALIZA (en vez de crear una
-- segunda); `landing_slug` es el link que se le pasa al cliente. Los dos
-- pueden quedar colgando si alguien borra la landing desde el CRM: el panel
-- trata "no está" como "todavía no tiene" y la vuelve a crear.
USE goldpaw_control;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS landing_id   INT         NULL DEFAULT NULL
    COMMENT 'id en `landings` de NUESTRA base: con esto el panel la actualiza',
  ADD COLUMN IF NOT EXISTS landing_slug VARCHAR(24) NULL DEFAULT NULL
    COMMENT 'slug de esa landing = el link que se le pasa al cliente';
