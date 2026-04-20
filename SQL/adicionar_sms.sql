-- Adicionar configuración SMS para notificaciones por tenant
-- Ejecutar en hosting una sola vez por base de datos

SET @has_sms_enabled := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'notifications_sms_enabled'
);

SET @ddl_sms_enabled := IF(
  @has_sms_enabled = 0,
  'ALTER TABLE `tenants` ADD COLUMN `notifications_sms_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notifications_email_enabled`',
  'SELECT ''notifications_sms_enabled ya existe'' AS msg'
);

PREPARE stmt1 FROM @ddl_sms_enabled;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @has_test_sms := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'test_notification_sms_numbers'
);

SET @ddl_test_sms := IF(
  @has_test_sms = 0,
  'ALTER TABLE `tenants` ADD COLUMN `test_notification_sms_numbers` JSON NULL AFTER `test_notification_whatsapp_numbers`',
  'SELECT ''test_notification_sms_numbers ya existe'' AS msg'
);

PREPARE stmt2 FROM @ddl_test_sms;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

UPDATE `tenants`
SET `notifications_sms_enabled` = 0
WHERE `notifications_sms_enabled` IS NULL;

SELECT
  `tenant_id`,
  `notifications_email_enabled`,
  `notifications_sms_enabled`,
  `notifications_channel`,
  `test_notification_emails`,
  `test_notification_whatsapp_numbers`,
  `test_notification_sms_numbers`
FROM `tenants`;

