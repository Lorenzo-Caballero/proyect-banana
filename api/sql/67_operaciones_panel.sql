-- 67. El LIBRO de lo que la plataforma ejecutó de verdad.
--
-- POR QUÉ EXISTE. Finanzas contaba los retiros desde `acciones_saldo`, que es
-- NUESTRA cola: solo tiene los que el jugador pide por el chat y ejecuta el
-- worker. El retiro que el jugador pide con el botón de adentro del juego, y el
-- que el operador hace directo desde el panel, no pasan por ahí.
--
-- Medido el 14/09/2026 contra el panel, sobre 60 días:
--     el libro del panel : 44 retiros por $157.630
--     lo que veía Finanzas:  5 retiros por      $692
-- O sea que la ganancia venía sobrestimada en casi todo lo que sale.
--
-- QUÉ ES ESTE LIBRO Y POR QUÉ SE PUEDE CONFIAR EN ÉL
--     GET /api/agent_admin/payment/requests/history/?type=0|1&date_from=&date_to=
--
-- `type` filtra: 0 = depósito, 1 = retiro. El parámetro `status` SE IGNORA
-- (pedir 0 y 1 devuelve lo mismo) y todas las filas vuelven con `status: 1`.
-- Eso no es un bug de la API: es que este endpoint NO lista solicitudes con su
-- resultado, lista OPERACIONES EJECUTADAS.
--
-- Verificado el 14/09/2026, y de la única forma que prueba algo: se tomó un
-- depósito que nosotros rechazamos a mano desde el CRM (request_id 234314468,
-- holanahuel265, $100, rechazado el 13/09 19:17) y se buscó en el libro. NO
-- ESTÁ. Y no es un hueco de paginación: los ids inmediatamente anterior y
-- posterior (234312811 y 234322596) sí están.
--
-- De ahí la regla que sostiene todo lo que se construye encima:
--
--     estar en este libro ES la prueba de que la operación se ejecutó,
--     y no estar es la prueba de que no.
--
-- Con eso se resuelve lo que faltaba: un retiro de `retiros_panel` que quedó
-- 'cerrado' se PAGÓ si su request_id figura acá, y se RECHAZÓ si no figura.
-- Antes no había forma de distinguirlos y Auditoría tenía que decir "no
-- sabemos si se pagó o se rechazó".
--
-- LAS FECHAS VIENEN EN NUESTRA MISMA ZONA, verificado con dos cruces exactos:
-- la acción 98 de `acciones_saldo` (ejecutada_en 2026-09-14 01:33:07) contra la
-- fila 234638975 del libro (created_at "2026-09-14 01:33"), y el retiro
-- 234580006 del espejo (primera_vez 00:41:12) contra su created_at "00:39". Sin
-- desfasaje: `cuando` se puede comparar directo contra el resto de la base.
--
-- Se guardan las DOS clases de operación, no solo los retiros: los depósitos
-- sirven de control cruzado sobre los que el bot marcó como 'hecha' sin que la
-- plataforma los registre, que es exactamente el bug del documento para Fauno.
--
-- Idempotente: se corre en cada deploy (panel/provisionar.php).
CREATE TABLE IF NOT EXISTS operaciones_panel (
    -- El id que le puso ganamos a la operación. PK a propósito: el worker
    -- reenvía la misma ventana cada vez y ver la misma fila diez veces no
    -- puede duplicarla ni sumar plata dos veces.
    payment_id  BIGINT        NOT NULL PRIMARY KEY,
    -- 0 = depósito (entró plata), 1 = retiro (salió plata).
    tipo        TINYINT       NOT NULL,
    username    VARCHAR(60)   NOT NULL DEFAULT '',
    monto       DECIMAL(12,2) NOT NULL DEFAULT 0,
    titular     VARCHAR(160)  NULL,        -- item.name
    destino     VARCHAR(120)  NULL,        -- item.cbu
    -- item.comment. Vale '' o 'direct withdrawal'; lo segundo es la operación
    -- que hace el agente directo (nuestro worker, o el operador en el panel) y
    -- lo primero la que nace de un pedido del jugador. Se guarda tal cual
    -- porque es la única señal de quién la originó.
    comentario  VARCHAR(60)   NULL,
    -- item.created_at crudo, como llega. Se guarda ADEMÁS de `cuando` por el
    -- mismo motivo que peticiones_carga.creada_api: viene de afuera, y si algún
    -- día cambia el formato conviene tener el original para darse cuenta.
    creada_api  VARCHAR(40)   NULL,
    -- `creada_api` parseado. Es por donde filtran Finanzas y Auditoría, así que
    -- necesita ser DATETIME de verdad y no texto.
    cuando      DATETIME      NULL,
    visto_en    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_tipo_cuando (tipo, cuando),
    -- Indice propio sobre `cuando` SOLO, ademas del compuesto de arriba. Es
    -- para fn_libro_desde(), que hace MIN(cuando) en cada request de Finanzas:
    -- el compuesto arranca por `tipo`, asi que un MIN(cuando) a secas no lo
    -- puede usar y terminaria escaneando la tabla entera. Con este, es una
    -- lectura de una sola fila del indice.
    KEY ix_cuando (cuando),
    KEY ix_usuario (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
