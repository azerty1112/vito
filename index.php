<?php require_once 'functions.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(SITE_TITLE) ?> - Latest Car Articles</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php"><?= e(SITE_TITLE) ?></a>
        <form class="d-flex" method="get" action="index.php">
            <input type="search" name="q" class="form-control me-2" placeholder="Search articles..." value="<?= e($_GET['q'] ?? '') ?>">
            <button class="btn btn-outline-light" type="submit">Search</button>
        </form>
    </div>
</nav>

<?php
$pdo = db_connect();
$search = trim($_GET['q'] ?? '');
$slug = trim($_GET['slug'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;
?>

<div class="container py-5">
<?php if ($slug !== ''): ?>
    <?php
    $stmt = $pdo->prepare("SELECT * FROM articles WHERE slug = ?");
    $stmt->execute([$slug]);
    $art = $stmt->fetch(PDO::FETCH_ASSOC);
    ?>

    <?php if ($art): ?>
        <a href="index.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="btn btn-sm btn-outline-secondary mb-3">&larr; Back to articles</a>
        <article class="bg-white p-4 rounded shadow-sm">
            <?= $art['content'] ?>
            <hr>
            <p class="text-muted mb-0">Estimated reading time: <?= estimateReadingTime($art['content']) ?> min</p>
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
                        <li class="list-group-item"><a href="?slug=<?= e($item['slug']) ?>"><?= e($item['title']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-warning">Article not found.</div>
    <?php endif; ?>

<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="display-6 mb-0">Latest Automotive Articles</h1>
        <span class="badge bg-dark fs-6"><?= (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn() ?> articles</span>
    </div>

    <?php
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = "WHERE title LIKE :search OR excerpt LIKE :search";
        $params['search'] = '%' . $search . '%';
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM articles $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT * FROM articles $where ORDER BY id DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    if ($search !== '') {
        $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <?php if (!$articles): ?>
        <div class="alert alert-info">No articles found<?= $search !== '' ? ' for "' . e($search) . '"' : '' ?>.</div>
    <?php else: ?>
        <p class="text-muted">Showing <?= count($articles) ?> of <?= $total ?> result(s).</p>
        <div class="row row-cols-1 row-cols-md-3 g-4">
            <?php foreach ($articles as $row): ?>
                <div class="col">
                    <div class="card h-100 shadow-sm">
                        <img src="<?= e($row['image']) ?>" class="card-img-top" style="height:200px;object-fit:cover" alt="<?= e($row['title']) ?>">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title"><?= e($row['title']) ?></h5>
                            <p class="card-text text-muted"><?= e($row['excerpt']) ?></p>
                            <small class="text-secondary mb-3">Published: <?= e($row['published_at']) ?></small>
                            <a href="?slug=<?= e($row['slug']) ?>" class="btn btn-primary mt-auto">Read Full Article →</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php
                        $query = ['page' => $i];
                        if ($search !== '') {
                            $query['q'] = $search;
                        }
                        $url = 'index.php?' . http_build_query($query);
                        ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e($url) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
