<?php
/**
 * GigBase v2.1 — Pitch Templates API (Multi-User)
 */

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$user = requireAuth($db);
$userId = $user['id'];

if ($method === 'GET') {
    // Get default templates + user's custom templates
    $stmt = $db->prepare("SELECT * FROM pitch_templates WHERE (is_default = 1 AND user_id IS NULL) OR user_id = ? ORDER BY is_default DESC, id ASC");
    $stmt->execute([$userId]);
    jsonResponse(['templates' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!empty($data['action']) && $data['action'] === 'create') {
        $stmt = $db->prepare("INSERT INTO pitch_templates (user_id, name, category, content, is_default) VALUES (?, ?, ?, ?, 0)");
        $stmt->execute([
            $userId,
            clean($data['name'] ?? 'Custom Template'),
            in_array($data['category'] ?? '', ['whatsapp','email','dm','cold_call','custom']) ? $data['category'] : 'custom',
            $data['content'] ?? '',
        ]);
        jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()], 201);
    }
    
    $templateId = (int)($data['template_id'] ?? 0);
    if ($templateId <= 0) jsonResponse(['error' => 'Template ID required'], 400);

    // Allow access to default templates OR user's own templates
    $stmt = $db->prepare("SELECT * FROM pitch_templates WHERE id = ? AND ((is_default = 1 AND user_id IS NULL) OR user_id = ?)");
    $stmt->execute([$templateId, $userId]);
    $template = $stmt->fetch();
    if (!$template) jsonResponse(['error' => 'Template not found'], 404);

    $settings = getAllSettings($db, $userId);
    $portfolioLinks = json_decode($settings['portfolio_links'] ?? '[]', true);
    $portfolioStr = '';
    if (!empty($portfolioLinks)) {
        foreach ($portfolioLinks as $link) {
            $label = $link['label'] ?? '';
            $url = $link['url'] ?? '';
            if ($url) $portfolioStr .= ($label ? "→ $label: " : "🔗 ") . "$url\n";
        }
    }

    $content = str_replace(
        ['{{BUSINESS}}', '{{OWNER}}', '{{NICHE}}', '{{MY_NAME}}', '{{BRAND}}', '{{PORTFOLIO}}', '{{PRICE}}', '{{PRICE_USD}}'],
        [
            $data['business_name'] ?? '[Business Name]',
            $data['owner_name'] ?? '[Owner Name]',
            $data['niche'] ?? '[Your Niche]',
            $settings['owner_name'] ?? '[Your Name]',
            $settings['brand_name'] ?? '[Your Brand]',
            $portfolioStr ?: '[Add portfolio links in Settings]',
            ($settings['currency'] ?? 'INR') === 'INR' ? '₹' . number_format((float)($settings['avg_deal_size_inr'] ?? 15000)) : '$' . number_format((float)($settings['avg_deal_size_usd'] ?? 350)),
            '$' . number_format((float)($settings['avg_deal_size_usd'] ?? 350)),
        ],
        $template['content']
    );

    jsonResponse(['template' => $template, 'generated' => $content]);
}

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['id'])) jsonResponse(['error' => 'Template ID required'], 400);
    // Only delete user's custom templates, never default ones
    $db->prepare("DELETE FROM pitch_templates WHERE id = ? AND user_id = ? AND is_default = 0")->execute([(int)$data['id'], $userId]);
    jsonResponse(['success' => true]);
}