-- 07/08 — Бригади (3 таблицы + колонка contracts.brigade_id)
-- Сначала слева выбрать базу kotegg007_novabuild, потом вкладка SQL.

-- Бригада. Создаёт фрилансер — он бригадир (owner). Один бригадир = одна бригада.
CREATE TABLE IF NOT EXISTS `brigades` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `owner_user_id` INT(11) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `owner_user_id` (`owner_user_id`),
  CONSTRAINT `brigades_ibfk_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Участники (кроме самого бригадира). status: invited — приглашение ждёт ответа,
-- active — в бригаде. Человек может быть active только в одной бригаде (проверяет API),
-- приглашений от разных бригад может быть несколько. role — свободный текст от бригадира.
CREATE TABLE IF NOT EXISTS `brigade_members` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `brigade_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `role` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('invited','active') NOT NULL DEFAULT 'invited',
  `invited_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `joined_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `brigade_user` (`brigade_id`, `user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `brigade_members_ibfk_brigade` FOREIGN KEY (`brigade_id`) REFERENCES `brigades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `brigade_members_ibfk_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Участники (кроме самого бригадира). status: invited — приглашение ждёт ответа,
-- active — в бригаде. Человек может быть active только в одной бригаде (проверяет API),
-- приглашений от разных бригад может быть несколько. role — свободный текст от бригадира.
CREATE TABLE IF NOT EXISTS `brigade_members` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `brigade_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `role` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('invited','active') NOT NULL DEFAULT 'invited',
  `invited_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `joined_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `brigade_user` (`brigade_id`, `user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `brigade_members_ibfk_brigade` FOREIGN KEY (`brigade_id`) REFERENCES `brigades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `brigade_members_ibfk_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Розподіл оплати: сколько из цены контракта/этапа бригадир отдаёт каждому участнику.
-- Остаток — бригадиру. При выплате (contract_payouts.php pay_freelancer) эскроу
-- делится по этим суммам (пропорционально, если внесён не весь депозит).
CREATE TABLE IF NOT EXISTS `contract_allocations` (
  `contract_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`contract_id`, `user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `contract_allocations_ibfk_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_allocations_ibfk_user`     FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
