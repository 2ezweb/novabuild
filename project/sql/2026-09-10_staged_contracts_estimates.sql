-- ─────────────────────────────────────────────────────────────────────────────
-- NovaBuild migration — staged contracts, material-purchase clause, estimates
-- Run ONCE against the test DB via phpMyAdmin → SQL.
-- (No mysqldump on the FTP host; this is how schema changes are applied.)
-- ─────────────────────────────────────────────────────────────────────────────

-- Contract type + stage support. A "stage" is just a contracts row with
-- parent_contract_id set — it reuses the whole contract lifecycle
-- (accept/reject/close/escrow/dispute/change) with no new code.
ALTER TABLE `contracts`
  ADD COLUMN IF NOT EXISTS `type` ENUM('full','staged') NOT NULL DEFAULT 'full' AFTER `offer_id`,
  ADD COLUMN IF NOT EXISTS `parent_contract_id` INT(11) DEFAULT NULL AFTER `type`,
  ADD COLUMN IF NOT EXISTS `seq` INT(11) DEFAULT NULL AFTER `parent_contract_id`,
  ADD COLUMN IF NOT EXISTS `title` VARCHAR(255) DEFAULT NULL AFTER `seq`,
  ADD COLUMN IF NOT EXISTS `materials_paid_by` ENUM('exchange_reserve','client','exchange_service') DEFAULT NULL AFTER `escrowed_amount`,
  ADD COLUMN IF NOT EXISTS `estimate_id` INT(11) DEFAULT NULL AFTER `materials_paid_by`;

ALTER TABLE `contracts`
  ADD INDEX IF NOT EXISTS `parent_contract_id` (`parent_contract_id`),
  ADD INDEX IF NOT EXISTS `estimate_id` (`estimate_id`);

-- СМЕТА — the freelancer's bill of materials, sent to the client before a
-- contract. items is a JSON array of {name, qty, unit, unit_price}.
CREATE TABLE IF NOT EXISTS `contract_estimates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` INT(11) NOT NULL,
  `freelancer_user_id` INT(11) NOT NULL,
  `items` LONGTEXT NOT NULL,
  `note` TEXT DEFAULT NULL,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('submitted','accepted','rejected') NOT NULL DEFAULT 'submitted',
  `created_at` TIMESTAMP NULL DEFAULT current_timestamp(),
  `decided_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `freelancer_user_id` (`freelancer_user_id`),
  CONSTRAINT `contract_estimates_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_estimates_ibfk_2` FOREIGN KEY (`freelancer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foreign keys on the new contracts columns. MariaDB has no "IF NOT EXISTS"
-- for constraints — if you re-run this file, comment these two out.
ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_parent`   FOREIGN KEY (`parent_contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `contracts_ibfk_estimate` FOREIGN KEY (`estimate_id`) REFERENCES `contract_estimates` (`id`) ON DELETE SET NULL;
