-- ---------------------------------------------------------------------------
-- Migracion 02 (control): clientes SIN dominio propio, identificados por PATH.
--
-- Hasta ahora un cliente = un dominio propio (dominio UNIQUE). Para clientes
-- sin dominio, la solucion es un subcamino bajo el dominio del operador:
-- https://ganamoscrm.online/<slug>/... en vez de <slug>.ganamoscrm.online.
--
-- Eso significa que "dominio" deja de identificar sola a un cliente: muchos
-- clientes por-path van a compartir el mismo dominio='ganamoscrm.online'. Por
-- eso el UNIQUE simple de dominio se reemplaza por un UNIQUE compuesto
-- (dominio, slug) -- slug ya era UNIQUE de por si, asi que en la practica no
-- se relaja nada: dos clientes nunca pueden pisarse.
--
-- path_tenant marca la diferencia para quien arma URLs (provisionar.php,
-- nginx, el widget): 0 = dominio propio de siempre, 1 = entra por /slug/.
--
-- Aditiva y (mayormente) idempotente. Correr una vez en goldpaw_control:
--   mariadb goldpaw_control < 02_path_tenant.sql
-- ---------------------------------------------------------------------------

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS path_tenant TINYINT(1) NOT NULL DEFAULT 0 AFTER dominio;

-- El UNIQUE viejo tiene un nombre autogenerado por MySQL/MariaDB (típicamente
-- "dominio"); si tu instancia le puso otro nombre, revisá con:
--   SHOW INDEX FROM clientes WHERE Column_name = 'dominio' AND Non_unique = 0;
-- y ajustá el DROP INDEX de abajo antes de correr esto.
--
-- IF EXISTS porque en una instalación NUEVA ese índice no existe: 01_control.sql
-- ya crea la tabla con uk_dominio_slug. Sin esto, correr las migraciones de
-- control en orden sobre una base limpia cortaba acá con
-- "ERROR 1091: Can't DROP INDEX `dominio`" y el resto del archivo no se
-- aplicaba — lo que deja a cualquiera que provisione de cero preguntándose si
-- la migración corrió o no.
ALTER TABLE clientes DROP INDEX IF EXISTS dominio;

-- IF NOT EXISTS por lo mismo que el DROP de arriba: en una instalación nueva
-- 01_control.sql ya lo creó, y sin esto cortaba con
-- "ERROR 1061: Duplicate key name 'uk_dominio_slug'".
ALTER TABLE clientes
  ADD UNIQUE KEY IF NOT EXISTS uk_dominio_slug (dominio, slug);
