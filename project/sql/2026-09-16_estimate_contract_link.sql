-- ─────────────────────────────────────────────────────────────────────────────
-- NovaBuild migration — explicit contract_estimates.contract_id
-- Run ONCE against the test DB via phpMyAdmin → SQL.
-- ─────────────────────────────────────────────────────────────────────────────

-- Until now an estimate only knew its conversation; which contract/stage it
-- was written for was inferred at submit time and never stored on the row
-- itself. That made "one pending estimate at a time" a conversation-wide
-- lock instead of a per-contract one (a stale unanswered estimate on an
-- already-closed contract could block a brand new one on the next contract
-- signed in the same conversation), and gave no way to tell, later, which
-- past estimate belonged to which contract/stage. contract_id fixes both.
ALTER TABLE `contract_estimates`
  ADD COLUMN IF NOT EXISTS `contract_id` INT(11) DEFAULT NULL AFTER `conversation_id`;

ALTER TABLE `contract_estimates`
  ADD INDEX IF NOT EXISTS `contract_id` (`contract_id`);

-- MariaDB has no "IF NOT EXISTS" for constraints — comment this out if you
-- re-run the file after it already succeeded once.
ALTER TABLE `contract_estimates`
  ADD CONSTRAINT `contract_estimates_ibfk_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE;
