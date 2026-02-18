<?php require_once 'functions.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(SITE_TITLE) ?> - Latest Car Articles</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --brand-primary: #0d6efd;
            --brand-dark: #111827;
            --surface: #ffffff;
            --app-bg: radial-gradient(circle at top right, #e9f2ff 0%, #f7f9fc 45%, #f3f4f6 100%);
        }

        body {
            background: var(--app-bg);
            color: #1f2937;
            min-height: 100vh;
        }

        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.4px;
        }

        .navbar {
            background: linear-gradient(120deg, #0f172a 0%, #111827 65%, #1f2937 100%) !important;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.3);
        }

        .page-hero {
            background: linear-gradient(120deg, rgba(13, 110, 253, 0.12), rgba(15, 23, 42, 0.08));
            border: 1px solid rgba(13, 110, 253, 0.15);
            border-radius: 1rem;
            padding: 1.25rem;
        }

        .stats-card {
            background: var(--surface);
            border-radius: 1rem;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.18);
        }

        .card,
        .list-group,
        article,
        .alert {
            border: 1px solid rgba(148, 163, 184, 0.2);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }

        .card {
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            border-radius: 1rem;
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 35px rgba(13, 110, 253, 0.18);
        }

        .card-img-top {
            filter: saturate(1.05);
        }

        .featured-spotlight {
            border-radius: 1rem;
            box-shadow: 0 18px 35px rgba(13, 110, 253, 0.23);
        }

        .btn-primary {
            background: linear-gradient(120deg, #2563eb, #1d4ed8);
            border: none;
        }

        .pagination .page-link {
            border-radius: 0.6rem;
            margin-inline: 2px;
        }

        .toolbar-form {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 0.85rem;
            padding: 0.6rem;
            backdrop-filter: blur(4px);
        }

        .toolbar-form .form-control,
        .toolbar-form .form-select {
            min-width: 165px;
            border-color: rgba(148, 163, 184, 0.45);
        }

        .toolbar-form .form-control:focus,
        .toolbar-form .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.2);
        }

        .content-shell {
            max-width: 1180px;
        }

        .article-content {
            line-height: 1.8;
        }

        .article-content img {
            max-width: 100%;
            border-radius: 0.7rem;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: #eff6ff;
            border: 1px solid #dbeafe;
            color: #1e3a8a;
            padding: 0.3rem 0.6rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 500;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.65rem;
            margin-bottom: 0.85rem;
        }

        .list-group-item {
            border: 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .list-group-item:last-child {
            border-bottom: 0;
        }

        @media (max-width: 767px) {
            .toolbar-form {
                width: 100%;
            }

            .toolbar-form .form-control,
            .toolbar-form .form-select {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container flex-wrap gap-2">
        <a class="navbar-brand" href="index.php"><?= e(SITE_TITLE) ?></a>
        <form class="toolbar-form d-flex gap-2 flex-wrap" method="get" action="index.php">
            <input type="search" name="q" class="form-control" placeholder="Search articles..." value="<?= e($_GET['q'] ?? '') ?>">
            <select name="sort" class="form-select" aria-label="Sort articles">
                <option value="newest" <?= (($_GET['sort'] ?? 'newest') === 'newest') ? 'selected' : '' ?>>Newest</option>
                <option value="oldest" <?= (($_GET['sort'] ?? '') === 'oldest') ? 'selected' : '' ?>>Oldest</option>
                <option value="title_asc" <?= (($_GET['sort'] ?? '') === 'title_asc') ? 'selected' : '' ?>>Title A-Z</option>
                <option value="title_desc" <?= (($_GET['sort'] ?? '') === 'title_desc') ? 'selected' : '' ?>>Title Z-A</option>
                <option value="reading_fast" <?= (($_GET['sort'] ?? '') === 'reading_fast') ? 'selected' : '' ?>>Shortest Read</option>
            </select>
            <select name="category" class="form-select" aria-label="Filter by category">
                <option value="">All categories</option>
                <?php
                $categoryParam = trim($_GET['category'] ?? '');
                $categoryStmt = db_connect()->query("SELECT DISTINCT category FROM articles WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
                $categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($categories as $categoryOpt):
                ?>
                    <option value="<?= e($categoryOpt) ?>" <?= $categoryParam === $categoryOpt ? 'selected' : '' ?>><?= e($categoryOpt) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="view" value="<?= e($_GET['view'] ?? 'grid') ?>">
            <button class="btn btn-outline-light" type="submit">Apply</button>
        </form>
    </div>
</nav>

<?php
$pdo = db_connect();
$search = trim($_GET['q'] ?? '');
$slug = trim($_GET['slug'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$sort = $_GET['sort'] ?? 'newest';
$category = trim($_GET['category'] ?? '');
$view = ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
$perPage = 9;
$sortMap = [
    'newest' => 'id DESC',
    'oldest' => 'id ASC',
    'title_asc' => 'title ASC',
    'title_desc' => 'title DESC',
    'reading_fast' => 'LENGTH(content) ASC',
];
$orderBy = $sortMap[$sort] ?? $sortMap['newest'];
$baseQuery = [];
if ($search !== '') {
    $baseQuery['q'] = $search;
}
if (isset($sortMap[$sort])) {
    $baseQuery['sort'] = $sort;
}
if ($category !== '') {
    $baseQuery['category'] = $category;
}
$baseQuery['view'] = $view;
?>

<div class="container content-shell py-5">
<?php if ($slug !== ''): ?>
    <?php
    $stmt = $pdo->prepare("SELECT * FROM articles WHERE slug = ?");
    $stmt->execute([$slug]);
    $art = $stmt->fetch(PDO::FETCH_ASSOC);
    ?>

    <?php if ($art): ?>
        <a href="index.php<?= $baseQuery ? '?' . http_build_query($baseQuery) : '' ?>" class="btn btn-sm btn-outline-secondary mb-3">&larr; Back to articles</a>
        <article class="article-content bg-white p-4 rounded shadow-sm">
            <?= $art['content'] ?>
            <hr>
            <div class="d-flex flex-wrap gap-2">
                <span class="meta-pill">⏱ <?= estimateReadingTime($art['content']) ?> min read</span>
                <span class="meta-pill">🏷 <?= e($art['category'] ?: 'General') ?></span>
                <span class="meta-pill">📅 <?= e($art['published_at']) ?></span>
            </div>
        </article>

        <?php
        $relatedStmt = $pdo->prepare("SELECT title, slug FROM articles WHERE id != ? ORDER BY id DESC LIMIT 3");
        $relatedStmt->execute([$art['id']]);
        $related = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if ($related): ?>
            <section class="mt-4">
                <h3 class="h5">Related Articles</h3>
                <ul class="list-group">
                    <?php foreach ($related as $item): ?>
                        <?php $relatedQuery = array_merge($baseQuery, ['slug' => $item['slug']]); ?>
                        <li class="list-group-item"><a href="?<?= e(http_build_query($relatedQuery)) ?>"><?= e($item['title']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-warning">Article not found.</div>
    <?php endif; ?>

<?php else: ?>
    <div class="page-hero d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <span class="badge text-bg-primary mb-2">Automotive Insights</span>
            <h1 class="display-6 mb-1">Latest Automotive Articles</h1>
            <p class="text-muted mb-0">Explore curated guides, reviews, and practical tips from the car world.</p>
        </div>
        <div class="btn-group" role="group" aria-label="View switch">
            <?php $gridQuery = array_merge($baseQuery, ['view' => 'grid']); ?>
            <?php $listQuery = array_merge($baseQuery, ['view' => 'list']); ?>
            <a class="btn btn-sm <?= $view === 'grid' ? 'btn-dark' : 'btn-outline-dark' ?>" href="index.php?<?= e(http_build_query($gridQuery)) ?>">Grid</a>
            <a class="btn btn-sm <?= $view === 'list' ? 'btn-dark' : 'btn-outline-dark' ?>" href="index.php?<?= e(http_build_query($listQuery)) ?>">List</a>
        </div>
    </div>

    <?php
    $clauses = [];
    $params = [];
    if ($search !== '') {
        $clauses[] = "(title LIKE :search OR excerpt LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }
    if ($category !== '') {
        $clauses[] = "category = :category";
        $params['category'] = $category;
    }
    $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM articles $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalArticles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT * FROM articles $where ORDER BY $orderBy LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    if ($search !== '') {
        $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
    }
    if ($category !== '') {
        $stmt->bindValue(':category', $category, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $featured = null;
    if ($page === 1) {
        $featureStmt = $pdo->prepare("SELECT * FROM articles $where ORDER BY id DESC LIMIT 1");
        if ($search !== '') {
            $featureStmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
        }
        if ($category !== '') {
            $featureStmt->bindValue(':category', $category, PDO::PARAM_STR);
        }
        $featureStmt->execute();
        $featured = $featureStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $avgReading = 0;
    if ($articles) {
        $sumReading = 0;
        foreach ($articles as $entry) {
            $sumReading += estimateReadingTime($entry['content']);
        }
        $avgReading = (int)ceil($sumReading / count($articles));
    }
    ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="stats-card p-3"><small class="text-muted d-block">Total articles</small><strong class="fs-4"><?= $totalArticles ?></strong></div></div>
        <div class="col-md-4"><div class="stats-card p-3"><small class="text-muted d-block">Filtered results</small><strong class="fs-4"><?= $total ?></strong></div></div>
        <div class="col-md-4"><div class="stats-card p-3"><small class="text-muted d-block">Avg read time (page)</small><strong class="fs-4"><?= $avgReading ?> min</strong></div></div>
    </div>

    <?php if ($featured): ?>
        <?php $featuredQuery = array_merge($baseQuery, ['slug' => $featured['slug']]); ?>
        <section class="featured-spotlight mb-4 p-4 text-white" style="background:linear-gradient(120deg,#0d6efd,#212529);">
            <small class="text-uppercase">Featured article</small>
            <h2 class="h4 mt-2"><?= e($featured['title']) ?></h2>
            <p class="mb-3"><?= e($featured['excerpt']) ?></p>
            <a class="btn btn-light btn-sm" href="?<?= e(http_build_query($featuredQuery)) ?>">Read featured</a>
        </section>
    <?php endif; ?>

    <?php if (!$articles): ?>
        <div class="alert alert-info">No articles found<?= $search !== '' ? ' for "' . e($search) . '"' : '' ?>.</div>
    <?php elseif ($view === 'list'): ?>
        <div class="list-group shadow-sm">
            <?php foreach ($articles as $row): ?>
                <?php $articleQuery = array_merge($baseQuery, ['slug' => $row['slug']]); ?>
                <a href="?<?= e(http_build_query($articleQuery)) ?>" class="list-group-item list-group-item-action py-3">
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1"><?= e($row['title']) ?></h5>
                        <small class="text-muted"><?= estimateReadingTime($row['content']) ?> min</small>
                    </div>
                    <p class="mb-1 text-muted"><?= e($row['excerpt']) ?></p>
                    <small class="text-secondary d-flex flex-wrap gap-2"><span class="meta-pill">🏷 <?= e($row['category'] ?: 'General') ?></span><span class="meta-pill">📅 <?= e($row['published_at']) ?></span></small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="results-header">
            <p class="text-muted mb-0">Showing <strong><?= count($articles) ?></strong> of <strong><?= $total ?></strong> result(s).</p>
            <span class="meta-pill">Page <?= $page ?> / <?= $totalPages ?></span>
        </div>
        <div class="row row-cols-1 row-cols-md-3 g-4">
            <?php foreach ($articles as $row): ?>
                <div class="col">
                    <div class="card h-100 shadow-sm">
                        <img src="<?= e($row['image']) ?>" class="card-img-top" style="height:200px;object-fit:cover" alt="<?= e($row['title']) ?>">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title"><?= e($row['title']) ?></h5>
                            <p class="card-text text-muted"><?= e($row['excerpt']) ?></p>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <span class="meta-pill">📅 <?= e($row['published_at']) ?></span>
                                <span class="meta-pill">🏷 <?= e($row['category'] ?: 'General') ?></span>
                                <span class="meta-pill">⏱ <?= estimateReadingTime($row['content']) ?> min</span>
                            </div>
                            <?php $articleQuery = array_merge($baseQuery, ['slug' => $row['slug']]); ?>
                            <a href="?<?= e(http_build_query($articleQuery)) ?>" class="btn btn-primary mt-auto">Read Full Article →</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <ul class="pagination mb-0">
                <?php
                $prevPage = max(1, $page - 1);
                $prevUrl = 'index.php?' . http_build_query(array_merge($baseQuery, ['page' => $prevPage]));
                ?>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($prevUrl) ?>">&larr; Previous</a>
                </li>
            </ul>
            <ul class="pagination mb-0">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php $url = 'index.php?' . http_build_query(array_merge($baseQuery, ['page' => $i])); ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e($url) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
            <ul class="pagination mb-0">
                <?php
                $nextPage = min($totalPages, $page + 1);
                $nextUrl = 'index.php?' . http_build_query(array_merge($baseQuery, ['page' => $nextPage]));
                ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($nextUrl) ?>">Next &rarr;</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
