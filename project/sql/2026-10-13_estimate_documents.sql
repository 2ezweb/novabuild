-- ─────────────────────────────────────────────────────────────────────────────
-- NovaBuild migration — кошторис как документ (.docx) для клиента
-- Run via phpMyAdmin → SQL BEFORE uploading the new code. Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

-- Путь к сгенерированному .docx (includes/estimate_document.php) — снимок
-- кошториса на момент отправки. NULL у старых — сгенерируется при первом чтении.
ALTER TABLE `contract_estimates`
  ADD COLUMN IF NOT EXISTS `document_path` VARCHAR(500) DEFAULT NULL AFTER `total`;
