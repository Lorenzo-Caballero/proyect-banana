# Selectores del DEPÓSITO por UI en agents.ganamosonline.com

**HOY NO SE USAN.** El depósito va por API — una sola llamada, sin navegador:

    POST /api/agent_admin/user/{id}/payment/   body {"operation":0,"amount":N}

(la hace el bot en `_depositar_una()`, bot_crear_jugador.py). El camino por
UI —buscar al jugador en el listado y apretar botones— es el que FALLABA
(13 cargas contra 28 errores, ver bot_cargar_fichas.py) y se abandonó.

Estos selectores quedan documentados como RESPALDO por si el panel algún día
cierra la API y hay que volver a la UI. Capturados a mano el 11/9/2026 sobre
el panel real (capturas en el chat de esa fecha).

## Flujo por UI

1. Listado: `https://agents.ganamosonline.com/users/all`
   - Input de búsqueda del jugador:
     `#root > div > div.app__wrapper > main > div.app__wrapper__content > div.users > div.users__filter > form > div:nth-child(1) > div.search-user-input > div > input`
   - Resultado (aparece a los segundos):
     `#root > div > div.app__wrapper > main > div.app__wrapper__content > div.users > div.users-table.users-table_tab_all > div.users-table__table > div.users-table__tbody > div > div:nth-child(1) > div > div.adm-bets-table-row-user__td-data-user`
   - Botón DEPOSITAR de la fila:
     `#root > div > div.app__wrapper > main > div.app__wrapper__content > div.users > div.users-table.users-table_tab_all > div.users-table__table > div.users-table__tbody > div > div:nth-child(3) > div > a.button.button_sizable_default.button_colors_default`

2. Pantalla de depósito: `https://agents.ganamosonline.com/user/deposit/{id}`
   - Input del monto:
     `#root > div > div.app__wrapper > main > div.app__wrapper__content > div > div > div.deposit__top > div.deposit__inputs > div:nth-child(1) > div > div > div > div > input`
   - Botón DEPÓSITO (confirma):
     `#root > div > div.app__wrapper > main > div.app__wrapper__content > div > div > div.deposit__bottom > button.button.button_sizable_low.button_colors_default`

## Dato del panel que puede servir a futuro

La pantalla de depósito tiene la opción «¿Quieres depositar un bono?» con
Moneda / Por ciento %: la plataforma distingue nativamente depósito de bono.
Si algún día conviene que los bonos entren MARCADOS como bono en ganamos
(en vez de como depósito común), el parámetro correspondiente de la API
habría que capturarlo desde ahí (DevTools > Network al confirmar).
