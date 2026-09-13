-- 64. Espejo de los retiros que el jugador pide DENTRO del juego.
--
-- POR QUÉ: había tres formas de pedir un retiro y la pantalla «Retiros
-- pendientes» solo mostraba dos. Los del chat y los que carga el operador van a
-- `acciones_saldo`; los que el jugador pide desde el juego solo disparaban un
-- Telegram y no quedaban en ningún lado. O sea que el canal con MÁS volumen
-- era el único invisible: en esta base el último retiro por chat es del
-- 19/08/2026, y sin embargo llegan pedidos todo el tiempo.
--
-- NO SE GUARDAN EN `acciones_saldo`, y esto es lo importante: esa tabla es la
-- COLA que nuestro worker ejecuta. Un retiro del panel metido ahí se pagaría
-- dos veces -- una en el panel (donde lo resuelve el operador) y otra por el
-- bot. Son cosas distintas y viven separadas, igual que `peticiones_carga` vive
-- separada de `recargas`.
--
-- Esta tabla es SOLO UN ESPEJO: se refresca cada minuto con lo que lista el
-- panel y no se ejecuta nada desde acá. Se resuelve en ganamos y el espejo se
-- entera solo, igual que las cargas pedidas en el juego.
--
-- Idempotente: se corre en cada deploy (panel/provisionar.php).
CREATE TABLE IF NOT EXISTS retiros_panel (
    request_id     BIGINT       NOT NULL PRIMARY KEY,   -- el id de ganamos
    username       VARCHAR(60)  NOT NULL DEFAULT '',
    titular        VARCHAR(160) NULL,                   -- a nombre de quién va
    monto          DECIMAL(12,2) NOT NULL DEFAULT 0,
    destino        VARCHAR(120) NULL,                   -- CBU/alias al que cobra
    creada_api     VARCHAR(40)  NULL,                   -- lo que informa el panel
    -- 'abierto'  el panel todavía lo lista: falta resolverlo
    -- 'cerrado'  ya no aparece: alguien lo pagó o lo rechazó en el panel
    estado         ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
    primera_vez    DATETIME     NOT NULL,               -- cuándo lo vimos por primera vez
    actualizada_en DATETIME     NULL,
    KEY ix_estado_vez (estado, primera_vez)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
