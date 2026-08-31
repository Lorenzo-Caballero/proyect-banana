# Recargas de coins por transferencia — flujo completo

El chatbot toma el pedido, el usuario transfiere, el colector lee el mail de
aviso y los coins se acreditan solos.

```
  Usuario ── chatbot ──► crear_recarga ──► monto EXACTO a transferir (con centavos)
                                                  │
  Usuario transfiere ese importe                  │
                                                  ▼
  Mail de aviso ──► colector_mail.py ──► pagos.php ──► casa por monto ──► +coins
```

## Piezas

| Pieza | Dónde | Qué hace |
|---|---|---|
| Chatbot con tools | `api/chatbot.php` | Pide usuario + coins, llama `crear_recarga`, da instrucciones |
| Lógica de recargas | `api/recargas_lib.php` | Crea recargas, casa pagos, acredita `jugadores.coins` |
| Endpoint de pagos | `api/pagos.php` | Recibe cada transferencia del colector (X-API-Key) |
| Colector de mails | tu carpeta del colector | Lee los mails IMAP y postea a `pagos.php` |
| Adaptador | `colector/api_client.py` | Hace que el colector apunte a nuestra `pagos.php` |

## Puesta en marcha

**1. Migrar la base** (agrega `jugadores.coins` + tablas `recargas` y `pagos`):

```bash
mysql -u USUARIO -p TU_BASE < api/sql/02_recargas.sql
```

**2. Configurar la cuenta de cobro** en `api/recargas_lib.php` (arriba del todo):

```php
const RL_COINS_POR_PESO = 1;              // 1 coin = 1 peso
const RL_ALIAS   = 'tu.alias.aca';
const RL_CBU     = '0000000000000000000000';
const RL_TITULAR = 'Titular de la cuenta';
```

**3. Subir** `api/chatbot.php`, `api/recargas_lib.php`, `api/pagos.php`.

**4. Enchufar el colector** (tu `colector.rar`):

- Copiá `colector/api_client.py` (de este repo) dentro de la carpeta del
  colector, **pisando** el `api_client.py` original.
- Editá `config.json` del colector con tu casilla, carpeta y remitente del banco
  (ya lo tenías configurado).
- Corré el colector apuntando a nuestra API:

```bash
set API_URL=https://ganamoscrm.online/gp-api/pagos.php
set API_TOKEN=<la BOT_API_KEY del server>
python colector_mail.py --escuchar
```

(`API_TOKEN` es la misma `BOT_API_KEY` de `api/config.local.php`. En Linux/Mac
usá `export`.)

> **Nunca pegues la clave acá.** Este README se commitea y el repo es público.
> Hasta agosto de 2026 esta sección tuvo la `BOT_API_KEY` de producción escrita
> en claro; sigue en el historial de git, así que la única solución de verdad
> es **rotar la clave**, no borrar la línea.

## Cómo se casa un pago (matcher, en `recargas_lib.php`)

1. **Monto exacto** — cada recarga pendiente lleva centavos únicos (01–99). El
   importe de la transferencia identifica la recarga sin ambigüedad. Es el
   camino normal y 100% automático.
2. **Respaldo por monto entero** — si la billetera truncó los decimales, casa
   por la parte entera dentro de una ventana de tiempo, sólo si hay UNA sola
   recarga candidata.
3. **Revisión** — si nada da único, el pago queda `revision` en la tabla
   `pagos`. Nunca adivina ni acredita a ciegas.

> **Importante:** el match por centavos sólo sirve si tu billetera/banco muestra
> los decimales en el aviso. Si los trunca, dependés del respaldo entero (menos
> preciso con varias recargas del mismo monto a la vez).

## Seguridad

- El colector ya filtra por **remitente autorizado + DKIM** antes de postear:
  sólo llegan avisos reales del banco.
- `pagos.php` está protegido por la API key. La acreditación es **atómica** y el
  `id_unico` (nro de transacción) es único: un pago no puede acreditarse dos veces.
- El chatbot sólo **crea** recargas y consulta estado; nunca acredita. El único
  que suma coins es `pagos.php`, detrás de la key.
- Verificá que la cuenta que recibe las transferencias sea la misma que dispara
  el mail de aviso que lee el colector.
