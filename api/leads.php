<?php
/**
 * GigBase v2.1 — Leads API (Multi-User)
 */

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$user = requireAuth($db);
$userId = $user['id'];

if ($method === 'GET') {
    // ─── CSV Export ───
    if (isset($_GET['action']) && $_GET['action'] === 'export') {
        $searchInfo = getSearchesRemaining($db, $userId);
        if (!$searchInfo['export']) {
            jsonResponse(['error' => 'Export requires Pro or Elite plan', 'upgrade' => true], 403);
        }

        $stmt = $db->prepare("SELECT business_name, owner_name, phone, email, niche, city, area, source, budget, status, notes, website_url, has_website, google_rating, priority, followup_date, created_at FROM leads WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gigbase-leads-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['Business', 'Owner', 'Phone', 'Email', 'Niche', 'City', 'Area', 'Source', 'Budget', 'Status', 'Notes', 'Website', 'Has Website', 'Rating', 'Priority', 'Follow-up', 'Added']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['business_name'], $r['owner_name'], $r['phone'], $r['email'], $r['niche'], $r['city'], $r['area'], $r['source'], $r['budget'], $r['status'], $r['notes'], $r['website_url'], $r['has_website'] ? 'Yes' : 'No', $r['google_rating'], $r['priority'], $r['followup_date'], $r['created_at']]);
        }
        fclose($out);
        exit;
    }

    // ─── List / Filter ───
    $where = ['user_id = :uid'];
    $params = [':uid' => $userId];

    if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
        $where[] = 'status = :status';
        $params[':status'] = clean($_GET['status']);
    }
    if (!empty($_GET['niche'])) {
        $where[] = 'niche = :niche';
        $params[':niche'] = clean($_GET['niche']);
    }
    if (!empty($_GET['city'])) {
        $where[] = 'city = :city';
        $params[':city'] = clean($_GET['city']);
    }
    if (!empty($_GET['search'])) {
        $where[] = '(business_name LIKE :search OR owner_name LIKE :search2 OR phone LIKE :search3 OR area LIKE :search4)';
        $term = '%' . clean($_GET['search']) . '%';
        $params[':search'] = $term;
        $params[':search2'] = $term;
        $params[':search3'] = $term;
        $params[':search4'] = $term;
    }

    $sql = 'SELECT * FROM leads WHERE ' . implode(' AND ', $where) . ' ORDER BY priority DESC, created_at DESC';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $sql .= " LIMIT $limit OFFSET $offset";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll();

    $statsStmt = $db->prepare("SELECT 
        COUNT(*) as total,
        SUM(status = 'new') as new_count,
        SUM(status = 'contacted') as contacted_count,
        SUM(status = 'followup') as followup_count,
        SUM(status = 'negotiating') as negotiating_count,
        SUM(status = 'closed') as closed_count,
        SUM(status = 'lost') as lost_count
    FROM leads WHERE user_id = ?");
    $statsStmt->execute([$userId]);
    $stats = $statsStmt->fetch();

    $duStmt = $db->prepare("SELECT COUNT(*) as count FROM leads WHERE user_id = ? AND followup_date <= CURDATE() AND status NOT IN ('closed','lost')");
    $duStmt->execute([$userId]);
    $dueFollowups = (int)$duStmt->fetch()['count'];

    jsonResponse(['leads' => $leads, 'page' => $page, 'limit' => $limit, 'stats' => $stats, 'due_followups' => $dueFollowups]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['business_name'])) jsonResponse(['error' => 'Business name is required'], 400);

    $searchInfo = getSearchesRemaining($db, $userId);
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM leads WHERE user_id = ?");
    $stmt->execute([$userId]);
    $currentCount = (int)$stmt->fetch()['c'];

    if ($currentCount >= $searchInfo['leads_max']) {
        jsonResponse(['error' => 'Lead limit reached (' . $searchInfo['leads_max'] . ')', 'upgrade' => true, 'plan' => $searchInfo['plan']], 403);
    }

    $dupCheck = $db->prepare("SELECT id FROM leads WHERE user_id = ? AND business_name = ? AND city = ? LIMIT 1");
    $dupCheck->execute([$userId, clean($data['business_name']), clean($data['city'] ?? '')]);
    if ($dupCheck->fetch()) {
        jsonResponse(['error' => 'Already in your pipeline', 'duplicate' => true], 409);
    }

    $stmt = $db->prepare("INSERT INTO leads 
        (user_id, business_name, owner_name, phone, email, niche, city, area, source, budget, status, notes, instagram, website_url, has_website, google_place_id, google_rating, priority, followup_date) 
        VALUES (:uid, :business_name, :owner_name, :phone, :email, :niche, :city, :area, :source, :budget, :status, :notes, :instagram, :website_url, :has_website, :google_place_id, :google_rating, :priority, :followup_date)");

    $stmt->execute([
        ':uid'             => $userId,
        ':business_name'   => clean($data['business_name']),
        ':owner_name'      => clean($data['owner_name'] ?? ''),
        ':phone'           => clean($data['phone'] ?? ''),
        ':email'           => clean($data['email'] ?? ''),
        ':niche'           => clean($data['niche'] ?? 'other'),
        ':city'            => clean($data['city'] ?? ''),
        ':area'            => clean($data['area'] ?? ''),
        ':source'          => clean($data['source'] ?? 'google_maps'),
        ':budget'          => clean($data['budget'] ?? ''),
        ':status'          => 'new',
        ':notes'           => clean($data['notes'] ?? ''),
        ':instagram'       => clean($data['instagram'] ?? ''),
        ':website_url'     => clean($data['website_url'] ?? ''),
        ':has_website'     => (int)($data['has_website'] ?? 0),
        ':google_place_id' => clean($data['google_place_id'] ?? ''),
        ':google_rating'   => !empty($data['google_rating']) ? (float)$data['google_rating'] : null,
        ':priority'        => (int)($data['priority'] ?? 0),
        ':followup_date'   => !empty($data['followup_date']) ? $data['followup_date'] : null,
    ]);

    $id = $db->lastInsertId();
    $db->prepare("INSERT INTO activity_log (user_id, lead_id, action_type, description) VALUES (?, ?, 'created', ?)")
       ->execute([$userId, $id, 'Lead created: ' . clean($data['business_name'])]);

    jsonResponse(['success' => true, 'id' => (int)$id], 201);
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['id'])) jsonResponse(['error' => 'Lead ID is required'], 400);

    $id = (int)$data['id'];
    // Verify ownership
    $check = $db->prepare("SELECT id FROM leads WHERE id = ? AND user_id = ?");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) jsonResponse(['error' => 'Lead not found'], 404);

    $fields = [];
    $params = [':id' => $id];
    $allowed = ['business_name', 'owner_name', 'phone', 'email', 'niche', 'city', 'area', 'source', 'budget', 'status', 'notes', 'instagram', 'website_url', 'priority', 'followup_date'];

    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = clean($data[$field]);
        }
    }

    if (isset($data['status'])) {
        $fields[] = 'last_contact = NOW()';
        $db->prepare("INSERT INTO activity_log (user_id, lead_id, action_type, description) VALUES (?, ?, 'status_change', ?)")
           ->execute([$userId, $id, 'Status → ' . clean($data['status'])]);
    }
    if (isset($data['notes']) && $data['notes'] !== '') {
        $db->prepare("INSERT INTO activity_log (user_id, lead_id, action_type, description) VALUES (?, ?, 'note_added', ?)")
           ->execute([$userId, $id, 'Note: ' . clean($data['notes'])]);
    }
    if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);

    $sql = "UPDATE leads SET " . implode(', ', $fields) . " WHERE id = :id AND user_id = $userId";
    $db->prepare($sql)->execute($params);
    jsonResponse(['success' => true]);
}

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['id'])) jsonResponse(['error' => 'Lead ID is required'], 400);
    $db->prepare("DELETE FROM leads WHERE id = ? AND user_id = ?")->execute([(int)$data['id'], $userId]);
    jsonResponse(['success' => true]);
}