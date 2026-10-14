-- 08/08 — Внешний ключ contracts → brigades
-- Сначала слева выбрать базу kotegg007_novabuild, потом вкладка SQL.

-- Внешний ключ contracts → brigades (в самом конце, после создания таблицы brigades)
ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_brigade` FOREIGN KEY IF NOT EXISTS (`brigade_id`) REFERENCES `brigades` (`id`) ON DELETE SET NULL;
