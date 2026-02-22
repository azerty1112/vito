<?php
require_once 'functions.php';

header('Content-Type: application/xml; charset=UTF-8');

$pdo = db_connect();
$baseUrl = getSiteBaseUrl();
if ($baseUrl === '') {
    $baseUrl = 'http://localhost';
}

$publicationName = SITE_TITLE;
$publicationLanguage = 'ar';

$urls = [];
$urls[] = [
    'loc' => $baseUrl . '/index.php',
    'lastmod' => date('c'),
    'changefreq' => 'hourly',
    'priority' => '1.0',
    'news' => null,
];

$stmt = $pdo->query("SELECT title, slug, category, published_at FROM articles ORDER BY datetime(published_at) DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $publishedAt = trim((string)($row['published_at'] ?? ''));
    $title = trim((string)($row['title'] ?? ''));
    $category = trim((string)($row['category'] ?? ''));

    $timestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
    if ($timestamp === false) {
        $timestamp = time();
    }

    $lastmod = date('c', $timestamp);
    $publicationDate = date('c', $timestamp);
    $publicationDay = date('l', $timestamp);

    $urls[] = [
        'loc' => $baseUrl . '/index.php?slug=' . rawurlencode((string)$row['slug']),
        'lastmod' => $lastmod,
        'changefreq' => 'daily',
        'priority' => '0.8',
        'news' => [
            'title' => $title !== '' ? $title : 'Untitled',
            'date' => $publicationDate,
            'day' => $publicationDay,
            'keywords' => $category !== '' ? $category . ', ' . $publicationDay : $publicationDay,
        ],
    ];
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
<?php foreach ($urls as $item): ?>
    <url>
        <loc><?= e($item['loc']) ?></loc>
        <lastmod><?= e($item['lastmod']) ?></lastmod>
        <changefreq><?= e($item['changefreq']) ?></changefreq>
        <priority><?= e($item['priority']) ?></priority>
<?php if (is_array($item['news'])): ?>
        <news:news>
            <news:publication>
                <news:name><?= e($publicationName) ?></news:name>
                <news:language><?= e($publicationLanguage) ?></news:language>
            </news:publication>
            <news:publication_date><?= e($item['news']['date']) ?></news:publication_date>
            <news:title><?= e($item['news']['title']) ?></news:title>
            <news:keywords><?= e($item['news']['keywords']) ?></news:keywords>
        </news:news>
<?php endif; ?>
    </url>
<?php endforeach; ?>
</urlset>
