-- 06/08 — Кошторис .docx (колонка document_path)
-- Сначала слева выбрать базу kotegg007_novabuild, потом вкладка SQL.

-- Путь к сгенерированному .docx (includes/estimate_document.php) — снимок
-- кошториса на момент отправки. NULL у старых — сгенерируется при первом чтении.
ALTER TABLE `contract_estimates`
  ADD COLUMN IF NOT EXISTS `document_path` VARCHAR(500) DEFAULT NULL AFTER `total`;
