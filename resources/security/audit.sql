CREATE TABLE `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ts` int(10) unsigned NOT NULL,
  `user` int(10) unsigned DEFAULT NULL,
  `model` varchar(60) NOT NULL,
  `record_id` varchar(40) NOT NULL,
  `action` enum('create','update','delete') NOT NULL,
  `changes` json NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `model_record` (`model`,`record_id`),
  KEY `user_ts` (`user`,`ts`),
  KEY `ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
