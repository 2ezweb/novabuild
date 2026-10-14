-- 03/08 — Файлы в чате + відгуки (2 таблицы)
-- Сначала слева выбрать базу kotegg007_novabuild, потом вкладка SQL.

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
