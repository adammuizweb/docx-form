CREATE TABLE IF NOT EXISTS `dxf_import_ledger` (
  `namespace_id` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `definition_id` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `release_id` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `template_id` BIGINT UNSIGNED NOT NULL,
  `template_version_id` BIGINT UNSIGNED NOT NULL,
  `projection_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `updated_by` BIGINT UNSIGNED NOT NULL,
  `created_at_utc` DATETIME NOT NULL,
  `updated_at_utc` DATETIME NOT NULL,
  PRIMARY KEY (`namespace_id`,`definition_id`),
  UNIQUE KEY `dxf_import_template_uq` (`template_id`),
  KEY `dxf_import_version` (`template_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
