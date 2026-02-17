<?php
require_once 'config.php';

function slugify($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function e($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function getSetting($key, $default = null) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

function articleExists($title) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT id FROM articles WHERE title = ?");
    $stmt->execute([$title]);
    return $stmt->fetch() !== false;
}

function generateUniqueSlug($title) {
    $pdo = db_connect();
    $base = slugify($title);
    $base = $base !== '' ? $base : 'article';
    $slug = $base;
    $i = 2;

    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM articles WHERE slug = ?");
        $stmt->execute([$slug]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

function getRandomIntro($title) {
    $intros = [
        "The $title is making waves in the automotive world with its bold design and cutting-edge technology.",
        "If you're looking for the perfect blend of performance and luxury, the $title delivers on every level.",
        "The $title enters the market with a fresh identity, smarter features, and stronger road presence.",
        "Drivers interested in comfort and power will find the $title an impressive all-around package."
    ];
    return $intros[array_rand($intros)];
}

function generateArticle($title) {
    $model = preg_replace('/\b(202[0-9]|20[0-9]{2})\b/', '', $title);
    $isEV = stripos($title, 'EV') !== false || stripos($title, 'electric') !== false;

    $content = "<h1>" . htmlspecialchars($title) . "</h1>\n";
    $content .= "<p class='text-muted'>Published " . date('F j, Y') . " • AutoCar Niche</p>\n";
    $content .= "<img src='https://loremflickr.com/800/450/car," . urlencode(strtolower($model)) . "' class='img-fluid rounded mb-4' alt='" . htmlspecialchars($title) . "'>\n";
    $content .= "<p>" . getRandomIntro($title) . " This model redefines what drivers expect from a modern vehicle.</p>\n";

    $sections = ['Performance', 'Exterior Design', 'Interior Comfort', 'Technology', 'Safety Features'];
    foreach ($sections as $sec) {
        $paras = [
            "In terms of $sec, the " . $title . " impresses with its responsive handling and powerful acceleration.",
            "The advanced " . ($isEV ? "electric powertrain" : "engine") . " delivers exceptional efficiency and thrilling performance."
        ];
        $content .= "<h2>" . $sec . "</h2>\n<p>" . $paras[array_rand($paras)] . "</p>\n";
    }

    $content .= "<h2>Key Specifications</h2>\n<table class='table table-bordered'><tr><th>Engine</th><td>" . ($isEV ? "Dual Electric Motors" : "2.5L Turbo") . "</td></tr><tr><th>Horsepower</th><td>" . rand(250, 650) . " hp</td></tr><tr><th>0-60 mph</th><td>" . rand(35, 65) / 10 . " seconds</td></tr></table>\n";
    $content .= "<h2>Pros and Cons</h2>\n<ul><li><strong>Pros:</strong> Excellent build quality, modern tech, comfortable ride.</li><li><strong>Cons:</strong> Slightly higher price point, limited cargo in some trims.</li></ul>\n";
    $content .= "<p class='mt-5'>In conclusion, the $title is a standout choice for car enthusiasts who demand both style and substance. Highly recommended!</p>";

    $excerpt = substr(strip_tags($content), 0, 200) . '...';

    return [
        'content' => $content,
        'excerpt' => $excerpt,
        'image' => "https://loremflickr.com/800/450/car," . urlencode(strtolower($model))
    ];
}

function saveArticle($title, $data) {
    $pdo = db_connect();
    $slug = generateUniqueSlug($title);
    $stmt = $pdo->prepare("INSERT INTO articles (title, slug, content, image, excerpt, published_at, category) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$title, $slug, $data['content'], $data['image'], $data['excerpt'], date('Y-m-d H:i:s'), 'Auto']);
}

function estimateReadingTime($htmlContent) {
    $wordCount = str_word_count(strip_tags($htmlContent));
    return max(1, (int)ceil($wordCount / 220));
}

function csrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}
