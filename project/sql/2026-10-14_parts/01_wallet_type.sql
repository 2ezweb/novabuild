-- 01/08 — Тип транзакции balance_payment
-- Сначала слева выбрать базу kotegg007_novabuild, потом вкладка SQL.

-- Депозит по контракту сначала берётся с баланса клиента (escrow.php). Эта часть
-- пишется отдельной строкой 'balance_payment', чтобы не задвоить «пришло» в
-- finance.php (там клиентский приход = 'deposit'). Безопасно перезапускать.
ALTER TABLE `wallet_transactions`
  MODIFY `type` ENUM('deposit','withdrawal','contract_payment','contract_earning','balance_payment') NOT NULL;
