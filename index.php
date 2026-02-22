<?php
require_once 'functions.php';
publishAutoArticleBySchedule();

$pdo = db_connect();
$slug = trim($_GET['slug'] ?? '');
$staticPage = trim((string)($_GET['page'] ?? ''));
$baseUrl = getSiteBaseUrl();
$pageTitle = (string)getSetting('seo_home_title', SITE_TITLE);
$pageDescription = (string)getSetting('seo_home_description', 'Automotive reviews, guides, and practical car ownership tips.');
$canonicalUrl = $baseUrl . '/index.php';
$openGraphType = 'website';
$openGraphImage = null;
$articleStructuredData = null;
$breadcrumbStructuredData = null;
$listingStructuredData = null;
$staticPages = [
    'about' => [
        'title' => 'About Us',
        'description' => 'Learn more about our automotive editorial mission, publishing standards, and audience-first approach.',
        'content' => [
            'We publish practical automotive content focused on real ownership questions: maintenance, buying, safety, and long-term value.',
            'Our editorial workflow combines automated research with final quality checks for readability, originality, and user usefulness.',
            'We prioritize clear language, transparent labeling, and content that helps readers make better car decisions.',
        ],
    ],
    'contact' => [
        'title' => 'Contact Us',
        'description' => 'Need support, want to report an issue, or discuss collaboration? Reach our editorial and support team.',
        'content' => [
            'For support and editorial requests, please email: contact@example.com',
            'For ad and business inquiries, please email: partnerships@example.com',
            'We review all messages and aim to reply within 2 business days.',
        ],
    ],
    'privacy' => [
        'title' => 'Privacy Policy',
        'description' => 'Read how we collect, use, and protect visitor data, cookies, and analytics information.',
        'content' => [
            'We may use analytics and advertising technologies (such as cookies and measurement scripts) to understand traffic and improve user experience.',
            'We do not intentionally collect sensitive personal information through public pages. If you contact us directly, we only use your information to respond.',
            'Third-party services (including ad providers) may process data according to their own privacy policies. You can disable cookies from your browser settings.',
        ],
    ],
    'terms' => [
        'title' => 'Terms of Use',
        'description' => 'Website terms covering acceptable use, intellectual property, disclaimers, and content usage.',
        'content' => [
            'By using this website, you agree to use the content for lawful and personal informational purposes only.',
            'All articles are provided for general information and do not replace professional legal, financial, or mechanical advice.',
            'We may update content and site policies at any time to maintain quality, compliance, and platform requirements.',
        ],
    ],
];

if (!isset($staticPages[$staticPage])) {
    $staticPage = '';
}
if ($pageDescription === '') {
    $pageDescription = 'Automotive reviews, guides, and practical car ownership tips.';
}
$pageDescription = mb_substr($pageDescription, 0, 160);
$robotsDirective = (string)getSetting('seo_default_robots', 'index,follow');
if (!in_array($robotsDirective, ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'], true)) {
    $robotsDirective = 'index,follow';
}
$defaultSocialImage = trim((string)getSetting('seo_default_og_image', ''));
$twitterSiteUsername = trim((string)getSetting('seo_twitter_site', ''));
$articleTitleSuffix = trim((string)getSetting('seo_article_title_suffix', SITE_TITLE));
if ($articleTitleSuffix === '') {
    $articleTitleSuffix = SITE_TITLE;
}
$imageAltSuffix = trim((string)getSetting('seo_image_alt_suffix', ' - car image'));
$imageTitleSuffix = trim((string)getSetting('seo_image_title_suffix', ' - photo'));
$googleAnalyticsId = trim((string)getSetting('google_analytics_id', ''));
$googleTagManagerId = trim((string)getSetting('google_tag_manager_id', ''));
$googleSiteVerification = trim((string)getSetting('google_site_verification', ''));
$bingSiteVerification = trim((string)getSetting('bing_site_verification', ''));
$metaPixelId = trim((string)getSetting('meta_pixel_id', ''));
$customHeadScripts = trim((string)getSetting('custom_head_scripts', ''));
$customBodyScripts = trim((string)getSetting('custom_body_scripts', ''));


function getPublicCategoryLabel($category) {
    $category = trim((string)$category);
    if ($category === '' || mb_strtolower($category, 'UTF-8') === 'auto') {
        return 'General';
    }

    return $category;
}

function buildImageSeoText($primary, $fallback, $suffix) {
    $base = trim((string)$primary);
    if ($base === '') {
        $base = trim((string)$fallback);
    }
    $suffix = trim((string)$suffix);
    if ($suffix !== '') {
        $base .= ' ' . ltrim($suffix, '- ');
    }
    return trim($base);
}

$socialImageAltText = buildImageSeoText($pageTitle, SITE_TITLE, $imageAltSuffix);

$isFilteredListing = $slug === '' && (
    trim((string)($_GET['q'] ?? '')) !== ''
    || trim((string)($_GET['category'] ?? '')) !== ''
    || trim((string)($_GET['published_from'] ?? '')) !== ''
    || trim((string)($_GET['published_to'] ?? '')) !== ''
    || (int)($_GET['page'] ?? 1) > 1
);
if ($isFilteredListing) {
    $robotsDirective = 'noindex,follow';
}

if ($staticPage !== '') {
    $pageTitle = $staticPages[$staticPage]['title'] . ' | ' . SITE_TITLE;
    $pageDescription = $staticPages[$staticPage]['description'];
    $canonicalUrl = $baseUrl . '/index.php?page=' . rawurlencode($staticPage);
    $openGraphType = 'website';
    if ($defaultSocialImage !== '') {
        $openGraphImage = $defaultSocialImage;
    }
}

if ($slug !== '') {
    $seoStmt = $pdo->prepare("SELECT title, slug, excerpt, content, image, published_at FROM articles WHERE slug = ? LIMIT 1");
    $seoStmt->execute([$slug]);
    $seoArticle = $seoStmt->fetch(PDO::FETCH_ASSOC);

    if ($seoArticle) {
        $pageTitle = $seoArticle['title'] . ' | ' . $articleTitleSuffix;
        $pageDescription = trim((string)($seoArticle['excerpt'] ?? ''));
        if ($pageDescription === '') {
            $pageDescription = mb_substr(trim(strip_tags((string)($seoArticle['content'] ?? ''))), 0, 160);
        }
        $pageDescription = mb_substr($pageDescription, 0, 160);
        $canonicalUrl = $baseUrl . '/index.php?slug=' . rawurlencode((string)$seoArticle['slug']);
        $openGraphType = 'article';
        $openGraphImage = trim((string)($seoArticle['image'] ?? '')) ?: ($defaultSocialImage !== '' ? $defaultSocialImage : null);
        $socialImageAltText = buildImageSeoText($seoArticle['title'] ?? '', SITE_TITLE, $imageAltSuffix);

        $articleStructuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $seoArticle['title'],
            'description' => $pageDescription,
            'author' => [
                '@type' => 'Organization',
                'name' => SITE_TITLE,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => SITE_TITLE,
            ],
            'datePublished' => date('c', strtotime((string)$seoArticle['published_at'])),
            'dateModified' => date('c', strtotime((string)$seoArticle['published_at'])),
            'mainEntityOfPage' => $canonicalUrl,
            'url' => $canonicalUrl,
        ];

        if ($openGraphImage) {
            $articleStructuredData['image'] = [$openGraphImage];
        }

        $breadcrumbStructuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Home',
                    'item' => $baseUrl . '/index.php',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => (string)$seoArticle['title'],
                    'item' => $canonicalUrl,
                ],
            ],
        ];
    }
}

$websiteStructuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => SITE_TITLE,
    'url' => $baseUrl . '/index.php',
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => $baseUrl . '/index.php?q={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
];

if ($slug === '' && $openGraphImage === null && $defaultSocialImage !== '') {
    $openGraphImage = $defaultSocialImage;
}

if ($slug === '' && $staticPage === '') {
    $latestForSchema = $pdo->query("SELECT title, slug FROM articles ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if ($latestForSchema) {
        $listingStructuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Automotive Articles',
            'itemListElement' => [],
        ];
        foreach ($latestForSchema as $position => $item) {
            $listingStructuredData['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'url' => $baseUrl . '/index.php?slug=' . rawurlencode((string)$item['slug']),
                'name' => (string)$item['title'],
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($pageDescription) ?>">
    <meta name="robots" content="<?= e($robotsDirective) ?>">
    <?php if ($googleSiteVerification !== ''): ?>
        <meta name="google-site-verification" content="<?= e($googleSiteVerification) ?>">
    <?php endif; ?>
    <?php if ($bingSiteVerification !== ''): ?>
        <meta name="msvalidate.01" content="<?= e($bingSiteVerification) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <meta property="og:type" content="<?= e($openGraphType) ?>">
    <meta property="og:url" content="<?= e($canonicalUrl) ?>">
    <?php if ($openGraphImage): ?>
        <meta property="og:image" content="<?= e($openGraphImage) ?>">
        <meta property="og:image:alt" content="<?= e($socialImageAltText) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($pageTitle) ?>">
    <meta name="twitter:description" content="<?= e($pageDescription) ?>">
    <?php if ($twitterSiteUsername !== ''): ?>
        <meta name="twitter:site" content="<?= e($twitterSiteUsername) ?>">
    <?php endif; ?>
    <?php if ($openGraphImage): ?>
        <meta name="twitter:image" content="<?= e($openGraphImage) ?>">
        <meta name="twitter:image:alt" content="<?= e($socialImageAltText) ?>">
    <?php endif; ?>
    <script type="application/ld+json"><?= json_encode($websiteStructuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php if ($articleStructuredData): ?>
        <script type="application/ld+json"><?= json_encode($articleStructuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php endif; ?>
    <?php if ($breadcrumbStructuredData): ?>
        <script type="application/ld+json"><?= json_encode($breadcrumbStructuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php endif; ?>
    <?php if ($listingStructuredData): ?>
        <script type="application/ld+json"><?= json_encode($listingStructuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php endif; ?>
    <?php if ($googleAnalyticsId !== ''): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($googleAnalyticsId) ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', <?= json_encode($googleAnalyticsId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
        </script>
    <?php endif; ?>
    <?php if ($googleTagManagerId !== ''): ?>
        <script>
            (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer',<?= json_encode($googleTagManagerId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
        </script>
    <?php endif; ?>
    <?php if ($metaPixelId !== ''): ?>
        <script>
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window, document,'script',
            'https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', <?= json_encode($metaPixelId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
            fbq('track', 'PageView');
        </script>
    <?php endif; ?>
    <?php if ($customHeadScripts !== ''): ?>
        <?= $customHeadScripts ?>
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --brand-primary: #f97316;
            --brand-dark: #111827;
            --surface: #ffffff;
            --app-bg: radial-gradient(circle at 10% 20%, #ffedd5 0%, #fff7ed 42%, #fef3c7 100%);
        }

        body {
            background: var(--app-bg);
            color: #1f2937;
            min-height: 100vh;
            position: relative;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            z-index: -1;
            filter: blur(20px);
            opacity: 0.4;
        }

        body::before {
            top: -90px;
            right: -70px;
            background: radial-gradient(circle, rgba(249, 115, 22, 0.7), rgba(249, 115, 22, 0));
        }

        body::after {
            bottom: -120px;
            left: -70px;
            background: radial-gradient(circle, rgba(251, 146, 60, 0.65), rgba(251, 146, 60, 0));
        }

        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.4px;
        }

        .navbar {
            background: linear-gradient(120deg, rgba(15, 23, 42, 0.92) 0%, rgba(17, 24, 39, 0.9) 65%, rgba(30, 41, 59, 0.9) 100%) !important;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.3);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(148, 163, 184, 0.25);
        }

        .stats-card {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border-radius: 1rem;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.1);
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
            box-shadow: 0 18px 35px rgba(249, 115, 22, 0.2);
        }

        .card-img-top {
            filter: saturate(1.05);
        }

        .btn-primary {
            background: linear-gradient(120deg, #ea580c, #f97316);
            border: none;
            box-shadow: 0 8px 20px rgba(234, 88, 12, 0.28);
        }

        .btn-primary:hover {
            background: linear-gradient(120deg, #c2410c, #ea580c);
        }

        .pagination .page-link {
            border-radius: 0.6rem;
            margin-inline: 2px;
        }

        .toolbar-strip {
            margin-top: 0.85rem;
            padding-inline: 0.25rem;
        }

        .toolbar-form {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 0.95rem;
            padding: 0.7rem;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
            width: 100%;
            display: grid;
            grid-template-columns: minmax(220px, 1.4fr) repeat(5, minmax(120px, 1fr)) auto;
            gap: 0.6rem;
            align-items: center;
        }

        .toolbar-form .form-control,
        .toolbar-form .form-select {
            min-width: 0;
            border-color: rgba(148, 163, 184, 0.45);
        }

        .toolbar-form input[type="search"] {
            min-width: 220px;
        }

        .toolbar-form .btn {
            min-width: 110px;
        }

        .toolbar-form .form-control:focus,
        .toolbar-form .form-select:focus {
            border-color: #fdba74;
            box-shadow: 0 0 0 0.2rem rgba(249, 115, 22, 0.2);
        }

        .content-shell {
            max-width: 1180px;
        }

        .hero-subtitle {
            max-width: 640px;
        }

        .article-content {
            line-height: 1.8;
        }

        .article-content h1,
        .article-content h2,
        .article-content h3 {
            color: #9a3412;
            font-weight: 700;
            line-height: 1.35;
        }

        .article-content p {
            font-size: 1.04rem;
            color: #334155;
        }

        .article-content p:first-of-type::first-letter {
            font-size: 2.1rem;
            font-weight: 700;
            color: #ea580c;
            float: left;
            line-height: 1;
            padding-right: 0.3rem;
            margin-top: 0.1rem;
        }

        .article-content blockquote {
            margin: 1.25rem 0;
            padding: 0.9rem 1rem;
            border-inline-start: 4px solid #f97316;
            background: #fff7ed;
            border-radius: 0.75rem;
            color: #7c2d12;
            font-style: italic;
        }

        .article-content ul,
        .article-content ol {
            padding-inline-start: 1.2rem;
        }

        .article-content img {
            max-width: 100%;
            border-radius: 0.7rem;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.18);
            margin-block: 0.9rem;
        }

        .article-header-panel {
            background: linear-gradient(120deg, #fff7ed 0%, #ffffff 60%);
            border: 1px solid #fed7aa;
            border-radius: 1rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .article-kicker {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.73rem;
            color: #c2410c;
            font-weight: 700;
        }

        .article-title {
            font-size: clamp(1.5rem, 2.6vw, 2.2rem);
            margin-top: 0.35rem;
            margin-bottom: 0.5rem;
            color: #7c2d12;
        }

        .article-excerpt {
            color: #57534e;
            margin-bottom: 0.7rem;
        }

        .hero-highlight {
            background: linear-gradient(90deg, #ea580c, #f97316);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .card-title {
            color: #1e293b;
        }

        .card:hover .card-title {
            color: #c2410c;
        }

        .inline-ad-unit {
            margin: 1.5rem 0;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: linear-gradient(160deg, #fff7ed, #ffffff 60%);
            border-radius: 0.9rem;
            padding: 0.9rem;
        }

        .inline-ad-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9a3412;
            margin-bottom: 0.45rem;
            font-weight: 700;
        }

        .ad-unit-inner {
            min-height: 80px;
            border: 1px dashed rgba(251, 146, 60, 0.7);
            border-radius: 0.75rem;
            padding: 0.9rem;
            color: #9a3412;
            background: rgba(255, 247, 237, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
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

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border-radius: 999px;
            padding: 0.36rem 0.7rem;
            font-size: 0.82rem;
            font-weight: 600;
        }

        .status-chip.ready {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .status-chip.warn {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .quick-categories {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .quick-categories a {
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            padding: 0.32rem 0.7rem;
            background: rgba(255, 255, 255, 0.85);
            color: #334155;
        }

        .quick-categories a.active {
            background: #ea580c;
            border-color: #ea580c;
            color: #fff;
        }

        .featured-spotlight {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 1rem;
            box-shadow: 0 18px 35px rgba(234, 88, 12, 0.24);
        }

        .featured-spotlight::after {
            content: "";
            position: absolute;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            right: -35px;
            top: -35px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.5), rgba(255, 255, 255, 0));
        }

        .app-footer {
            text-align: center;
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 2.2rem;
        }

        .policy-card {
            border-radius: 1rem;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            padding: 1.4rem;
        }

        .policy-card p {
            color: #334155;
            line-height: 1.8;
        }

        .footer-links {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
            margin-top: 0.4rem;
        }

        .footer-links a {
            color: #475569;
            text-decoration: none;
            font-weight: 500;
        }

        .footer-links a:hover {
            color: #c2410c;
        }

        .list-group-item {
            border: 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .list-group-item:last-child {
            border-bottom: 0;
        }

        @media (max-width: 1199px) {
            .toolbar-form {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .toolbar-form input[type="search"] {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 767px) {
            .toolbar-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container flex-wrap gap-2 py-2">
        <a class="navbar-brand" href="index.php"><?= e(SITE_TITLE) ?></a>
        <div class="d-flex gap-2 flex-wrap">
            <a href="index.php?page=about" class="btn btn-sm btn-outline-light">About</a>
            <a href="index.php?page=privacy" class="btn btn-sm btn-outline-light">Privacy</a>
            <a href="index.php?page=contact" class="btn btn-sm btn-outline-light">Contact</a>
        </div>
    </div>
</nav>

<?php if ($staticPage === ''): ?>
<section class="toolbar-strip">
    <div class="container content-shell">
        <form class="toolbar-form" method="get" action="index.php">
            <input type="search" name="q" class="form-control" placeholder="Search articles..." value="<?= e($_GET['q'] ?? '') ?>">
            <select name="sort" class="form-select" aria-label="Sort articles">
                <option value="newest" <?= (($_GET['sort'] ?? 'newest') === 'newest') ? 'selected' : '' ?>>Newest</option>
                <option value="oldest" <?= (($_GET['sort'] ?? '') === 'oldest') ? 'selected' : '' ?>>Oldest</option>
                <option value="title_asc" <?= (($_GET['sort'] ?? '') === 'title_asc') ? 'selected' : '' ?>>Title A-Z</option>
                <option value="title_desc" <?= (($_GET['sort'] ?? '') === 'title_desc') ? 'selected' : '' ?>>Title Z-A</option>
                <option value="reading_fast" <?= (($_GET['sort'] ?? '') === 'reading_fast') ? 'selected' : '' ?>>Shortest Read</option>
                <option value="relevance" <?= (($_GET['sort'] ?? '') === 'relevance') ? 'selected' : '' ?>>Smart Relevance</option>
            </select>
            <select name="category" class="form-select" aria-label="Filter by category">
                <option value="">All categories</option>
                <?php
                $categoryParam = trim($_GET['category'] ?? '');
                $categoryStmt = db_connect()->query("SELECT DISTINCT category FROM articles WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
                $categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($categories as $categoryOpt):
                ?>
                    <option value="<?= e($categoryOpt) ?>" <?= $categoryParam === $categoryOpt ? 'selected' : '' ?>><?= e(getPublicCategoryLabel($categoryOpt)) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="published_from" class="form-control" value="<?= e($_GET['published_from'] ?? '') ?>" aria-label="Published from">
            <input type="date" name="published_to" class="form-control" value="<?= e($_GET['published_to'] ?? '') ?>" aria-label="Published to">
            <select name="per_page" class="form-select" aria-label="Articles per page">
                <?php $perPageRequest = (int)($_GET['per_page'] ?? 9); ?>
                <?php foreach ([6, 9, 12, 18] as $perPageOption): ?>
                    <option value="<?= $perPageOption ?>" <?= $perPageRequest === $perPageOption ? 'selected' : '' ?>><?= $perPageOption ?> / page</option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="view" value="<?= e($_GET['view'] ?? 'grid') ?>">
            <button class="btn btn-primary" type="submit">Apply</button>
        </form>
    </div>
</section>
<?php endif; ?>

<?php
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$sort = $_GET['sort'] ?? 'newest';
$category = trim($_GET['category'] ?? '');
$publishedFrom = normalizeDateInput($_GET['published_from'] ?? '');
$publishedTo = normalizeDateInput($_GET['published_to'] ?? '');
if ($publishedFrom !== '' && $publishedTo !== '' && $publishedFrom > $publishedTo) {
    [$publishedFrom, $publishedTo] = [$publishedTo, $publishedFrom];
}
$view = ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
$perPage = (int)($_GET['per_page'] ?? 9);
$perPageAllowed = [6, 9, 12, 18];
if (!in_array($perPage, $perPageAllowed, true)) {
    $perPage = 9;
}
$sortMap = [
    'newest' => 'id DESC',
    'oldest' => 'id ASC',
    'title_asc' => 'title ASC',
    'title_desc' => 'title DESC',
    'reading_fast' => 'LENGTH(content) ASC',
    'relevance' => 'id DESC',
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
if ($publishedFrom !== '') {
    $baseQuery['published_from'] = $publishedFrom;
}
if ($publishedTo !== '') {
    $baseQuery['published_to'] = $publishedTo;
}
$baseQuery['view'] = $view;
$baseQuery['per_page'] = $perPage;
?>

<div class="container content-shell py-5">
<?php if ($staticPage !== ''): ?>
    <?php $selectedPage = $staticPages[$staticPage]; ?>
    <?php recordPageVisit('page:' . $staticPage, 'Static Page: ' . $selectedPage['title']); ?>
    <section class="policy-card">
        <h1 class="h3 mb-3"><?= e($selectedPage['title']) ?></h1>
        <?php foreach ($selectedPage['content'] as $block): ?>
            <p><?= e($block) ?></p>
        <?php endforeach; ?>
        <?php if ($staticPage === 'privacy'): ?>
            <p class="mb-0"><strong>Advertising notice:</strong> This site may display third-party advertisements. Sponsored placements are labeled where applicable.</p>
        <?php endif; ?>
    </section>
<?php elseif ($slug !== ''): ?>
    <?php
    $stmt = $pdo->prepare("SELECT * FROM articles WHERE slug = ?");
    $stmt->execute([$slug]);
    $art = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($art) {
        recordPageVisit('article:' . $art['slug'], 'Article: ' . $art['title']);
    } else {
        recordPageVisit('article:not-found', 'Article Not Found');
    }
    ?>

    <?php if ($art): ?>
        <?php $articleContentWithAds = injectAdsIntoArticleContent((string)($art['content'] ?? ''), (string)($art['title'] ?? ''), (string)($art['category'] ?? '')); ?>
        <a href="index.php<?= $baseQuery ? '?' . http_build_query($baseQuery) : '' ?>" class="btn btn-sm btn-outline-secondary mb-3">&larr; Back to articles</a>
        <article class="article-content bg-white p-4 rounded shadow-sm">
            <header class="article-header-panel">
                <span class="article-kicker">Featured read</span>
                <h1 class="article-title"><?= e($art['title']) ?></h1>
                <?php if (trim((string)($art['excerpt'] ?? '')) !== ''): ?>
                    <p class="article-excerpt"><?= e($art['excerpt']) ?></p>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                    <span class="meta-pill">🏷 <?= e(getPublicCategoryLabel($art['category'] ?? '')) ?></span>
                    <span class="meta-pill">📅 <?= e($art['published_at']) ?></span>
                    <span class="meta-pill">⏱ <?= estimateReadingTime($art['content']) ?> min read</span>
                </div>
            </header>
            <?php $heroImage = trim((string)($art['image'] ?? '')) !== '' ? $art['image'] : buildFreeArticleImageUrl($art['title'] ?? $art['slug']); ?>
            <img src="<?= e($heroImage) ?>" alt="<?= e(buildImageSeoText($art['title'] ?? '', $art['slug'] ?? '', $imageAltSuffix)) ?>" title="<?= e(buildImageSeoText($art['title'] ?? '', $art['slug'] ?? '', $imageTitleSuffix)) ?>" class="img-fluid rounded mb-3" loading="eager" decoding="async" fetchpriority="high">
            <?= $articleContentWithAds ?>
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
    <?php recordPageVisit('home', 'Homepage'); ?>
    <div class="d-flex justify-content-end mb-4">
        <div class="btn-group" role="group" aria-label="View switch">
            <?php $gridQuery = array_merge($baseQuery, ['view' => 'grid']); ?>
            <?php $listQuery = array_merge($baseQuery, ['view' => 'list']); ?>
            <a class="btn btn-sm <?= $view === 'grid' ? 'btn-dark' : 'btn-outline-dark' ?>" href="index.php?<?= e(http_build_query($gridQuery)) ?>">Grid</a>
            <a class="btn btn-sm <?= $view === 'list' ? 'btn-dark' : 'btn-outline-dark' ?>" href="index.php?<?= e(http_build_query($listQuery)) ?>">List</a>
        </div>
    </div>

    <?php if ($categories): ?>
        <div class="quick-categories mb-4">
            <?php $allQuery = array_merge($baseQuery, ['category' => '', 'page' => 1]); ?>
            <a class="<?= $category === '' ? 'active' : '' ?>" href="index.php?<?= e(http_build_query($allQuery)) ?>">All</a>
            <?php foreach (array_slice($categories, 0, 8) as $categoryChip): ?>
                <?php $chipQuery = array_merge($baseQuery, ['category' => $categoryChip, 'page' => 1]); ?>
                <a class="<?= $category === $categoryChip ? 'active' : '' ?>" href="index.php?<?= e(http_build_query($chipQuery)) ?>"><?= e($categoryChip) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    $clauses = [];
    $params = [];
    if ($search !== '') {
        $clauses[] = "(title LIKE :search OR excerpt LIKE :search OR content LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }
    if ($category !== '') {
        $clauses[] = "category = :category";
        $params['category'] = $category;
    }
    if ($publishedFrom !== '') {
        $clauses[] = "DATE(published_at) >= :published_from";
        $params['published_from'] = $publishedFrom;
    }
    if ($publishedTo !== '') {
        $clauses[] = "DATE(published_at) <= :published_to";
        $params['published_to'] = $publishedTo;
    }
    $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM articles $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalArticles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    if ($sort === 'relevance' && $search !== '') {
        $orderBy = '(CASE WHEN title LIKE :search_exact THEN 100 ELSE 0 END + CASE WHEN excerpt LIKE :search_exact THEN 45 ELSE 0 END + CASE WHEN content LIKE :search_exact THEN 25 ELSE 0 END + CASE WHEN title LIKE :search THEN 20 ELSE 0 END + CASE WHEN excerpt LIKE :search THEN 10 ELSE 0 END) DESC, id DESC';
    }

    $sql = "SELECT * FROM articles $where ORDER BY $orderBy LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    if ($search !== '') {
        $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
        if ($sort === 'relevance') {
            $stmt->bindValue(':search_exact', $search . '%', PDO::PARAM_STR);
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
        if ($publishedFrom !== '') {
            $featureStmt->bindValue(':published_from', $publishedFrom, PDO::PARAM_STR);
        }
        if ($publishedTo !== '') {
            $featureStmt->bindValue(':published_to', $publishedTo, PDO::PARAM_STR);
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

    <section class="mb-4 p-4 rounded-4 featured-spotlight text-white" style="background:linear-gradient(130deg,#0f172a,#1d4ed8 58%,#f97316);">
        <small class="text-uppercase">New look</small>
        <h1 class="h3 mt-2 mb-2">Discover <span class="hero-highlight">colorful</span> automotive insights</h1>
        <p class="hero-subtitle mb-0">Browse curated reviews, practical maintenance advice, and buying tips with cleaner cards and improved article readability.</p>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="stats-card p-3"><small class="text-muted d-block">Total articles</small><strong class="fs-4"><?= $totalArticles ?></strong></div></div>
        <div class="col-md-4"><div class="stats-card p-3"><small class="text-muted d-block">Filtered results</small><strong class="fs-4"><?= $total ?></strong></div></div>
        <div class="col-md-4"><div class="stats-card p-3"><small class="text-muted d-block">Avg read time (page)</small><strong class="fs-4"><?= $avgReading ?> min</strong></div></div>
    </div>

    <?php if ($featured): ?>
        <?php $featuredQuery = array_merge($baseQuery, ['slug' => $featured['slug']]); ?>
        <section class="featured-spotlight mb-4 p-4 text-white" style="background:linear-gradient(125deg,#ea580c,#0f172a 60%,#b45309);">
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
                    <small class="text-secondary d-flex flex-wrap gap-2"><span class="meta-pill">🏷 <?= e(getPublicCategoryLabel($row['category'] ?? '')) ?></span><span class="meta-pill">📅 <?= e($row['published_at']) ?></span></small>
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
                <?php $cardImage = trim((string)($row['image'] ?? '')) !== '' ? $row['image'] : buildFreeArticleImageUrl($row['title'] ?? $row['slug']); ?>
                <div class="col">
                    <div class="card h-100 shadow-sm">
                        <img src="<?= e($cardImage) ?>" class="card-img-top" style="height:200px;object-fit:cover" alt="<?= e(buildImageSeoText($row['title'] ?? '', $row['slug'] ?? '', $imageAltSuffix)) ?>" title="<?= e(buildImageSeoText($row['title'] ?? '', $row['slug'] ?? '', $imageTitleSuffix)) ?>" loading="lazy" decoding="async">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title"><?= e($row['title']) ?></h5>
                            <p class="card-text text-muted"><?= e($row['excerpt']) ?></p>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <span class="meta-pill">📅 <?= e($row['published_at']) ?></span>
                                <span class="meta-pill">🏷 <?= e(getPublicCategoryLabel($row['category'] ?? '')) ?></span>
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

<footer class="app-footer">
    Designed for car enthusiasts • <?= gmdate('Y') ?>
    <div class="footer-links">
        <a href="index.php?page=about">About</a>
        <a href="index.php?page=privacy">Privacy Policy</a>
        <a href="index.php?page=terms">Terms</a>
        <a href="index.php?page=contact">Contact</a>
    </div>
</footer>
</div>
<?php if ($googleTagManagerId !== ''): ?>
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= e($googleTagManagerId) ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<?php endif; ?>
<?php if ($customBodyScripts !== ''): ?>
    <?= $customBodyScripts ?>
<?php endif; ?>
</body>
</html>
