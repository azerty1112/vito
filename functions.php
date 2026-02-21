<?php
require_once 'config.php';

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

function slugify($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function e($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function getSiteBaseUrl() {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = ($https === 'on' || $https === '1' || $forwardedProto === 'https') ? 'https' : 'http';

    return $scheme . '://' . $host;
}

function getVisitorFingerprint() {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown-ip'));
    $agent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent'));
    return hash('sha256', $ip . '|' . $agent);
}

function recordPageVisit($pageKey, $pageLabel) {
    $pageKey = trim((string)$pageKey);
    $pageLabel = trim((string)$pageLabel);
    if ($pageKey === '' || $pageLabel === '') {
        return;
    }

    $pdo = db_connect();
    $visitorHash = getVisitorFingerprint();
    $now = time();

    $stmt = $pdo->prepare("INSERT INTO page_visits (page_key, page_label, visitor_hash, views, created_at, updated_at)
        VALUES (:page_key, :page_label, :visitor_hash, 1, :created_at, :updated_at)
        ON CONFLICT(page_key, visitor_hash) DO UPDATE SET
            views = views + 1,
            page_label = excluded.page_label,
            updated_at = excluded.updated_at");

    $stmt->execute([
        'page_key' => $pageKey,
        'page_label' => $pageLabel,
        'visitor_hash' => $visitorHash,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function getPageVisitStats($limit = 8) {
    $limit = max(1, min(30, (int)$limit));
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT
            page_key,
            MIN(page_label) AS page_label,
            SUM(views) AS total_views,
            COUNT(*) AS unique_visitors,
            SUM(CASE WHEN updated_at >= :last_24h THEN 1 ELSE 0 END) AS visitors_24h,
            MAX(updated_at) AS last_visit_at
        FROM page_visits
        GROUP BY page_key
        ORDER BY total_views DESC
        LIMIT :limit");
    $stmt->bindValue(':last_24h', time() - 86400, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchUrlBody($url, $timeoutSeconds = null) {
    $url = trim((string)$url);
    if ($url === '') {
        return null;
    }

    $cacheHit = getCachedUrlBody($url);
    if ($cacheHit !== null) {
        return $cacheHit;
    }

    $timeoutSeconds = $timeoutSeconds === null
        ? getSettingInt('fetch_timeout_seconds', 12, 3, 45)
        : max(1, (int)$timeoutSeconds);

    $retryAttempts = getSettingInt('fetch_retry_attempts', 3, 1, 5);
    $backoffMs = getSettingInt('fetch_retry_backoff_ms', 350, 100, 3000);

    for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'user_agent' => getFetcherUserAgent(),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $statusCode = extractHttpStatusCode($http_response_header ?? []);
        $ok = is_string($body) && $body !== '' && $statusCode >= 200 && $statusCode < 400;

        if ($ok) {
            cacheUrlBody($url, $body, $statusCode, true);
            return $body;
        }

        if ($attempt < $retryAttempts) {
            $jitterMs = random_int(0, 120);
            usleep(($backoffMs * $attempt + $jitterMs) * 1000);
        }
    }

    cacheUrlBody($url, is_string($body) ? $body : '', $statusCode, false);
    return null;
}

function getFetcherUserAgent() {
    $ua = trim((string)getSetting('fetch_user_agent', 'Mozilla/5.0 (compatible; VitoBot/1.0; +https://example.com/bot)'));
    return $ua !== '' ? $ua : 'Mozilla/5.0 (compatible; VitoBot/1.0; +https://example.com/bot)';
}

function extractHttpStatusCode(array $headers) {
    foreach ($headers as $headerLine) {
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', (string)$headerLine, $matches)) {
            return (int)$matches[1];
        }
    }
    return 0;
}

function getUrlCacheTtlSeconds() {
    return getSettingInt('url_cache_ttl_seconds', 900, 60, 86400);
}

function getCachedUrlBody($url) {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT body, fetched_at, ttl_seconds, blocked_until FROM url_cache WHERE url = ? LIMIT 1');
    $stmt->execute([$url]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $now = time();
    if ((int)$row['blocked_until'] > $now) {
        return null;
    }

    $ttl = max(60, (int)$row['ttl_seconds']);
    if (((int)$row['fetched_at'] + $ttl) < $now) {
        return null;
    }

    $body = (string)($row['body'] ?? '');
    return $body !== '' ? $body : null;
}

function cacheUrlBody($url, $body, $statusCode, $success) {
    $pdo = db_connect();
    $ttl = getUrlCacheTtlSeconds();
    $now = time();
    $statusCode = (int)$statusCode;

    $existingStmt = $pdo->prepare('SELECT fail_count FROM url_cache WHERE url = ? LIMIT 1');
    $existingStmt->execute([$url]);
    $existingFail = (int)$existingStmt->fetchColumn();

    $failCount = $success ? 0 : ($existingFail + 1);
    $blockedUntil = 0;
    if (!$success && ($statusCode === 429 || $statusCode === 403 || $failCount >= 3)) {
        $blockedUntil = $now + min(1800, 60 * $failCount);
    }

    $stmt = $pdo->prepare("INSERT INTO url_cache (url, body, status_code, fetched_at, ttl_seconds, fail_count, blocked_until)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(url) DO UPDATE SET
            body = excluded.body,
            status_code = excluded.status_code,
            fetched_at = excluded.fetched_at,
            ttl_seconds = excluded.ttl_seconds,
            fail_count = excluded.fail_count,
            blocked_until = excluded.blocked_until");

    $stmt->execute([$url, (string)$body, $statusCode, $now, $ttl, $failCount, $blockedUntil]);
}

function extractFeedTitlesWithSymfonyCrawler($xmlString, $limit = 50) {
    if (!class_exists('Symfony\\Component\\DomCrawler\\Crawler')) {
        return [];
    }

    $limit = max(1, (int)$limit);
    $crawler = new Symfony\Component\DomCrawler\Crawler();

    try {
        $crawler->addXmlContent($xmlString, 'UTF-8');
    } catch (Throwable $e) {
        return [];
    }

    $selectors = [
        'channel > item > title',
        'feed > entry > title',
        'rdf\:RDF > item > title',
        'item > title',
        'entry > title',
    ];

    $titles = [];
    foreach ($selectors as $selector) {
        $nodes = $crawler->filter($selector);
        if ($nodes->count() === 0) {
            continue;
        }

        foreach ($nodes as $node) {
            $title = trim((string)$node->textContent);
            if ($title !== '') {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break 2;
            }
        }
    }

    return mergeAndDeduplicateTitles($titles);
}

function extractFeedTitlesWithSimpleXml($xmlString, $limit = 50) {
    $limit = max(1, (int)$limit);
    $xml = @simplexml_load_string($xmlString);
    if (!$xml) {
        return [];
    }

    $titles = [];

    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $title = trim((string)$item->title);
            if ($title !== '') {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break;
            }
        }
    }

    if (count($titles) < $limit && isset($xml->entry)) {
        foreach ($xml->entry as $entry) {
            $title = trim((string)$entry->title);
            if ($title !== '') {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break;
            }
        }
    }

    return mergeAndDeduplicateTitles($titles);
}

function extractFeedTitles($url, $limit = 50) {
    $xmlString = fetchUrlBody($url);
    if (!is_string($xmlString) || trim($xmlString) === '') {
        return [];
    }

    $titles = extractFeedTitlesWithSymfonyCrawler($xmlString, $limit);
    if ($titles) {
        return $titles;
    }

    return extractFeedTitlesWithSimpleXml($xmlString, $limit);
}


function extractTitlesFromNormalPageWithSymfonyCrawler($htmlString, $limit = 50) {
    if (!class_exists('Symfony\Component\DomCrawler\Crawler')) {
        return [];
    }

    $limit = max(1, (int)$limit);
    $crawler = new Symfony\Component\DomCrawler\Crawler();

    try {
        $crawler->addHtmlContent($htmlString, 'UTF-8');
    } catch (Throwable $e) {
        return [];
    }

    $selectors = [
        'article h1 a, article h2 a, article h3 a',
        'main h1 a, main h2 a, main h3 a',
        '.post-title a, .entry-title a',
        'h1 a, h2 a, h3 a',
    ];

    $titles = [];
    foreach ($selectors as $selector) {
        $nodes = $crawler->filter($selector);
        if ($nodes->count() === 0) {
            continue;
        }

        foreach ($nodes as $node) {
            $title = trim((string)$node->textContent);
            if ($title !== '' && mb_strlen($title) >= 5) {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break 2;
            }
        }
    }

    if (!$titles) {
        foreach ($crawler->filter('title') as $titleNode) {
            $title = trim((string)$titleNode->textContent);
            if ($title !== '' && mb_strlen($title) >= 5) {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break;
            }
        }
    }

    return mergeAndDeduplicateTitles($titles);
}

function extractTitlesFromNormalPage($url, $limit = 50) {
    $htmlString = fetchUrlBody($url);
    if (!is_string($htmlString) || trim($htmlString) === '') {
        return [];
    }

    return extractTitlesFromNormalPageWithSymfonyCrawler($htmlString, $limit);
}

function getSelectedContentWorkflow() {
    $allowed = ['rss', 'web'];
    $workflow = trim((string)getSetting('content_workflow', 'rss'));
    return in_array($workflow, $allowed, true) ? $workflow : 'rss';
}

function enqueueWorkflowSources($workflow, array $sources) {
    $workflow = $workflow === 'web' ? 'web' : 'rss';
    $pdo = db_connect();
    $now = time();

    $existsStmt = $pdo->prepare('SELECT id FROM scrape_queue WHERE workflow = ? AND source_url = ? AND status IN ("pending","processing") LIMIT 1');
    $insertStmt = $pdo->prepare('INSERT INTO scrape_queue (workflow, source_url, status, attempts, locked_until, available_at, created_at, updated_at) VALUES (?, ?, "pending", 0, 0, ?, ?, ?)');

    foreach ($sources as $sourceUrl) {
        $sourceUrl = trim((string)$sourceUrl);
        if ($sourceUrl === '') {
            continue;
        }

        $existsStmt->execute([$workflow, $sourceUrl]);
        if ($existsStmt->fetchColumn() !== false) {
            continue;
        }

        $insertStmt->execute([$workflow, $sourceUrl, $now, $now, $now]);
    }
}

function pullWorkflowQueueItems($workflow, $limit) {
    $workflow = $workflow === 'web' ? 'web' : 'rss';
    $limit = max(1, (int)$limit);
    $pdo = db_connect();
    $now = time();
    $sourceCooldown = getSettingInt('queue_source_cooldown_seconds', 180, 30, 7200);

    resetStaleQueueLocks($workflow);

    $sql = 'SELECT id, source_url
        FROM scrape_queue
        WHERE workflow = ?
          AND status = "pending"
          AND available_at <= ?
          AND locked_until <= ?
          AND source_url NOT IN (
              SELECT source_url
              FROM scrape_queue
              WHERE workflow = ?
                AND status IN ("done", "processing")
                AND updated_at >= ?
          )
        ORDER BY id ASC
        LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$workflow, $now, $now, $workflow, $now - $sourceCooldown]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lockUntil = $now + 120;
    $lockStmt = $pdo->prepare('UPDATE scrape_queue SET status = "processing", locked_until = ?, updated_at = ? WHERE id = ?');
    foreach ($items as $item) {
        $lockStmt->execute([$lockUntil, $now, (int)$item['id']]);
    }

    return $items;
}

function resetStaleQueueLocks($workflow) {
    $workflow = $workflow === 'web' ? 'web' : 'rss';
    $pdo = db_connect();
    $now = time();

    $stmt = $pdo->prepare('UPDATE scrape_queue
        SET status = "pending", locked_until = 0, updated_at = ?
        WHERE workflow = ? AND status = "processing" AND locked_until > 0 AND locked_until <= ?');
    $stmt->execute([$now, $workflow, $now]);
}

function markQueueItemDone($id) {
    $pdo = db_connect();
    $stmt = $pdo->prepare('UPDATE scrape_queue SET status = "done", locked_until = 0, updated_at = ? WHERE id = ?');
    $stmt->execute([time(), (int)$id]);
}

function markQueueItemForRetry($id) {
    $pdo = db_connect();
    $retryDelay = getSettingInt('queue_retry_delay_seconds', 60, 10, 3600);
    $maxAttempts = getSettingInt('queue_max_attempts', 3, 1, 10);
    $now = time();

    $stmt = $pdo->prepare('SELECT attempts FROM scrape_queue WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$id]);
    $attempts = (int)$stmt->fetchColumn() + 1;

    if ($attempts >= $maxAttempts) {
        $failStmt = $pdo->prepare('UPDATE scrape_queue SET status = "failed", attempts = ?, locked_until = 0, updated_at = ? WHERE id = ?');
        $failStmt->execute([$attempts, $now, (int)$id]);
        return;
    }

    $retryAt = $now + ($retryDelay * $attempts);
    $retryStmt = $pdo->prepare('UPDATE scrape_queue SET status = "pending", attempts = ?, available_at = ?, locked_until = 0, updated_at = ? WHERE id = ?');
    $retryStmt->execute([$attempts, $retryAt, $now, (int)$id]);
}

function cleanAndNormalizeTitle($title) {
    $title = html_entity_decode((string)$title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = preg_replace('/\s+/u', ' ', $title);
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$title);
    $title = trim((string)$title);
    if (mb_strlen($title) < 5) {
        return '';
    }
    return $title;
}

function mergeAndDeduplicateTitles(array ...$collections) {
    $merged = [];
    foreach ($collections as $titles) {
        foreach ($titles as $title) {
            $clean = cleanAndNormalizeTitle($title);
            if ($clean === '') {
                continue;
            }
            $key = normalizeTextForComparison($clean);
            if (!isset($merged[$key])) {
                $merged[$key] = $clean;
            }
        }
    }
    return array_values($merged);
}

function runRssWorkflow($limit = null) {
    $pdo = db_connect();
    $sources = $pdo->query("SELECT url FROM rss_sources ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    $limit = $limit === null ? max(1, (int)getSetting('daily_limit', 5)) : max(1, (int)$limit);
    $batchSize = getSettingInt('workflow_batch_size', 8, 1, 30);

    enqueueWorkflowSources('rss', $sources);
    $queueItems = pullWorkflowQueueItems('rss', $batchSize);

    $count = 0;
    $sourceStats = [];

    foreach ($queueItems as $queueItem) {
        $url = (string)$queueItem['source_url'];
        if ($count >= $limit) {
            break;
        }

        $titles = extractFeedTitles($url, max(10, $limit * 3));
        if (!$titles) {
            markQueueItemForRetry((int)$queueItem['id']);
            continue;
        }

        $publishedFromSource = 0;

        foreach ($titles as $title) {
            if ($count >= $limit) {
                break;
            }

            if ($title !== '' && !articleExists($title)) {
                $data = generateArticle($title);
                if (saveArticle($title, $data)) {
                    $count++;
                    $publishedFromSource++;
                }
            }
        }

        $sourceStats[] = [
            'url' => $url,
            'fetched_titles' => count($titles),
            'published' => $publishedFromSource,
        ];

        markQueueItemDone((int)$queueItem['id']);
    }

    return [
        'workflow' => 'rss',
        'sources_count' => count($sources),
        'queue_batch' => count($queueItems),
        'published' => $count,
        'stats' => $sourceStats,
    ];
}

function runWebWorkflow($limit = null) {
    $pdo = db_connect();
    $sources = $pdo->query("SELECT url FROM web_sources ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    $limit = $limit === null ? max(1, (int)getSetting('daily_limit', 5)) : max(1, (int)$limit);
    $batchSize = getSettingInt('workflow_batch_size', 8, 1, 30);

    enqueueWorkflowSources('web', $sources);
    $queueItems = pullWorkflowQueueItems('web', $batchSize);

    $count = 0;
    $sourceStats = [];

    foreach ($queueItems as $queueItem) {
        $url = (string)$queueItem['source_url'];
        if ($count >= $limit) {
            break;
        }

        $titles = extractTitlesFromNormalPage($url, max(10, $limit * 3));
        if (!$titles) {
            markQueueItemForRetry((int)$queueItem['id']);
            continue;
        }

        $publishedFromSource = 0;

        foreach ($titles as $title) {
            if ($count >= $limit) {
                break;
            }

            if ($title !== '' && !articleExists($title)) {
                $data = generateArticle($title);
                if (saveArticle($title, $data)) {
                    $count++;
                    $publishedFromSource++;
                }
            }
        }

        $sourceStats[] = [
            'url' => $url,
            'fetched_titles' => count($titles),
            'published' => $publishedFromSource,
        ];

        markQueueItemDone((int)$queueItem['id']);
    }

    return [
        'workflow' => 'web',
        'sources_count' => count($sources),
        'queue_batch' => count($queueItems),
        'published' => $count,
        'stats' => $sourceStats,
    ];
}

function runSelectedContentWorkflow($limit = null) {
    $selected = getSelectedContentWorkflow();
    if ($selected === 'web') {
        return runWebWorkflow($limit);
    }

    return runRssWorkflow($limit);
}

function getContentWorkflowSummary() {
    $pdo = db_connect();
    $selected = getSelectedContentWorkflow();
    $rssSources = (int)$pdo->query("SELECT COUNT(*) FROM rss_sources")->fetchColumn();
    $webSources = (int)$pdo->query("SELECT COUNT(*) FROM web_sources")->fetchColumn();
    $dailyLimit = getSettingInt('daily_limit', 5, 1, 200);

    $selectedSources = $selected === 'web' ? $webSources : $rssSources;
    $scheduler = getAutoPublishSchedulerMeta();

    $health = 'ready';
    if ($selectedSources <= 0) {
        $health = 'missing_sources';
    } elseif (getSettingInt('auto_ai_enabled', 1, 0, 1) === 0) {
        $health = 'scheduler_disabled';
    }

    return [
        'selected_workflow' => $selected,
        'selected_workflow_label' => $selected === 'web' ? 'Normal Sites Workflow' : 'RSS Workflow',
        'rss_sources' => $rssSources,
        'web_sources' => $webSources,
        'selected_sources' => $selectedSources,
        'daily_limit' => $dailyLimit,
        'auto_ai_enabled' => getSettingInt('auto_ai_enabled', 1, 0, 1) === 1,
        'scheduler' => $scheduler,
        'health' => $health,
    ];
}


function normalizeDateInput($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}


function getSettingInt($key, $default = 0, $min = null, $max = null) {
    $value = (int)getSetting($key, (string)$default);
    if ($min !== null) {
        $value = max((int)$min, $value);
    }
    if ($max !== null) {
        $value = min((int)$max, $value);
    }
    return $value;
}


function getAutoPublishIntervalSeconds() {
    $secondsRaw = getSetting('auto_publish_interval_seconds', null);
    if ($secondsRaw !== null && $secondsRaw !== '') {
        return getSettingInt('auto_publish_interval_seconds', 10800, 10, 86400);
    }

    $minutesRaw = getSetting('auto_publish_interval_minutes', null);
    if ($minutesRaw !== null && $minutesRaw !== '') {
        $minutes = getSettingInt('auto_publish_interval_minutes', 180, 1, 1440);
        return max(10, min(86400, $minutes * 60));
    }

    return 10800;
}


function getCurrentBaseUrl() {
    $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $isHttps = $forwardedProto !== ''
        ? strtolower($forwardedProto) === 'https'
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443));

    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $scriptName = trim((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = str_replace('\\', '/', dirname($scriptName));
    if ($basePath === '.' || $basePath === '/') {
        $basePath = '';
    }
    $basePath = rtrim($basePath, '/');

    return $scheme . '://' . $host . $basePath;
}

function getCronEndpointUrl() {
    return getCurrentBaseUrl() . '/cron.php';
}

function getAutoPublishSchedulerMeta() {
    $lastRun = strtotime((string)getSetting('auto_publish_last_run_at', '1970-01-01 00:00:00')) ?: 0;
    $interval = getAutoPublishIntervalSeconds();
    $nextRun = $lastRun + $interval;
    $remaining = max(0, $nextRun - time());

    return [
        'interval_seconds' => $interval,
        'last_run_at' => date('Y-m-d H:i:s', $lastRun),
        'next_run_at' => date('Y-m-d H:i:s', $nextRun),
        'remaining_seconds' => $remaining,
    ];
}

function classifyVehicleProfile($title) {
    $titleLower = mb_strtolower($title, 'UTF-8');
    $map = [
        'SUV' => ['suv', 'crossover'],
        'Sedan' => ['sedan', 'saloon'],
        'Coupe' => ['coupe'],
        'Hatchback' => ['hatch', 'hatchback'],
        'Truck' => ['pickup', 'truck'],
    ];

    foreach ($map as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($titleLower, $keyword) !== false) {
                return $label;
            }
        }
    }

    return 'Vehicle';
}

function buildBuyerPersonaSection($title, $isEV, $bodyType) {
    $useCase = $isEV ? 'daily charging rhythm, home charging access, and route planning confidence' : 'annual mileage, fuel cost sensitivity, and service-network convenience';
    $personaA = "<li><strong>Urban Professional:</strong> Best if your priority is refinement, technology usability, and stress-free commuting in mixed traffic.</li>";
    $personaB = "<li><strong>Family-Oriented Driver:</strong> Strong candidate when cabin practicality, comfort, and predictable ownership costs matter most.</li>";
    $personaC = "<li><strong>Enthusiast Pragmatist:</strong> Suitable for buyers who want engaging performance without sacrificing real-world comfort and reliability.</li>";

    return "<h2>Who Should Buy This {$bodyType}?</h2>
"
        . "<p>Before committing to the {$title}, align your decision with real usage patterns: {$useCase}. The strongest purchase decisions come from fit, not hype.</p>
"
        . "<ul>{$personaA}{$personaB}{$personaC}</ul>
";
}

function buildFaqSection($title, $isEV) {
    $runningCost = $isEV
        ? 'In many markets, charging remains cheaper per kilometer than fuel, especially with home charging and off-peak tariffs.'
        : 'Running costs depend on driving style and service intervals, but predictable maintenance plans can stabilize yearly expenses.';

    $faq = [
        ['Is the ' . $title . ' good for daily use?', 'Yes. Its strongest argument is consistency in comfort, usability, and technology behavior across routine driving scenarios.'],
        ['How does it compare with rivals?', 'It competes best when buyers value balanced engineering and ownership confidence rather than headline numbers alone.'],
        ['What about long-term cost?', $runningCost],
    ];

    $html = "<h2>Frequently Asked Questions</h2>
";
    foreach ($faq as [$q, $a]) {
        $html .= "<h3>{$q}</h3>
<p>{$a}</p>
";
    }

    return $html;
}


function getAutoTitleDefaultSettings() {
    return [
        'auto_title_mode' => 'template',
        'auto_title_min_year_offset' => '0',
        'auto_title_max_year_offset' => '1',
        'auto_title_brands' => "Toyota
BMW
Mercedes
Audi
Porsche
Tesla
Hyundai
Kia
Ford
Nissan
Volvo
Lexus",
        'auto_title_models' => "SUV
Sedan
Coupe
EV Crossover
Hybrid SUV
Performance Hatchback
Electric Sedan
Luxury Wagon
Premium Crossover",
        'auto_title_modifiers' => "Review
Specs
Price
Comparison
Buying Guide
Ownership Cost",
        'auto_title_audiences' => "Smart Buyers
First-Time Premium Buyers
Tech-Focused Drivers
Family Buyers",
        'auto_title_angles' => "Full Review and Buyer Guide
Long-Term Ownership Analysis
Real-World Efficiency Test
Daily Driving Impression
Smart Technology Deep Dive
Comparison and Value Breakdown
Reliability, Resale, and Total Cost Breakdown",
        'auto_title_templates' => "{year} {brand} {model} {modifier}: {angle} for {audience}
{year} {brand} {model} {modifier} — {angle} ({audience})
{year} {brand} {model}: {modifier} + {angle}",
        'auto_title_fixed_titles' => '',
    ];
}

function getAutoTitleSetting($key) {
    $defaults = getAutoTitleDefaultSettings();
    $fallback = $defaults[$key] ?? '';
    return (string)getSetting($key, $fallback);
}

function parseSettingList($key, $fallback) {
    $raw = (string)getSetting($key, $fallback);
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $clean = [];

    foreach ($lines as $line) {
        $value = trim((string)$line);
        if ($value !== '') {
            $clean[] = $value;
        }
    }

    return $clean;
}

function pickRandomFromList(array $items, $fallback = '') {
    if (!$items) {
        return (string)$fallback;
    }

    return (string)$items[array_rand($items)];
}

function generateAutoTitle() {
    $mode = trim(getAutoTitleSetting('auto_title_mode'));

    if ($mode === 'list') {
        $fixedTitles = parseSettingList('auto_title_fixed_titles', '');
        if ($fixedTitles) {
            return pickRandomFromList($fixedTitles);
        }
        $mode = 'template';
    }

    $minOffset = getSettingInt('auto_title_min_year_offset', 0, -1, 2);
    $maxOffset = getSettingInt('auto_title_max_year_offset', 1, -1, 3);
    if ($maxOffset < $minOffset) {
        [$minOffset, $maxOffset] = [$maxOffset, $minOffset];
    }

    $year = (int)date('Y') + rand($minOffset, $maxOffset);
    $brand = pickRandomFromList(parseSettingList('auto_title_brands', getAutoTitleSetting('auto_title_brands')), 'Toyota');
    $model = pickRandomFromList(parseSettingList('auto_title_models', getAutoTitleSetting('auto_title_models')), 'SUV');
    $modifier = pickRandomFromList(parseSettingList('auto_title_modifiers', getAutoTitleSetting('auto_title_modifiers')), 'Review');
    $audience = pickRandomFromList(parseSettingList('auto_title_audiences', getAutoTitleSetting('auto_title_audiences')), 'Smart Buyers');
    $angle = pickRandomFromList(parseSettingList('auto_title_angles', getAutoTitleSetting('auto_title_angles')), 'Full Review and Buyer Guide');

    $templates = parseSettingList('auto_title_templates', getAutoTitleSetting('auto_title_templates'));
    if (!$templates) {
        $templates = ['{year} {brand} {model} {modifier}: {angle} for {audience}'];
    }

    $template = pickRandomFromList($templates, '{year} {brand} {model} {modifier}: {angle} for {audience}');
    $replacements = [
        '{year}' => (string)$year,
        '{brand}' => $brand,
        '{model}' => $model,
        '{modifier}' => $modifier,
        '{audience}' => $audience,
        '{angle}' => $angle,
    ];

    return trim(strtr($template, $replacements));
}

function generateUniqueAutoTitle($maxAttempts = 12) {
    $attempts = max(1, (int)$maxAttempts);
    for ($i = 0; $i < $attempts; $i++) {
        $title = generateAutoTitle();
        if (!articleExists($title)) {
            return $title;
        }
    }

    return null;
}

function publishAutoArticleBySchedule($force = false) {
    $enabled = getSettingInt('auto_ai_enabled', 1, 0, 1);
    if (!$force && $enabled !== 1) {
        return ['published' => 0, 'reason' => 'disabled'];
    }

    $intervalSeconds = getAutoPublishIntervalSeconds();
    $lastRun = strtotime((string)getSetting('auto_publish_last_run_at', '1970-01-01 00:00:00')) ?: 0;
    $now = time();

    if (!$force && ($now - $lastRun) < $intervalSeconds) {
        return ['published' => 0, 'reason' => 'not_due'];
    }

    $title = generateUniqueAutoTitle();
    if ($title === null) {
        return ['published' => 0, 'reason' => 'title_generation_failed'];
    }

    $data = generateArticle($title);
    if (!saveArticle($title, $data)) {
        return ['published' => 0, 'reason' => 'duplicate_title_or_content'];
    }
    setSetting('auto_publish_last_run_at', date('Y-m-d H:i:s', $now));

    return ['published' => 1, 'reason' => 'ok', 'title' => $title];
}

function getSetting($key, $default = null) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

function setSetting($key, $value) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    executeStatementWithRetry($stmt, [$key, (string)$value]);
}

function articleExists($title) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT id FROM articles WHERE title = ?");
    $stmt->execute([$title]);
    return $stmt->fetch() !== false;
}

function normalizeTextForComparison($text) {
    $text = mb_strtolower((string)$text, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

function normalizeTitleFingerprint($title) {
    $title = cleanAndNormalizeTitle($title);
    if ($title === '') {
        return '';
    }

    $title = mb_strtolower($title, 'UTF-8');
    $title = preg_replace('/\b(19|20)\d{2}\b/u', ' ', (string)$title);
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$title);

    $tokens = preg_split('/\s+/u', trim((string)$title));
    if (!is_array($tokens)) {
        return '';
    }

    $stopWords = [
        'the', 'a', 'an', 'and', 'or', 'for', 'with', 'from', 'to', 'of',
        'review', 'guide', 'buying', 'best', 'top', 'new', 'vs'
    ];

    $filtered = [];
    foreach ($tokens as $token) {
        if ($token === '' || in_array($token, $stopWords, true)) {
            continue;
        }
        $filtered[] = $token;
    }

    return implode(' ', $filtered);
}

function articleTitleVariantExists($title) {
    $pdo = db_connect();
    $baseSlug = slugify($title);
    if ($baseSlug === '') {
        return false;
    }

    $stmt = $pdo->prepare("SELECT 1 FROM articles WHERE slug = ? OR slug LIKE ? LIMIT 1");
    $stmt->execute([$baseSlug, $baseSlug . '-%']);
    return $stmt->fetchColumn() !== false;
}

function articleTitleFingerprintExists($title) {
    $fingerprint = normalizeTitleFingerprint($title);
    if ($fingerprint === '') {
        return false;
    }

    $pdo = db_connect();
    $rows = $pdo->query("SELECT title FROM articles ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $storedTitle) {
        $storedFingerprint = normalizeTitleFingerprint((string)$storedTitle);
        if ($storedFingerprint !== '' && hash_equals($storedFingerprint, $fingerprint)) {
            return true;
        }
    }

    return false;
}

function articleContentExists($content) {
    $normalizedContent = normalizeTextForComparison(strip_tags((string)$content));
    if ($normalizedContent === '') {
        return false;
    }

    $pdo = db_connect();
    $stmt = $pdo->query("SELECT content FROM articles ORDER BY id DESC LIMIT 200");
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rows as $storedContent) {
        $storedNormalized = normalizeTextForComparison(strip_tags((string)$storedContent));
        if ($storedNormalized !== '' && hash_equals($storedNormalized, $normalizedContent)) {
            return true;
        }
    }

    return false;
}

function isDuplicateArticlePayload($title, $content) {
    return articleExists($title)
        || articleTitleVariantExists($title)
        || articleTitleFingerprintExists($title)
        || articleContentExists($content);
}

function generateUniqueSlug($title) {
    $pdo = db_connect();
    $base = slugify($title);
    $base = $base !== '' ? $base : 'article';
    $slug = $base;
    $i = 2;

    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM articles WHERE slug = ?");
        $stmt->execute([$slug]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

function buildFreeArticleImageUrl($seed) {
    $seedText = trim((string)$seed);
    if ($seedText === '') {
        $seedText = 'car-article';
    }

    $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($seedText));
    $slug = trim((string)$slug, '-');
    if ($slug === '') {
        $slug = 'car-article';
    }

    return "https://picsum.photos/seed/{$slug}/1200/675";
}

function buildUniqueArticleImageUrl($title, $model = '') {
    $pdo = db_connect();
    $baseSeed = trim($title . '-' . $model);

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $seed = $attempt === 1 ? $baseSeed : $baseSeed . '-' . $attempt;
        $candidate = buildFreeArticleImageUrl($seed);
        $stmt = $pdo->prepare("SELECT 1 FROM articles WHERE image = ? LIMIT 1");
        $stmt->execute([$candidate]);
        if ($stmt->fetchColumn() === false) {
            return $candidate;
        }
    }

    return buildFreeArticleImageUrl($baseSeed . '-' . uniqid('', true));
}

function verifyAdminPassword($password) {
    if (!is_string($password) || $password === '') {
        return false;
    }

    $adminPassword = getenv('ADMIN_PASSWORD');
    if (is_string($adminPassword) && $adminPassword !== '' && hash_equals($adminPassword, $password)) {
        return true;
    }

    return password_verify($password, PASSWORD_HASH);
}

function getRandomIntro($title) {
    $intros = [
        "The $title arrives at a time when buyers expect more than raw performance—they expect intelligence, consistency, and real ownership value.",
        "The $title reflects a modern automotive philosophy where design, software, efficiency, and durability must all work together.",
        "With the $title, the brand is clearly targeting drivers who care about emotional appeal and practical decision-making in equal measure.",
        "The $title enters a competitive segment, and its real strength is how it balances premium character with day-to-day usability."
    ];

    return $intros[array_rand($intros)];
}

function pickRandomFrom(array $items) {
    return $items[array_rand($items)];
}

function buildAnalyticalParagraph($title, $sectionTitle, $focus, $isEV, $perspective) {
    $energyContext = $isEV
        ? 'its electric architecture, battery management logic, and charging ecosystem'
        : 'its engine calibration, transmission strategy, and thermal durability';

    $openingBank = [
        "In the context of {$sectionTitle}, the {$title} deserves attention for {$focus}.",
        "Looking at {$sectionTitle} through a practical lens, {$focus} becomes one of the most relevant points for {$title}.",
        "When analysts evaluate {$sectionTitle}, they usually start with {$focus}, and the {$title} performs in a convincing way."
    ];

    $analysisBank = [
        "The most credible part of this story is not a single headline figure, but the consistency of behavior across daily scenarios like traffic, highway cruising, and weekend travel.",
        "What separates mature products from average ones is repeatability, and here the vehicle keeps a stable character even when road quality, weather, and load conditions change.",
        "Instead of over-optimizing for lab-style results, the package appears tuned for real-world confidence where comfort, control, and predictability matter every day."
    ];

    $perspectiveBank = [
        "From an owner perspective, this means fewer compromises between comfort and capability, and a lower chance of buyer regret after the first months of excitement.",
        "For mixed-use drivers, this creates a meaningful advantage: the car feels refined in city conditions yet remains composed when pushed on open roads.",
        "From a long-term standpoint, this balance supports stronger perceived quality because the driving experience remains coherent rather than fragmented."
    ];

    $closingBank = [
        "That broader coherence is reinforced by {$energyContext}, which helps the {$title} translate engineering choices into tangible daily benefits.",
        "The result is a clearer value proposition: the {$title} is not merely impressive on paper, it is understandable and rewarding in normal ownership use.",
        "Ultimately, this is where product intelligence appears—different systems collaborate naturally instead of competing for attention."
    ];

    $paragraph = pickRandomFrom($openingBank) . ' '
        . pickRandomFrom($analysisBank) . ' '
        . pickRandomFrom($perspectiveBank) . ' '
        . pickRandomFrom($closingBank);

    if ($perspective !== '') {
        $paragraph .= ' ' . $perspective;
    }

    return "<p>{$paragraph}</p>";
}

function buildSectionContent($title, $sectionTitle, array $focusPoints, $isEV) {
    $perspectives = [
        'This is especially important in segments where buyers compare six or seven alternatives before committing.',
        'In competitive markets, small gains in usability often influence purchase decisions more than aggressive marketing claims.',
        'For families and frequent commuters, these details can be more valuable than short-term novelty features.'
    ];

    $paragraphs = [];
    foreach ($focusPoints as $index => $focus) {
        $paragraphs[] = buildAnalyticalParagraph(
            $title,
            $sectionTitle,
            $focus,
            $isEV,
            $perspectives[$index % count($perspectives)]
        );
    }

    return $paragraphs;
}

function buildComparisonTable($title, $bodyType, $isEV) {
    $rivalMap = [
        'SUV' => ['Toyota RAV4 Hybrid', 'Honda CR-V Hybrid'],
        'Sedan' => ['Toyota Camry', 'Honda Accord'],
        'Truck' => ['Ford Ranger', 'Toyota Hilux'],
        'Coupe' => ['BMW 4 Series', 'Audi A5'],
        'Hatchback' => ['Volkswagen Golf', 'Mazda 3'],
    ];

    $rivals = $rivalMap[$bodyType] ?? ['Segment Benchmark Model', 'Value-Focused Alternative'];
    $efficiencyMetric = $isEV ? 'Range (km est.)' : 'Fuel Economy (L/100km)';
    $efficiencyValue = $isEV ? (string)rand(460, 690) : (string)rand(6, 10);

    return "<h2>Competitor Comparison at a Glance</h2>
"
        . "<p>Buyers searching for {$title} alternatives usually compare real ownership outcomes, not only launch marketing numbers. This table highlights how {$title} stacks up against common choices in the same {$bodyType} category.</p>
"
        . "<div class='table-responsive'><table class='table table-striped table-bordered'><thead><tr><th>Model</th><th>Positioning</th><th>{$efficiencyMetric}</th><th>Best For</th></tr></thead><tbody>"
        . "<tr><td><strong>{$title}</strong></td><td>Balanced performance + daily practicality</td><td>{$efficiencyValue}</td><td>Buyers wanting long-term ownership confidence</td></tr>"
        . "<tr><td>{$rivals[0]}</td><td>Mainstream benchmark setup</td><td>Competitive</td><td>Drivers prioritizing proven familiarity</td></tr>"
        . "<tr><td>{$rivals[1]}</td><td>Value-first package</td><td>Strong on paper</td><td>Cost-sensitive buyers with simpler needs</td></tr>"
        . "</tbody></table></div>
";
}

function buildBuyingChecklistSection($title, $isEV) {
    $specificPoint = $isEV
        ? 'Verify home charging readiness, local fast-charging coverage, and expected charging curve behavior in your climate.'
        : 'Check expected fuel economy in your driving pattern and compare maintenance package coverage by dealership.';

    return "<h2>Pre-Purchase Checklist</h2>
"
        . "<p>Before finalizing {$title}, use this shortlist to reduce buyer regret and improve long-term value:</p>
"
        . "<ol>"
        . "<li>Compare trims by safety and comfort features, not badge labels alone.</li>"
        . "<li>{$specificPoint}</li>"
        . "<li>Request a real-world test route that includes city traffic, rough roads, and highway speeds.</li>"
        . "<li>Confirm warranty details, service intervals, and total ownership cost projections for 3–5 years.</li>"
        . "</ol>
";
}

function buildPeopleAlsoAskSection($title, $isEV) {
    $chargingQuestion = $isEV
        ? "<li><strong>How fast can {$title} charge in daily use?</strong> Charging speed depends on charger type and battery state, but owners should prioritize charging curve stability and local infrastructure quality over peak brochure numbers.</li>"
        : "<li><strong>Is {$title} fuel-efficient enough for daily commuting?</strong> Real-world efficiency is strongest when traffic, maintenance, and driving style are considered together instead of relying on ideal test cycles.</li>";

    return "<h2>People Also Ask</h2>
"
        . "<p>These are common search questions potential buyers ask before deciding:</p>
"
        . "<ul>"
        . "<li><strong>Is {$title} worth buying this year?</strong> It is a strong candidate for buyers who want a complete package with fewer compromises across comfort, tech, and ownership predictability.</li>"
        . "<li><strong>How does {$title} compare with main competitors?</strong> The model typically wins by offering more balanced day-to-day behavior, even if some rivals lead in one isolated metric.</li>"
        . $chargingQuestion
        . "<li><strong>What should I check before signing the purchase?</strong> Compare trim value, warranty clarity, service network quality, and total cost of ownership rather than focusing only on sticker price.</li>"
        . "</ul>
";
}

function buildFaqSchemaScript($title, $isEV) {
    $faqItems = [
        [
            'question' => "Is {$title} a good daily driver?",
            'answer' => "Yes. {$title} is tuned to balance comfort, performance, and practical ownership, making it a solid daily-use option for most buyers.",
        ],
        [
            'question' => "How does {$title} compare with competitors?",
            'answer' => "It usually stands out through better overall balance and ownership confidence rather than relying on a single headline metric.",
        ],
        [
            'question' => $isEV ? "Is {$title} practical for charging routines?" : "Is {$title} economical to run over time?",
            'answer' => $isEV
                ? "With suitable home or public charging access, it can be practical for daily routines while also reducing long-term running costs."
                : "When maintained well and matched with the right trim, it can deliver predictable ownership costs over the long term.",
        ],
    ];

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($item) {
            return [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ];
        }, $faqItems),
    ];

    return "<script type='application/ld+json'>" . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>";
}

function ensureMinimumWordCount($html, $title, $minimumWords = 2100) {
    $expansionLibrary = [
        "<p>Beyond specifications, the {$title} should be evaluated through lifecycle quality: software stability over updates, ease of service access, parts availability, and dealership competence. These factors rarely appear in launch headlines, but they shape ownership satisfaction in a decisive way. Buyers who prioritize this complete picture are usually the ones who feel most confident in their purchase years after delivery.</p>",
        "<p>A final strategic angle concerns resale narrative. Vehicles that keep a clear identity, avoid over-complicated interfaces, and maintain predictable reliability tend to preserve value more effectively. The {$title} appears aligned with that principle by emphasizing balance rather than gimmicks, which is often the smarter long-term formula in a rapidly changing automotive market.</p>",
        "<p>From a product-planning perspective, the {$title} also signals where the brand may be heading next: deeper integration between hardware and software, stronger efficiency discipline, and a clearer user-first philosophy. If this direction continues, future iterations could build on an already credible foundation while reducing the remaining trade-offs that naturally exist in every vehicle category.</p>"
    ];

    $currentWords = str_word_count(strip_tags($html));
    $i = 0;
    while ($currentWords < $minimumWords) {
        $html .= "\n" . $expansionLibrary[$i % count($expansionLibrary)];
        $currentWords = str_word_count(strip_tags($html));
        $i++;
    }

    return $html;
}

function buildSeoBlock($title, $excerpt) {
    $keywords = [
        $title,
        $title . ' review',
        $title . ' specs',
        $title . ' price',
        $title . ' reliability',
        $title . ' pros and cons',
        $title . ' vs competitors',
        'best car buying guide'
    ];

    $metaDescription = mb_substr(trim((string)$excerpt), 0, 155);
    $keywordHtml = '<ul><li>' . implode('</li><li>', array_map('e', $keywords)) . '</li></ul>';

    return [
        'meta_title' => $title . ' | SEO Review & Buyer Guide',
        'meta_description' => $metaDescription,
        'keywords' => $keywords,
        'html_block' => "<section class='seo-optimization'><h2>SEO Focus Keywords</h2>{$keywordHtml}<p><strong>Meta Description:</strong> " . e($metaDescription) . "</p></section>",
    ];
}

function writeArticleExportFiles($articleId, $slug, array $payload) {
    $exportsDir = __DIR__ . '/data/exports';
    if (!is_dir($exportsDir)) {
        mkdir($exportsDir, 0777, true);
    }

    $htmlPath = $exportsDir . '/' . $slug . '.html';
    $jsonPath = $exportsDir . '/' . $slug . '.json';

    file_put_contents($htmlPath, (string)($payload['content'] ?? ''));
    file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $pdo = db_connect();
    $stmt = $pdo->prepare("INSERT INTO article_exports (article_id, slug, html_path, json_path, created_at)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(article_id) DO UPDATE SET
            slug = excluded.slug,
            html_path = excluded.html_path,
            json_path = excluded.json_path,
            created_at = excluded.created_at");
    $stmt->execute([(int)$articleId, $slug, 'data/exports/' . $slug . '.html', 'data/exports/' . $slug . '.json', date('Y-m-d H:i:s')]);
}

function buildHeadingAnchorId($text, $fallback = 'section') {
    $base = strtolower(trim((string)$text));
    $base = preg_replace('/[^a-z0-9\s-]/', '', $base);
    $base = preg_replace('/\s+/', '-', (string)$base);
    $base = trim((string)$base, '-');
    if ($base === '') {
        $base = trim((string)$fallback);
    }
    return $base;
}

function buildArticleTableOfContents(array $sections) {
    if (!$sections) {
        return '';
    }

    $items = [];
    foreach ($sections as $section) {
        $title = trim((string)($section['title'] ?? ''));
        $id = trim((string)($section['id'] ?? ''));
        if ($title === '' || $id === '') {
            continue;
        }
        $items[] = "<li><a href='#" . e($id) . "'>" . e($title) . "</a></li>";
    }

    if (!$items) {
        return '';
    }

    return "<section class='article-toc'><h2>Quick Navigation</h2><ol>" . implode('', $items) . "</ol></section>";
}

function generateArticle($title) {
    $model = trim(preg_replace('/\b(202[0-9]|20[0-9]{2})\b/', '', $title));
    $isEV = stripos($title, 'EV') !== false || stripos($title, 'electric') !== false;
    $bodyType = classifyVehicleProfile($title);

    $content = "<h1>" . htmlspecialchars($title) . "</h1>\n";
    $content .= "<p class='text-muted'>Published " . date('F j, Y') . " • AutoCar Niche</p>\n";
    $coverImage = buildUniqueArticleImageUrl($title, $model);
    $content .= "<img src='" . htmlspecialchars($coverImage, ENT_QUOTES, 'UTF-8') . "' class='img-fluid rounded mb-4' alt='" . htmlspecialchars($title) . "'>\n";
    $content .= "<p>" . getRandomIntro($title) . " This review follows an editorial structure designed to deliver deep analysis, clear comparisons, and practical buying guidance.</p>\n";
    $content .= "<p>This {$title} review is optimized to answer the top buyer questions around performance, reliability, pricing logic, and long-term ownership value.</p>\n";
    $content .= "<p><strong>Quick Take:</strong> The {$title} is a {$bodyType}-class product focused on balanced performance, everyday usability, and ownership predictability rather than one-dimensional headline metrics.</p>\n";
    $content .= "<h2>What You Will Learn in This Guide</h2>\n";
    $content .= "<ul><li>How {$title} performs in real ownership conditions, not only in launch marketing.</li><li>Which trim strategy makes the most financial sense for different buyer types.</li><li>Where {$title} stands versus competitors in comfort, tech, efficiency, and long-term value.</li></ul>\n";

    $sections = [
        'Executive Summary and Market Position' => [
            'segment fit and target audience clarity',
            'how the model differentiates against direct rivals',
            'the real value story behind headline marketing claims'
        ],
        'Exterior Design, Proportion, and Visual Character' => [
            'surface treatment, stance, and brand identity execution',
            'aerodynamic decisions that influence both style and efficiency',
            'why design coherence affects owner satisfaction over time'
        ],
        'Cabin Quality, Space, and Human-Centered Ergonomics' => [
            'seat comfort, posture support, and long-distance usability',
            'dashboard hierarchy, physical controls, and interaction clarity',
            'perceived quality through materials, fit, and acoustic control'
        ],
        'Powertrain Intelligence, Performance Delivery, and Efficiency' => [
            'response quality under partial and full throttle situations',
            'efficiency behavior in urban, mixed, and highway duty cycles',
            'engineering trade-offs between excitement and sustainability'
        ],
        'Ride Comfort, Handling Balance, and Braking Confidence' => [
            'suspension tuning over varied road surfaces',
            'steering communication and directional stability at speed',
            'predictable braking behavior in repeated real-world use'
        ],
        'Technology Stack, Infotainment, and Connectivity Experience' => [
            'interface speed, readability, and cognitive simplicity',
            'smartphone integration and navigation reliability in practice',
            'software maturity, update path, and feature longevity'
        ],
        'Safety Systems, Driver Assistance, and Durability Outlook' => [
            'calibration quality of active safety interventions',
            'passive safety confidence and structural reassurance',
            'maintenance predictability and long-term reliability perception'
        ],
        'Ownership Economics, Trim Strategy, and Buyer Recommendations' => [
            'cost of ownership across fuel or charging, service, and insurance',
            'which configuration levels provide the strongest value density',
            'how to shortlist based on real priorities rather than hype'
        ]
    ];

    $tocSections = [];
    foreach ($sections as $sectionTitle => $focusPoints) {
        $sectionId = buildHeadingAnchorId($sectionTitle, 'section-' . (count($tocSections) + 1));
        $tocSections[] = ['title' => $sectionTitle, 'id' => $sectionId];
        $content .= "<h2 id='" . e($sectionId) . "'>{$sectionTitle}</h2>\n";
        foreach (buildSectionContent($title, $sectionTitle, $focusPoints, $isEV) as $paragraph) {
            $content .= $paragraph . "\n";
        }
    }

    $content = preg_replace('/(<h2>What You Will Learn in This Guide<\/h2>\n<ul>.*?<\/ul>\n)/s', "$1" . buildArticleTableOfContents($tocSections) . "\n", $content, 1);

    $horsepower = rand(260, 640);
    $zeroToSixty = number_format(rand(34, 67) / 10, 1);
    $efficiencyLine = $isEV
        ? rand(420, 680) . ' km estimated range (mixed use)'
        : rand(6, 11) . ' L/100km combined estimate';
    $drivetrain = $isEV ? 'Dual Electric Motors (AWD)' : '2.5L Turbo + Advanced Automatic Transmission';

    $content .= "<h2>Technical Snapshot</h2>\n";
    $content .= "<table class='table table-bordered'><tr><th>Powertrain</th><td>{$drivetrain}</td></tr><tr><th>Output</th><td>{$horsepower} hp</td></tr><tr><th>0-60 mph</th><td>{$zeroToSixty} seconds</td></tr><tr><th>Efficiency</th><td>{$efficiencyLine}</td></tr><tr><th>Editorial Category</th><td>Auto</td></tr></table>\n";

    $content .= buildComparisonTable($title, $bodyType, $isEV);
    $content .= buildBuyerPersonaSection($title, $isEV, $bodyType);

    $content .= "<h2>Strengths and Trade-Offs</h2>\n";
    $content .= "<ul><li><strong>Strengths:</strong> Cohesive engineering balance, strong day-to-day usability, mature technology integration, and a clear long-term value narrative.</li><li><strong>Trade-Offs:</strong> Higher entry price in premium trims, optional packages that may overlap in features, and availability pressure in high-demand regions.</li></ul>\n";

    $content .= buildFaqSection($title, $isEV);
    $content .= buildPeopleAlsoAskSection($title, $isEV);
    $content .= buildBuyingChecklistSection($title, $isEV);

    $content .= "<h2>Final Editorial Verdict</h2>\n";
    $content .= "<p class='mt-3'>The {$title} succeeds because it behaves like a complete product, not a collection of isolated features. It combines emotional appeal with practical intelligence, and that combination is exactly what modern buyers need in an uncertain, fast-evolving market. If your priority is a vehicle that remains convincing beyond launch-week excitement, this model is a serious and well-justified candidate.</p>";

    $minimumWords = getSettingInt('min_words', 3000, 1200, 5000);
    $content = ensureMinimumWordCount($content, $title, $minimumWords);

    $plainText = trim(strip_tags($content));
    $excerpt = mb_substr($plainText, 0, 340);
    if (mb_strlen($plainText) > 340) {
        $excerpt .= '...';
    }

    $seo = buildSeoBlock($title, $excerpt);
    $content .= "\n" . $seo['html_block'];
    $content .= "\n" . buildFaqSchemaScript($title, $isEV);

    return [
        'content' => $content,
        'excerpt' => $excerpt,
        'image' => $coverImage,
        'meta_title' => $seo['meta_title'],
        'meta_description' => $seo['meta_description'],
        'focus_keywords' => $seo['keywords'],
    ];
}

function isSqliteLockedException(PDOException $exception) {
    $message = mb_strtolower($exception->getMessage(), 'UTF-8');
    return strpos($message, 'database is locked') !== false || strpos($message, 'database table is locked') !== false;
}

function executeStatementWithRetry(PDOStatement $statement, array $params, $maxAttempts = 5, $initialDelayMs = 100) {
    $attempt = 0;
    $delayMs = max(1, (int)$initialDelayMs);
    $limit = max(1, (int)$maxAttempts);

    while (true) {
        try {
            $statement->execute($params);
            return;
        } catch (PDOException $exception) {
            $attempt++;
            if ($attempt >= $limit || !isSqliteLockedException($exception)) {
                throw $exception;
            }
            usleep($delayMs * 1000);
            $delayMs *= 2;
        }
    }
}

function saveArticle($title, $data) {
    if (isDuplicateArticlePayload($title, $data['content'] ?? '')) {
        return false;
    }

    $pdo = db_connect();
    $slug = generateUniqueSlug($title);
    $stmt = $pdo->prepare("INSERT INTO articles (title, slug, content, image, excerpt, published_at, category) VALUES (?,?,?,?,?,?,?)");
    executeStatementWithRetry($stmt, [$title, $slug, $data['content'], $data['image'], $data['excerpt'], date('Y-m-d H:i:s'), 'Auto']);
    $articleId = (int)$pdo->lastInsertId();
    writeArticleExportFiles($articleId, $slug, [
        'id' => $articleId,
        'title' => $title,
        'slug' => $slug,
        'content' => $data['content'],
        'excerpt' => $data['excerpt'],
        'image' => $data['image'] ?? null,
        'meta_title' => $data['meta_title'] ?? null,
        'meta_description' => $data['meta_description'] ?? null,
        'focus_keywords' => $data['focus_keywords'] ?? [],
        'published_at' => date('c'),
    ]);

    return true;
}

function estimateReadingTime($htmlContent) {
    $wordCount = str_word_count(strip_tags($htmlContent));
    return max(1, (int)ceil($wordCount / 220));
}

function csrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}
