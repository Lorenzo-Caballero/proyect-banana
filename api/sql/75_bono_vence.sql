-- ---------------------------------------------------------------------------
-- Migración 75: los bonos prometidos vencen.
--
-- EL PEDIDO (Nahuel, 19/09/2026). Al repasar el estado del sistema apareció
-- que había **629 bonos pendientes y ninguno vence nunca**. Hoy no duele — el
-- más viejo es del 16/09 — pero es una deuda que solo crece: alguien que
-- vuelve dentro de seis meses cobra igual el 50% que la campaña le prometió
-- una tarde, y a esa altura ni la promoción ni el número tienen que ver con el
-- negocio de ese momento.
--
-- Es el mismo riesgo que ya se había acotado por el otro lado: desde el
-- 19/09/2026 un bono nuevo da de baja el anterior, así que una persona no
-- acumula varios. Lo que faltaba es que el ÚNICO que le queda tampoco sea
-- eterno.
--
-- DOS COLUMNAS Y UN ESTADO NUEVO:
--
--   vence_en   cuándo deja de valer. NULL = no vence (es lo que valía hasta
--              hoy, y lo que siguen valiendo los 629 que ya están: esta
--              migración NO les pone fecha a los viejos. Ponerles una a todos
--              de golpe sería anular de un saque bonos que ya se prometieron
--              por chat y por push, y el jugador no tiene por qué pagar un
--              cambio de reglas que no vio. La fecha arranca a correr para los
--              que se creen de acá en adelante).
--
--   'vencido'  estado propio, y no 'cancelado'. Los dos sacan el bono de
--              circulación, pero significan cosas distintas: 'cancelado' es
--              "se lo reemplazó otro bono o lo dio de baja un operador" y
--              'vencido' es "se le pasó el tiempo". Mirando para atrás, poder
--              distinguirlos es lo que permite contestar si la gente no cobra
--              porque le cambiamos el bono o porque tarda demasiado en volver
--              — y son dos problemas con soluciones opuestas.
--
-- QUIÉN LO APLICA: `crmnotif_bono_aplicar_en_recarga()` ignora los vencidos al
-- elegir cuál acreditar, y esa es la guarda que de verdad protege la plata. La
-- barrida que los marca 'vencido' es para que se vean como tales en la ficha y
-- en Auditoría; si no corriera, igual no se paga ninguno.
-- ---------------------------------------------------------------------------

ALTER TABLE bonos_pendientes
  MODIFY estado ENUM('pendiente','aplicado','cancelado','vencido')
         NOT NULL DEFAULT 'pendiente';

ALTER TABLE bonos_pendientes
  ADD COLUMN vence_en DATETIME NULL DEFAULT NULL AFTER creado_en;

-- El applier filtra por (vence_en IS NULL OR vence_en > NOW()) sobre los
-- pendientes de un usuario. Con el índice de usuario+estado que ya existe
-- alcanza para las filas de una persona; este índice es para la barrida, que
-- busca por fecha entre TODOS los pendientes.
CREATE INDEX ix_vence ON bonos_pendientes (estado, vence_en);
