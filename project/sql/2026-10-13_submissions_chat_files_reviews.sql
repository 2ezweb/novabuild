-- ─────────────────────────────────────────────────────────────────────────────
-- NovaBuild migration — balance_payment, work hand-over, chat files, reviews
-- Run via phpMyAdmin → SQL BEFORE uploading the new code. Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

-- Депозит по контракту сначала берётся с баланса клиента (escrow.php). Эта часть
-- пишется отдельной строкой 'balance_payment', чтобы не задвоить «пришло» в
-- finance.php (там клиентский приход = 'deposit'). Безопасно перезапускать.
ALTER TABLE `wallet_transactions`
  MODIFY `type` ENUM('deposit','withdrawal','contract_payment','contract_earning','balance_payment') NOT NULL;

-- Сдача-приёмка работы: фрилансер сдаёт работу по контракту/этапу (комментарий +
-- файлы), клиент принимает (= закрытие с выплатой) или возвращает на доработку.
CREATE TABLE IF NOT EXISTS `work_submissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `contract_id` INT(11) NOT NULL,
  `freelancer_user_id` INT(11) NOT NULL,
  `comment` TEXT DEFAULT NULL,
  `status` ENUM('submitted','accepted','rejected') NOT NULL DEFAULT 'submitted',
  `client_comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT current_timestamp(),
  `decided_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contract_id` (`contract_id`),
  KEY `freelancer_user_id` (`freelancer_user_id`),
  CONSTRAINT `work_submissions_ibfk_contract`   FOREIGN KEY (`contract_id`)        REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `work_submissions_ibfk_freelancer` FOREIGN KEY (`freelancer_user_id`) REFERENCES `users` (`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_submission_files` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `submission_id` INT(11) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `size` INT(11) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `submission_id` (`submission_id`),
  CONSTRAINT `work_submission_files_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `work_submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Файлы и фото в чате. messages.body остаётся NOT NULL — у сообщения «только файлы»
-- там пустая строка.
CREATE TABLE IF NOT EXISTS `message_attachments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `message_id` INT(11) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `size` INT(11) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `message_id` (`message_id`),
  CONSTRAINT `message_attachments_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Отзывы клиента о фрилансере — один на закрытый контракт верхнего уровня
-- (полный контракт или поэтапный целиком, не на каждый этап).
CREATE TABLE IF NOT EXISTS `reviews` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `contract_id` INT(11) NOT NULL,
  `client_user_id` INT(11) NOT NULL,
  `freelancer_user_id` INT(11) NOT NULL,
  `rating` TINYINT(4) NOT NULL,
  `comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `contract_id` (`contract_id`),
  KEY `freelancer_user_id` (`freelancer_user_id`),
  KEY `client_user_id` (`client_user_id`),
  CONSTRAINT `reviews_ibfk_contract`   FOREIGN KEY (`contract_id`)        REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_ibfk_client`     FOREIGN KEY (`client_user_id`)     REFERENCES `users` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `reviews_ibfk_freelancer` FOREIGN KEY (`freelancer_user_id`) REFERENCES `users` (`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
