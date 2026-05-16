<?php
/**
 * GigBase v2.1 — Portfolio API (Multi-User)
 */

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$user = requireAuth($db);
$userId = $user['id'];

$uploadDir = __DIR__ . '/../uploads/portfolio/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

if ($method === 'GET') {
    $stmt = $db->prepare("SELECT * FROM portfolio_projects WHERE user_id = ? ORDER BY is_featured DESC, sort_order ASC, created_at DESC");
    $stmt->execute([$userId]);
    jsonResponse(['projects' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $searchInfo = getSearchesRemaining($db, $userId);
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM portfolio_projects WHERE user_id = ?");
    $stmt->execute([$userId]);
    $currentCount = (int)$stmt->fetch()['c'];

    if ($currentCount >= $searchInfo['portfolio_slots']) {
        jsonResponse(['error' => 'Portfolio slot limit reached (' . $searchInfo['portfolio_slots'] . ')', 'upgrade' => true], 403);
    }

    $title       = clean($_POST['title'] ?? '');
    $description = clean($_POST['description'] ?? '');
    $url         = clean($_POST['url'] ?? '');
    $niche       = clean($_POST['niche'] ?? 'web_development');
    $techStack   = clean($_POST['tech_stack'] ?? '');
    $clientName  = clean($_POST['client_name'] ?? '');
    $isFeatured  = (int)($_POST['is_featured'] ?? 0);

    if (!$title) jsonResponse(['error' => 'Project title is required'], 400);

    $imagePath = '';
    if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $mime = mime_content_type($_FILES['image']['tmp_name']);
        if (!in_array($mime, $allowed)) jsonResponse(['error' => 'Only JPG, PNG, WebP, GIF allowed'], 400);
        if ($_FILES['image']['size'] > 5 * 1024 * 1024) jsonResponse(['error' => 'Image must be under 5MB'], 400);
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = 'proj_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $filename);
        $imagePath = 'uploads/portfolio/' . $filename;
    }

    $sortOrder = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM portfolio_projects WHERE user_id = ?");
    $sortOrder->execute([$userId]);
    $next = (int)$sortOrder->fetch()['next'];

    $stmt = $db->prepare("INSERT INTO portfolio_projects (user_id, title, description, url, image_path, niche, tech_stack, client_name, is_featured, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $title, $description, $url, $imagePath, $niche, $techStack, $clientName, $isFeatured, $next]);

    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()], 201);
}

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['id'])) jsonResponse(['error' => 'Project ID required'], 400);

    $row = $db->prepare("SELECT image_path FROM portfolio_projects WHERE id = ? AND user_id = ?");
    $row->execute([(int)$data['id'], $userId]);
    $proj = $row->fetch();
    if ($proj && $proj['image_path']) {
        $fullPath = __DIR__ . '/../' . $proj['image_path'];
        if (file_exists($fullPath)) unlink($fullPath);
    }

    $db->prepare("DELETE FROM portfolio_projects WHERE id = ? AND user_id = ?")->execute([(int)$data['id'], $userId]);
    jsonResponse(['success' => true]);
}