-- 16_crm_sin_ia.sql — El CRM "solo bandeja", sin chatbot IA.
--
-- Un producto aparte que el dueño vende: un CRM donde el jugador escribe y lo
-- atiende SIEMPRE una persona, sin el bot que crea recargas, carga fichas o
-- verifica comprobantes solo. El modo ya existía entero (config_chatbot.activo
-- / config_crm.chat_activo apagan la IA y el mensaje queda en la bandeja para
-- un humano); lo que faltaba era poder ELEGIRLO desde el panel al crear el
-- cliente, y que el cliente no pueda volver a prenderlo.
--
-- Por qué vive acá y no en la base del cliente: la clave de IA es la GLOBAL
-- compartida (una sola ANTHROPIC_API_KEY para todos). Si esto fuera solo un
-- default en la base del cliente, el cliente lo prende desde su CRM y consume
-- nuestro presupuesto de API sin pagarlo. En el control es un candado: el
-- chatbot lo lee (rl_crm_con_ia) y no responde aunque el cliente prenda su
-- switch local.
--
-- Default 1 = con IA, el comportamiento de siempre: ningún cliente existente
-- cambia al correr esto.
USE goldpaw_control;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS crm_con_ia TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = CRM con chatbot IA; 0 = solo bandeja, atiende una persona';
