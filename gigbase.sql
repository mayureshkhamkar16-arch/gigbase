<?php
/**
 * GigBase v2.1 — Config + Auth + Helpers
 * Multi-user with email OTP authentication
 * 
 * SETUP:
 * 1. Run gigbase.sql first, then migration_auth.sql
 * 2. Fill SMTP credentials below (Gmail App Password or Hostinger SMTP)
 * 3. Change DB_PORT to 3306 on Hostinger
 * 4. No Composer or PHPMailer needed — uses raw SMTP sockets
 */

// ═══════════════════════════════════════════════
// DATABASE
// ═══════════════════════════════════════════════
define('DB_HOST', 'localhost');
define('DB_NAME', 'gigbase');
define('DB_USER', 'root');
define('DB_PASS', '');          // Fill on Hostinger
define('DB_PORT', 3307);        // 3306 on Hostinger
define('DB_CHARSET', 'utf8mb4');

// ═══════════════════════════════════════════════
// API KEYS
// ═══════════════════════════════════════════════
define('GOOGLE_PLACES_KEY', 'AIzaSyCebHx0x9F9V-yNRoLPv-O6nAvOKwf0jpE');
define('RZP_KEY_ID',     'rzp_test_SZKRc4c3emH8b2');
define('RZP_KEY_SECRET', 't6xIp1s6NVT5vEa29AETt64lD');

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
// SMTP CONFIG — FILL THESE FOR OTP EMAILS TO WORK
// ═══════════════════════════════════════════════
// Gmail: Create App Password at https://myaccount.google.com/apppasswords
// Hostinger: Use your hosting email credentials
// IMPORTANT: For Gmail you MUST have 2-Step Verification ON first, then create App Password
define('SMTP_HOST',      'smtp.gmail.com');    // smtp.hostinger.com for Hostinger
define('SMTP_PORT',      587);                 // 587 for TLS (Gmail/Hostinger)
define('SMTP_USER',      '');                  // FILL: your@gmail.com
define('SMTP_PASS',      '');                  // FILL: Gmail App Password (16 chars, no spaces)
define('SMTP_FROM_NAME', 'GigBase');
define('SMTP_ENABLED', SMTP_USER !== '' && SMTP_PASS !== '');

// ═══════════════════════════════════════════════
// PLAN LIMITS
// ═══════════════════════════════════════════════
define('PLAN_LIMITS', [
    'free'  => ['searches_per_day'=>3,  'leads_max'=>30,   'templates'=>3,  'export'=>false, 'portfolio_slots'=>2,  'price_inr'=>0,     'price_usd'=>0],
    'pro'   => ['searches_per_day'=>20, 'leads_max'=>300,  'templates'=>99, 'export'=>true,  'portfolio_slots'=>10, 'price_inr'=>14900, 'price_usd'=>399],
    'elite' => ['searches_per_day'=>60, 'leads_max'=>9999, 'templates'=>99, 'export'=>true,  'portfolio_slots'=>50, 'price_inr'=>44900, 'price_usd'=>899],
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
// EMAIL SENDING — Raw SMTP (zero dependencies, works on XAMPP)
// ═══════════════════════════════════════════════

/**
 * Send email via raw SMTP socket — no PHPMailer, no Composer needed.
 * Works on XAMPP, Hostinger, any PHP 8.0+ server with openssl extension.
 */
function smtpSend(string $to, string $subject, string $htmlBody): bool {
    if (!SMTP_ENABLED) return false;

    $from     = SMTP_USER;
    $fromName = SMTP_FROM_NAME;
    $host     = SMTP_HOST;
    $port     = SMTP_PORT;
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;

    // Build MIME message
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
        // Connect with TLS
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno, $errstr, 15,
            STREAM_CLIENT_CONNECT, $ctx
        );

        if (!$socket) {
            error_log("GigBase SMTP: Connection failed — {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($socket, 15);

        // Helper to read response
        $read = function() use ($socket): string {
            $response = '';
            while ($line = fgets($socket, 512)) {
                $response .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $response;
        };

        // Helper to send command
        $send = function(string $cmd) use ($socket, $read): string {
            fwrite($socket, $cmd . "\r\n");
            return $read();
        };

        // SMTP conversation
        $read(); // greeting

        $send("EHLO " . gethostname());

        // STARTTLS
        $resp = $send("STARTTLS");
        if (strpos($resp, '220') === false) {
            error_log("GigBase SMTP: STARTTLS rejected — {$resp}");
            fclose($socket);
            return false;
        }

        // Enable TLS on the existing connection
        $cryptoOk = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if (!$cryptoOk) {
            // Retry with broader method
            $cryptoOk = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_ANY_CLIENT);
        }
        if (!$cryptoOk) {
            error_log("GigBase SMTP: TLS handshake failed");
            fclose($socket);
            return false;
        }

        // Re-EHLO after TLS
        $send("EHLO " . gethostname());

        // AUTH LOGIN
        $send("AUTH LOGIN");
        $send(base64_encode($user));
        $authResp = $send(base64_encode($pass));

        if (strpos($authResp, '235') === false) {
            error_log("GigBase SMTP: Auth failed — {$authResp}");
            fclose($socket);
            return false;
        }

        // MAIL FROM
        $resp = $send("MAIL FROM:<{$from}>");
        if (strpos($resp, '250') === false) {
            error_log("GigBase SMTP: MAIL FROM rejected — {$resp}");
            fclose($socket);
            return false;
        }

        // RCPT TO
        $resp = $send("RCPT TO:<{$to}>");
        if (strpos($resp, '250') === false) {
            error_log("GigBase SMTP: RCPT TO rejected — {$resp}");
            fclose($socket);
            return false;
        }

        // DATA
        $resp = $send("DATA");
        if (strpos($resp, '354') === false) {
            error_log("GigBase SMTP: DATA rejected — {$resp}");
            fclose($socket);
            return false;
        }

        // Send the actual message (dot-stuff any lines starting with .)
        $lines = explode("\n", str_replace("\r\n", "\n", $fullMsg));
        foreach ($lines as $line) {
            if (isset($line[0]) && $line[0] === '.') $line = '.' . $line;
            fwrite($socket, $line . "\r\n");
        }

        // End with <CRLF>.<CRLF>
        $resp = $send(".");
        $success = strpos($resp, '250') !== false;

        $send("QUIT");
        fclose($socket);

        if ($success) {
            error_log("GigBase SMTP: Email sent to {$to}");
        } else {
            error_log("GigBase SMTP: Send failed — {$resp}");
        }

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

    // Try SMTP first
    if (SMTP_ENABLED) {
        $sent = smtpSend($email, $subject, $body);
        if ($sent) return true;
    }

    // Fallback: PHP mail() — works on Hostinger but not XAMPP
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . SMTP_FROM_NAME . ' <' . (SMTP_USER ?: 'noreply@gigbase.app') . '>',
    ]);
    $sent = @mail($email, $subject, $body, $headers);
    if ($sent) return true;

    // Last resort: save OTP to file for local dev testing
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
// SEARCH LIMITS (user-scoped)
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
    $today = date('Y-m-d');

    if ($userId) {
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM search_log WHERE user_id = ? AND DATE(created_at) = ?");
        $stmt->execute([$userId, $today]);
        $used = (int)$stmt->fetch()['c'];
    } else {
        $used = 0;
    }

    return [
        'plan'            => $plan,
        'limit'           => $limits['searches_per_day'],
        'used'            => $used,
        'remaining'       => max(0, $limits['searches_per_day'] - $used),
        'leads_max'       => $limits['leads_max'],
        'export'          => $limits['export'],
        'portfolio_slots' => $limits['portfolio_slots'],
    ];
}

function incrementSearch(PDO $db, ?int $userId = null): void {
    if ($userId) {
        $db->prepare("UPDATE users SET total_searches_used = total_searches_used + 1 WHERE id = ?")->execute([$userId]);
    }
}

function canSearch(PDO $db, ?int $userId = null): bool {
    return getSearchesRemaining($db, $userId)['remaining'] > 0;
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