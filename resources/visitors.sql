CREATE TABLE `visitors` (
  `id` char(20) NOT NULL,
  `token` char(20) NOT NULL,
  `host` varchar(60) NOT NULL,
  `page` varchar(100) NOT NULL,
  `lang` char(2) NOT NULL,
  `IP` varchar(15) DEFAULT NULL,
  `browser` varchar(18) NOT NULL,
  `os` varchar(18) DEFAULT NULL,
  `device` varchar(12) DEFAULT NULL,
  `requests` smallint(5) unsigned NOT NULL,
  `state` enum('active','blurred','hidden') NOT NULL,
  `width` smallint(5) unsigned NOT NULL,
  `height` smallint(5) unsigned NOT NULL,
  `referrer` varchar(250) DEFAULT NULL,
  `created` int(10) unsigned NOT NULL,
  `changed` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `token` (`token`),
  KEY `host` (`host`),
  KEY `IP` (`IP`),
  KEY `browser` (`browser`),
  KEY `referrer` (`referrer`),
  KEY `created` (`created`),
  KEY `changed` (`changed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migration voor bestaande installaties:
-- ALTER TABLE `visitors` MODIFY `IP` varchar(15) DEFAULT NULL;
-- ALTER TABLE `visitors` MODIFY `os` varchar(18) DEFAULT NULL;
-- ALTER TABLE `visitors` MODIFY `device` varchar(12) DEFAULT NULL;
