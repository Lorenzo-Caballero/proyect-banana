-- ---------------------------------------------------------------------------
-- Migración 77: la dirección de Firebase de cada celular.
--
-- EL PROBLEMA (medido el 19/09/2026 sobre los 42 celulares con la app y el
-- permiso dado). Con la app cerrada, el aviso lo iba a buscar el propio
-- teléfono cada 15 minutos (SondeoWorker). Ese "cada 15 minutos" no es una
-- garantía: es un pedido que el administrador de batería del fabricante puede
-- negar, y lo negaba casi siempre. En 24 horas:
--
--     Samsung    5 sondeos de  6 esperados
--     Motorola   2 sondeos de 13 esperados
--     Xiaomi     1 sondeo  de 20 esperados
--
-- Xiaomi mató 19 de 20. No es un bug del worker y no se arregla programando
-- mejor: es el sistema operativo matando procesos de apps de terceros a
-- propósito, para ahorrar batería.
--
-- POR QUÉ FIREBASE SÍ. FCM no corre en nuestro proceso sino dentro de Google
-- Play Services, que es del sistema. El administrador de batería no lo mata
-- porque matarlo rompería Gmail, WhatsApp y el propio Android. En vez de que
-- la app pregunte, Google le golpea la puerta al teléfono.
--
-- ESTO DA VUELTA UNA DECISIÓN QUE ESTABA BIEN TOMADA, y conviene que quede
-- escrito por qué. Hasta la versión 1.6 no había Firebase A PROPÓSITO: ninguna
-- cuenta de Google en el medio, todo en el mismo servidor que el resto de la
-- API, nada que mantener. El argumento sigue siendo bueno; lo que lo movió fue
-- el número de arriba. El precio que se paga es que Google queda en el camino
-- de los avisos, y por eso se paga lo menos posible:
--
--     EL PUSH NO LLEVA EL AVISO, LLEVA UN "FIJATE".
--
-- El mensaje que se manda por FCM va vacío. El teléfono lo recibe, se despierta
-- y pide los avisos por donde ya los pedía. O sea que el texto de las
-- notificaciones --que dice cuánta plata se le acreditó a quién-- nunca viaja
-- por Google, la entrega única la sigue garantizando
-- `notificaciones_entregas` y el sondeo de 15 minutos queda intacto como
-- respaldo. Si mañana Firebase se cae o se saca, todo sigue funcionando como
-- el 19/09/2026, nada más que lento.
--
-- `fcm_token` es esa dirección: la que Google le dio a ESE celular.
-- ---------------------------------------------------------------------------

-- NO ES UN SECRETO NUESTRO NI UN IDENTIFICADOR ESTABLE. Google lo rota al
-- reinstalar la app, al restaurar un backup en otro aparato o al limpiar los
-- datos. Por eso es NULL-able y por eso el APK lo reenvía en cada arranque, no
-- sólo cuando cambia: un token muerto NO da error al usarlo, simplemente el
-- aviso no llega a ningún lado, y el síntoma sería "a este jugador dejaron de
-- llegarle las notificaciones" sin nada roto a la vista.
ALTER TABLE dispositivos
  ADD COLUMN IF NOT EXISTS fcm_token VARCHAR(255) NULL DEFAULT NULL AFTER version;

-- Cuándo lo registró. Sirve para dos cosas concretas: ver qué parte del parque
-- ya está en la versión con Firebase, y distinguir "nunca mandó token" (NULL)
-- de "mandó uno hace meses y no volvió a abrir la app".
ALTER TABLE dispositivos
  ADD COLUMN IF NOT EXISTS fcm_en DATETIME NULL DEFAULT NULL AFTER fcm_token;

-- Se busca POR TOKEN en un solo caso, pero es el que mantiene la tabla sana:
-- cuando Google contesta UNREGISTERED o INVALID_ARGUMENT hay que borrar ese
-- token para no seguir golpeando una puerta que no existe. Sin el índice, esa
-- limpieza recorre la tabla entera cada vez.
--
-- Prefijo de 64 y no la columna completa: alcanza de sobra para distinguirlos
-- (los tokens de FCM son largos y difieren desde el principio) y entra holgado
-- en el límite de 3072 bytes de InnoDB con utf8mb4.
ALTER TABLE dispositivos
  ADD KEY IF NOT EXISTS ix_fcm (fcm_token(64));
