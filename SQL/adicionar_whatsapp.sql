-- 1) Agregar columna solo si no existe
SET @db := DATABASE();

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'notifications_channel'
);

SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `tenants`
     ADD COLUMN `notifications_channel` VARCHAR(16) NOT NULL DEFAULT ''email''
     AFTER `notifications_email_enabled`',
  'SELECT ''notifications_channel ya existe'' AS msg'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Normalizar datos por si había registros antiguos
UPDATE `tenants`
SET `notifications_channel` = 'email'
WHERE `notifications_channel` IS NULL
   OR TRIM(`notifications_channel`) = '';

-- 3) Verificación
SELECT `tenant_id`, `notifications_email_enabled`, `notifications_channel`
FROM `tenants`
LIMIT 20;