-- mgomnibus install
CREATE TABLE IF NOT EXISTS `_DB_PREFIX_mgomnibus_price_history` (
  `id_history` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_shop` INT UNSIGNED NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
  `id_currency` INT UNSIGNED NOT NULL,
  `id_country` INT UNSIGNED NOT NULL,
  `id_group` INT UNSIGNED NOT NULL,
  `price_tax_excl` DECIMAL(20,6) NOT NULL,
  `price_tax_incl` DECIMAL(20,6) NOT NULL,
  `captured_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_history`),
  KEY `idx_lookup` (`id_shop`,`id_product`,`id_product_attribute`,`id_currency`,`id_country`,`id_group`,`captured_at`),
  KEY `idx_product` (`id_product`,`id_product_attribute`,`captured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
