<?php
session_start();
define('DB_FILE', __DIR__ . '/data/data.db');
define('SITE_TITLE', 'AutoCar Niche');
define('PASSWORD_HASH', '$2y$12$iFCL8jqvoVMbZBcRy3wY..IUJNTqFcIfNAtUZRKiY4pFSspOevkHi'); // admin123

function db_connect() {
    if (!file_exists(dirname(DB_FILE))) mkdir(dirname(DB_FILE), 0777, true);
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// إنشاء الجداول عند أول تشغيل
$pdo = db_connect();
$pdo->exec("CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY, 
    title TEXT UNIQUE, 
    slug TEXT UNIQUE, 
    content TEXT, 
    image TEXT, 
    excerpt TEXT, 
    published_at TEXT,
    category TEXT
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS rss_sources (id INTEGER PRIMARY KEY, url TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS web_sources (id INTEGER PRIMARY KEY, url TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS url_cache (
    url TEXT PRIMARY KEY,
    body TEXT,
    status_code INTEGER DEFAULT 0,
    fetched_at INTEGER DEFAULT 0,
    ttl_seconds INTEGER DEFAULT 900,
    fail_count INTEGER DEFAULT 0,
    blocked_until INTEGER DEFAULT 0
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS scrape_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow TEXT NOT NULL,
    source_url TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER DEFAULT 0,
    locked_until INTEGER DEFAULT 0,
    available_at INTEGER DEFAULT 0,
    created_at INTEGER DEFAULT 0,
    updated_at INTEGER DEFAULT 0
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS article_exports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    slug TEXT NOT NULL,
    html_path TEXT NOT NULL,
    json_path TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(article_id),
    FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE
)");
$pdo->exec("DELETE FROM rss_sources WHERE id NOT IN (SELECT MIN(id) FROM rss_sources GROUP BY url)");
$pdo->exec("DELETE FROM web_sources WHERE id NOT IN (SELECT MIN(id) FROM web_sources GROUP BY url)");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_rss_sources_url ON rss_sources(url)");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_web_sources_url ON web_sources(url)");

// إعدادات افتراضية
$defaults = [
    'min_words' => '3000',
    'auto_publish' => '1',
    'daily_limit' => '5',
    'auto_ai_enabled' => '1',
    'auto_publish_interval_minutes' => '180',
    'auto_publish_interval_seconds' => '10800',
    'auto_publish_last_run_at' => '1970-01-01 00:00:00',
    'content_workflow' => 'rss',
    'url_cache_ttl_seconds' => '900',
    'fetch_timeout_seconds' => '12',
    'fetch_user_agent' => 'Mozilla/5.0 (compatible; VitoBot/1.0; +https://example.com/bot)',
    'workflow_batch_size' => '8',
    'queue_retry_delay_seconds' => '60',
    'queue_max_attempts' => '3'
];
foreach ($defaults as $k => $v) {
    $pdo->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (?,?)")->execute([$k, $v]);
}

// one-time migration for installs created before the scalable pipeline defaults
$migrationKey = 'pipeline_defaults_v2_applied';
$migrationStmt = $pdo->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
$migrationStmt->execute([$migrationKey]);
$migrationApplied = $migrationStmt->fetchColumn();
if ($migrationApplied === false) {
    $currentMinWords = (int)$pdo->query("SELECT value FROM settings WHERE key = 'min_words' LIMIT 1")->fetchColumn();
    if ($currentMinWords <= 1200) {
        $pdo->prepare("UPDATE settings SET value = '3000' WHERE key = 'min_words'")->execute();
    }

    $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('fetch_timeout_seconds', '12')")->execute();
    $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('fetch_user_agent', 'Mozilla/5.0 (compatible; VitoBot/1.0; +https://example.com/bot)')")->execute();
    $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
        ->execute([$migrationKey, date('Y-m-d H:i:s')]);
}

// مصادر RSS افتراضية
$rss_defaults = [
    'https://www.caranddriver.com/rss/all.xml',
    'https://www.motor1.com/rss/news/all/',
    'https://www.autoblog.com/rss.xml'
];
foreach ($rss_defaults as $url) {
    $pdo->prepare("INSERT OR IGNORE INTO rss_sources (url) VALUES (?)")->execute([$url]);
}


$web_defaults = [
    'https://www.caranddriver.com/news/',
    'https://www.motor1.com/news/',
    'https://www.autoblog.com/news/'
];
foreach ($web_defaults as $url) {
    $pdo->prepare("INSERT OR IGNORE INTO web_sources (url) VALUES (?)")->execute([$url]);
}
?>
