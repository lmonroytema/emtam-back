CREATE TABLE IF NOT EXISTS `notas_operativas_leido_trs` (
  `no_op_le-id` varchar(64) NOT NULL,
  `no_op_le-no_op_id-fk` varchar(64) NOT NULL,
  `no_op_le-ac_de_pl_id-fk` varchar(64) NOT NULL,
  `no_op_le-per_id-fk` varchar(64) NOT NULL,
  `no_op_le-ts_leido` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`no_op_le-id`),
  UNIQUE KEY `uq_no_op_le_note_person` (`no_op_le-no_op_id-fk`, `no_op_le-per_id-fk`),
  KEY `idx_no_op_le_activation` (`no_op_le-ac_de_pl_id-fk`),
  KEY `idx_no_op_le_person` (`no_op_le-per_id-fk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
