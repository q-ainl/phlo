CREATE TABLE `rate_limit` (
  `rkey` varchar(120) NOT NULL,
  `count` int(10) unsigned NOT NULL DEFAULT 0,
  `window_start` int(10) unsigned NOT NULL,
  PRIMARY KEY (`rkey`),
  KEY `window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
