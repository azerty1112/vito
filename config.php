<?php
session_start();
define('DB_FILE', __DIR__ . '/data/data.db');
define('SITE_TITLE', 'AutoCar Niche');
define('PASSWORD_HASH', '$2y$12$iFCL8jqvoVMbZBcRy3wY..IUJNTqFcIfNAtUZRKiY4pFSspOevkHi'); // admin123

function db_connect() {
    if (!file_exists(dirname(DB_FILE))) mkdir(dirname(DB_FILE), 0777, true);
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
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
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_scrape_queue_workflow_status_available ON scrape_queue(workflow, status, available_at, locked_until)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_scrape_queue_source_url ON scrape_queue(source_url)");
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
$pdo->exec("CREATE TABLE IF NOT EXISTS page_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_key TEXT NOT NULL,
    page_label TEXT NOT NULL,
    visitor_hash TEXT NOT NULL,
    views INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE(page_key, visitor_hash)
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_visits_page_key ON page_visits(page_key)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_visits_updated_at ON page_visits(updated_at)");
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
    'fetch_retry_attempts' => '3',
    'fetch_retry_backoff_ms' => '350',
    'fetch_user_agent' => 'Mozilla/5.0 (compatible; VitoBot/1.0; +https://example.com/bot)',
    'workflow_batch_size' => '8',
    'queue_retry_delay_seconds' => '60',
    'queue_max_attempts' => '3',
    'queue_source_cooldown_seconds' => '180',
    'visit_excluded_ips' => '',
    'auto_title_mode' => 'template',
    'auto_title_min_year_offset' => '0',
    'auto_title_max_year_offset' => '1',
    'auto_title_brands' => "Toyota\nBMW\nMercedes\nAudi\nPorsche\nTesla\nHyundai\nKia\nFord\nNissan\nVolvo\nLexus",
    'auto_title_models' => "SUV\nSedan\nCoupe\nEV Crossover\nHybrid SUV\nPerformance Hatchback\nElectric Sedan\nLuxury Wagon\nPremium Crossover",
    'auto_title_modifiers' => "Review\nSpecs\nPrice\nComparison\nBuying Guide\nOwnership Cost",
    'auto_title_audiences' => "Smart Buyers\nFirst-Time Premium Buyers\nTech-Focused Drivers\nFamily Buyers",
    'auto_title_angles' => "Full Review and Buyer Guide\nLong-Term Ownership Analysis\nReal-World Efficiency Test\nDaily Driving Impression\nSmart Technology Deep Dive\nComparison and Value Breakdown\nReliability, Resale, and Total Cost Breakdown",
    'auto_title_templates' => "{year} {brand} {model} {modifier}: {angle} for {audience}\n{year} {brand} {model} {modifier} — {angle} ({audience})\n{year} {brand} {model}: {modifier} + {angle}",
    'auto_title_fixed_titles' => '',
    'seo_home_title' => SITE_TITLE,
    'seo_home_description' => 'Automotive reviews, guides, and practical car ownership tips.',
    'seo_article_title_suffix' => SITE_TITLE,
    'seo_default_robots' => 'index,follow',
    'seo_default_og_image' => '',
    'seo_twitter_site' => '',
    'seo_image_alt_suffix' => ' - car image',
    'seo_image_title_suffix' => ' - photo',
    'ads_enabled' => '0',
    'ads_injection_mode' => 'smart',
    'ads_paragraph_interval' => '4',
    'ads_max_units_per_article' => '2',
    'ads_min_words_before_first_injection' => '180',
    'ads_label_text' => 'Sponsored',
    'ads_html_code' => '<div class="ad-unit-inner">Place your ad code here</div>'
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
