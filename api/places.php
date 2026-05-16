<?php
/**
 * GigBase v3.0 — Google Places Search API
 * Cache-first architecture · Free = cached · Pro = live API
 * Active Business Score · Lazy detail loading · Monthly/Weekly limits
 */

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') jsonResponse(['error' => 'Method not allowed'], 405);

$db = getDB();
$user = requireAuth($db);
$userId = $user['id'];

// ─── Rate limit check ───
if (isset($_GET['action']) && $_GET['action'] === 'limits') {
    jsonResponse(getSearchesRemaining($db, $userId));
}

// ─── Lazy Detail Fetch (single business) ───
if (isset($_GET['action']) && $_GET['action'] === 'detail') {
    $placeId = trim($_GET['place_id'] ?? '');
    if (!$placeId) jsonResponse(['error' => 'place_id required'], 400);

    // Check if detail is already cached
    $stmt = $db->prepare("SELECT results_json FROM places_cache WHERE cache_key = ? AND cached_at > DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1");
    $detailCacheKey = 'detail|' . $placeId;
    $stmt->execute([$detailCacheKey, CACHE_TTL_DAYS]);
    $cached = $stmt->fetch();

    if ($cached) {
        $detail = json_decode($cached['results_json'], true);
        if ($detail) jsonResponse(['detail' => $detail, 'from_cache' => true]);
    }

    // Only Pro+ users get live detail calls
    $searchInfo = getSearchesRemaining($db, $userId);
    if (!$searchInfo['live_api']) {
        jsonResponse(['error' => 'Live details require Pro plan', 'upgrade' => true, 'plan' => $searchInfo['plan']], 403);
    }

    $apiKey = getPlacesKey($db, $userId);
    $detailUrl = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $placeId,
        'fields'   => 'website,formatted_phone_number,international_phone_number,opening_hours,url,photos',
        'key'      => $apiKey,
    ]);
    $dRes = safeCurl($detailUrl, [CURLOPT_TIMEOUT => 10]);

    if (!$dRes['ok'] || !$dRes['body']) {
        jsonResponse(['error' => 'Failed to fetch details'], 502);
    }

    $data = json_decode($dRes['body'], true);
    $r = $data['result'] ?? [];
    $detail = [
        'website'     => $r['website'] ?? '',
        'has_website' => !empty($r['website']),
        'phone'       => $r['international_phone_number'] ?? $r['formatted_phone_number'] ?? '',
        'open_now'    => $r['opening_hours']['open_now'] ?? null,
        'photo_count' => count($r['photos'] ?? []),
        'maps_url'    => $r['url'] ?? '',
    ];

    // Cache the detail
    $db->prepare("INSERT INTO places_cache (cache_key, niche, city, area, results_json, results_count, cached_at) VALUES (?, 'detail', '', '', ?, 1, NOW()) ON DUPLICATE KEY UPDATE results_json = VALUES(results_json), cached_at = NOW()")
       ->execute([$detailCacheKey, json_encode($detail)]);

    jsonResponse(['detail' => $detail, 'from_cache' => false]);
}


// ═══════════════════════════════════════════════
// MAIN SEARCH
// ═══════════════════════════════════════════════

$niche = trim($_GET['niche'] ?? '');
$city  = trim($_GET['city'] ?? 'Pune');
$area  = trim($_GET['area'] ?? '');
$noWebsiteOnly = ($_GET['no_website'] ?? '0') === '1';

if (!$niche) jsonResponse(['error' => 'Niche is required'], 400);

// Check search limits (monthly/weekly)
$searchCheck = canSearch($db, $userId);
if (!$searchCheck['allowed']) {
    $info = $searchCheck['info'];
    $upgradeMsg = match ($info['plan']) {
        'free'     => 'Upgrade to Pro for 150 searches/month — just ₹889/mo',
        'pro'      => 'Upgrade to Pro+ for 500 searches/month or buy an extra pack',
        'pro_plus' => 'Upgrade to Elite for 1500 searches/month or buy an extra pack',
        'elite'    => 'Buy an extra search pack to continue',
        default    => 'Upgrade your plan for more searches',
    };

    jsonResponse([
        'error'        => 'Search limit reached',
        'limit'        => true,
        'plan'         => $info['plan'],
        'month_used'   => $info['month_used'],
        'month_limit'  => $info['month_limit'],
        'bonus'        => $info['bonus_remaining'],
        'upgrade_msg'  => $upgradeMsg,
        'show_packs'   => in_array($info['plan'], ['pro', 'pro_plus', 'elite']),
        'packs'        => EXTENDED_PACKS,
    ], 429);
}

$searchInfo = getSearchesRemaining($db, $userId);
$cacheKey = getCacheKey($niche, $city, $area);

// ─── FREE USERS: Serve from cache ONLY ───
if (!$searchInfo['live_api']) {
    $cached = getCachedResults($db, $cacheKey, CACHE_TTL_FREE_DAYS);

    if ($cached) {
        // Filter + score
        $results = processResults($cached, $noWebsiteOnly);

        // Log search (no API cost)
        $db->prepare("INSERT INTO search_log (user_id, query_text, city, results_count, no_website_count, from_cache) VALUES (?, ?, ?, ?, ?, 1)")
           ->execute([$userId, $niche . ' ' . ($area ? $area . ' ' : '') . $city, $city, count($results['all']), $results['no_website_count']]);
        incrementSearch($db, $userId);

        $remaining = getSearchesRemaining($db, $userId);
        jsonResponse([
            'results'            => $results['filtered'],
            'total_found'        => count($results['all']),
            'no_website_count'   => $results['no_website_count'],
            'filtered'           => $noWebsiteOnly,
            'from_cache'         => true,
            'cache_age_hours'    => $results['cache_age_hours'] ?? null,
            'searches_remaining' => $remaining['remaining'],
            'month_used'         => $remaining['month_used'],
            'month_limit'        => $remaining['month_limit'],
            'plan'               => $remaining['plan'],
        ]);
    }

    // No cache available for this query
    $db->prepare("INSERT INTO search_log (user_id, query_text, city, results_count, no_website_count, from_cache) VALUES (?, ?, ?, 0, 0, 1)")
       ->execute([$userId, $niche . ' ' . ($area ? $area . ' ' : '') . $city, $city]);
    incrementSearch($db, $userId);

    $remaining = getSearchesRemaining($db, $userId);
    jsonResponse([
        'results'            => [],
        'total_found'        => 0,
        'no_website_count'   => 0,
        'from_cache'         => true,
        'no_cache'           => true,
        'message'            => 'No cached data for this search yet. Upgrade to Pro for live results.',
        'searches_remaining' => $remaining['remaining'],
        'plan'               => $remaining['plan'],
    ]);
}


// ─── PRO+ USERS: Check cache first, then live API ───
$cached = getCachedResults($db, $cacheKey, CACHE_TTL_DAYS);
if ($cached) {
    $results = processResults($cached, $noWebsiteOnly);

    $db->prepare("INSERT INTO search_log (user_id, query_text, city, results_count, no_website_count, from_cache) VALUES (?, ?, ?, ?, ?, 1)")
       ->execute([$userId, $niche . ' ' . ($area ? $area . ' ' : '') . $city, $city, count($results['all']), $results['no_website_count']]);
    incrementSearch($db, $userId, $searchCheck['using_bonus']);

    $remaining = getSearchesRemaining($db, $userId);
    jsonResponse([
        'results'            => $results['filtered'],
        'total_found'        => count($results['all']),
        'no_website_count'   => $results['no_website_count'],
        'filtered'           => $noWebsiteOnly,
        'from_cache'         => true,
        'searches_remaining' => $remaining['remaining'],
        'month_used'         => $remaining['month_used'],
        'month_limit'        => $remaining['month_limit'],
        'bonus_remaining'    => $remaining['bonus_remaining'],
        'plan'               => $remaining['plan'],
    ]);
}

// ─── No cache → Live API call ───
$apiKey = getPlacesKey($db, $userId);
if (!$apiKey) jsonResponse(['error' => 'No API key configured', 'results' => []]);

$searchQuery = $niche . ' ' . ($area ? $area . ' ' : '') . $city;

$url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query([
    'query' => $searchQuery, 'key' => $apiKey, 'language' => 'en',
]);

$res = safeCurl($url);
if ($res['error']) jsonResponse(['error' => 'Google API connection failed: ' . $res['error']], 502);

$data = json_decode($res['body'], true);
if (!$data) jsonResponse(['error' => 'Invalid response from Google API'], 502);

$status = $data['status'] ?? 'UNKNOWN';

if ($status === 'REQUEST_DENIED') {
    jsonResponse(['error' => 'Google Places API access denied. Enable billing in Google Cloud Console or check your API key.', 'detail' => $data['error_message'] ?? ''], 502);
}

if ($status === 'ZERO_RESULTS') {
    $db->prepare("INSERT INTO search_log (user_id, query_text, city, results_count, no_website_count, from_cache) VALUES (?, ?, ?, 0, 0, 0)")
       ->execute([$userId, $searchQuery, $city]);
    incrementSearch($db, $userId, $searchCheck['using_bonus']);
    jsonResponse(['results' => [], 'total_found' => 0, 'no_website_count' => 0]);
}

if ($status !== 'OK') jsonResponse(['error' => 'Places API error: ' . $status], 502);

$places = $data['results'] ?? [];
$allResults = [];

foreach ($places as $place) {
    $placeId = $place['place_id'] ?? '';
    $photoCount = count($place['photos'] ?? []);

    // Basic data from Text Search (FREE — no extra API call)
    $result = [
        'name'               => $place['name'] ?? '',
        'address'            => $place['formatted_address'] ?? '',
        'place_id'           => $placeId,
        'rating'             => $place['rating'] ?? null,
        'user_ratings_total' => $place['user_ratings_total'] ?? 0,
        'photo_count'        => $photoCount,
        'types'              => $place['types'] ?? [],
        'lat'                => $place['geometry']['location']['lat'] ?? null,
        'lng'                => $place['geometry']['location']['lng'] ?? null,
        // These will be filled by lazy detail loading (not here!)
        'has_website'        => null,
        'website_url'        => '',
        'phone'              => '',
        'open_now'           => null,
        'detail_loaded'      => false,
    ];

    // Calculate Active Score from available data
    $result['active_score'] = calculateActiveScore($result);
    $result['active_label'] = getActiveLabel($result['active_score']);

    $allResults[] = $result;
}

// Sort by active score (highest first)
usort($allResults, fn($a, $b) => $b['active_score'] <=> $a['active_score']);

// Cache all results for future requests
setCachedResults($db, $cacheKey, $niche, $city, $area, $allResults);

// Log + increment
$db->prepare("INSERT INTO search_log (user_id, query_text, city, results_count, no_website_count, from_cache) VALUES (?, ?, ?, ?, 0, 0)")
   ->execute([$userId, $searchQuery, $city, count($allResults)]);
incrementSearch($db, $userId, $searchCheck['using_bonus']);

$db->prepare("INSERT INTO activity_log (user_id, lead_id, action_type, description) VALUES (?, NULL, 'search_performed', ?)")
   ->execute([$userId, "Searched: $searchQuery (" . count($allResults) . " found)"]);

$remaining = getSearchesRemaining($db, $userId);

jsonResponse([
    'results'            => $allResults,
    'total_found'        => count($allResults),
    'no_website_count'   => 0, // Not known yet — details loaded lazily
    'filtered'           => false,
    'from_cache'         => false,
    'searches_remaining' => $remaining['remaining'],
    'month_used'         => $remaining['month_used'],
    'month_limit'        => $remaining['month_limit'],
    'bonus_remaining'    => $remaining['bonus_remaining'],
    'plan'               => $remaining['plan'],
]);


// ═══════════════════════════════════════════════
// HELPER: Process cached results
// ═══════════════════════════════════════════════

function processResults(array $results, bool $noWebsiteOnly): array {
    $noWebsiteCount = 0;
    $filtered = [];

    foreach ($results as &$r) {
        // Recalculate active score (in case formula changed)
        $r['active_score'] = calculateActiveScore($r);
        $r['active_label'] = getActiveLabel($r['active_score']);

        if (isset($r['has_website']) && $r['has_website'] === false) {
            $noWebsiteCount++;
        }

        if ($noWebsiteOnly && isset($r['has_website']) && $r['has_website'] === true) {
            continue;
        }

        $filtered[] = $r;
    }

    // Sort by active score
    usort($filtered, fn($a, $b) => $b['active_score'] <=> $a['active_score']);

    return [
        'all'              => $results,
        'filtered'         => $filtered,
        'no_website_count' => $noWebsiteCount,
    ];
}