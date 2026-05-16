<?php
/**
 * GigBase v3.0 — Config + Auth + Helpers
 * Multi-user with email OTP authentication
 * Cache-first architecture · Monthly/Weekly limits · Active Business Score
 * 
 * SETUP:
 * 1. Run gigbase_v3.sql in phpMyAdmin
 * 2. Fill SMTP credentials below
 * 3. DB_PORT = 3306 on Hostinger
 */

// ═══════════════════════════════════════════════
// DATABASE
// ═══════════════════════════════════════════════
define('DB_HOST', 'localhost');
define('DB_NAME', 'YourOwnDBName');
define('DB_USER', 'YourOwnDbUser');
define('DB_PASS', 'YourOwnPassword');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

// ═══════════════════════════════════════════════
// API KEYS
// ═══════════════════════════════════════════════
define('GOOGLE_PLACES_KEY', 'YourOwnGooglePlacesAPIKey');
define('RZP_KEY_ID',     'YourRazorPayKeyId');
define('RZP_KEY_SECRET', 'YourRazorPayKeySecret');

// ═══════════════════════════════════════════════
// AUTH CONFIG
// ═══════════════════════════════════════════════
define('OTP_EXPIRY_MINUTES', 5);
define('OTP_MAX_ATTEMPTS', 5);
define('OTP_RATE_LIMIT', 3);
define('OTP_RATE_WINDOW_MINUTES', 10);
define('SESSION_COOKIE_NAME', 'gb_session');
define('SESSION_EXPIRY_DAYS', 30);
define('APP_NAME', 'GigBase');

// ═══════════════════════════════════════════════
// SMTP CONFIG
// ═══════════════════════════════════════════════
define('SMTP_HOST',      'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_USER',      'YourOwnReplyEmail');
define('SMTP_PASS',      'YourOwnEmailPassword');
define('SMTP_FROM_NAME', 'GigBase');
define('SMTP_ENABLED', SMTP_USER !== '' && SMTP_PASS !== '');

// ═══════════════════════════════════════════════
// CACHE CONFIG
// ═══════════════════════════════════════════════
define('CACHE_TTL_DAYS', 7);           // Cache expires after 7 days
define('CACHE_TTL_FREE_DAYS', 14);     // Free users get older cache (14 days)

// ═══════════════════════════════════════════════
// PLAN LIMITS — v3 Monthly/Weekly
// ═══════════════════════════════════════════════
define('PLAN_LIMITS', [
    'free' => [
        'searches_per_month' => 15,
        'searches_per_week'  => 0,       // No weekly cap for free (monthly is enough)
        'leads_max'          => 25,
        'templates'          => 3,
        'export'             => false,
        'portfolio_slots'    => 2,
        'live_api'           => false,    // Free = cached results only
        'ai_pitches'         => false,
        'competitor_analysis'=> false,
        'price_inr'          => 0,
        'price_usd'          => 0,
    ],
    'pro' => [
        'searches_per_month' => 150,
        'searches_per_week'  => 50,
        'leads_max'          => 500,
        'templates'          => 99,
        'export'             => true,
        'portfolio_slots'    => 10,
        'live_api'           => true,     // Pro = live API calls
        'ai_pitches'         => true,
        'competitor_analysis'=> false,
        'price_inr'          => 88900,    // ₹889/mo (paise)
        'price_usd'          => 1900,     // $19/mo (cents)
    ],
    'pro_plus' => [
        'searches_per_month' => 500,
        'searches_per_week'  => 150,
        'leads_max'          => 2000,
        'templates'          => 99,
        'export'             => true,
        'portfolio_slots'    => 25,
        'live_api'           => true,
        'ai_pitches'         => true,
        'competitor_analysis'=> true,
        'price_inr'          => 169900,   // ₹1,699/mo
        'price_usd'          => 2900,     // $29/mo
    ],
    'elite' => [
        'searches_per_month' => 1500,     // Fair use
        'searches_per_week'  => 0,        // No weekly cap
        'leads_max'          => 9999,
        'templates'          => 99,
        'export'             => true,
        'portfolio_slots'    => 50,
        'live_api'           => true,
        'ai_pitches'         => true,
        'competitor_analysis'=> true,
        'price_inr'          => 299900,   // ₹2,999/mo
        'price_usd'          => 4900,     // $49/mo
    ],
]);

// Extended usage packs (Claude-style)
define('EXTENDED_PACKS', [
    'pack_50'  => ['searches' => 50,  'price_inr' => 19900, 'price_usd' => 499],   // ₹199 / $4.99
    'pack_100' => ['searches' => 100, 'price_inr' => 34900, 'price_usd' => 899],   // ₹349 / $8.99
    'pack_250' => ['searches' => 250, 'price_inr' => 69900, 'price_usd' => 1499],  // ₹699 / $14.99
]);


// ═══════════════════════════════════════════════
// CORE HELPERS
// ═══════════════════════════════════════════════

function safeCurl(string $url, array $opts = []): array {
    $ch = curl_init();
    $base = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    foreach ($opts as $k => $v) $base[$k] = $v;
    curl_setopt_array($ch, $base);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'error' => $err, 'httpCode' => $code, 'ok' => !$err && $code >= 200 && $code < 300];
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=".DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}


// ═══════════════════════════════════════════════
// MULTI-USER SETTINGS
// ═══════════════════════════════════════════════

function getSetting(PDO $db, string $key, $default = null, ?int $userId = null) {
    if ($userId === null) {
        $stmt = $db->prepare("SELECT setting_value FROM user_settings WHERE setting_key = ? AND user_id IS NULL");
        $stmt->execute([$key]);
    } else {
        $stmt = $db->prepare("SELECT setting_value FROM user_settings WHERE setting_key = ? AND user_id = ?");
        $stmt->execute([$key, $userId]);
    }
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function setSetting(PDO $db, string $key, string $value, ?int $userId = null): void {
    if ($userId === null) {
        $stmt = $db->prepare("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (NULL, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, $value]);
    } else {
        $stmt = $db->prepare("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$userId, $key, $value]);
    }
}

function getAllSettings(PDO $db, ?int $userId = null): array {
    if ($userId === null) {
        $rows = $db->query("SELECT setting_key, setting_value FROM user_settings WHERE user_id IS NULL")->fetchAll();
    } else {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    }
    $settings = [];
    foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];
    return $settings;
}


// ═══════════════════════════════════════════════
// SESSION MANAGEMENT
// ═══════════════════════════════════════════════

function generateSessionToken(): string {
    return bin2hex(random_bytes(32));
}

function hashToken(string $token): string {
    return hash('sha256', $token);
}

function createSession(PDO $db, int $userId, bool $rememberMe = true): string {
    $token = generateSessionToken();
    $hash  = hashToken($token);
    $days  = $rememberMe ? SESSION_EXPIRY_DAYS : 1;
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));

    $db->prepare("INSERT INTO sessions (user_id, token_hash, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)")
       ->execute([$userId, $hash, getClientIP(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), $expiresAt]);

    $db->prepare("UPDATE users SET last_login_at = NOW(), last_ip = ? WHERE id = ?")
       ->execute([getClientIP(), $userId]);

    $cookieExpiry = $rememberMe ? time() + ($days * 86400) : 0;
    setcookie(SESSION_COOKIE_NAME, $token, [
        'expires'  => $cookieExpiry,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);

    return $token;
}

function getAuthenticatedUser(PDO $db): ?array {
    $token = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    if (!$token) return null;

    $hash = hashToken($token);
    $stmt = $db->prepare("
        SELECT u.* FROM users u
        INNER JOIN sessions s ON u.id = s.user_id
        WHERE s.token_hash = ? AND s.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$hash]);
    return $stmt->fetch() ?: null;
}

function requireAuth(PDO $db): array {
    $user = getAuthenticatedUser($db);
    if (!$user) {
        jsonResponse(['error' => 'Unauthorized', 'auth_required' => true], 401);
    }
    return $user;
}

function destroySession(PDO $db): void {
    $token = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    if ($token) {
        $db->prepare("DELETE FROM sessions WHERE token_hash = ?")->execute([hashToken($token)]);
    }
    setcookie(SESSION_COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

function destroyAllSessions(PDO $db, int $userId): void {
    $db->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$userId]);
    setcookie(SESSION_COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}


// ═══════════════════════════════════════════════
// OTP SYSTEM
// ═══════════════════════════════════════════════

function generateOTP(): string {
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function canSendOTP(PDO $db, string $email): array {
    $windowStart = date('Y-m-d H:i:s', strtotime('-' . OTP_RATE_WINDOW_MINUTES . ' minutes'));

    $stmt = $db->prepare("SELECT COUNT(*) as c FROM otp_codes WHERE email = ? AND created_at > ?");
    $stmt->execute([$email, $windowStart]);
    if ((int)$stmt->fetch()['c'] >= OTP_RATE_LIMIT) {
        return ['allowed' => false, 'reason' => 'Too many requests. Wait a few minutes.'];
    }

    $ipHash = hash('sha256', getClientIP());
    $stmt = $db->prepare("SELECT SUM(request_count) as c FROM otp_rate_limits WHERE identifier = ? AND identifier_type = 'ip' AND window_start > ?");
    $stmt->execute([$ipHash, $windowStart]);
    if ((int)($stmt->fetch()['c'] ?? 0) >= OTP_RATE_LIMIT * 3) {
        return ['allowed' => false, 'reason' => 'Too many requests from this network.'];
    }

    return ['allowed' => true];
}

function createOTP(PDO $db, string $email, string $purpose = 'login'): string {
    $db->prepare("UPDATE otp_codes SET is_used = 1 WHERE email = ? AND purpose = ? AND is_used = 0")
       ->execute([$email, $purpose]);

    $otp = generateOTP();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));

    $db->prepare("INSERT INTO otp_codes (email, otp_code, purpose, expires_at) VALUES (?, ?, ?, ?)")
       ->execute([$email, $otp, $purpose, $expiresAt]);

    $ipHash = hash('sha256', getClientIP());
    $windowKey = date('Y-m-d H:i:00');
    $db->prepare("INSERT INTO otp_rate_limits (identifier, identifier_type, request_count, window_start) VALUES (?, 'ip', 1, ?) ON DUPLICATE KEY UPDATE request_count = request_count + 1")
       ->execute([$ipHash, $windowKey]);

    return $otp;
}

function verifyOTP(PDO $db, string $email, string $inputOTP, string $purpose = 'login'): array {
    $stmt = $db->prepare("SELECT * FROM otp_codes WHERE email = ? AND purpose = ? AND is_used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$email, $purpose]);
    $record = $stmt->fetch();

    if (!$record) {
        return ['valid' => false, 'error' => 'OTP expired or not found. Request a new one.'];
    }
    if ((int)$record['attempts'] >= OTP_MAX_ATTEMPTS) {
        $db->prepare("UPDATE otp_codes SET is_used = 1 WHERE id = ?")->execute([$record['id']]);
        return ['valid' => false, 'error' => 'Too many attempts. Request a new OTP.'];
    }
    if ($record['otp_code'] !== $inputOTP) {
        $db->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$record['id']]);
        $rem = OTP_MAX_ATTEMPTS - $record['attempts'] - 1;
        return ['valid' => false, 'error' => "Incorrect OTP. {$rem} attempts left."];
    }

    $db->prepare("UPDATE otp_codes SET is_used = 1 WHERE id = ?")->execute([$record['id']]);
    return ['valid' => true];
}


// ═══════════════════════════════════════════════
// EMAIL — Raw SMTP (zero dependencies)
// ═══════════════════════════════════════════════

function smtpSend(string $to, string $subject, string $htmlBody): bool {
    if (!SMTP_ENABLED) return false;

    $from     = SMTP_USER;
    $fromName = SMTP_FROM_NAME;
    $host     = SMTP_HOST;
    $port     = SMTP_PORT;
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;

    $boundary = md5(uniqid(time()));
    $headers  = "From: {$fromName} <{$from}>\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
    $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
    $plainText = preg_replace('/\n{3,}/', "\n\n", trim($plainText));

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $plainText . "\r\n\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";
    $message .= "--{$boundary}--\r\n";

    $fullMsg = $headers . "\r\n" . $message;

    try {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]]);

        $useSSL = ($port === 465);
        $prefix = $useSSL ? "ssl" : "tcp";
        $socket = @stream_socket_client("{$prefix}://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);

        if (!$socket) {
            error_log("GigBase SMTP: Connection failed — {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($socket, 15);

        $read = function() use ($socket): string {
            $response = '';
            while ($line = fgets($socket, 512)) {
                $response .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $response;
        };

        $send = function(string $cmd) use ($socket, $read): string {
            fwrite($socket, $cmd . "\r\n");
            return $read();
        };

        $read();
        $send("EHLO " . (gethostname() ?: 'gigbaseapp.com'));

        if (!$useSSL) {
            $resp = $send("STARTTLS");
            if (strpos($resp, '220') === false) { fclose($socket); return false; }
            $cryptoOk = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$cryptoOk) $cryptoOk = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_ANY_CLIENT);
            if (!$cryptoOk) { fclose($socket); return false; }
            $send("EHLO " . (gethostname() ?: 'gigbaseapp.com'));
        }

        $send("AUTH LOGIN");
        $send(base64_encode($user));
        $authResp = $send(base64_encode($pass));
        if (strpos($authResp, '235') === false) { fclose($socket); return false; }

        $resp = $send("MAIL FROM:<{$from}>");
        if (strpos($resp, '250') === false) { fclose($socket); return false; }

        $resp = $send("RCPT TO:<{$to}>");
        if (strpos($resp, '250') === false) { fclose($socket); return false; }

        $resp = $send("DATA");
        if (strpos($resp, '354') === false) { fclose($socket); return false; }

        $lines = explode("\n", str_replace("\r\n", "\n", $fullMsg));
        foreach ($lines as $line) {
            if (isset($line[0]) && $line[0] === '.') $line = '.' . $line;
            fwrite($socket, $line . "\r\n");
        }

        $resp = $send(".");
        $success = strpos($resp, '250') !== false;
        $send("QUIT");
        fclose($socket);

        if ($success) error_log("GigBase SMTP: Sent to {$to}");
        return $success;

    } catch (\Exception $e) {
        error_log("GigBase SMTP exception: " . $e->getMessage());
        if (isset($socket) && is_resource($socket)) fclose($socket);
        return false;
    }
}

function sendOTPEmail(string $email, string $otp, string $purpose = 'login'): bool {
    $purposeText = match ($purpose) {
        'login'          => 'log into your GigBase account',
        'verify'         => 'verify your email address',
        'delete_account' => 'confirm account deletion',
        default          => 'complete your action',
    };

    $subject = APP_NAME . ' — Your Code: ' . $otp;

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:0;background:#f8fafc;font-family:system-ui,sans-serif">'
        . '<div style="max-width:460px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06)">'
        . '<div style="background:linear-gradient(135deg,#2563eb,#3b82f6);padding:32px 24px;text-align:center">'
        . '<h1 style="color:#fff;font-size:20px;margin:0;font-weight:800">⚡ GigBase</h1>'
        . '<p style="color:rgba(255,255,255,0.8);font-size:12px;margin:8px 0 0">Hunt · Pitch · Close</p></div>'
        . '<div style="padding:32px 24px;text-align:center">'
        . '<p style="color:#334155;font-size:14px;margin:0 0 24px;line-height:1.6">Use this code to ' . $purposeText . ':</p>'
        . '<div style="background:#f1f5f9;border:2px dashed #cbd5e1;border-radius:12px;padding:20px;margin:0 auto;display:inline-block">'
        . '<span style="font-family:monospace;font-size:36px;font-weight:900;letter-spacing:8px;color:#0f172a">' . $otp . '</span></div>'
        . '<p style="color:#94a3b8;font-size:11px;margin:20px 0 0">Expires in <strong style="color:#64748b">5 minutes</strong>.</p>'
        . '<p style="color:#94a3b8;font-size:11px;margin:8px 0 0">Didn\'t request this? Ignore this email.</p></div>'
        . '<div style="background:#f8fafc;padding:16px 24px;border-top:1px solid #e2e8f0;text-align:center">'
        . '<p style="color:#94a3b8;font-size:9px;text-transform:uppercase;letter-spacing:2px;margin:0">GigBase — Built for freelancers who grind</p>'
        . '</div></div></body></html>';

    if (SMTP_ENABLED) {
        $sent = smtpSend($email, $subject, $body);
        if ($sent) return true;
    }

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . SMTP_FROM_NAME . ' <' . (SMTP_USER ?: 'noreply@gigbase.app') . '>',
    ]);
    $sent = @mail($email, $subject, $body, $headers);
    if ($sent) return true;

    $logDir = __DIR__ . '/../otp_logs/';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . 'otp_' . date('Y-m-d_H-i-s') . '_' . substr(md5($email), 0, 6) . '.txt';
    file_put_contents($logFile, "To: {$email}\nOTP: {$otp}\nPurpose: {$purpose}\nTime: " . date('Y-m-d H:i:s') . "\nExpires: " . date('Y-m-d H:i:s', strtotime('+5 minutes')) . "\n");
    error_log("GigBase OTP for {$email}: {$otp} (saved to {$logFile})");
    return false;
}


// ═══════════════════════════════════════════════
// ANTI-ABUSE
// ═══════════════════════════════════════════════

function getDeletedAccountSearches(PDO $db, string $email): int {
    $stmt = $db->prepare("SELECT SUM(total_searches_used) as total FROM deleted_accounts WHERE email = ?");
    $stmt->execute([$email]);
    return (int)($stmt->fetch()['total'] ?? 0);
}


// ═══════════════════════════════════════════════
// SEARCH LIMITS — v3 Monthly/Weekly + Extended Packs
// ═══════════════════════════════════════════════

function getSearchesRemaining(PDO $db, ?int $userId = null): array {
    $plan = 'free';
    if ($userId) {
        $stmt = $db->prepare("SELECT plan FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $plan = $row['plan'] ?? 'free';
    }
    $limits = PLAN_LIMITS[$plan] ?? PLAN_LIMITS['free'];

    // Monthly usage
    $monthStart = date('Y-m-01');
    $monthUsed = 0;
    if ($userId) {
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM search_log WHERE user_id = ? AND created_at >= ?");
        $stmt->execute([$userId, $monthStart]);
        $monthUsed = (int)$stmt->fetch()['c'];
    }

    // Weekly usage
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekUsed = 0;
    if ($userId) {
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM search_log WHERE user_id = ? AND created_at >= ?");
        $stmt->execute([$userId, $weekStart]);
        $weekUsed = (int)$stmt->fetch()['c'];
    }

    // Extended pack bonus searches
    $bonusRemaining = 0;
    if ($userId) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(searches_remaining), 0) as bonus FROM extended_packs WHERE user_id = ? AND searches_remaining > 0 AND (expires_at IS NULL OR expires_at > NOW())");
        $stmt->execute([$userId]);
        $bonusRemaining = (int)$stmt->fetch()['bonus'];
    }

    $monthlyLimit = $limits['searches_per_month'];
    $weeklyLimit  = $limits['searches_per_week'];
    $totalLimit   = $monthlyLimit + $bonusRemaining;

    // Check weekly cap first (if set), then monthly
    $monthRemaining = max(0, $monthlyLimit - $monthUsed) + $bonusRemaining;
    $weekRemaining  = $weeklyLimit > 0 ? max(0, $weeklyLimit - $weekUsed) : $monthRemaining;
    $remaining      = min($monthRemaining, $weekRemaining);

    return [
        'plan'              => $plan,
        'month_limit'       => $monthlyLimit,
        'month_used'        => $monthUsed,
        'month_remaining'   => max(0, $monthlyLimit - $monthUsed),
        'week_limit'        => $weeklyLimit,
        'week_used'         => $weekUsed,
        'week_remaining'    => $weeklyLimit > 0 ? max(0, $weeklyLimit - $weekUsed) : null,
        'bonus_remaining'   => $bonusRemaining,
        'remaining'         => $remaining,
        'leads_max'         => $limits['leads_max'],
        'export'            => $limits['export'],
        'portfolio_slots'   => $limits['portfolio_slots'],
        'live_api'          => $limits['live_api'],
        'ai_pitches'        => $limits['ai_pitches'] ?? false,
        'competitor_analysis' => $limits['competitor_analysis'] ?? false,
    ];
}

function incrementSearch(PDO $db, ?int $userId = null, bool $usedBonus = false): void {
    if ($userId) {
        $db->prepare("UPDATE users SET total_searches_used = total_searches_used + 1 WHERE id = ?")->execute([$userId]);

        // If over monthly limit, deduct from bonus pack
        if ($usedBonus) {
            $db->prepare("UPDATE extended_packs SET searches_remaining = searches_remaining - 1 WHERE user_id = ? AND searches_remaining > 0 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at ASC LIMIT 1")
               ->execute([$userId]);
        }
    }
}

function canSearch(PDO $db, ?int $userId = null): array {
    $info = getSearchesRemaining($db, $userId);
    $canSearch = $info['remaining'] > 0;
    $usingBonus = $canSearch && $info['month_remaining'] <= 0 && $info['bonus_remaining'] > 0;
    return ['allowed' => $canSearch, 'using_bonus' => $usingBonus, 'info' => $info];
}

function getPlacesKey(PDO $db, ?int $userId = null): string {
    if ($userId) {
        $k = getSetting($db, 'google_places_key', '', $userId);
        if ($k) return $k;
    }
    return GOOGLE_PLACES_KEY;
}

function getPlanLimits(string $plan): array {
    return PLAN_LIMITS[$plan] ?? PLAN_LIMITS['free'];
}


// ═══════════════════════════════════════════════
// ACTIVE BUSINESS SCORE
// ═══════════════════════════════════════════════

function calculateActiveScore(array $place): int {
    $score = 0;

    // Rating quality (max 30)
    $rating = (float)($place['rating'] ?? 0);
    if ($rating >= 4.5) $score += 30;
    elseif ($rating >= 4.0) $score += 25;
    elseif ($rating >= 3.5) $score += 20;
    elseif ($rating >= 3.0) $score += 10;

    // Review volume = actively getting customers (max 25)
    $reviews = (int)($place['user_ratings_total'] ?? 0);
    if ($reviews >= 100) $score += 25;
    elseif ($reviews >= 50) $score += 20;
    elseif ($reviews >= 20) $score += 15;
    elseif ($reviews >= 10) $score += 10;
    elseif ($reviews >= 5) $score += 5;

    // Has phone = real business (max 20)
    if (!empty($place['phone'])) $score += 20;

    // Has photos = cares about presence (max 15)
    $photoCount = (int)($place['photo_count'] ?? 0);
    if ($photoCount >= 5) $score += 15;
    elseif ($photoCount >= 3) $score += 10;
    elseif ($photoCount >= 1) $score += 5;

    // Has business hours set = operational (max 10)
    if (!empty($place['open_now']) || $place['open_now'] === false) $score += 10;
    // open_now being boolean (true/false) means hours are set. null means no hours.

    return min(100, $score);
}

function getActiveLabel(int $score): string {
    if ($score >= 70) return 'hot';
    if ($score >= 40) return 'warm';
    return 'cold';
}


// ═══════════════════════════════════════════════
// CACHE HELPERS
// ═══════════════════════════════════════════════

function getCacheKey(string $niche, string $city, string $area = ''): string {
    return strtolower(trim($niche) . '|' . trim($city) . '|' . trim($area));
}

function getCachedResults(PDO $db, string $cacheKey, int $maxAgeDays): ?array {
    $stmt = $db->prepare("SELECT results_json, cached_at FROM places_cache WHERE cache_key = ? AND cached_at > DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1");
    $stmt->execute([$cacheKey, $maxAgeDays]);
    $row = $stmt->fetch();
    if ($row) {
        $results = json_decode($row['results_json'], true);
        if ($results !== null) return $results;
    }
    return null;
}

function setCachedResults(PDO $db, string $cacheKey, string $niche, string $city, string $area, array $results): void {
    $json = json_encode($results, JSON_UNESCAPED_UNICODE);
    $db->prepare("INSERT INTO places_cache (cache_key, niche, city, area, results_json, results_count, cached_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE results_json = VALUES(results_json), results_count = VALUES(results_count), cached_at = NOW()")
       ->execute([$cacheKey, $niche, $city, $area, $json, count($results)]);
}


// ═══════════════════════════════════════════════
// UTILITY
// ═══════════════════════════════════════════════

function getClientIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) return trim(explode(',', $_SERVER[$h])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function cleanupExpiredData(PDO $db): void {
    $db->exec("DELETE FROM otp_codes WHERE expires_at < NOW()");
    $db->exec("DELETE FROM sessions WHERE expires_at < NOW()");
    $db->exec("DELETE FROM otp_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $db->exec("DELETE FROM places_cache WHERE cached_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
}

// Lazy garbage collection (1% of requests)
if (random_int(1, 100) === 1) {
    try { cleanupExpiredData(getDB()); } catch (Exception $e) { /* silent */ }
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }