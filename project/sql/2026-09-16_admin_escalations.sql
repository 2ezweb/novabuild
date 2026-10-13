-- ─────────────────────────────────────────────────────────────────────────────
-- NovaBuild migration — "Вызов администратора" / conflict escalation
-- Run ONCE against the test DB via phpMyAdmin → SQL.
-- ─────────────────────────────────────────────────────────────────────────────

-- Visible flag while an admin has an escalation open on a contract/stage —
-- a stub for now: it doesn't block other actions, it's just shown to both
-- sides. The real support-ticket + internal investigation workflow behind a
-- "freeze" resolution is future work.
ALTER TABLE `contracts`
  ADD COLUMN IF NOT EXISTS `frozen_at` DATETIME DEFAULT NULL AFTER `status`;

-- One row per "Позвать администратора" call. Always tied to a specific
-- contract or stage (never the staged parent as a whole — see escalations.php).
CREATE TABLE IF NOT EXISTS `admin_escalations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `contract_id` INT(11) NOT NULL,
  `raised_by_user_id` INT(11) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `status` ENUM('open','resolved') NOT NULL DEFAULT 'open',
  `resolution` ENUM('terms_changed','closed_success','closed_failure','frozen') DEFAULT NULL,
  `resolution_note` TEXT DEFAULT NULL,
  `resolved_by_user_id` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT current_timestamp(),
  `resolved_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contract_id` (`contract_id`),
  KEY `raised_by_user_id` (`raised_by_user_id`),
  KEY `resolved_by_user_id` (`resolved_by_user_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB has no "IF NOT EXISTS" for constraints — comment these out if you
-- re-run the file after it already succeeded once.
ALTER TABLE `admin_escalations`
  ADD CONSTRAINT `admin_escalations_ibfk_contract`    FOREIGN KEY (`contract_id`)         REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admin_escalations_ibfk_raised_by`   FOREIGN KEY (`raised_by_user_id`)   REFERENCES `users` (`id`)     ON DELETE CASCADE,
  ADD CONSTRAINT `admin_escalations_ibfk_resolved_by` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`)     ON DELETE SET NULL;
