-- 04/08 — Статус planned + stage_mode у contracts
-- Сначала слева выбрать базу kotegg007_novabuild, потом вкладка SQL.

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
