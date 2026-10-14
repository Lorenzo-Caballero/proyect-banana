-- 17_recarga_asistida.sql — El modo "asistido": la IA atiende, el humano aprueba.
--
-- Tercer modo del CRM, entre "completo" (IA + acreditación automática) y "solo
-- bandeja" (sin IA, migración 16). Acá la IA charla y crea la recarga como
-- siempre, el matcher del mail del banco CONFIRMA que la plata llegó y de
-- quién... pero NO acredita: deja la recarga esperando que un agente la
-- apruebe con un click desde el CRM. Para el cajero desconfiado que quiere
-- atención 24/7 pero decidir él cada acreditación.
--
-- Se compone con crm_con_ia (no lo reemplaza):
--   crm_con_ia=1 + recarga_auto=1  -> completo
--   crm_con_ia=1 + recarga_auto=0  -> asistido
--   crm_con_ia=0                   -> solo bandeja (recarga_auto no aplica)
--
-- Por qué vive en el control y no en la base del cliente: es el matcher
-- (api/recargas_lib.php) el que lo tiene que respetar, y lo lee igual que
-- crm_con_ia -- rl_recarga_auto(), default-allow. Default 1 = automático, lo
-- de siempre: ningún cliente existente cambia al correr esto.
USE goldpaw_control;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS recarga_auto TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = el matcher acredita solo; 0 = la recarga espera aprobación humana en el CRM';
