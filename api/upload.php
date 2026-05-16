<?php
/**
 * GigBase v2.1 — File Upload API (Multi-User)
 */

require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'POST only'], 405);

$db = getDB();
$user = requireAuth($db);

if (!isset($_FILES['image'])) jsonResponse(['error' => 'No file'], 400);
$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) jsonResponse(['error' => 'Upload error'], 400);
if ($file['size'] > 2 * 1024 * 1024) jsonResponse(['error' => 'Max 2MB'], 400);

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) jsonResponse(['error' => 'JPEG/PNG/WebP only'], 400);

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$fn = 'portfolio_' . $user['id'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dir = __DIR__ . '/../uploads/portfolios/';
if (!is_dir($dir)) mkdir($dir, 0755, true);
if (!move_uploaded_file($file['tmp_name'], $dir . $fn)) jsonResponse(['error' => 'Save failed'], 500);
jsonResponse(['success' => true, 'path' => 'uploads/portfolios/' . $fn], 201);