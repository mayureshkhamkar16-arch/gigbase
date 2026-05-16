<?php
/**
 * GigBase v2.1 — Dashboard Stats API (Multi-User)
 */

require_once __DIR__ . '/../includes/config.php';

$db = getDB();
$user = requireAuth($db);
$userId = $user['id'];
$settings = getAllSettings($db, $userId);

$rate = (float)($settings['usd_to_inr_rate'] ?? 85);
$totalTarget = (float)($settings['revenue_target'] ?? 550000);

$leadStats = $db->prepare("SELECT 
    COUNT(*) as total,
    SUM(status = 'new') as new_count,
    SUM(status = 'contacted') as contacted_count,
    SUM(status = 'followup') as followup_count,
    SUM(status = 'negotiating') as negotiating_count,
    SUM(status = 'closed') as closed_count,
    SUM(status = 'lost') as lost_count
FROM leads WHERE user_id = ?");
$leadStats->execute([$userId]);
$leadStats = $leadStats->fetch();

$revStmt = $db->prepare("SELECT 
    COALESCE(SUM(CASE WHEN currency = 'INR' THEN amount ELSE 0 END), 0) as total_inr,
    COALESCE(SUM(CASE WHEN currency = 'USD' THEN amount ELSE 0 END), 0) as total_usd,
    COALESCE(SUM(CASE 
        WHEN currency = 'INR' THEN amount 
        WHEN currency = 'USD' THEN amount * ?
        WHEN currency = 'EUR' THEN amount * (? * 1.08)
        WHEN currency = 'GBP' THEN amount * (? * 1.26)
        ELSE amount 
    END), 0) as total_combined
FROM revenue WHERE user_id = ?");
$revStmt->execute([$rate, $rate, $rate, $userId]);
$revenueStats = $revStmt->fetch();

$tl = $db->prepare("SELECT COUNT(*) as count FROM leads WHERE user_id = ? AND DATE(created_at) = CURDATE()");
$tl->execute([$userId]);
$todayLeads = (int)$tl->fetch()['count'];

$tc = $db->prepare("SELECT COUNT(*) as count FROM leads WHERE user_id = ? AND DATE(last_contact) = CURDATE()");
$tc->execute([$userId]);
$todayContacted = (int)$tc->fetch()['count'];

$wl = $db->prepare("SELECT COUNT(*) as count FROM leads WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$wl->execute([$userId]);
$weekLeads = (int)$wl->fetch()['count'];

$mr = $db->prepare("SELECT COALESCE(SUM(CASE 
    WHEN currency = 'INR' THEN amount 
    WHEN currency = 'USD' THEN amount * ?
    WHEN currency = 'EUR' THEN amount * (? * 1.08)
    WHEN currency = 'GBP' THEN amount * (? * 1.26)
    ELSE amount 
END), 0) as total
FROM revenue WHERE user_id = ? AND MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())");
$mr->execute([$rate, $rate, $rate, $userId]);
$monthRevenue = (float)$mr->fetch()['total'];

$ra = $db->prepare("SELECT al.*, l.business_name FROM activity_log al LEFT JOIN leads l ON al.lead_id = l.id WHERE al.user_id = ? ORDER BY al.created_at DESC LIMIT 15");
$ra->execute([$userId]);
$recentActivity = $ra->fetchAll();

$df = $db->prepare("SELECT COUNT(*) as count FROM leads WHERE user_id = ? AND followup_date <= CURDATE() AND status NOT IN ('closed','lost')");
$df->execute([$userId]);
$dueFollowups = (int)$df->fetch()['count'];

$totalLeads = (int)$leadStats['total'];
$closedLeads = (int)$leadStats['closed_count'];
$conversionRate = $totalLeads > 0 ? round(($closedLeads / $totalLeads) * 100, 1) : 0;

$totalEarned = (float)$revenueStats['total_combined'];
$remaining = max(0, $totalTarget - $totalEarned);

$targetDeadline = $settings['target_deadline'] ?? '2027-05-01';
$now = new DateTime();
$target = new DateTime($targetDeadline);
$diff = $now->diff($target);
$monthsLeft = max(1, ($diff->y * 12) + $diff->m);
if ($diff->invert) $monthsLeft = 1;
$monthlyNeeded = ceil($remaining / $monthsLeft);
$pctComplete = min(100, round(($totalEarned / max(1, $totalTarget)) * 100, 1));

jsonResponse([
    'leads' => $leadStats,
    'revenue' => $revenueStats,
    'today' => ['leads_added' => $todayLeads, 'contacted' => $todayContacted],
    'week' => ['leads_added' => $weekLeads],
    'month_revenue' => $monthRevenue,
    'conversion_rate' => $conversionRate,
    'due_followups' => $dueFollowups,
    'target' => [
        'total' => $totalTarget, 'earned' => $totalEarned, 'remaining' => $remaining,
        'months_left' => $monthsLeft, 'monthly_needed' => $monthlyNeeded,
        'pct_complete' => $pctComplete, 'deadline' => $targetDeadline,
    ],
    'recent_activity' => $recentActivity,
    'settings' => $settings,
]);