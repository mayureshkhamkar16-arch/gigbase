<?php
/**
 * GigBase v2.1 — Revenue API (Multi-User)
 */

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$user = requireAuth($db);
$userId = $user['id'];

if ($method === 'GET') {
    $settings = getAllSettings($db, $userId);
    $rate = (float)($settings['usd_to_inr_rate'] ?? 85);
    $target = (float)($settings['revenue_target'] ?? 550000);

    $stmt = $db->prepare("SELECT * FROM revenue WHERE user_id = ? ORDER BY payment_date DESC, created_at DESC");
    $stmt->execute([$userId]);
    $entries = $stmt->fetchAll();

    $ss = $db->prepare("SELECT 
        COALESCE(SUM(CASE WHEN currency = 'INR' THEN amount ELSE 0 END), 0) as total_inr,
        COALESCE(SUM(CASE WHEN currency = 'USD' THEN amount ELSE 0 END), 0) as total_usd,
        COALESCE(SUM(CASE WHEN currency = 'EUR' THEN amount ELSE 0 END), 0) as total_eur,
        COALESCE(SUM(CASE WHEN currency = 'GBP' THEN amount ELSE 0 END), 0) as total_gbp,
        COUNT(*) as total_payments,
        COALESCE(SUM(CASE 
            WHEN currency = 'INR' THEN amount 
            WHEN currency = 'USD' THEN amount * ?
            WHEN currency = 'EUR' THEN amount * (? * 1.08)
            WHEN currency = 'GBP' THEN amount * (? * 1.26)
            ELSE amount 
        END), 0) as total_combined
    FROM revenue WHERE user_id = ?");
    $ss->execute([$rate, $rate, $rate, $userId]);
    $stats = $ss->fetch();

    $ms = $db->prepare("SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(CASE 
        WHEN currency = 'INR' THEN amount 
        WHEN currency = 'USD' THEN amount * ?
        WHEN currency = 'EUR' THEN amount * (? * 1.08)
        WHEN currency = 'GBP' THEN amount * (? * 1.26)
        ELSE amount 
    END) as total FROM revenue WHERE user_id = ? AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY month ASC");
    $ms->execute([$rate, $rate, $rate, $userId]);
    $monthly = $ms->fetchAll();

    $gs = $db->prepare("SELECT * FROM goals WHERE user_id = ? ORDER BY priority_order ASC");
    $gs->execute([$userId]);
    $goals = $gs->fetchAll();
    $totalEarned = (float)($stats['total_combined'] ?? 0);
    $goalsWithProgress = array_map(function($goal) use ($totalEarned) {
        $goal['progress'] = min(100, round(($totalEarned / max(1, (float)$goal['target_amount'])) * 100, 1));
        return $goal;
    }, $goals);

    jsonResponse(['entries' => $entries, 'stats' => $stats, 'monthly' => $monthly, 'goals' => $goalsWithProgress, 'target' => $target, 'rate' => $rate]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['client_name']) || empty($data['amount'])) jsonResponse(['error' => 'Client name and amount required'], 400);

    $allowedCurrencies = ['INR', 'USD', 'EUR', 'GBP'];
    $currency = in_array($data['currency'] ?? 'INR', $allowedCurrencies) ? $data['currency'] : 'INR';

    $stmt = $db->prepare("INSERT INTO revenue (user_id, client_name, amount, currency, payment_date, payment_method, notes, lead_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $userId,
        clean($data['client_name']),
        (float)$data['amount'],
        $currency,
        $data['payment_date'] ?? date('Y-m-d'),
        clean($data['payment_method'] ?? ''),
        clean($data['notes'] ?? ''),
        !empty($data['lead_id']) ? (int)$data['lead_id'] : null,
    ]);

    $db->prepare("INSERT INTO activity_log (user_id, lead_id, action_type, description) VALUES (?, ?, 'payment_received', ?)")
       ->execute([$userId, !empty($data['lead_id']) ? (int)$data['lead_id'] : null, 'Payment: ' . $currency . ' ' . $data['amount'] . ' from ' . clean($data['client_name'])]);

    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()], 201);
}

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['id'])) jsonResponse(['error' => 'Payment ID required'], 400);
    $db->prepare("DELETE FROM revenue WHERE id = ? AND user_id = ?")->execute([(int)$data['id'], $userId]);
    jsonResponse(['success' => true]);
}