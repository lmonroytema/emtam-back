START TRANSACTION;

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
ORDER BY `ac_se_de-id` ASC;

COMMIT;
