# Selectores del panel de agentes — RETIRO (para el worker, pendiente)

El chat ya PIDE el retiro (`fichas_pedir_retiro` → cola `acciones_saldo` con
`tipo='retirar'`). Lo que **todavía NO está automatizado** es que el worker
(`colector/ejecutar_acciones.py`, Playwright) lo EJECUTE en el panel de agentes,
igual que hace con la carga. Cuando se implemente, estos son los selectores del
panel (`agents.ganamosonline.com`), tal como los pasó el dueño.

> **Re-verificalos antes de usarlos.** Los dos paneles no tienen el mismo
> markup — ver la nota de `FORM_SELS` en `bot/bot_crear_jugador.py`: el
> mismo formulario lleva clase en uno y viene pelado en el otro. Si estos
> selectores salieron del panel viejo, hay que confirmarlos contra
> `agents.ganamosonline.com` antes de darlos por buenos.

## Flujo de retiro en el panel

1. **Abrir el retiro del usuario** (botón en la fila de la tabla de usuarios):
   ```
   #root > div > div.app__wrapper > main > div.app__wrapper__content > div.users > div.users-table.users-table_tab_all > div.users-table__table > div.users-table__tbody > div:nth-child(1) > div:nth-child(3) > div > a.button.button_sizable_default.button_colors_full-transparent
   ```
   > Ojo: `div:nth-child(1)` es la primera fila. Hay que ubicar la fila del
   > usuario correcto (buscar por nombre), no asumir que es la primera.

2. **Leer el saldo disponible** del usuario (para validar contra la BD):
   ```
   #root > div > div.app__wrapper > main > div.app__wrapper__content > div > div > div.withdrawal__top > div.withdrawal__user-blocks > div:nth-child(1) > div.withdrawal__user-block-wrapper > div.withdrawal__user-block-right > span:nth-child(2)
   ```
   El retiro debe ser **menor o igual** a este saldo. Los BONOS no se retiran.

3. **Ingresar la cantidad a retirar** (input):
   ```
   #root > div > div.app__wrapper > main > div.app__wrapper__content > div > div > div.withdrawal__top > div.withdrawal__input-block > div > div.input__wrapper > input
   ```

4. **Retirar TODO** (botón, alternativa a tipear la cantidad):
   ```
   #root > div > div.app__wrapper > main > div.app__wrapper__content > div > div > div.withdrawal__top > div.withdrawal__input-block > div > div.withdrawal__all-btn > button
   ```

5. **Confirmar el retiro** (botón final):
   ```
   #root > div > div.app__wrapper > main > div.app__wrapper__content > div > div > div.withdrawal__bottom > button.button.button_sizable_low.button_colors_default
   ```

## Notas para cuando se automatice

- Igual que la carga, el worker debe correr en `DRY_RUN` primero y solo apretar
  el botón final en `LIVE`.
- La comprobación monto ≤ saldo ya la hace `fichas_pedir_retiro` contra
  `usuarios.balance` (espejo). Acá, además, conviene releer el saldo REAL del
  panel (selector 2) antes de confirmar, porque el espejo puede estar viejo.
- El monto/`todo` viene en la fila de `acciones_saldo` (`monto`, y el motivo).
- Al terminar, marcar la acción `hecho` / `revisar` / `error` como en la carga.
