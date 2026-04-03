START TRANSACTION;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

DROP TEMPORARY TABLE IF EXISTS tmp_accion_set_detalle_cfg;
CREATE TEMPORARY TABLE tmp_accion_set_detalle_cfg LIKE accion_set_detalle_cfg;

LOAD DATA LOCAL INFILE 'c:/trabajos/Lito-emtam/ACCION_SET_DETALLE.csv'
INTO TABLE tmp_accion_set_detalle_cfg
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ';'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(@ac_se_de_id,@ac_se_de_tenant_id,@ac_se_de_ac_se_id_fk,@ac_se_de_fa_ac_id_fk,@ac_se_de_rol_id_fk,@ac_se_de_ac_op_id_fk,@ac_se_de_obligatoria,@ac_se_de_ord_ejec,@ac_se_de_activo,@ac_se_de_observ)
SET
  `ac_se_de-id` = NULLIF(TRIM(REPLACE(@ac_se_de_id, '\r', '')), ''),
  `ac_se_de-tenant_id` = NULLIF(TRIM(REPLACE(@ac_se_de_tenant_id, '\r', '')), ''),
  `ac_se_de-ac_se_id-fk` = NULLIF(TRIM(REPLACE(@ac_se_de_ac_se_id_fk, '\r', '')), ''),
  `ac_se_de-fa_ac_id-fk` = NULLIF(TRIM(REPLACE(@ac_se_de_fa_ac_id_fk, '\r', '')), ''),
  `ac_se_de-rol_id-fk` = NULLIF(TRIM(REPLACE(@ac_se_de_rol_id_fk, '\r', '')), ''),
  `ac_se_de-ac_op_id-fk` = NULLIF(TRIM(REPLACE(@ac_se_de_ac_op_id_fk, '\r', '')), ''),
  `ac_se_de-obligatoria` = NULLIF(TRIM(REPLACE(@ac_se_de_obligatoria, '\r', '')), ''),
  `ac_se_de-ord_ejec` = NULLIF(TRIM(REPLACE(@ac_se_de_ord_ejec, '\r', '')), ''),
  `ac_se_de-activo` = NULLIF(TRIM(REPLACE(@ac_se_de_activo, '\r', '')), ''),
  `ac_se_de-observ` = NULLIF(TRIM(REPLACE(@ac_se_de_observ, '\r', '')), '');

DELETE FROM accion_set_detalle_cfg;

INSERT INTO accion_set_detalle_cfg (
  `ac_se_de-id`,
  `ac_se_de-tenant_id`,
  `ac_se_de-ac_se_id-fk`,
  `ac_se_de-fa_ac_id-fk`,
  `ac_se_de-rol_id-fk`,
  `ac_se_de-ac_op_id-fk`,
  `ac_se_de-obligatoria`,
  `ac_se_de-ord_ejec`,
  `ac_se_de-activo`,
  `ac_se_de-observ`
)
SELECT
  `ac_se_de-id`,
  `ac_se_de-tenant_id`,
  `ac_se_de-ac_se_id-fk`,
  `ac_se_de-fa_ac_id-fk`,
  `ac_se_de-rol_id-fk`,
  `ac_se_de-ac_op_id-fk`,
  `ac_se_de-obligatoria`,
  `ac_se_de-ord_ejec`,
  `ac_se_de-activo`,
  `ac_se_de-observ`
FROM tmp_accion_set_detalle_cfg
ORDER BY
  CAST(COALESCE(NULLIF(`ac_se_de-ord_ejec`, ''), '999999') AS UNSIGNED),
  `ac_se_de-id`;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
COMMIT;

SELECT COUNT(*) AS total_registros FROM accion_set_detalle_cfg;
