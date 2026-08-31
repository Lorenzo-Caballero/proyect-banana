-- ---------------------------------------------------------------------------
-- Migracion 46: que `usuarios.ultima_actividad` sea un dato de verdad.
--
-- LA COLUMNA YA EXISTIA (migracion 07) y el CRM la mostraba en la ficha de
-- cada jugador... pero NADIE le escribia nunca. Estuvo en NULL para todos
-- desde el dia uno, 38 migraciones.
--
-- La consecuencia no era cosmetica: la unica fecha confiable de "el jugador
-- hizo algo" era recargas.acreditada_en, y de ahi colgaba TODO -- el filtro
-- de inactivos, la retencion, los "activos" de Finanzas, los envios masivos.
-- O sea que "activo" en realidad significaba "puso plata": alguien que entra
-- todos los dias a jugar con el saldo que ya tenia contaba como inactivo
-- desde siempre, y le llegaba el push de "hace mucho que no te vemos".
--
-- Desde ahora la escriben las cinco señales de que la persona esta del otro
-- lado (ver api/actividad_lib.php): reporta saldo desde la pagina del juego,
-- pide cargar fichas, se le acredita una recarga, escribe por el chat, o
-- inicia sesion.
-- ---------------------------------------------------------------------------

-- El filtro de inactivos ordena y compara por esta columna. Sin indice, cada
-- vez que el agente abre la vista Usuarios se recorre la tabla entera.
ALTER TABLE usuarios
  ADD INDEX IF NOT EXISTS ix_ultima_actividad (ultima_actividad);

-- ---------------------------------------------------------------------------
-- Backfill: sembrar la columna con lo que YA sabemos, para no arrancar con
-- todos los jugadores en NULL (que se leeria como "nunca hizo nada", el
-- error opuesto al que veniamos arrastrando).
--
-- Se toma la fecha MAS RECIENTE entre las señales que si quedaron guardadas
-- historicamente. Son aproximadas y esta bien que lo sean: es el punto de
-- partida, y a partir de ahora cada señal la va corrigiendo sola.
--
-- Los COLLATE son obligatorios: `usuarios` esta en la collation por defecto
-- del servidor (uca1400) y las tablas del CRM en utf8mb4_unicode_ci.
-- Comparar los usuarios sin esto tira "Illegal mix of collations".
-- `recargas` comparte collation con `usuarios`, por eso ahi no lleva.
-- ---------------------------------------------------------------------------
UPDATE usuarios u
   SET u.ultima_actividad = GREATEST(
         COALESCE((SELECT MAX(r.acreditada_en) FROM recargas r
                    WHERE r.usuario = u.username AND r.estado = 'acreditada'), '1000-01-01'),
         COALESCE((SELECT MAX(a.ejecutada_en) FROM acciones_saldo a
                    WHERE a.usuario = u.username COLLATE utf8mb4_unicode_ci), '1000-01-01'),
         COALESCE((SELECT MAX(c.actualizada_en) FROM conversaciones c
                    WHERE c.clave = u.username COLLATE utf8mb4_unicode_ci), '1000-01-01'),
         COALESCE(u.balance_web_en, '1000-01-01')
       )
 WHERE u.ultima_actividad IS NULL;

-- GREATEST devuelve el centinela cuando el jugador no tenia NINGUNA señal.
-- Esos vuelven a NULL, que es lo correcto: "no sabemos", distinto de "estuvo
-- activo en el año 1000".
UPDATE usuarios SET ultima_actividad = NULL WHERE ultima_actividad = '1000-01-01';
