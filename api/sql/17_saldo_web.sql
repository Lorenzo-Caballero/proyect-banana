-- ---------------------------------------------------------------------------
-- Migracion 17: saldo en vivo, reportado por el navegador del jugador.
--
-- `usuarios.balance` lo escribe SOLO sync_usuarios.py, que se loguea al panel de
-- agentes con Playwright y pagina la API. Es lento (una pasada cada 5 min) pero
-- es CONFIABLE: sale del panel, no del cliente.
--
-- El widget, en cambio, corre adentro de la pagina de la plataforma y ve el
-- saldo del header en tiempo real. Es fresquisimo y es GRATIS -no cuesta una
-- request al panel- pero lo manda el navegador del jugador, asi que cualquiera
-- puede reportar el numero que se le antoje con el inspector abierto.
--
-- El endpoint escribe las DOS columnas: `balance` (para que la base coincida
-- con lo que el jugador ve, que es lo que se pidio) y `balance_web` con su
-- fecha, para saber cuando un valor vino del navegador y no del panel.
--
-- CONDICION para que esto sea seguro: sync_usuarios.py TIENE que estar
-- corriendo. Cada pasada pisa `balance` con el saldo real del panel, asi que un
-- numero inventado desde el inspector vive 5 minutos como mucho. Sin el sync,
-- `balance` es lo que diga el cliente — y fichas_pedir_retiro() valida contra
-- esa columna.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 17_saldo_web.sql
-- ---------------------------------------------------------------------------

ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS balance_web    DECIMAL(14,2) NULL,
  ADD COLUMN IF NOT EXISTS balance_web_en DATETIME      NULL;
