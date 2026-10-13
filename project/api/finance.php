<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

$me = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!in_array($me['role'], ['client', 'freelancer'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Финансовый отчёт доступен клиентам и фрилансерам']);
    exit;
}

$period = $_GET['period'] ?? 'month';
if (!in_array($period, ['month', 'year', 'all'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'period must be month, year or all']);
    exit;
}

$db = get_db();

// Which wallet_transactions.type counts as "in" / "out" for this role.
// Right now only 'withdrawal' is ever actually written (see wallet.php +
// admin.php::approve_withdrawal) — 'deposit'/'contract_payment'/'contract_earning'
// are reserved for when the contract-signing/escrow flow exists.
[$inType, $outType, $inLabel, $outLabel] = $me['role'] === 'freelancer'
    ? ['contract_earning', 'withdrawal', 'Заработано', 'Выведено']
    : ['deposit', 'contract_payment', 'Пополнено', 'Отправлено на контракты'];

if ($period === 'month') {
    $since      = new DateTime(date('Y-m-01'));
    $step       = new DateInterval('P1D');
    $bucketFmt  = 'Y-m-d';
} elseif ($period === 'year') {
    $since      = new DateTime(date('Y-01-01'));
    $step       = new DateInterval('P1M');
    $bucketFmt  = 'Y-m';
} else {
    $stmt = $db->prepare('SELECT created_at FROM users WHERE id = ?');
    $stmt->execute([$me['user_id']]);
    $createdAt  = $stmt->fetchColumn();
    $since      = new DateTime(date('Y-m-01', $createdAt ? strtotime($createdAt) : time()));
    $step       = new DateInterval('P1M');
    $bucketFmt  = 'Y-m';
}

$now = new DateTime();

// Pre-fill every bucket in the range with zeros so gaps between real
// transactions read as "no activity that day/month", not a misleading
// straight line connecting two sparse points.
$buckets = [];
$cursor  = clone $since;
while ($cursor <= $now) {
    $key = $cursor->format($bucketFmt);
    $buckets[$key] = ['bucket' => $key, 'in' => 0.0, 'out' => 0.0, 'transactions' => []];
    $cursor->add($step);
}

$stmt = $db->prepare('
    SELECT type, amount, description, created_at
    FROM wallet_transactions
    WHERE user_id = ? AND type IN (?, ?) AND created_at >= ?
    ORDER BY created_at ASC
');
$stmt->execute([$me['user_id'], $inType, $outType, $since->format('Y-m-d H:i:s')]);

$totalsIn  = 0.0;
$totalsOut = 0.0;

foreach ($stmt->fetchAll() as $row) {
    $key = (new DateTime($row['created_at']))->format($bucketFmt);
    if (!isset($buckets[$key])) continue; // shouldn't happen — outside the generated range

    $amount = (float) $row['amount'];
    if ($row['type'] === $inType) {
        $buckets[$key]['in'] += $amount;
        $totalsIn += $amount;
    } else {
        $buckets[$key]['out'] += $amount;
        $totalsOut += $amount;
    }

    $buckets[$key]['transactions'][] = [
        'type'        => $row['type'],
        'amount'      => $amount,
        'description' => $row['description'],
        'created_at'  => $row['created_at'],
    ];
}

echo json_encode([
    'period'    => $period,
    'in_label'  => $inLabel,
    'out_label' => $outLabel,
    'points'    => array_values($buckets),
    'totals'    => ['in' => $totalsIn, 'out' => $totalsOut],
]);
