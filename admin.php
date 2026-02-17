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
        $_SESSION['flash_message'] = 'Daily RSS generation limit updated.';
        $_SESSION['flash_type'] = 'success';
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
                saveArticle($title, $data);
                $generated++;
            }
        }

        $_SESSION['flash_message'] = "Generated {$generated} new article(s).";
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['fetch_rss'])) {
        $sources = $pdo->query("SELECT url FROM rss_sources ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        $limit = max(1, (int)getSetting('daily_limit', 5));

        foreach ($sources as $url) {
            $xml = @simplexml_load_file($url);
            if ($xml && isset($xml->channel->item)) {
                foreach ($xml->channel->item as $item) {
                    $title = trim((string)$item->title);
                    if ($title && !articleExists($title) && $count < $limit) {
                        $data = generateArticle($title);
                        saveArticle($title, $data);
                        $count++;
                    }
                }
            }

            if ($count >= $limit) {
                break;
            }
        }

        $_SESSION['flash_message'] = "Fetched and published {$count} new article(s).";
        $_SESSION['flash_type'] = 'info';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['add_rss'])) {
        $url = trim($_POST['rss_url'] ?? '');
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO rss_sources (url) VALUES (?)");
            $stmt->execute([$url]);
            $_SESSION['flash_message'] = 'RSS source added.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Invalid RSS URL.';
            $_SESSION['flash_type'] = 'danger';
        }

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

$totalArticles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalSources = (int)$pdo->query("SELECT COUNT(*) FROM rss_sources")->fetchColumn();
$latestDate = $pdo->query("SELECT MAX(published_at) FROM articles")->fetchColumn();
$dailyLimit = (int)getSetting('daily_limit', 5);

$articleSql = "SELECT id, title, slug, published_at FROM articles";
$articleParams = [];
if ($articleSearch !== '') {
    $articleSql .= " WHERE title LIKE :title";
    $articleParams['title'] = '%' . $articleSearch . '%';
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
    </style>
</head>
<body class="text-light">
<div class="container py-4 py-lg-5">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="mb-1"><i class="bi bi-speedometer2"></i> <?= e(SITE_TITLE) ?> Control Panel</h1>
            <p class="text-secondary mb-0">Manage article generation, RSS sources, and publishing workflow.</p>
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
                    <small class="text-light-emphasis">RSS Sources</small>
                    <h3><?= $totalSources ?></h3>
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
                </div>
            </div>
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
                            <label class="form-label">Daily RSS Limit</label>
                            <input type="number" name="daily_limit" class="form-control" min="1" max="50" value="<?= $dailyLimit ?>">
                        </div>
                        <div class="col-4">
                            <button name="update_daily_limit" value="1" class="btn btn-outline-light w-100">Save</button>
                        </div>
                    </form>
                    <small class="text-secondary">Controls max articles auto-generated per RSS fetch.</small>
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
                        <input type="url" name="rss_url" class="form-control" placeholder="https://example.com/feed.xml" required>
                        <button name="add_rss" class="btn btn-outline-light mt-3 w-100">Save Source</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <form method="post" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button name="fetch_rss" value="1" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-arrow-repeat"></i> Fetch New Titles from RSS & Generate
                </button>
            </form>

            <div class="card section-card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Recent Articles</h5>
                        <form method="get" class="d-flex gap-2">
                            <input type="text" name="qa" class="form-control form-control-sm" placeholder="Search title" value="<?= e($articleSearch) ?>">
                            <button class="btn btn-sm btn-outline-light">Search</button>
                        </form>
                    </div>

                    <div class="table-responsive rounded shadow-sm">
                        <table class="table table-dark table-striped align-middle mb-0">
                            <thead>
                            <tr><th>Title</th><th>Date</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                            <?php if (!$articles): ?>
                                <tr><td colspan="3" class="text-center text-secondary">No articles found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($articles as $row): ?>
                                    <tr>
                                        <td><?= e($row['title']) ?></td>
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
        </div>
    </div>
</div>
</body>
</html>
