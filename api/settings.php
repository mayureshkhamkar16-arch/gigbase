<?php
/**
 * GigBase v2.1 — Settings API (Multi-User)
 */

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$user = requireAuth($db);
$userId = $user['id'];

if ($method === 'GET') {
    $settings = getAllSettings($db, $userId);
    $goals = $db->prepare("SELECT * FROM goals WHERE user_id = ? ORDER BY priority_order ASC");
    $goals->execute([$userId]);
    $settings['goals'] = $goals->fetchAll();
    $searchInfo = getSearchesRemaining($db, $userId);
    $settings['search_limits'] = $searchInfo;
    $settings['plan'] = $user['plan'];
    $settings['onboarded'] = (int)$user['onboarded'];
    $settings['email'] = $user['email'];
    jsonResponse(['settings' => $settings]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) jsonResponse(['error' => 'Invalid data'], 400);

    // ─── Razorpay: Create Order ───
    if (($data['action'] ?? '') === 'create_order') {
        $plan = $data['plan'] ?? 'pro';
        if (!in_array($plan, ['pro', 'elite'])) jsonResponse(['error' => 'Invalid plan'], 400);

        $limits = getPlanLimits($plan);
        $currency = ($data['currency'] ?? 'INR') === 'USD' ? 'USD' : 'INR';
        $amount = $currency === 'INR' ? $limits['price_inr'] : $limits['price_usd'];
        if ($amount <= 0) jsonResponse(['error' => 'Invalid amount'], 400);

        $orderData = json_encode([
            'amount'   => $amount,
            'currency' => $currency,
            'receipt'  => 'gb_' . $userId . '_' . $plan . '_' . time(),
            'notes'    => ['plan' => $plan, 'user_id' => $userId, 'email' => $user['email']],
        ]);

        $res = safeCurl('https://api.razorpay.com/v1/orders', [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $orderData,
            CURLOPT_USERPWD    => RZP_KEY_ID . ':' . RZP_KEY_SECRET,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        if ($res['error']) jsonResponse(['error' => 'Razorpay connection failed: ' . $res['error']], 502);
        if (!$res['ok']) jsonResponse(['error' => 'Razorpay order failed', 'detail' => $res['body']], 502);

        $order = json_decode($res['body'], true);
        if (!$order || empty($order['id'])) jsonResponse(['error' => 'Invalid Razorpay response'], 502);

        jsonResponse([
            'success'  => true,
            'order_id' => $order['id'],
            'amount'   => $amount,
            'currency' => $currency,
            'key_id'   => RZP_KEY_ID,
            'plan'     => $plan,
        ]);
    }

    // ─── Razorpay: Verify Payment ───
    if (($data['action'] ?? '') === 'verify_payment') {
        $orderId   = $data['razorpay_order_id'] ?? '';
        $paymentId = $data['razorpay_payment_id'] ?? '';
        $signature = $data['razorpay_signature'] ?? '';
        $plan      = $data['plan'] ?? 'pro';

        if (!$orderId || !$paymentId || !$signature) jsonResponse(['error' => 'Missing payment data'], 400);

        $expectedSig = hash_hmac('sha256', $orderId . '|' . $paymentId, RZP_KEY_SECRET);
        if ($expectedSig !== $signature) jsonResponse(['error' => 'Payment verification failed'], 400);

        $db->prepare("UPDATE users SET plan = ?, plan_activated_at = NOW(), plan_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?")
           ->execute([$plan, $userId]);

        setSetting($db, 'last_payment_id', $paymentId, $userId);
        setSetting($db, 'last_order_id', $orderId, $userId);

        $db->prepare("INSERT INTO activity_log (user_id, lead_id, action_type, description) VALUES (?, NULL, 'settings_updated', ?)")
           ->execute([$userId, "Upgraded to $plan (Payment: $paymentId)"]);

        jsonResponse(['success' => true, 'plan' => $plan]);
    }

    // ─── Regular Settings Update ───
    $allowed = [
        'brand_name', 'tagline', 'owner_name', 'currency', 'revenue_target',
        'target_deadline', 'portfolio_links', 'niches', 'custom_cities',
        'usd_to_inr_rate', 'theme_accent', 'daily_lead_goal',
        'weekly_pitch_goal', 'avg_deal_size_inr', 'avg_deal_size_usd',
        'whatsapp_country_code'
    ];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowed)) {
            $val = is_array($value) ? json_encode($value) : (string)$value;
            setSetting($db, $key, $val, $userId);
        }
    }

    // Handle onboarded flag on users table directly
    if (isset($data['onboarded'])) {
        $db->prepare("UPDATE users SET onboarded = ? WHERE id = ?")->execute([(int)$data['onboarded'], $userId]);
        if (!empty($data['owner_name'])) {
            $db->prepare("UPDATE users SET display_name = ? WHERE id = ?")->execute([clean($data['owner_name']), $userId]);
        }
    }

    if (isset($data['goals']) && is_array($data['goals'])) {
        $db->prepare("DELETE FROM goals WHERE user_id = ?")->execute([$userId]);
        $stmt = $db->prepare("INSERT INTO goals (user_id, name, emoji, target_amount, priority_order) VALUES (?, ?, ?, ?, ?)");
        foreach ($data['goals'] as $i => $goal) {
            if (!empty($goal['name']) && !empty($goal['target_amount'])) {
                $stmt->execute([$userId, clean($goal['name']), $goal['emoji'] ?? '🎯', (float)$goal['target_amount'], $i]);
            }
        }
    }

    $db->prepare("INSERT INTO activity_log (user_id, lead_id, action_type, description) VALUES (?, NULL, 'settings_updated', 'Settings updated')")
       ->execute([$userId]);

    jsonResponse(['success' => true]);
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['key'])) jsonResponse(['error' => 'Key is required'], 400);
    $val = is_array($data['value']) ? json_encode($data['value']) : (string)$data['value'];
    setSetting($db, clean($data['key']), $val, $userId);
    jsonResponse(['success' => true]);
}