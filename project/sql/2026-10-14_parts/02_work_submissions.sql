-- 02/08 — Здача роботи (2 таблицы)
-- Сначала слева выбрать базу kotegg007_novabuild, потом вкладка SQL.

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
