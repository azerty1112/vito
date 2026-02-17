<?php 
require_once 'functions.php';
if (!isset($_SESSION['logged']) && $_POST['pass'] ?? '' !== '') {
    if (password_verify($_POST['pass'], PASSWORD_HASH)) $_SESSION['logged'] = true;
}
if (!isset($_SESSION['logged'])) {
    echo '<form method="post" class="mt-5"><input type="password" name="pass" class="form-control" placeholder="Password"><button class="btn btn-primary mt-2">Login</button></form>'; exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel - <?=SITE_TITLE?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<div class="container py-5">
    <h1 class="mb-4"><i class="bi bi-car-front"></i> <?=SITE_TITLE?> Control Panel</h1>
    
    <div class="row">
        <div class="col-md-4">
            <div class="card bg-secondary mb-3">
                <div class="card-body">
                    <h5>Add Titles Manually</h5>
                    <form method="post">
                        <textarea name="titles" class="form-control" rows="6" placeholder="One title per line"></textarea><br>
                        <button name="add_titles" class="btn btn-success">Add to Queue & Generate</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <button onclick="location.reload();window.location='?fetch=1'" class="btn btn-primary btn-lg mb-3">🔄 Fetch New Titles from RSS & Generate</button>
            
            <h5>Recent Articles (<?= db_connect()->query("SELECT COUNT(*) FROM articles")->fetchColumn() ?>)</h5>
            <table class="table table-dark table-striped">
                <thead><tr><th>Title</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                <?php 
                $stmt = db_connect()->query("SELECT * FROM articles ORDER BY id DESC LIMIT 10");
                while($row = $stmt->fetch()) {
                    echo "<tr><td>{$row['title']}</td><td>{$row['published_at']}</td><td><a href='../index.php?slug={$row['slug']}' target='_blank' class='btn btn-sm btn-info'>View</a></td></tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php
    if (isset($_POST['add_titles'])) {
        $titles = array_filter(array_map('trim', explode("\n", $_POST['titles'])));
        foreach ($titles as $t) {
            if (!articleExists($t)) {
                $data = generateArticle($t);
                saveArticle($t, $data);
            }
        }
        echo '<div class="alert alert-success">Articles generated and published successfully!</div>';
    }
    
    if (isset($_GET['fetch'])) {
        $pdo = db_connect();
        $sources = $pdo->query("SELECT url FROM rss_sources")->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($sources as $url) {
            $xml = @simplexml_load_file($url);
            if ($xml) {
                foreach ($xml->channel->item as $item) {
                    $t = trim((string)$item->title);
                    if ($t && !articleExists($t) && $count < 5) {
                        $data = generateArticle($t);
                        saveArticle($t, $data);
                        $count++;
                    }
                }
            }
        }
        echo "<div class='alert alert-info'>Fetched and published $count new articles automatically!</div>";
    }
    ?>
    
    <hr>
    <a href="index.php" class="btn btn-outline-light" target="_blank">View Public Site →</a>
    <a href="?logout=1" class="btn btn-danger float-end">Logout</a>
</div>
</body>
</html>
<?php if(isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); } ?>