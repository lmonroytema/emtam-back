START TRANSACTION;

SET @db := DATABASE();

-- 1) users.persona_id
SET @sql := IF(
  (SELECT COUNT(*)
   FROM information_schema.columns
   WHERE table_schema = @db
     AND table_name = 'users'
     AND column_name = 'persona_id') = 0,
  'ALTER TABLE users ADD COLUMN persona_id VARCHAR(255) NULL AFTER tenant_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) users.is_active
SET @sql := IF(
  (SELECT COUNT(*)
   FROM information_schema.columns
   WHERE table_schema = @db
     AND table_name = 'users'
     AND column_name = 'is_active') = 0,
  'ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER perfil',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Backfill defensivo
UPDATE users
SET is_active = 1
WHERE is_active IS NULL;

-- 4) Índice persona_id
SET @sql := IF(
  (SELECT COUNT(*)
   FROM information_schema.statistics
   WHERE table_schema = @db
     AND table_name = 'users'
     AND index_name = 'idx_users_persona_id') = 0,
  'CREATE INDEX idx_users_persona_id ON users (persona_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) Índice is_active
SET @sql := IF(
  (SELECT COUNT(*)
   FROM information_schema.statistics
   WHERE table_schema = @db
     AND table_name = 'users'
     AND index_name = 'idx_users_is_active') = 0,
  'CREATE INDEX idx_users_is_active ON users (is_active)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;