# _legacy — prototipo viejo del colector, archivado en Fase 0

Estos cinco scripts quedaron inertes cuando `colector/api_client.py` se
adaptó para postear directo a `api/pagos.php` (el camino real de producción,
ver `colector/README.md`). Se archivan acá en vez de borrarse porque tienen
lógica de matching y de aprobación que puede servir de referencia, pero
**ninguno corre en producción ni tiene su configuración completa**. Detalle
completo del porqué en `AUDIT.md` (raíz del repo), sección 1.2.

| Script | Por qué quedó inerte |
|---|---|
| `matcher.py` | Matching por 3 capas sobre SQLite `pagos.db`. Reemplazado por `rl_matchear_y_acreditar()` en `api/recargas_lib.php`, que corre en MySQL y es al que de verdad llama `pagos.php`. |
| `panel.py` | Dashboard local sobre la misma `pagos.db`. Las tablas de las que depende (`ganamos_peticiones`, `ganamos_log`) ya no se llenan porque `api_client.py` tiene esas funciones stubbeadas. |
| `ganamos_bot.py` | Aprobaba pedidos de depósito preexistentes y daba un % de bono fijo, sin verificar transferencia real. No usa `colector_mail.py` ni ningún matching. |
| `ganamos_conciliador.py` | Superset de `ganamos_bot.py`: buscaba respaldo real en `pagos.db` antes de aprobar. Como `api_client.buscar_pagos()` es un stub que devuelve `[]`, nunca encuentra respaldo — quedó efectivamente inerte. |
| `ejecutar_acciones.py` | Worker de saldo vía `SESSION_COOKIE` (sin Playwright). Reemplazado por `bot/bot_cargar_fichas.py`, que además sí soporta retiro. No tiene `.env` en este repo — no puede arrancar tal cual. |

`colector/api_client.py` **no** está acá — sigue en `colector/` porque
`colector_mail.py` (que sí está en producción) lo importa y usa de verdad su
función `guardar_pago()`.
