<?php
require_once 'functions.php';

header('Content-Type: application/xml; charset=UTF-8');

$pdo = db_connect();
$baseUrl = getSiteBaseUrl();
if ($baseUrl === '') {
    $baseUrl = 'http://localhost';
}

$urls = [];
$urls[] = [
    'loc' => $baseUrl . '/index.php',
    'lastmod' => date('c'),
    'changefreq' => 'hourly',
    'priority' => '1.0',
];

$stmt = $pdo->query("SELECT slug, published_at FROM articles ORDER BY datetime(published_at) DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $publishedAt = trim((string)($row['published_at'] ?? ''));
    $lastmod = date('c');
    if ($publishedAt !== '') {
        $timestamp = strtotime($publishedAt);
        if ($timestamp !== false) {
            $lastmod = date('c', $timestamp);
        }
    }

    $urls[] = [
        'loc' => $baseUrl . '/index.php?slug=' . rawurlencode((string)$row['slug']),
        'lastmod' => $lastmod,
        'changefreq' => 'weekly',
        'priority' => '0.8',
    ];
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $item): ?>
    <url>
        <loc><?= e($item['loc']) ?></loc>
        <lastmod><?= e($item['lastmod']) ?></lastmod>
        <changefreq><?= e($item['changefreq']) ?></changefreq>
        <priority><?= e($item['priority']) ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
