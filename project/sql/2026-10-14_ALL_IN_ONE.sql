-- ═════════════════════════════════════════════════════════════════════════════
-- NovaBuild — ВСЕ миграции за 2026-10-13 и 2026-10-14 одним файлом
-- Вставить целиком в phpMyAdmin → вкладка SQL → «Вперёд». Порядок важен (как ниже).
-- Безопасно запускать повторно (IF NOT EXISTS везде, MODIFY ENUM переопределяет то же).
-- Результат: 27 таблиц (было 17). Миграции 2026-09-xx уже применены — их здесь нет.
-- Заменяет 2026-10-13_ALL_IN_ONE.sql — если его уже прогнали, этот всё равно можно.
-- ═════════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────────────
-- 1/5 — баланс клієнта (balance_payment), здача роботи, файли в чаті, відгуки
-- (2026-10-13_submissions_chat_files_reviews.sql)
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


-- ─────────────────────────────────────────────────────────────────────────────
-- 2/5 — поетапний контракт «весь план одразу» (статус planned, stage_mode)
-- (2026-10-13_planned_stages.sql)
-- ─────────────────────────────────────────────────────────────────────────────

-- Новый статус этапа 'planned' — «За планом»: этап описан в плане и согласован,
-- но ещё не начался (стартует после завершения предыдущего).
ALTER TABLE `contracts`
  MODIFY `status` ENUM('proposed','active','completed','failed','rejected','planned') NOT NULL DEFAULT 'proposed';

-- Как формируются этапы поэтапного контракта (только у родителя):
--   incremental — по одному, следующий после завершения текущего (как было);
--   planned     — весь план этапов описан сразу при создании.
-- NULL у полных контрактов, этапов и старых поэтапных (= incremental).
ALTER TABLE `contracts`
  ADD COLUMN IF NOT EXISTS `stage_mode` ENUM('incremental','planned') DEFAULT NULL AFTER `type`;


-- ─────────────────────────────────────────────────────────────────────────────
-- 3/5 — підтримка: екстрені заявки (тимчасовий чат з адміном)
-- (2026-10-13_support_tickets.sql)
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


-- ─────────────────────────────────────────────────────────────────────────────
-- 4/5 — кошторис документом .docx (document_path)
-- (2026-10-13_estimate_documents.sql)
-- ─────────────────────────────────────────────────────────────────────────────

-- Путь к сгенерированному .docx (includes/estimate_document.php) — снимок
-- кошториса на момент отправки. NULL у старых — сгенерируется при первом чтении.
ALTER TABLE `contract_estimates`
  ADD COLUMN IF NOT EXISTS `document_path` VARCHAR(500) DEFAULT NULL AFTER `total`;


-- ─────────────────────────────────────────────────────────────────────────────
-- 5/5 — бригади (2026-10-14_brigades.sql)
-- ─────────────────────────────────────────────────────────────────────────────

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

-- Контракт, предложенный бригаде (у родителя поэтапного и у всех его этапов).
-- Сторона контракта по-прежнему freelancer_user_id = бригадир.
ALTER TABLE `contracts`
  ADD COLUMN IF NOT EXISTS `brigade_id` INT(11) DEFAULT NULL AFTER `freelancer_user_id`;

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

-- ─────────────────────────────────────────────────────────────────────────────
-- Внешний ключ contracts → brigades (в самом конце, после создания таблицы brigades)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_brigade` FOREIGN KEY IF NOT EXISTS (`brigade_id`) REFERENCES `brigades` (`id`) ON DELETE SET NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- Проверка: должно быть 27 таблиц
-- ─────────────────────────────────────────────────────────────────────────────
SELECT COUNT(*) AS tables_total FROM information_schema.tables WHERE table_schema = DATABASE();
