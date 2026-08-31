-- ---------------------------------------------------------------------------
-- Migracion 47: espejo de los datos bancarios cargados en el panel de ganamos.
--
-- EL PROBLEMA QUE RESUELVE
-- El jugador puede pedir los datos para transferir de dos formas: por el chat
-- (contesta nuestro CRM) o pidiendo un deposito DENTRO de la plataforma, donde
-- ve lo que este cargado en el panel de agentes (Peticiones de jugadores ->
-- Datos Bancarios). Eran dos fuentes distintas para el mismo dato, y si no
-- coincidian la plata entraba en dos cuentas mientras el colector escuchaba
-- los mails de UNA sola: lo que caia en la otra no se acreditaba nunca.
--
-- LA SOLUCION: UNA SOLA FUENTE DE VERDAD, Y NO ES ESTA
-- Manda el panel de ganamos. El cliente cambia su billetera ahi, donde ya va,
-- y esta tabla es un ESPEJO de solo lectura. Nada del CRM escribe aca salvo
-- el sync: si alguien edita esta tabla a mano, la proxima corrida lo pisa.
--
-- Se eligio espejar (leer) y no empujar (escribir) a proposito: si falla una
-- lectura mostramos el ultimo valor conocido y avisamos; si fallara una
-- escritura, mandariamos jugadores a una cuenta equivocada.
--
-- Lo llena colector/sync_bancos.py desde
--   GET https://agents.ganamos7.com/api/agent_admin/banks/
-- que devuelve, por entrada: id, titular, details (el alias o CBU) y bank
-- (el tipo: "Alias", "CBU", ...).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS bancos_ganamos (
  -- El id que le puso ganamos. Es la PK a proposito: asi el sync hace upsert
  -- por ese id y no duplica entradas al correr de nuevo.
  id_ganamos     BIGINT       NOT NULL PRIMARY KEY,
  titular        VARCHAR(160) NULL,
  -- El dato que el jugador copia: puede ser un alias ("ganamos1010") o un
  -- CBU/CVU. Cual de los dos lo dice `tipo`.
  details        VARCHAR(190) NOT NULL,
  tipo           VARCHAR(40)  NULL COMMENT 'Lo que ganamos llama "bank": Alias, CBU, ...',
  -- Orden en que los devuelve el panel. IMPORTA: segun las pruebas, la
  -- plataforma le muestra al jugador la PRIMERA entrada. Con una sola entrada
  -- cargada (lo recomendado) esto da igual, pero si hay varias es la unica
  -- forma de saber cual esta viendo la gente.
  posicion       INT          NOT NULL DEFAULT 0,
  visto_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_posicion (posicion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cuando corrio el sync por ultima vez. Va aparte de visto_en porque hace
-- falta distinguir "el panel no tiene ninguna billetera cargada" de "hace tres
-- dias que no podemos leer el panel". Las dos dejan la tabla vacia o vieja,
-- pero una es un problema del cliente y la otra es un problema nuestro.
INSERT INTO config_crm (clave, valor)
VALUES ('bancos_sync_en', '')
ON DUPLICATE KEY UPDATE clave = clave;
