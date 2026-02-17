<?php
session_start();
define('DB_FILE', __DIR__ . '/data/data.db');
define('SITE_TITLE', 'AutoCar Niche');
define('PASSWORD_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'); // admin123

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

// إعدادات افتراضية
$defaults = [
    'min_words' => '1200',
    'auto_publish' => '1',
    'daily_limit' => '5'
];
foreach ($defaults as $k => $v) {
    $pdo->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (?,?)")->execute([$k, $v]);
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
?>