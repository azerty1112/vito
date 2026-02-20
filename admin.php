<?php
require_once 'functions.php';

$maxAttempts = 5;
$lockSeconds = 60;
$now = time();

$_SESSION['login_attempts'] = (int)($_SESSION['login_attempts'] ?? 0);
$_SESSION['login_lock_until'] = (int)($_SESSION['login_lock_until'] ?? 0);

$isLocked = $_SESSION['login_lock_until'] > $now;
$remainingLockSeconds = max(0, $_SESSION['login_lock_until'] - $now);

if (!isset($_SESSION['logged']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
    if ($isLocked) {
        $_SESSION['login_error'] = 'Too many attempts. Try again in ' . $remainingLockSeconds . ' seconds.';
    } elseif (verifyAdminPassword($_POST['pass'])) {
        session_regenerate_id(true);
        $_SESSION['logged'] = true;
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_lock_until'] = 0;
        unset($_SESSION['login_error']);
    } else {
        $_SESSION['login_attempts']++;

        if ($_SESSION['login_attempts'] >= $maxAttempts) {
            $_SESSION['login_lock_until'] = $now + $lockSeconds;
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_error'] = 'Too many attempts. Login locked for ' . $lockSeconds . ' seconds.';
        } else {
            $remaining = $maxAttempts - $_SESSION['login_attempts'];
            $_SESSION['login_error'] = 'Invalid password. Remaining attempts: ' . $remaining . '.';
        }
    }

    header('Location: admin.php');
    exit;
}

if (!isset($_SESSION['logged'])) {
    $loginError = $_SESSION['login_error'] ?? null;
    unset($_SESSION['login_error']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin Login - <?= e(SITE_TITLE) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body {
                min-height: 100vh;
                background: radial-gradient(circle at top, #1f2937, #0b1120 65%);
            }
            .login-card {
                max-width: 430px;
                border: 1px solid rgba(255, 255, 255, 0.12);
                backdrop-filter: blur(8px);
            }
        </style>
    </head>
    <body class="d-flex align-items-center justify-content-center text-light p-3">
    <main class="card bg-dark shadow-lg login-card w-100">
        <div class="card-body p-4 p-md-5">
            <h1 class="h4 mb-3 text-center"><?= e(SITE_TITLE) ?> Admin</h1>
            <p class="text-secondary text-center mb-4">Secure access to content management dashboard.</p>

            <?php if ($loginError): ?>
                <div class="alert alert-danger py-2 small mb-3" role="alert"><?= e($loginError) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <label for="pass" class="form-label">Password</label>
                <input id="pass" type="password" name="pass" class="form-control form-control-lg" placeholder="Enter admin password" required autofocus>
                <button class="btn btn-primary w-100 mt-3">Login</button>
            </form>

            <small class="d-block text-secondary mt-3 text-center">Tip: set <code>ADMIN_PASSWORD</code> env var for production.</small>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$pdo = db_connect();
$csrf = csrfToken();
publishAutoArticleBySchedule();

if (!isset($_SESSION['flash_message'])) {
    $_SESSION['flash_message'] = null;
    $_SESSION['flash_type'] = 'info';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = 'Invalid security token. Please refresh and try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['logout'])) {
        session_destroy();
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_daily_limit'])) {
        $newLimit = (int)($_POST['daily_limit'] ?? 5);
        $newLimit = max(1, min(50, $newLimit));
        setSetting('daily_limit', (string)$newLimit);
        $_SESSION['flash_message'] = 'Daily workflow generation limit updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_fetch_settings'])) {
        $timeout = (int)($_POST['fetch_timeout_seconds'] ?? 12);
        $timeout = max(3, min(45, $timeout));
        $retryAttempts = (int)($_POST['fetch_retry_attempts'] ?? 3);
        $retryAttempts = max(1, min(5, $retryAttempts));
        $retryBackoffMs = (int)($_POST['fetch_retry_backoff_ms'] ?? 350);
        $retryBackoffMs = max(100, min(3000, $retryBackoffMs));
        $sourceCooldown = (int)($_POST['queue_source_cooldown_seconds'] ?? 180);
        $sourceCooldown = max(30, min(7200, $sourceCooldown));
        $userAgent = trim((string)($_POST['fetch_user_agent'] ?? ''));
        if ($userAgent === '') {
            $userAgent = 'Mozilla/5.0 (compatible; VitoBot/1.0; +https://example.com/bot)';
        }
        if (mb_strlen($userAgent) > 255) {
            $userAgent = mb_substr($userAgent, 0, 255);
        }

        setSetting('fetch_timeout_seconds', (string)$timeout);
        setSetting('fetch_retry_attempts', (string)$retryAttempts);
        setSetting('fetch_retry_backoff_ms', (string)$retryBackoffMs);
        setSetting('queue_source_cooldown_seconds', (string)$sourceCooldown);
        setSetting('fetch_user_agent', $userAgent);
        $_SESSION['flash_message'] = 'Fetcher timeout, retries, cooldown, and user-agent updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }


    if (isset($_POST['update_auto_scheduler'])) {
        $enabled = isset($_POST['auto_ai_enabled']) ? 1 : 0;
        $interval = (int)($_POST['auto_publish_interval_seconds'] ?? 10800);
        $interval = max(10, min(86400, $interval));

        setSetting('auto_ai_enabled', (string)$enabled);
        setSetting('auto_publish_interval_seconds', (string)$interval);

        $_SESSION['flash_message'] = 'Automatic AI publishing settings updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['auto_generate_now'])) {
        $result = publishAutoArticleBySchedule(true);
        if (($result['published'] ?? 0) === 1) {
            $_SESSION['flash_message'] = 'Auto-generated and published: ' . ($result['title'] ?? 'New article');
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Automatic generation failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['add_titles'])) {
        $rawTitles = array_map('trim', explode("\n", $_POST['titles'] ?? ''));
        $titles = array_values(array_unique(array_filter($rawTitles, fn($t) => mb_strlen($t) >= 5)));
        $generated = 0;

        foreach ($titles as $title) {
            if (!articleExists($title)) {
                $data = generateArticle($title);
                if (saveArticle($title, $data)) {
                    $generated++;
                }
            }
        }

        $_SESSION['flash_message'] = "Generated {$generated} new article(s).";
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['generate_demo_pack'])) {
        $demoTitles = [
            '2026 Porsche Taycan Turbo GT Track Review',
            'Best Hybrid SUVs for Families in 2026',
            'Mercedes-AMG C63 Daily Driving Impressions',
            'How Fast Charging Changed EV Road Trips',
            'Budget Performance Cars Worth Buying This Year',
        ];
        $generated = 0;
        foreach ($demoTitles as $title) {
            if (!articleExists($title)) {
                $data = generateArticle($title);
                if (saveArticle($title, $data)) {
                    $generated++;
                }
            }
        }

        $_SESSION['flash_message'] = "Demo content pack generated: {$generated} new article(s).";
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['run_content_workflow'])) {
        $result = runSelectedContentWorkflow();
        $workflowName = ($result['workflow'] ?? 'rss') === 'web' ? 'Normal Sites' : 'RSS';
        $published = (int)($result['published'] ?? 0);
        $sourcesCount = (int)($result['sources_count'] ?? 0);

        $_SESSION['flash_message'] = "Workflow {$workflowName}: published {$published} new article(s) from {$sourcesCount} source(s).";
        $_SESSION['flash_type'] = 'info';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['add_rss'])) {
        $singleUrl = trim($_POST['rss_url'] ?? '');
        $bulkInput = trim($_POST['rss_urls'] ?? '');
        $bulkUrls = $bulkInput === '' ? [] : preg_split('/\r\n|\r|\n/', $bulkInput);

        $rawUrls = [];
        if ($singleUrl !== '') {
            $rawUrls[] = $singleUrl;
        }
        foreach ($bulkUrls as $rawUrl) {
            $rawUrl = trim((string)$rawUrl);
            if ($rawUrl !== '') {
                $rawUrls[] = $rawUrl;
            }
        }

        $rawUrls = array_values(array_unique($rawUrls));
        if (!$rawUrls) {
            $_SESSION['flash_message'] = 'Please enter at least one RSS/XML URL.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin.php');
            exit;
        }

        $inserted = 0;
        $invalid = 0;
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO rss_sources (url) VALUES (?)");
        foreach ($rawUrls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $invalid++;
                continue;
            }

            $stmt->execute([$url]);
            if ($stmt->rowCount() > 0) {
                $inserted++;
            }
        }

        $ignored = count($rawUrls) - $inserted - $invalid;
        if ($inserted > 0) {
            $_SESSION['flash_message'] = "Added {$inserted} RSS source(s)."
                . ($ignored > 0 ? " {$ignored} duplicate(s) skipped." : '')
                . ($invalid > 0 ? " {$invalid} invalid link(s) skipped." : '');
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = $invalid > 0
                ? "No RSS source was added. {$invalid} invalid link(s) detected."
                : 'No RSS source was added (all links already exist).';
            $_SESSION['flash_type'] = 'warning';
        }

        header('Location: admin.php');
        exit;
    }


    if (isset($_POST['update_content_workflow'])) {
        $workflow = trim((string)($_POST['content_workflow'] ?? 'rss'));
        if (!in_array($workflow, ['rss', 'web'], true)) {
            $workflow = 'rss';
        }

        setSetting('content_workflow', $workflow);
        $_SESSION['flash_message'] = 'Content workflow updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['add_web'])) {
        $singleUrl = trim($_POST['web_url'] ?? '');
        $bulkInput = trim($_POST['web_urls'] ?? '');
        $bulkUrls = $bulkInput === '' ? [] : preg_split('/\r\n|\r|\n/', $bulkInput);

        $rawUrls = [];
        if ($singleUrl !== '') {
            $rawUrls[] = $singleUrl;
        }
        foreach ($bulkUrls as $rawUrl) {
            $rawUrl = trim((string)$rawUrl);
            if ($rawUrl !== '') {
                $rawUrls[] = $rawUrl;
            }
        }

        $rawUrls = array_values(array_unique($rawUrls));
        if (!$rawUrls) {
            $_SESSION['flash_message'] = 'Please enter at least one normal website URL.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin.php');
            exit;
        }

        $inserted = 0;
        $invalid = 0;
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO web_sources (url) VALUES (?)");
        foreach ($rawUrls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $invalid++;
                continue;
            }

            $stmt->execute([$url]);
            if ($stmt->rowCount() > 0) {
                $inserted++;
            }
        }

        $ignored = count($rawUrls) - $inserted - $invalid;
        if ($inserted > 0) {
            $_SESSION['flash_message'] = "Added {$inserted} normal website source(s)."
                . ($ignored > 0 ? " {$ignored} duplicate(s) skipped." : '')
                . ($invalid > 0 ? " {$invalid} invalid link(s) skipped." : '');
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = $invalid > 0
                ? "No normal website source was added. {$invalid} invalid link(s) detected."
                : 'No normal website source was added (all links already exist).';
            $_SESSION['flash_type'] = 'warning';
        }

        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['delete_web'])) {
        $stmt = $pdo->prepare("DELETE FROM web_sources WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_web']]);
        $_SESSION['flash_message'] = 'Normal website source removed.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['delete_article'])) {
        $stmt = $pdo->prepare("DELETE FROM articles WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_article']]);
        $_SESSION['flash_message'] = 'Article deleted.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['delete_rss'])) {
        $stmt = $pdo->prepare("DELETE FROM rss_sources WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_rss']]);
        $_SESSION['flash_message'] = 'RSS source removed.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: admin.php');
        exit;
    }
}

$message = $_SESSION['flash_message'];
$messageType = $_SESSION['flash_type'];
$_SESSION['flash_message'] = null;
$_SESSION['flash_type'] = 'info';

$articleSearch = trim($_GET['qa'] ?? '');
$rssSearch = trim($_GET['qr'] ?? '');
$webSearch = trim($_GET['qw'] ?? '');
$articleCategory = trim($_GET['cat'] ?? '');

if (isset($_GET['export']) && $_GET['export'] === 'articles_json') {
    $exportRows = $pdo->query("SELECT title, slug, excerpt, category, published_at FROM articles ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=articles-export.json');
    echo json_encode($exportRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'articles_csv') {
    $exportRows = $pdo->query("SELECT title, slug, category, published_at FROM articles ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=articles-export.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['title', 'slug', 'category', 'published_at']);
    foreach ($exportRows as $row) {
        fputcsv($out, [$row['title'], $row['slug'], $row['category'], $row['published_at']]);
    }
    fclose($out);
    exit;
}

$totalArticles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalSources = (int)$pdo->query("SELECT COUNT(*) FROM rss_sources")->fetchColumn();
$totalWebSources = (int)$pdo->query("SELECT COUNT(*) FROM web_sources")->fetchColumn();
$latestDate = $pdo->query("SELECT MAX(published_at) FROM articles")->fetchColumn();
$pageVisitStats = getPageVisitStats(7);
$totalTrackedViews = 0;
$totalTrackedVisitors = 0;
foreach ($pageVisitStats as $visitRow) {
    $totalTrackedViews += (int)($visitRow['total_views'] ?? 0);
    $totalTrackedVisitors += (int)($visitRow['unique_visitors'] ?? 0);
}
$dailyLimit = (int)getSetting('daily_limit', 5);
$fetchTimeoutSeconds = getSettingInt('fetch_timeout_seconds', 12, 3, 45);
$fetchRetryAttempts = getSettingInt('fetch_retry_attempts', 3, 1, 5);
$fetchRetryBackoffMs = getSettingInt('fetch_retry_backoff_ms', 350, 100, 3000);
$queueSourceCooldownSeconds = getSettingInt('queue_source_cooldown_seconds', 180, 30, 7200);
$fetchUserAgent = (string)getSetting('fetch_user_agent', 'Mozilla/5.0 (compatible; VitoBot/1.0; +https://example.com/bot)');
$selectedWorkflow = getSelectedContentWorkflow();
$workflowSummary = getContentWorkflowSummary();
$autoAiEnabled = getSettingInt('auto_ai_enabled', 1, 0, 1);
$autoPublishInterval = getAutoPublishIntervalSeconds();
$autoPublishLastRun = (string)getSetting('auto_publish_last_run_at', '1970-01-01 00:00:00');
$cronUrl = getCronEndpointUrl();
$categoryOptions = $pdo->query("SELECT DISTINCT category FROM articles WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

$articleSql = "SELECT id, title, slug, category, published_at FROM articles";
$articleParams = [];
$articleClauses = [];
if ($articleSearch !== '') {
    $articleClauses[] = "title LIKE :title";
    $articleParams['title'] = '%' . $articleSearch . '%';
}
if ($articleCategory !== '') {
    $articleClauses[] = "category = :cat";
    $articleParams['cat'] = $articleCategory;
}
if ($articleClauses) {
    $articleSql .= ' WHERE ' . implode(' AND ', $articleClauses);
}
$articleSql .= " ORDER BY id DESC LIMIT 20";
$articleStmt = $pdo->prepare($articleSql);
foreach ($articleParams as $key => $value) {
    $articleStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$articleStmt->execute();
$articles = $articleStmt->fetchAll(PDO::FETCH_ASSOC);

$rssSql = "SELECT id, url FROM rss_sources";
$rssParams = [];
if ($rssSearch !== '') {
    $rssSql .= " WHERE url LIKE :url";
    $rssParams['url'] = '%' . $rssSearch . '%';
}
$rssSql .= " ORDER BY id DESC";
$rssStmt = $pdo->prepare($rssSql);
foreach ($rssParams as $key => $value) {
    $rssStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$rssStmt->execute();
$rssRows = $rssStmt->fetchAll(PDO::FETCH_ASSOC);

$webSql = "SELECT id, url FROM web_sources";
$webParams = [];
if ($webSearch !== '') {
    $webSql .= " WHERE url LIKE :url";
    $webParams['url'] = '%' . $webSearch . '%';
}
$webSql .= " ORDER BY id DESC";
$webStmt = $pdo->prepare($webSql);
foreach ($webParams as $key => $value) {
    $webStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$webStmt->execute();
$webRows = $webStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Panel - <?= e(SITE_TITLE) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #10131a;
        }
        .section-card {
            background: #232a34;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .stat-card h3,
        .stat-card h6 {
            margin-bottom: 0;
        }
        .table td,
        .table th {
            vertical-align: middle;
        }
        .list-group-item {
            background: #232a34;
            color: #f8f9fa;
            border-color: rgba(255, 255, 255, 0.08);
        }
        .workflow-badge {
            font-size: 0.75rem;
            letter-spacing: 0.2px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .mini-analytics {
            border: 1px solid rgba(59, 130, 246, 0.28);
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(37, 99, 235, 0.2));
        }
        .mini-analytics .table {
            --bs-table-bg: transparent;
            --bs-table-border-color: rgba(255, 255, 255, 0.08);
            margin-bottom: 0;
        }
        .mini-analytics .progress {
            height: 6px;
            background-color: rgba(255, 255, 255, 0.12);
        }
    </style>
</head>
<body class="text-light">
<div class="container py-4 py-lg-5">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="mb-1"><i class="bi bi-speedometer2"></i> <?= e(SITE_TITLE) ?> Control Panel</h1>
            <p class="text-secondary mb-0">Manage article generation, RSS/normal sources, and publishing workflow.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-light" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Public Site</a>
            <form method="post" class="mb-0">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-danger" name="logout" value="1"><i class="bi bi-box-arrow-right"></i> Logout</button>
            </form>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?> shadow-sm"><?= e($message) ?></div>
    <?php endif; ?>

    <?php
    $workflowHealth = $workflowSummary['health'] ?? 'ready';
    $workflowAlertClass = $workflowHealth === 'ready' ? 'success' : 'warning';
    $workflowAlertText = $workflowHealth === 'ready'
        ? 'Workflow is ready. Scheduled and manual runs will follow the selected source type.'
        : ($workflowHealth === 'missing_sources'
            ? 'No sources found for the selected workflow. Add sources below before running.'
            : 'Auto scheduler is disabled. Manual workflow runs still work normally.');
    ?>
    <div class="alert alert-<?= $workflowAlertClass ?> d-flex flex-wrap align-items-center gap-2 shadow-sm" role="status">
        <span class="badge text-bg-dark workflow-badge">Workflow: <?= e($workflowSummary['selected_workflow_label']) ?></span>
        <span class="badge text-bg-secondary workflow-badge">Sources: <?= (int)$workflowSummary['selected_sources'] ?></span>
        <span class="badge text-bg-secondary workflow-badge">Daily limit: <?= (int)$workflowSummary['daily_limit'] ?></span>
        <span class="ms-1"><?= e($workflowAlertText) ?></span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card section-card stat-card h-100">
                <div class="card-body">
                    <small class="text-light-emphasis">Total Articles</small>
                    <h3><?= $totalArticles ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card section-card stat-card h-100">
                <div class="card-body">
                    <small class="text-light-emphasis">RSS / Web Sources</small>
                    <h3><?= $totalSources ?> / <?= $totalWebSources ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card section-card stat-card h-100">
                <div class="card-body">
                    <small class="text-light-emphasis">Daily Limit</small>
                    <h3><?= $dailyLimit ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card section-card stat-card h-100">
                <div class="card-body">
                    <small class="text-light-emphasis">Latest Publish</small>
                    <h6><?= e($latestDate ?: 'N/A') ?></h6>
                    <small class="text-secondary d-block mt-2">Visitors: <?= (int)$totalTrackedVisitors ?> • Views: <?= (int)$totalTrackedViews ?></small>
                </div>
            </div>
        </div>
    </div>

    <div class="card section-card mini-analytics mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h6 class="mb-0"><i class="bi bi-graph-up-arrow"></i> Visitor Analytics by Page</h6>
                <small class="text-light-emphasis">Compact view · top <?= count($pageVisitStats) ?> pages</small>
            </div>

            <?php if (!$pageVisitStats): ?>
                <small class="text-secondary">No visit data yet. Open the public pages and stats will appear automatically.</small>
            <?php else: ?>
                <?php $maxViews = max(1, (int)$pageVisitStats[0]['total_views']); ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle text-light">
                        <thead>
                        <tr>
                            <th>Page</th>
                            <th class="text-center">Unique</th>
                            <th class="text-center">Views</th>
                            <th class="text-center">Last 24h</th>
                            <th style="width: 180px;">Trend</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pageVisitStats as $visitRow): ?>
                            <?php $ratio = min(100, (int)round(((int)$visitRow['total_views'] / $maxViews) * 100)); ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?= e($visitRow['page_label']) ?></span>
                                    <small class="text-secondary d-block"><?= e($visitRow['page_key']) ?></small>
                                </td>
                                <td class="text-center"><span class="badge text-bg-secondary"><?= (int)$visitRow['unique_visitors'] ?></span></td>
                                <td class="text-center"><span class="badge text-bg-primary"><?= (int)$visitRow['total_views'] ?></span></td>
                                <td class="text-center"><span class="badge text-bg-dark"><?= (int)$visitRow['visitors_24h'] ?></span></td>
                                <td>
                                    <div class="progress" role="progressbar" aria-label="Page views trend" aria-valuenow="<?= $ratio ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar bg-info" style="width: <?= $ratio ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card section-card mb-3">
                <div class="card-body">
                    <h5><i class="bi bi-sliders"></i> Publishing Settings</h5>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-8">
                            <label class="form-label">Daily Workflow Limit</label>
                            <input type="number" name="daily_limit" class="form-control" min="1" max="50" value="<?= $dailyLimit ?>">
                        </div>
                        <div class="col-4">
                            <button name="update_daily_limit" value="1" class="btn btn-outline-light w-100">Save</button>
                        </div>
                    </form>
                    <small class="text-secondary">Controls max articles generated per selected workflow run.</small>

                    <form method="post" class="row g-2 align-items-end mt-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-4">
                            <label class="form-label">Fetch Timeout (s)</label>
                            <input type="number" name="fetch_timeout_seconds" class="form-control" min="3" max="45" value="<?= (int)$fetchTimeoutSeconds ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Retry Attempts</label>
                            <input type="number" name="fetch_retry_attempts" class="form-control" min="1" max="5" value="<?= (int)$fetchRetryAttempts ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Retry Backoff (ms)</label>
                            <input type="number" name="fetch_retry_backoff_ms" class="form-control" min="100" max="3000" value="<?= (int)$fetchRetryBackoffMs ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Source Cooldown (s)</label>
                            <input type="number" name="queue_source_cooldown_seconds" class="form-control" min="30" max="7200" value="<?= (int)$queueSourceCooldownSeconds ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Fetcher User-Agent</label>
                            <input type="text" name="fetch_user_agent" class="form-control" maxlength="255" value="<?= e($fetchUserAgent) ?>">
                        </div>
                        <div class="col-12">
                            <button name="update_fetch_settings" value="1" class="btn btn-outline-light w-100">Update Fetch Settings</button>
                        </div>
                    </form>
                    <small class="text-secondary">Anti-block controls: timeout, retries with backoff, URL queue cooldown, and custom UA.</small>

                    <form method="post" class="row g-2 align-items-end mt-1">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-8">
                            <label class="form-label">Selected Content Workflow</label>
                            <select name="content_workflow" class="form-select">
                                <option value="rss" <?= $selectedWorkflow === 'rss' ? 'selected' : '' ?>>RSS Workflow</option>
                                <option value="web" <?= $selectedWorkflow === 'web' ? 'selected' : '' ?>>Normal Sites Workflow (Symfony DomCrawler)</option>
                            </select>
                        </div>
                        <div class="col-4">
                            <button name="update_content_workflow" value="1" class="btn btn-outline-light w-100">Apply</button>
                        </div>
                    </form>
                    <small class="text-secondary">Cron and manual run will execute the selected workflow only.</small>

                    <hr class="border-secondary-subtle my-3">
                    <h6><i class="bi bi-robot"></i> AI Auto Publish Scheduler</h6>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="auto_ai_enabled" name="auto_ai_enabled" value="1" <?= $autoAiEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label" for="auto_ai_enabled">Enable fully automatic title + article publishing</label>
                            </div>
                        </div>
                        <div class="col-8">
                            <label class="form-label">Publish Every (seconds)</label>
                            <input type="number" name="auto_publish_interval_seconds" class="form-control" min="10" max="86400" value="<?= $autoPublishInterval ?>">
                        </div>
                        <div class="col-4">
                            <button name="update_auto_scheduler" value="1" class="btn btn-outline-warning w-100">Update</button>
                        </div>
                    </form>
                    <small class="text-secondary d-block mt-2">Set to 10 seconds for a fast demo. Last automatic publish run: <?= e($autoPublishLastRun) ?></small>
                    <div class="alert alert-secondary mt-3 mb-2">
                        <div class="small text-uppercase text-muted mb-1">Hosting Cron URL</div>
                        <code class="d-block text-break"><?= e($cronUrl) ?></code>
                        <small class="text-secondary d-block mt-2">Set your hosting cron job to call this URL every 10 seconds (or the smallest interval your provider allows).</small>
                        <small class="text-secondary d-block">No token required. This URL supports HTTPS proxy headers and subfolder deployments automatically.</small>
                    </div>
                    <form method="post" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <button name="auto_generate_now" value="1" class="btn btn-outline-success w-100">
                            <i class="bi bi-magic"></i> Generate Title + Publish Now
                        </button>
                    </form>

                    <form method="post" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <button name="generate_demo_pack" value="1" class="btn btn-outline-info w-100">
                            <i class="bi bi-stars"></i> Generate Demo Content Pack
                        </button>
                    </form>
                </div>
            </div>

            <div class="card section-card mb-3">
                <div class="card-body">
                    <h5><i class="bi bi-pencil-square"></i> Add Titles Manually</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <textarea name="titles" class="form-control" rows="6" placeholder="One title per line"></textarea>
                        <button name="add_titles" class="btn btn-success mt-3 w-100">Add to Queue & Generate</button>
                    </form>
                </div>
            </div>

            <div class="card section-card">
                <div class="card-body">
                    <h5><i class="bi bi-rss"></i> Add RSS Source</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="url" name="rss_url" class="form-control" placeholder="https://example.com/feed.xml">
                        <textarea name="rss_urls" class="form-control mt-2" rows="5" placeholder="Paste multiple RSS/XML links (one per line)"></textarea>
                        <small class="text-secondary d-block mt-2">You can add a single URL above or paste a full XML links list.</small>
                        <button name="add_rss" class="btn btn-outline-light mt-3 w-100">Save Source(s)</button>
                    </form>
                </div>
            </div>

            <div class="card section-card mt-3">
                <div class="card-body">
                    <h5><i class="bi bi-globe2"></i> Add Normal Website Source</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="url" name="web_url" class="form-control" placeholder="https://example.com/news/">
                        <textarea name="web_urls" class="form-control mt-2" rows="5" placeholder="Paste multiple normal website links (one per line)"></textarea>
                        <small class="text-secondary d-block mt-2">Used by Normal Sites workflow with Symfony DomCrawler + CSS selectors.</small>
                        <button name="add_web" class="btn btn-outline-light mt-3 w-100">Save Website Source(s)</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <form method="post" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button name="run_content_workflow" value="1" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-arrow-repeat"></i> Run Selected Content Workflow & Generate
                </button>
            </form>
            <div class="d-flex gap-2 mb-3">
                <a href="admin.php?export=articles_json" class="btn btn-outline-light w-100"><i class="bi bi-filetype-json"></i> Export JSON</a>
                <a href="admin.php?export=articles_csv" class="btn btn-outline-light w-100"><i class="bi bi-filetype-csv"></i> Export CSV</a>
            </div>

            <div class="card section-card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Recent Articles</h5>
                        <form method="get" class="d-flex gap-2">
                            <input type="text" name="qa" class="form-control form-control-sm" placeholder="Search title" value="<?= e($articleSearch) ?>">
                            <select name="cat" class="form-select form-select-sm">
                                <option value="">All categories</option>
                                <?php foreach ($categoryOptions as $cat): ?>
                                    <option value="<?= e($cat) ?>" <?= $articleCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-light">Filter</button>
                        </form>
                    </div>

                    <div class="table-responsive rounded shadow-sm">
                        <table class="table table-dark table-striped align-middle mb-0">
                            <thead>
                            <tr><th>Title</th><th>Category</th><th>Date</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                            <?php if (!$articles): ?>
                                <tr><td colspan="4" class="text-center text-secondary">No articles found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($articles as $row): ?>
                                    <tr>
                                        <td><?= e($row['title']) ?></td>
                                        <td><span class="badge text-bg-secondary"><?= e($row['category'] ?: 'General') ?></span></td>
                                        <td><?= e($row['published_at']) ?></td>
                                        <td class="d-flex gap-2">
                                            <a href="index.php?slug=<?= e($row['slug']) ?>" target="_blank" class="btn btn-sm btn-info">View</a>
                                            <form method="post" onsubmit="return confirm('Delete this article?')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <button name="delete_article" value="<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card section-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">RSS Sources</h5>
                        <form method="get" class="d-flex gap-2">
                            <input type="text" name="qr" class="form-control form-control-sm" placeholder="Search URL" value="<?= e($rssSearch) ?>">
                            <button class="btn btn-sm btn-outline-light">Search</button>
                        </form>
                    </div>

                    <ul class="list-group shadow-sm">
                        <?php if (!$rssRows): ?>
                            <li class="list-group-item text-center text-secondary">No RSS sources found.</li>
                        <?php else: ?>
                            <?php foreach ($rssRows as $rss): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="text-break pe-2"><?= e($rss['url']) ?></span>
                                    <form method="post" onsubmit="return confirm('Remove this source?')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <button name="delete_rss" value="<?= (int)$rss['id'] ?>" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="card section-card mt-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Normal Website Sources</h5>
                        <form method="get" class="d-flex gap-2">
                            <input type="text" name="qw" class="form-control form-control-sm" placeholder="Search URL" value="<?= e($webSearch) ?>">
                            <button class="btn btn-sm btn-outline-light">Search</button>
                        </form>
                    </div>

                    <ul class="list-group shadow-sm">
                        <?php if (!$webRows): ?>
                            <li class="list-group-item text-center text-secondary">No normal website sources found.</li>
                        <?php else: ?>
                            <?php foreach ($webRows as $web): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="text-break pe-2"><?= e($web['url']) ?></span>
                                    <form method="post" onsubmit="return confirm('Remove this source?')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <button name="delete_web" value="<?= (int)$web['id'] ?>" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
