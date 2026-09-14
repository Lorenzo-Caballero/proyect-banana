-- 65. Cargar el gasto de pauta también por LANDING, no solo por publicista.
--
-- POR QUÉ: el gasto estaba atado a `publicista`, un concepto pensado para medir
-- por separado la campaña de OTRA persona con su propio pixel. Nahuel no usa
-- eso -- no tiene ni un publicista creado -- y lo que sí necesita es lo
-- contrario: saber cuánto le cuesta cada LANDING, para comparar cuál convierte
-- mejor y cortar la que no rinde. Sin gasto no hay CPA ni ROAS, así que esas
-- dos métricas -- las únicas que deciden si escalar o cortar -- estaban vacías
-- para él siempre.
--
-- Una fila de gasto es de UN publicista o de UNA landing, nunca de los dos. Van
-- como dos columnas anulables en vez de una tabla nueva porque es el mismo
-- dato con el mismo significado (plata gastada un día), y partirlo en dos
-- tablas obligaría a duplicar cada consulta que lo suma.
--
-- Los dos índices únicos son lo que impide cargar dos veces el mismo día: en
-- MySQL los NULL no chocan entre sí, así que el índice de landings ignora las
-- filas de publicista y viceversa -- justo el comportamiento que hace falta.
--
-- Idempotente: se corre en cada deploy (panel/provisionar.php).
ALTER TABLE gasto_diario
    MODIFY publicista_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS landing_slug VARCHAR(80) NULL AFTER publicista_id;

-- El indice unico va con el truco de information_schema porque CREATE INDEX no
-- acepta IF NOT EXISTS: provisionar.php corre TODAS las migraciones en cada
-- deploy, y un error 1061 ("ya existe") haria figurar este archivo como fallado
-- para siempre en el reporte -- y un reporte que siempre tiene un error rojo
-- deja de leerse.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'gasto_diario'
       AND INDEX_NAME   = 'uq_gasto_landing'
);
SET @sql := IF(@existe > 0,
               'DO 0',
               'CREATE UNIQUE INDEX uq_gasto_landing ON gasto_diario (landing_slug, fecha)');
PREPARE crear FROM @sql;
EXECUTE crear;
DEALLOCATE PREPARE crear;
