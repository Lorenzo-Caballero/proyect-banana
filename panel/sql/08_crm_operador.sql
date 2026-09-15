-- ---------------------------------------------------------------------------
-- Migracion 08 (control): el acceso al CRM del cliente se carga EN EL ALTA.
--
-- POR QUE
-- El cliente nacia sin usuario de CRM: habia que esperar a que el worker
-- aprovisionara la base y recien despues volver al panel, boton "Operadores",
-- y crearle el acceso a mano. El dueño cargaba el cliente y se olvidaba, y el
-- cliente quedaba con un CRM al que no podia entrar.
--
-- Ahora el modal de "Nuevo cliente" pide usuario y contraseña del CRM, el
-- panel guarda aca el usuario y el HASH (nunca la contraseña en claro: se
-- hashea con password_hash() antes de tocar la base), y provisionar.php crea
-- el operador admin en la base del cliente en la MISMA pasada que la crea.
--
-- Guardar el hash (y no borrarlo despues) es a proposito: hace idempotente el
-- aprovisionamiento -- si la pasada se corta a mitad y se repite, el upsert
-- del operador da lo mismo. Cambios posteriores de clave van por el boton
-- "Operadores" del panel, que escribe directo en la base del cliente.
--
-- Idempotente (IF NOT EXISTS).
--
--   sudo mariadb goldpaw_control < panel/sql/08_crm_operador.sql
-- ---------------------------------------------------------------------------

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS crm_usuario VARCHAR(120) DEFAULT NULL
  COMMENT 'usuario admin del CRM del cliente; lo crea provisionar.php'
  AFTER agente_password;

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS crm_password_hash VARCHAR(255) DEFAULT NULL
  COMMENT 'password_hash() del acceso al CRM. NUNCA la clave en claro'
  AFTER crm_usuario;
