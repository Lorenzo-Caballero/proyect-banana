-- Plano de control (BD maestra). Va en una BASE APARTE de la de ganamos:
-- acá viven los clientes y su config, no jugadores. No lleva tenant_id (ella
-- ES quien define los tenants).
--
-- Correr una vez en el VPS:  sudo mariadb < 01_control.sql

CREATE DATABASE IF NOT EXISTS goldpaw_control
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE goldpaw_control;

-- Un cliente = un agente de ganamos con su propio dominio, cobro y config.
--
-- Dos formas de identificar a un cliente en la URL:
--   - dominio propio:  dominio='clienteX.com',        path_tenant=0 (o NULL)
--   - path compartido:  dominio='ganamoscrm.online',  path_tenant=1,
--                        y entra por ganamoscrm.online/<slug>/...
-- path_tenant=0 es el caso de siempre (el dominio identifica sola al cliente,
-- de ahí el UNIQUE compuesto con slug en vez de UNIQUE simple: permite que
-- muchos clientes por-path compartan el mismo dominio raíz).
CREATE TABLE IF NOT EXISTS clientes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(120)  NOT NULL,
  slug            VARCHAR(60)   NOT NULL UNIQUE,   -- id corto sin espacios (feudo, etc.)
  dominio         VARCHAR(190)  NOT NULL,          -- por donde entran sus jugadores
  path_tenant     TINYINT(1)    NOT NULL DEFAULT 0, -- 1 = se identifica por /slug/, no por dominio propio
  UNIQUE KEY uk_dominio_slug (dominio, slug),

  -- Credenciales del agente en ganamos (las usan los bots de ese cliente).
  agente_usuario  VARCHAR(120)  DEFAULT NULL,
  agente_password VARCHAR(190)  DEFAULT NULL,

  -- Cuenta de cobro (recargas por transferencia).
  cobro_alias     VARCHAR(120)  DEFAULT NULL,
  cobro_cbu       VARCHAR(40)   DEFAULT NULL,
  cobro_titular   VARCHAR(120)  DEFAULT NULL,
  coins_por_peso  DECIMAL(10,4) NOT NULL DEFAULT 1.0000,

  -- Llaves propias del cliente.
  cohere_key      VARCHAR(190)  DEFAULT NULL,
  bot_api_key     VARCHAR(190)  DEFAULT NULL,      -- se autogenera si no se pasa

  estado          ENUM('activo','pausado','baja') NOT NULL DEFAULT 'activo',
  notas           TEXT          DEFAULT NULL,
  creado          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Login del panel de operador (vos). Aparte del login de cada cliente.
CREATE TABLE IF NOT EXISTS operadores_panel (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  usuario       VARCHAR(60)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
