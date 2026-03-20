SET @db_name := DATABASE();

SELECT constraint_name
INTO @old_check_name
FROM information_schema.table_constraints
WHERE table_schema = @db_name
  AND table_name = 'persona_rol_grupo_cfg'
  AND constraint_type = 'CHECK'
  AND constraint_name LIKE 'chk_pe_ro_gr_tipo_asignacion%'
ORDER BY constraint_name
LIMIT 1;

SET @drop_sql := IF(
  @old_check_name IS NOT NULL,
  CONCAT('ALTER TABLE `persona_rol_grupo_cfg` DROP CHECK `', @old_check_name, '`'),
  'SELECT 1'
);
PREPARE stmt_drop FROM @drop_sql;
EXECUTE stmt_drop;
DEALLOCATE PREPARE stmt_drop;

ALTER TABLE `persona_rol_grupo_cfg`
ADD CONSTRAINT `chk_pe_ro_gr_tipo_asignacion`
CHECK (UPPER(COALESCE(`pe_ro_gr-tipo_asignacion`, '')) IN ('', 'TITULAR', 'SUPLENTE', 'LIDER'));
