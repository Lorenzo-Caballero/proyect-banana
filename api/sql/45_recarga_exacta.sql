-- 45: Recargas por MONTO EXACTO + datos declarados por el jugador.
--
-- El flujo nuevo: el jugador transfiere EXACTAMENTE lo que pidió en fichas
-- (sin centavos agregados; RL_MONTO_EXACTO en recargas_lib.php). Como varios
-- pueden pedir el mismo monto a la vez, el desempate ya no son los centavos:
-- son los datos que el jugador declara en el chat (o los que la IA de visión
-- lee de la foto del comprobante que sube):
--
--   titular_declarado  el nombre del titular de la cuenta DESDE la que dijo
--                      que transfirió. Se compara con pagos.remitente (lo que
--                      dice el mail del banco) para desempatar montos iguales.
--   trx_declarada      el número de operación/transacción del comprobante,
--                      normalizado a solo dígitos (mismo formato que
--                      pagos.id_unico que genera el colector). Si coincide con
--                      un pago Y el monto también, el match es directo (Capa 0).
--
-- Se llenan desde rl_declarar_pago() (herramientas informar_transferencia y
-- verificar_comprobante del chatbot). Pueden quedar NULL toda la vida: el
-- matcher por monto único y la revisión manual siguen funcionando igual.

-- IF NOT EXISTS en las tres, y no es decoracion:
--
-- `titular_declarado` YA la crea 45_match_titular.sql, que es de la misma
-- tanda. Sin el IF NOT EXISTS, en cualquier base donde esa ya corrio esto
-- aborta con "Duplicate column name 'titular_declarado'" -- y como las dos
-- columnas venian en UN solo ALTER, se caia tambien `trx_declarada`, que es
-- la unica que este archivo agrega de verdad. El sintoma no seria un error
-- visible: provisionar.php corre las migraciones cada minuto contra TODAS las
-- bases, asi que quedaria fallando en silencio para todos los clientes y el
-- codigo que usa trx_declarada tirando "columna desconocida".
--
-- Van en ALTERs separados por el mismo motivo: si una columna ya esta, que no
-- se lleve puesta a la otra.
ALTER TABLE recargas
  ADD COLUMN IF NOT EXISTS titular_declarado VARCHAR(120) NULL AFTER centavos;

ALTER TABLE recargas
  ADD COLUMN IF NOT EXISTS trx_declarada VARCHAR(64) NULL AFTER titular_declarado;

-- El matcher busca "pendiente con esta trx" en cada mail que llega.
ALTER TABLE recargas
  ADD INDEX IF NOT EXISTS ix_trx_declarada (trx_declarada);
