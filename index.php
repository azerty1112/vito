<?php require_once 'functions.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?=SITE_TITLE?> - Latest Car Articles</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container"><a class="navbar-brand" href="#"><?=SITE_TITLE?></a></div>
</nav>

<div class="container py-5">
    <h1 class="display-5 mb-4">Latest Automotive Articles</h1>
    <div class="row row-cols-1 row-cols-md-3 g-4">
    <?php
    $stmt = db_connect()->query("SELECT * FROM articles ORDER BY id DESC LIMIT 12");
    while($row = $stmt->fetch()) {
        echo "
        <div class='col'>
            <div class='card h-100 shadow'>
                <img src='{$row['image']}' class='card-img-top' style='height:200px;object-fit:cover'>
                <div class='card-body'>
                    <h5 class='card-title'>{$row['title']}</h5>
                    <p class='card-text text-muted'>{$row['excerpt']}</p>
                    <a href='?slug={$row['slug']}' class='btn btn-primary'>Read Full Article →</a>
                </div>
            </div>
        </div>";
    }
    ?>
    </div>
</div>

<?php
if (isset($_GET['slug'])) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT * FROM articles WHERE slug = ?");
    $stmt->execute([$_GET['slug']]);
    $art = $stmt->fetch();
    if ($art) {
        echo "<div class='container py-5 bg-white shadow'><div class='container'>" . $art['content'] . "</div></div>";
    }
}
?>
</body>
</html>