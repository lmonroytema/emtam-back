-- Script para hosting: actualizar zona horaria por tenant
-- Uso:
-- 1) Ajusta @TENANT_ID y @TIMEZONE
-- 2) Ejecuta el script completo

SET @TENANT_ID = 'Morell';
SET @TIMEZONE = 'Europe/Madrid';

-- 1) Agregar columna timezone si no existe (compatible con MySQL/MariaDB via INFORMATION_SCHEMA)
SET @has_timezone_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'timezone'
);

SET @ddl := IF(
  @has_timezone_col = 0,
  'ALTER TABLE `tenants` ADD COLUMN `timezone` VARCHAR(64) NOT NULL DEFAULT ''Europe/Madrid'' AFTER `default_language`',
  'SELECT ''timezone column already exists'' AS info'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Backfill de valores vacios/nulos
UPDATE `tenants`
SET `timezone` = 'Europe/Madrid'
WHERE `timezone` IS NULL OR TRIM(`timezone`) = '';

-- 3) Actualizar timezone del tenant objetivo
UPDATE `tenants`
SET `timezone` = @TIMEZONE
WHERE `tenant_id` = @TENANT_ID;

-- 4) Verificacion
SELECT
  `tenant_id`,
  `name`,
  `default_language`,
  `timezone`
FROM `tenants`
WHERE `tenant_id` = @TENANT_ID;

