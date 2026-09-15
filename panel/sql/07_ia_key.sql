-- ---------------------------------------------------------------------------
-- Migracion 07 (control): la clave de IA del cliente deja de llamarse "cohere".
--
-- POR QUE
-- El panel del dueño pedia "Cohere API key" y la guardaba en `cohere_key`.
-- Cohere quedo atras en agosto de 2026: hoy el chatbot habla con **Qwen**
-- (qwen-vl-max, endpoint internacional de DashScope en modo compatible con
-- OpenAI) y Cohere solo sobrevive como ULTIMO respaldo. O sea que el campo
-- pedia la clave de un proveedor que ya no es el principal.
--
-- Y peor que obsoleto: pegar ahi una clave de Cohere es la trampa que
-- describe api/chatbot_diag.php -- se la manda a Qwen, Qwen la rechaza con
-- 401, y el chat queda mudo con una clave "cargada".
--
-- El nombre nuevo es NEUTRO A PROPOSITO (`ia_key`, no `qwen_key`): este campo
-- ya cambio de proveedor una vez y va a volver a cambiar. Un nombre atado al
-- proveedor obliga a una migracion mas la proxima vez.
--
-- NO SE BORRA `cohere_key`. Se copia el valor y la columna vieja queda: si
-- este deploy se revierte, el panel viejo sigue leyendo lo suyo. Borrarla es
-- una decision de despues, cuando haga tiempo que nadie la mire.
--
-- Idempotente (IF NOT EXISTS + el UPDATE solo pisa lo vacio).
--
--   sudo mariadb goldpaw_control < panel/sql/07_ia_key.sql
-- ---------------------------------------------------------------------------

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS ia_key VARCHAR(190) DEFAULT NULL
  COMMENT 'clave del proveedor de IA del chatbot (hoy Qwen). Vacio = usa la del sistema'
  AFTER coins_por_peso;

-- Lo que ya habia cargado, al nombre nuevo. Solo donde ia_key esta vacia, para
-- que correr esto dos veces no pise una clave nueva con la vieja.
UPDATE clientes
   SET ia_key = cohere_key
 WHERE (ia_key IS NULL OR ia_key = '')
   AND cohere_key IS NOT NULL AND cohere_key <> '';
