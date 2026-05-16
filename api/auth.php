<?php
/**
 * GigBase v2.1 — Auth API
 * POST actions: send_otp, verify_otp, check_session, logout, logout_all, delete_account, confirm_delete
 * GET: check session
 */

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

// ─── GET: Check session ───
if ($method === 'GET') {
    $user = getAuthenticatedUser($db);
    if ($user) {
        $settings = getAllSettings($db, $user['id']);
        $settings['plan'] = $user['plan'];
        $settings['onboarded'] = (int)$user['onboarded'];
        $settings['email'] = $user['email'];
        $gs = $db->prepare("SELECT * FROM goals WHERE user_id = ? ORDER BY priority_order ASC");
        $gs->execute([$user['id']]);
        $settings['goals'] = $gs->fetchAll();
        $settings['search_limits'] = getSearchesRemaining($db, $user['id']);

        jsonResponse([
            'authenticated' => true,
            'user' => [
                'id'           => (int)$user['id'],
                'email'        => $user['email'],
                'display_name' => $user['display_name'] ?? '',
                'plan'         => $user['plan'] ?? 'free',
                'onboarded'    => (int)$user['onboarded'],
                'created_at'   => $user['created_at'],
            ],
            'settings' => $settings,
        ]);
    }
    jsonResponse(['authenticated' => false]);
}

if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['action'])) jsonResponse(['error' => 'Action required'], 400);

$action = $data['action'];


// ═══ SEND OTP ═══
if ($action === 'send_otp') {
    $email = strtolower(trim($data['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Enter a valid email address.'], 400);
    }

    // Block disposable domains
    $disposable = ['tempmail.com','throwaway.email','guerrillamail.com','mailinator.com','yopmail.com','10minutemail.com','trashmail.com','fakeinbox.com','maildrop.cc','dispostable.com','guerrillamailblock.com','sharklasers.com','grr.la','tempail.com'];
    $domain = explode('@', $email)[1] ?? '';
    if (in_array($domain, $disposable)) {
        jsonResponse(['error' => 'Disposable emails not allowed. Use a real email.'], 400);
    }

    $rateCheck = canSendOTP($db, $email);
    if (!$rateCheck['allowed']) {
        jsonResponse(['error' => $rateCheck['reason']], 429);
    }

    $otp  = createOTP($db, $email, 'login');
    $sent = sendOTPEmail($email, $otp, 'login');

    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $exists = (bool)$stmt->fetch();

    $response = [
        'success'    => true,
        'new_user'   => !$exists,
        'expires_in' => OTP_EXPIRY_MINUTES * 60,
    ];

    if ($sent) {
        $response['message'] = 'Login code sent to ' . $email;
    } else {
        // Email didn't send (SMTP not configured) — tell user to check otp_logs folder
        $response['message'] = 'Code generated. Check your email.';
        $response['smtp_warning'] = 'Email delivery may be delayed. If you don\'t receive it, check the otp_logs folder on your server.';
    }

    jsonResponse($response);
}


// ═══ VERIFY OTP ═══
if ($action === 'verify_otp') {
    $email    = strtolower(trim($data['email'] ?? ''));
    $inputOTP = trim($data['otp'] ?? '');

    if (!$email || !$inputOTP) jsonResponse(['error' => 'Email and OTP required.'], 400);
    if (strlen($inputOTP) !== 6 || !ctype_digit($inputOTP)) jsonResponse(['error' => 'OTP must be 6 digits.'], 400);

    $result = verifyOTP($db, $email, $inputOTP, 'login');
    if (!$result['valid']) jsonResponse(['error' => $result['error']], 401);

    // Find or create user
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $isNewUser = false;

    if (!$user) {
        $previousSearches = getDeletedAccountSearches($db, $email);

        $db->prepare("INSERT INTO users (email, is_verified, total_searches_used, last_login_at, last_ip) VALUES (?, 1, ?, NOW(), ?)")
           ->execute([$email, $previousSearches, getClientIP()]);

        $userId = (int)$db->lastInsertId();
        $isNewUser = true;

        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    } else {
        $userId = (int)$user['id'];
        if (!$user['is_verified']) {
            $db->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$userId]);
        }
    }

    createSession($db, $userId, true);

    $settings = getAllSettings($db, $userId);
    $settings['plan'] = $user['plan'] ?? 'free';
    $settings['onboarded'] = (int)($user['onboarded'] ?? 0);
    $settings['email'] = $email;

    jsonResponse([
        'success'  => true,
        'new_user' => $isNewUser,
        'user' => [
            'id'           => $userId,
            'email'        => $email,
            'display_name' => $user['display_name'] ?? '',
            'plan'         => $user['plan'] ?? 'free',
            'onboarded'    => (int)($user['onboarded'] ?? 0),
        ],
        'settings' => $settings,
    ]);
}


// ═══ LOGOUT ═══
if ($action === 'logout') {
    destroySession($db);
    jsonResponse(['success' => true]);
}

if ($action === 'logout_all') {
    $user = requireAuth($db);
    destroyAllSessions($db, $user['id']);
    jsonResponse(['success' => true]);
}


// ═══ DELETE ACCOUNT — Step 1 ═══
if ($action === 'delete_account') {
    $user = requireAuth($db);
    $rateCheck = canSendOTP($db, $user['email']);
    if (!$rateCheck['allowed']) jsonResponse(['error' => $rateCheck['reason']], 429);

    $otp = createOTP($db, $user['email'], 'delete_account');
    sendOTPEmail($user['email'], $otp, 'delete_account');
    jsonResponse(['success' => true, 'message' => 'Deletion code sent to ' . $user['email']]);
}


// ═══ DELETE ACCOUNT — Step 2 ═══
if ($action === 'confirm_delete') {
    $user = requireAuth($db);
    $inputOTP = trim($data['otp'] ?? '');
    if (!$inputOTP) jsonResponse(['error' => 'Enter the deletion code.'], 400);

    $result = verifyOTP($db, $user['email'], $inputOTP, 'delete_account');
    if (!$result['valid']) jsonResponse(['error' => $result['error']], 401);

    $userId = (int)$user['id'];

    // Count leads for archive
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM leads WHERE user_id = ?");
    $stmt->execute([$userId]);
    $leadCount = (int)$stmt->fetch()['c'];

    // Archive for anti-abuse
    $db->prepare("INSERT INTO deleted_accounts (email, total_searches_used, total_leads_created, original_user_id, ip_hash) VALUES (?, ?, ?, ?, ?)")
       ->execute([$user['email'], (int)$user['total_searches_used'], $leadCount, $userId, hash('sha256', getClientIP())]);

    // Clean non-cascading tables
    $db->prepare("DELETE FROM user_settings WHERE user_id = ?")->execute([$userId]);
    $db->prepare("DELETE FROM activity_log WHERE user_id = ?")->execute([$userId]);
    $db->prepare("DELETE FROM search_log WHERE user_id = ?")->execute([$userId]);

    // Delete user (CASCADE handles leads, revenue, goals, portfolio, sessions)
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

    destroySession($db);
    jsonResponse(['success' => true, 'message' => 'Account deleted.']);
}

jsonResponse(['error' => 'Unknown action'], 400);