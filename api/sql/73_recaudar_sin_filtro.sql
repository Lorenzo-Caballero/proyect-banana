-- ---------------------------------------------------------------------------
-- Migración 73: recaudar SIN el filtro de inactividad.
--
-- DE DÓNDE SALE (Nahuel, 21/09/2026): *"quiero que comience a recaudar sin
-- programar fechas, solo a partir de las páginas: si voy a ordenar de mayor a
-- menor y voy a la página 4, quiero que mires los montos"*. Y tenía razón en
-- que no coincidían.
--
-- POR QUÉ NO COINCIDÍAN, que es el fondo del asunto: el bot ordena por saldo
-- igual que el panel, pero después DESCARTA a los que jugaron hace poco. Los
-- de más saldo suelen ser justamente los que acaban de cargar, así que los
-- primeros 40 se caían casi enteros y el bot seguía bajando hasta juntar 10
-- inactivos -- terminaba en los de $13 mientras el operador miraba los de $50.
-- Los dos listados arrancan igual y se separan en la primera pantalla.
--
-- Con `sin_chequeo = 1` no se descarta a nadie: la página N del panel es la
-- página N del bot, que es lo que se pidió.
--
-- OJO CON LO QUE SE APAGA. El filtro de inactividad es la salvaguarda que
-- evita retirarle el saldo a alguien que está jugando en este momento. Sin
-- él, el criterio pasa a ser "los que más saldo tienen", que es una posición
-- en una lista que cambia sola. Por eso es explícito, por corrida, y la
-- pantalla lo dice con todas las letras antes de dejar apretar.
-- ---------------------------------------------------------------------------

ALTER TABLE recaudaciones
  ADD COLUMN IF NOT EXISTS sin_chequeo TINYINT(1) NOT NULL DEFAULT 0 AFTER min_saldo;
