-- ─────────────────────────────────────────────────────────────────────────────
-- NovaBuild migration — support widget: emergency "виклик адміністратора" chat
-- Run via phpMyAdmin → SQL BEFORE uploading the new code. Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

-- Заявка в поддержку. Сейчас реально создаётся только category = 'admin_call'
-- («Виклик адміністратора») — временный чат на 3 дня (expires_at). 'scam' и
-- 'transactions' зарезервированы под будущие сценарии (в UI пока заглушки).
-- status 'expired' проставляется лениво (api/support.php), крона нет.
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `conversation_id` INT(11) DEFAULT NULL,
  `category` ENUM('scam','transactions','admin_call') NOT NULL DEFAULT 'admin_call',
  `status` ENUM('open','closed','expired') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `expires_at` DATETIME NOT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `closed_by_user_id` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status_expires` (`status`, `expires_at`),
  CONSTRAINT `support_tickets_ibfk_user`   FOREIGN KEY (`user_id`)           REFERENCES `users` (`id`)         ON DELETE CASCADE,
  CONSTRAINT `support_tickets_ibfk_conv`   FOREIGN KEY (`conversation_id`)   REFERENCES `conversations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_tickets_ibfk_closer` FOREIGN KEY (`closed_by_user_id`) REFERENCES `users` (`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Какие админы подключились к заявке (подключиться может любой админ).
CREATE TABLE IF NOT EXISTS `support_ticket_admins` (
  `ticket_id` INT(11) NOT NULL,
  `admin_user_id` INT(11) NOT NULL,
  `joined_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ticket_id`, `admin_user_id`),
  KEY `admin_user_id` (`admin_user_id`),
  CONSTRAINT `support_ticket_admins_ibfk_ticket` FOREIGN KEY (`ticket_id`)     REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_ticket_admins_ibfk_admin`  FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сообщения временного чата. sender_id NULL = системное сообщение.
CREATE TABLE IF NOT EXISTS `support_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(11) NOT NULL,
  `sender_id` INT(11) DEFAULT NULL,
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  CONSTRAINT `support_messages_ibfk_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_messages_ibfk_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
