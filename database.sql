-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: mysql:3306
-- Generation Time: Apr 24, 2023 at 07:26 PM
-- Server version: 10.11.2-MariaDB-1:10.11.2+maria~ubu2204
-- PHP Version: 8.1.17
SET
  SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

SET
  time_zone = "+00:00";

--
-- Database: `proxmoxcp`
--
-- --------------------------------------------------------
--
-- Table structure for table `addresses`
--
CREATE TABLE IF NOT EXISTS `addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `gender` varchar(15) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `address1` varchar(150) DEFAULT NULL,
  `address2` varchar(150) DEFAULT NULL,
  `state` varchar(150) DEFAULT NULL,
  `zipcode` varchar(150) DEFAULT NULL,
  `city` varchar(150) DEFAULT NULL,
  `country` varchar(150) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `vatId` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `balances`
--
CREATE TABLE IF NOT EXISTS `balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `balance` double NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `charges`
--
CREATE TABLE IF NOT EXISTS `charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `price` decimal(15, 4) NOT NULL,
  `type` int(11) NOT NULL,
  `calcType` int(11) NOT NULL DEFAULT 1,
  `calcOnly` tinyint(1) NOT NULL DEFAULT 1,
  `osid` int(11) DEFAULT NULL,
  `recurring` tinyint(4) NOT NULL DEFAULT 0,
  `description` varchar(150) NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `creation_times`
--
CREATE TABLE IF NOT EXISTS `creation_times` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `templateId` int(11) NOT NULL,
  `creationTime` float NOT NULL,
  `totalCount` int(11) NOT NULL DEFAULT 0,
  `lastUpdated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `invoices`
--
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `amount` double NOT NULL,
  `type` int(11) NOT NULL,
  `userid` int(11) NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `done` tinyint(4) NOT NULL,
  `descriptor` VARCHAR(250) NULL,
  `imported` TINYINT(4) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `ipam_4`
--
CREATE TABLE IF NOT EXISTS `ipam_4` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `start` varchar(50) DEFAULT NULL,
  `end` varchar(50) DEFAULT NULL,
  `subnet` varchar(45) DEFAULT NULL,
  `gateway` varchar(50) DEFAULT NULL,
  `scope` varchar(45) DEFAULT NULL,
  `nodes` text DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `userId` int(14) NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `ipam_4_addresses`
--
CREATE TABLE IF NOT EXISTS `ipam_4_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) DEFAULT NULL,
  `fk_ipam` int(11) DEFAULT NULL,
  `mac` varchar(45) DEFAULT NULL,
  `in_use` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `ipam_6`
--
CREATE TABLE IF NOT EXISTS `ipam_6` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `network` varchar(50) DEFAULT NULL,
  `prefix` varchar(50) DEFAULT NULL,
  `target` varchar(45) DEFAULT NULL,
  `gateway` varchar(50) DEFAULT NULL,
  `scope` varchar(45) DEFAULT NULL,
  `nodes` text DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `userId` int(14) NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `ipam_6_addresses`
--
CREATE TABLE IF NOT EXISTS `ipam_6_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) DEFAULT NULL,
  `fk_ipam` int(11) DEFAULT NULL,
  `mac` varchar(45) DEFAULT NULL,
  `in_use` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `ips`
--
CREATE TABLE IF NOT EXISTS `ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) NOT NULL,
  `mask` varchar(15) DEFAULT NULL,
  `gateway` varchar(25) NOT NULL,
  `mac` varchar(25) DEFAULT NULL,
  `used` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `logs`
--
CREATE TABLE IF NOT EXISTS `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `level` int(11) NOT NULL,
  `message` varchar(2500) NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `migrations`
--
CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` tinyint(4) NOT NULL,
  `name` varchar(100) NOT NULL,
  `appliedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `monthly_charges`
--
CREATE TABLE IF NOT EXISTS `monthly_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `serverId` int(11) NOT NULL,
  `amount` double(14, 2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `serverType` varchar(255) NOT NULL,
  `chargeId` int(14) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `news`
--
CREATE TABLE IF NOT EXISTS `news` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` text NOT NULL,
  `content` text NOT NULL,
  `public` tinyint(4) NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `authorId` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `notifications`
--
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) NOT NULL,
  `email` varchar(50) NOT NULL,
  `emailTries` int(11) NOT NULL,
  `notificationType` enum('account', 'servers', 'tickets', '') NOT NULL,
  `meta` varchar(500) NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `hasRead` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `notifications_settings`
--
CREATE TABLE IF NOT EXISTS `notifications_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `enabled` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `packages`
--
CREATE TABLE IF NOT EXISTS `packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `price` decimal(15, 2) NOT NULL,
  `cpu` int(11) NOT NULL,
  `ram` int(11) NOT NULL,
  `disk` int(11) NOT NULL,
  `meta` text NOT NULL,
  `sort` int(11) DEFAULT NULL,
  `type` int(11) NOT NULL DEFAULT 1,
  `templateId` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `payments`
--
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `amount` decimal(15, 2) NOT NULL,
  `userId` int(11) NOT NULL,
  `paymentId` varchar(250) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `servers`
--
CREATE TABLE IF NOT EXISTS `servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vmid` int(11) NOT NULL,
  `userid` int(11) NOT NULL,
  `hostname` varchar(64) NOT NULL,
  `cpu` int(11) NOT NULL,
  `ram` int(11) NOT NULL,
  `disk` int(11) NOT NULL,
  `os` varchar(150) NOT NULL,
  `ip` varchar(35) DEFAULT NULL,
  `ip6` int(11) DEFAULT NULL,
  `node` varchar(70) NOT NULL DEFAULT 'dev-01',
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `deletedAt` timestamp NULL DEFAULT NULL,
  `nextPayment` timestamp NULL DEFAULT NULL,
  `paymentReminderSent` timestamp NULL DEFAULT NULL,
  `packageId` int(11) DEFAULT NULL,
  `status` VARCHAR(15) NOT NULL DEFAULT "online",
  `cancelledAt` DATETIME NULL DEFAULT NULL,
  `price` DECIMAL(15, 2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `ssh-keys`
--
CREATE TABLE IF NOT EXISTS `ssh-keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `fingerprint` text NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `templates`
--
CREATE TABLE IF NOT EXISTS `templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vmid` int(11) NOT NULL,
  `displayName` varchar(255) DEFAULT NULL,
  `defaultUser` varchar(255) DEFAULT NULL,
  `defaultDrive` varchar(255) DEFAULT NULL,
  `minDisk` int(11) DEFAULT NULL,
  `minCpu` int(11) DEFAULT NULL,
  `minRAM` int(11) DEFAULT NULL,
  `disabled` tinyint(4) DEFAULT 0,
  `sort` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `tickets`
--
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(250) NOT NULL,
  `assigned` int(11) NOT NULL DEFAULT 0,
  `status` int(11) NOT NULL,
  `priority` int(11) NOT NULL,
  `productId` int(11) DEFAULT NULL,
  `userid` int(11) NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `tickets_messages`
--
CREATE TABLE IF NOT EXISTS `tickets_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticketid` int(11) NOT NULL,
  `message` text NOT NULL,
  `userid` int(11) NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `users`
--
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(150) NOT NULL,
  `register` timestamp NOT NULL DEFAULT current_timestamp(),
  `confirmationToken` varchar(8) DEFAULT NULL,
  `permission` int(11) NOT NULL DEFAULT 1,
  `twofaSecret` VARCHAR(255) NULL,
  `twofaEnabled` DATETIME NULL,
  `resetToken` VARCHAR(20) NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `vouchers`
--
CREATE TABLE IF NOT EXISTS `vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `usagePerCustomer` int(11) DEFAULT NULL,
  `usageTotal` int(11) DEFAULT NULL,
  `usageTotalLeft` int(11) NOT NULL,
  `voucherBase` varchar(55) NOT NULL,
  `voucherType` varchar(55) DEFAULT NULL,
  `voucherBalanceVolume` double DEFAULT NULL,
  `voucherTypePercent` double DEFAULT NULL,
  `voucherTypeAmount` double DEFAULT NULL,
  `voucherRecurring` varchar(55) DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Table structure for table `voucher_uses`
--
CREATE TABLE IF NOT EXISTS `voucher_uses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) NOT NULL,
  `code` varchar(55) NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `api-tokens` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `userId` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `events` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `friendlyName` varchar(255) NOT NULL,
  `description` MEDIUMTEXT DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `enabled` tinyint NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `resource` varchar(255) NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `resource` (`resource`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- MIGRATIONS
INSERT INTO
  `migrations` (`id`, `status`, `name`, `appliedAt`)
VALUES
  (
    1,
    1,
    'create_migrations_table',
    '2023-03-15 22:09:54'
  ),
  (2, 1, 'create_logs_table', '2023-03-15 22:09:54'),
  (
    3,
    1,
    'add_calcType_to_charges',
    '2023-04-07 12:05:15'
  ),
  (
    4,
    1,
    'rename_disabled_templates',
    '2023-04-14 20:05:36'
  ),
  (
    5,
    1,
    'templates_add_fields',
    '2023-04-14 20:13:22'
  ),
  (
    6,
    1,
    'packages_add_sort_field',
    '2023-04-21 18:19:25'
  ),
  (
    7,
    1,
    'templates_add_sort_field',
    '2023-04-22 08:03:17'
  ),
  (
    8,
    1,
    'packages_add_type',
    '2023-04-23 09:24:44'
  ),
  (
    9,
    1,
    'add_package_to_servers',
    '2023-04-24 17:39:36'
  ),
  (
    10,
    1,
    'add_confirmationToken',
    '2023-04-24 17:39:37'
  ),
  (
    11,
    1,
    'invoices_add_descriptor',
    '2023-04-24 17:39:37'
  ),
  (
    12,
    1,
    'add_permissions_table',
    '2023-10-31 14:20:13'
  ),
  (
    13,
    1,
    'add_server_status',
    '2024-01-11 10:00:00'
  ),
  (
    14,
    1,
    'create_tokens_table',
    '2024-01-11 11:00:00'
  ),
  (
    15,
    1,
    'add_cancelledAt_column',
    '2024-01-11 11:00:00'
  ),
  (
    16,
    1,
    'add_ipam_scope_users',
    '2024-02-13 11:00:00'
  ),
  (
    17,
    1,
    'create_events_table',
    '2024-02-19 11:00:00'
  ),
  (
    18,
    1,
    'create_tags_table',
    '2024-02-19 11:00:00'
  ),
  (
    19,
    1,
    'mark_all_imported',
    '2024-04-18 12:00:00'
  ),
  (
    20,
    1,
    'monthly_charges_add_charge_id',
    '2024-04-18 12:00:00'
  ),
  (
    21,
    1,
    'add_resetToken',
    '2024-08-19 12:00:00'
  ),
  (
    22,
    1,
    'add_supportTemplates_table',
    '2024-08-19 12:00:00'
  ),
  (
    23,
    1,
    'add_price_field_server',
    '2024-11-06 12:00:00'
  );

ALTER TABLE
  `migrations`
MODIFY
  `id` int(11) NOT NULL AUTO_INCREMENT,
  AUTO_INCREMENT = 21;

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) NOT NULL,
  `permissions` varchar(24) NOT NULL,
  `resource` varchar(50) NOT NULL,
  `resourceId` int(11) NOT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `friendlyName` varchar(255) NOT NULL,
  `command` varchar(255) NOT NULL,
  `body` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;