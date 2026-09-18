-- ---------------------------------------------------------------------------
-- Migración 72: la FECHA y la IMAGEN del comprobante se guardan, para poder
-- decir "ese ya lo usaste" cuando no hay número de operación.
--
-- EL HUECO (18/09/2026, pedido del dueño: *"que el bot extraiga datos del
-- comprobante para ver si ya se ha usado antes, el horario y todo"*).
--
-- Los avisos de reuso que ya existían --COMPROBANTE YA USADO y COMPROBANTE YA
-- DECLARADO-- cuelgan los dos del `nro_transaccion`, y sólo se miran con 6
-- dígitos o más. Medido en producción ese día: de 36 declaraciones, 24 traían
-- número. **Un tercio no lo trae**, y para ese tercio ninguna alerta podía
-- dispararse: el comprobante se podía presentar otra vez sin que nada lo
-- notara.
--
-- Y la fecha estaba peor: `vision_lib` ya la leía y la normalizaba, el chatbot
-- la usaba una vez para decir "este comprobante es viejo", y después se tiraba.
-- No quedaba en ninguna parte, así que no servía para reconocer la MISMA
-- transferencia presentada dos veces.
--
--   fecha_declarada     cuándo dice el comprobante que se hizo la transferencia.
--                       Con el monto y el titular alcanza para reconocerla sin
--                       número de operación: nadie transfiere dos veces el
--                       mismo importe, a la misma hora, desde la misma cuenta.
--   comprobante_huella  SHA-256 del archivo subido. Es la señal más barata y
--                       la más dura para el caso simple: el jugador que
--                       reenvía LA MISMA foto. No reemplaza a las otras --un
--                       recorte distinto de la misma captura ya cambia el
--                       hash-- pero lo agarra sin depender de qué tan bien se
--                       leyó la imagen.
--
-- Las dos van indexadas porque se consultan en cada declaración, que pasa
-- mientras el jugador espera una respuesta en el chat.
-- ---------------------------------------------------------------------------

ALTER TABLE recargas
  ADD COLUMN IF NOT EXISTS fecha_declarada    DATETIME NULL DEFAULT NULL
       COMMENT 'Fecha/hora que dice el comprobante (la lee vision_lib)',
  ADD COLUMN IF NOT EXISTS comprobante_huella CHAR(64) NULL DEFAULT NULL
       COMMENT 'SHA-256 del archivo del comprobante subido';

ALTER TABLE recargas
  ADD INDEX IF NOT EXISTS idx_recargas_huella (comprobante_huella),
  ADD INDEX IF NOT EXISTS idx_recargas_fecha_decl (fecha_declarada, monto_pedido);
