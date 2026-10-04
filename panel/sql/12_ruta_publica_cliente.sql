-- Ruta publica editable por cliente, separada del slug interno e inmutable.
-- Ejecutar una vez en goldpaw_control antes de publicar el codigo que la usa.
-- Las rutas previas se conservan como alias para no romper bookmarks, cookies,
-- webhooks ni enlaces ya distribuidos. El panel las elimina solo al purgar
-- definitivamente el cliente; no se usa FK porque instalaciones antiguas
-- tienen clientes.id con tamaños distintos (INT/BIGINT).

USE goldpaw_control;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS ruta_slug VARCHAR(60) DEFAULT NULL AFTER slug;

UPDATE clientes
   SET ruta_slug = slug
 WHERE COALESCE(path_tenant, 0) = 1
   AND (ruta_slug IS NULL OR ruta_slug = '');

CREATE TABLE IF NOT EXISTS clientes_rutas_path (
  dominio VARCHAR(190) NOT NULL,
  ruta_slug VARCHAR(60) NOT NULL,
  cliente_id BIGINT NOT NULL,
  creada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (dominio, ruta_slug),
  KEY ix_clientes_rutas_path_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO clientes_rutas_path (dominio, ruta_slug, cliente_id)
SELECT dominio, ruta_slug, id
  FROM clientes
 WHERE COALESCE(path_tenant, 0) = 1
   AND ruta_slug IS NOT NULL AND ruta_slug <> '';
