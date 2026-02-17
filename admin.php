<?php
require_once 'functions.php';

if (!isset($_SESSION['logged']) && (($_POST['pass'] ?? '') !== '')) {
    if (password_verify($_POST['pass'], PASSWORD_HASH)) {
        $_SESSION['logged'] = true;
    }
}

if (!isset($_SESSION['logged'])) {
    echo '<form method="post" class="mt-5 container" style="max-width:360px"><input type="password" name="pass" class="form-control" placeholder="Password"><button class="btn btn-primary mt-2 w-100">Login</button></form>';
    exit;
}

$pdo = db_connect();
$message = null;
$messageType = 'info';
$csrf = csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout']) && verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $message = 'Invalid security token. Please refresh and try again.';
    $messageType = 'danger';
} else {
    if (isset($_POST['add_titles'])) {
        $rawTitles = array_map('trim', explode("\n", $_POST['titles'] ?? ''));
        $titles = array_values(array_unique(array_filter($rawTitles, fn($t) => mb_strlen($t) >= 5)));
        $generated = 0;

        foreach ($titles as $t) {
            if (!articleExists($t)) {
                $data = generateArticle($t);
                saveArticle($t, $data);
                $generated++;
            }
        }

        $message = "Generated {$generated} new article(s).";
        $messageType = 'success';
    }

    if (isset($_POST['fetch_rss'])) {
        $sources = $pdo->query("SELECT url FROM rss_sources ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        $limit = max(1, (int)getSetting('daily_limit', 5));

        foreach ($sources as $url) {
            $xml = @simplexml_load_file($url);
            if ($xml && isset($xml->channel->item)) {
                foreach ($xml->channel->item as $item) {
                    $t = trim((string)$item->title);
                    if ($t && !articleExists($t) && $count < $limit) {
                        $data = generateArticle($t);
                        saveArticle($t, $data);
                        $count++;
                    }
                }
            }
        }

        $message = "Fetched and published {$count} new article(s).";
        $messageType = 'info';
    }

    if (isset($_POST['add_rss'])) {
        $url = trim($_POST['rss_url'] ?? '');
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO rss_sources (url) VALUES (?)");
            $stmt->execute([$url]);
            $message = 'RSS source added.';
            $messageType = 'success';
        } else {
            $message = 'Invalid RSS URL.';
            $messageType = 'danger';
        }
    }

    if (isset($_POST['delete_article'])) {
        $stmt = $pdo->prepare("DELETE FROM articles WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_article']]);
        $message = 'Article deleted.';
        $messageType = 'warning';
    }

    if (isset($_POST['delete_rss'])) {
        $stmt = $pdo->prepare("DELETE FROM rss_sources WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_rss']]);
        $message = 'RSS source removed.';
        $messageType = 'warning';
    }
}

$totalArticles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalSources = (int)$pdo->query("SELECT COUNT(*) FROM rss_sources")->fetchColumn();
$latestDate = $pdo->query("SELECT MAX(published_at) FROM articles")->fetchColumn();
$dailyLimit = (int)getSetting('daily_limit', 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Panel - <?= e(SITE_TITLE) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0"><i class="bi bi-car-front"></i> <?= e(SITE_TITLE) ?> Control Panel</h1>
        <form method="post" class="mb-0">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="btn btn-danger" name="logout" value="1">Logout</button>
        </form>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card bg-secondary"><div class="card-body"><small>Total Articles</small><h3><?= $totalArticles ?></h3></div></div></div>
        <div class="col-md-3"><div class="card bg-secondary"><div class="card-body"><small>RSS Sources</small><h3><?= $totalSources ?></h3></div></div></div>
        <div class="col-md-3"><div class="card bg-secondary"><div class="card-body"><small>Daily Limit</small><h3><?= $dailyLimit ?></h3></div></div></div>
        <div class="col-md-3"><div class="card bg-secondary"><div class="card-body"><small>Latest Publish</small><h6><?= e($latestDate ?: 'N/A') ?></h6></div></div></div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card bg-secondary mb-3">
                <div class="card-body">
                    <h5>Add Titles Manually</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <textarea name="titles" class="form-control" rows="6" placeholder="One title per line"></textarea><br>
                        <button name="add_titles" class="btn btn-success">Add to Queue & Generate</button>
                    </form>
                </div>
            </div>

            <div class="card bg-secondary mb-3">
                <div class="card-body">
                    <h5>Add RSS Source</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="url" name="rss_url" class="form-control" placeholder="https://example.com/feed.xml" required>
                        <button name="add_rss" class="btn btn-outline-light mt-2">Save Source</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <form method="post" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button name="fetch_rss" value="1" class="btn btn-primary btn-lg">🔄 Fetch New Titles from RSS & Generate</button>
            </form>

            <h5>Recent Articles</h5>
            <div class="table-responsive">
                <table class="table table-dark table-striped align-middle">
                    <thead><tr><th>Title</th><th>Date</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php
                    $stmt = $pdo->query("SELECT id, title, slug, published_at FROM articles ORDER BY id DESC LIMIT 15");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo '<tr>';
                        echo '<td>' . e($row['title']) . '</td>';
                        echo '<td>' . e($row['published_at']) . '</td>';
                        echo '<td class="d-flex gap-2">';
                        echo '<a href="index.php?slug=' . e($row['slug']) . '" target="_blank" class="btn btn-sm btn-info">View</a>';
                        echo '<form method="post" onsubmit="return confirm(\'Delete this article?\')">';
                        echo '<input type="hidden" name="csrf_token" value="' . e($csrf) . '">';
                        echo '<button name="delete_article" value="' . (int)$row['id'] . '" class="btn btn-sm btn-outline-danger">Delete</button>';
                        echo '</form>';
                        echo '</td></tr>';
                    }
                    ?>
                    </tbody>
                </table>
            </div>

            <h5 class="mt-4">RSS Sources</h5>
            <ul class="list-group">
                <?php
                $rssRows = $pdo->query("SELECT id, url FROM rss_sources ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rssRows as $rss) {
                    echo '<li class="list-group-item d-flex justify-content-between align-items-center">';
                    echo '<span class="text-break pe-2">' . e($rss['url']) . '</span>';
                    echo '<form method="post" onsubmit="return confirm(\'Remove this source?\')">';
                    echo '<input type="hidden" name="csrf_token" value="' . e($csrf) . '">';
                    echo '<button name="delete_rss" value="' . (int)$rss['id'] . '" class="btn btn-sm btn-outline-danger">Remove</button>';
                    echo '</form>';
                    echo '</li>';
                }
                ?>
            </ul>
        </div>
    </div>

    <hr>
    <a href="index.php" class="btn btn-outline-light" target="_blank">View Public Site →</a>
</div>
</body>
</html>
