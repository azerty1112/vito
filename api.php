<?php
require_once 'functions.php';
publishAutoArticleBySchedule();

header('Content-Type: application/json; charset=utf-8');

$pdo = db_connect();
$endpoint = $_GET['endpoint'] ?? 'articles';

if ($endpoint === 'stats') {
    $totalArticles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    $totalSources = (int)$pdo->query("SELECT COUNT(*) FROM rss_sources")->fetchColumn();
    $totalWebSources = (int)$pdo->query("SELECT COUNT(*) FROM web_sources")->fetchColumn();
    $latestPublish = $pdo->query("SELECT MAX(published_at) FROM articles")->fetchColumn();

    echo json_encode([
        'site' => SITE_TITLE,
        'total_articles' => $totalArticles,
        'total_rss_sources' => $totalSources,
        'total_web_sources' => $totalWebSources,
        'selected_content_workflow' => getSelectedContentWorkflow(),
        'latest_publish' => $latestPublish,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($endpoint === 'article') {
    $slug = trim($_GET['slug'] ?? '');
    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing slug parameter.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, title, slug, excerpt, content, image, category, published_at FROM articles WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $article = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$article) {
        http_response_code(404);
        echo json_encode(['error' => 'Article not found.']);
        exit;
    }

    $article['reading_time_min'] = estimateReadingTime($article['content']);
    echo json_encode($article, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$publishedFrom = normalizeDateInput($_GET['published_from'] ?? '');
$publishedTo = normalizeDateInput($_GET['published_to'] ?? '');
if ($publishedFrom !== '' && $publishedTo !== '' && $publishedFrom > $publishedTo) {
    [$publishedFrom, $publishedTo] = [$publishedTo, $publishedFrom];
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int)($_GET['per_page'] ?? 10)));
$sort = $_GET['sort'] ?? 'newest';

$sortMap = [
    'newest' => 'id DESC',
    'oldest' => 'id ASC',
    'title_asc' => 'title ASC',
    'title_desc' => 'title DESC',
    'relevance' => 'id DESC',
];
$orderBy = $sortMap[$sort] ?? $sortMap['newest'];

$clauses = [];
if ($q !== '') {
    $clauses[] = '(title LIKE :q OR excerpt LIKE :q OR content LIKE :q)';
}
if ($category !== '') {
    $clauses[] = 'category = :category';
}
if ($publishedFrom !== '') {
    $clauses[] = 'DATE(published_at) >= :published_from';
}
if ($publishedTo !== '') {
    $clauses[] = 'DATE(published_at) <= :published_to';
}
$where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM articles $where");
if ($q !== '') {
    $countStmt->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
}
if ($category !== '') {
    $countStmt->bindValue(':category', $category, PDO::PARAM_STR);
}
if ($publishedFrom !== '') {
    $countStmt->bindValue(':published_from', $publishedFrom, PDO::PARAM_STR);
}
if ($publishedTo !== '') {
    $countStmt->bindValue(':published_to', $publishedTo, PDO::PARAM_STR);
}
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

if ($sort === 'relevance' && $q !== '') {
    $orderBy = '(CASE WHEN title LIKE :q_exact THEN 100 ELSE 0 END + CASE WHEN excerpt LIKE :q_exact THEN 45 ELSE 0 END + CASE WHEN content LIKE :q_exact THEN 25 ELSE 0 END + CASE WHEN title LIKE :q THEN 20 ELSE 0 END + CASE WHEN excerpt LIKE :q THEN 10 ELSE 0 END) DESC, id DESC';
}

$stmt = $pdo->prepare("SELECT id, title, slug, excerpt, image, category, published_at, content FROM articles $where ORDER BY $orderBy LIMIT :limit OFFSET :offset");
if ($q !== '') {
    $stmt->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
    if ($sort === 'relevance') {
        $stmt->bindValue(':q_exact', $q . '%', PDO::PARAM_STR);
    }
}
if ($category !== '') {
    $stmt->bindValue(':category', $category, PDO::PARAM_STR);
}
if ($publishedFrom !== '') {
    $stmt->bindValue(':published_from', $publishedFrom, PDO::PARAM_STR);
}
if ($publishedTo !== '') {
    $stmt->bindValue(':published_to', $publishedTo, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$articles = [];
foreach ($rows as $row) {
    $row['reading_time_min'] = estimateReadingTime($row['content']);
    unset($row['content']);
    $articles[] = $row;
}

echo json_encode([
    'endpoint' => 'articles',
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'total_pages' => $totalPages,
    'filters' => [
        'q' => $q,
        'category' => $category,
        'published_from' => $publishedFrom,
        'published_to' => $publishedTo,
        'sort' => $sort,
    ],
    'items' => $articles,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
