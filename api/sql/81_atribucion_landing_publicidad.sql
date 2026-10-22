-- ---------------------------------------------------------------------------
-- Migracion 81: separar atribucion de landing del origen promocional.
--
-- `altas.origen` tambien decide qué bono se acredita. No debe ser el unico
-- lugar donde vive la atribucion publicitaria: una URL antigua/mal escrita
-- podia crear la cuenta sin bono y hacer desaparecer el registro del embudo.
-- `landing_slug` queda dedicado a Publicidad; el bono conserva su logica.
-- Cada base de tenant se migra por provisionar.php.
-- ---------------------------------------------------------------------------

ALTER TABLE altas
  ADD COLUMN IF NOT EXISTS landing_slug VARCHAR(24) NULL AFTER publicista_id;

ALTER TABLE altas
  ADD KEY IF NOT EXISTS idx_altas_landing_slug (landing_slug);

-- Recupera la atribución ya guardada por el flujo anterior y por el referer
-- de lp.html. Nunca cambia `origen`, porque ese campo también decide bonos.
UPDATE altas a
  JOIN landings l ON l.slug = SUBSTRING(a.origen, 4)
   SET a.landing_slug = l.slug
 WHERE a.landing_slug IS NULL
   AND a.origen REGEXP '^lp:[a-z0-9-]{1,24}$';

-- También rescata altas como holaTest384: la URL demuestra que entró por una
-- landing, aunque una URL mal copiada (`?1=`) hiciera que el navegador enviara
-- `origen='landing'`. La base ya seleccionada es la del tenant; el slug debe
-- existir ahí. Se aceptan solo las dos formas conocidas de lp.html.
UPDATE altas a
  JOIN landings l
    ON LOCATE(CONCAT('/lp.html?l=', l.slug), a.url_landing) > 0
    OR LOCATE(CONCAT('/lp.html?1=', l.slug), a.url_landing) > 0
   SET a.landing_slug = l.slug
 WHERE a.landing_slug IS NULL;
