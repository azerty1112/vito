<?php
require_once 'functions.php';

$maxAttempts = 5;
$lockSeconds = 60;
$now = time();

$_SESSION['login_attempts'] = (int)($_SESSION['login_attempts'] ?? 0);
$_SESSION['login_lock_until'] = (int)($_SESSION['login_lock_until'] ?? 0);

$isLocked = $_SESSION['login_lock_until'] > $now;
$remainingLockSeconds = max(0, $_SESSION['login_lock_until'] - $now);

if (!isset($_SESSION['logged']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
    $submittedPassword = (string)($_POST['pass'] ?? '');

    if ($isLocked) {
        $_SESSION['login_error'] = 'Too many attempts. Try again in ' . $remainingLockSeconds . ' seconds.';
    } elseif ($submittedPassword === '') {
        $_SESSION['login_error'] = 'Password is required.';
    } elseif (verifyAdminPassword($submittedPassword)) {
        session_regenerate_id(true);
        $_SESSION['logged'] = true;
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_lock_until'] = 0;
        unset($_SESSION['login_error']);
    } else {
        $_SESSION['login_attempts']++;

        if ($_SESSION['login_attempts'] >= $maxAttempts) {
            $_SESSION['login_lock_until'] = $now + $lockSeconds;
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_error'] = 'Too many attempts. Login locked for ' . $lockSeconds . ' seconds.';
        } else {
            $remaining = $maxAttempts - $_SESSION['login_attempts'];
            $_SESSION['login_error'] = 'Invalid password. Remaining attempts: ' . $remaining . '.';
        }
    }

    header('Location: admin.php');
    exit;
}

if (!isset($_SESSION['logged'])) {
    $loginError = $_SESSION['login_error'] ?? null;
    unset($_SESSION['login_error']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin Login - <?= e(SITE_TITLE) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body {
                min-height: 100vh;
                background: radial-gradient(circle at top, #1f2937, #0b1120 65%);
            }
            .login-card {
                max-width: 430px;
                border: 1px solid rgba(255, 255, 255, 0.12);
                backdrop-filter: blur(8px);
            }
        </style>
    </head>
    <body class="d-flex align-items-center justify-content-center text-light p-3">
    <main class="card bg-dark shadow-lg login-card w-100">
        <div class="card-body p-4 p-md-5">
            <h1 class="h4 mb-3 text-center"><?= e(SITE_TITLE) ?> Admin</h1>
            <p class="text-secondary text-center mb-4">Secure access to content management dashboard.</p>

            <?php if ($loginError): ?>
                <div class="alert alert-danger py-2 small mb-3" role="alert"><?= e($loginError) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <label for="pass" class="form-label">Password</label>
                <input id="pass" type="password" name="pass" class="form-control form-control-lg" placeholder="Enter admin password" autocomplete="current-password" required autofocus>
                <button class="btn btn-primary w-100 mt-3">Login</button>
            </form>

            <small class="d-block text-secondary mt-3 text-center">Tip: set <code>ADMIN_PASSWORD</code> env var for production.</small>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$pdo = db_connect();
$csrf = csrfToken();
publishAutoArticleBySchedule();

if (!isset($_SESSION['flash_message'])) {
    $_SESSION['flash_message'] = null;
    $_SESSION['flash_type'] = 'info';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = 'Invalid security token. Please refresh and try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['logout'])) {
        session_destroy();
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_admin_password'])) {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (!verifyAdminPassword($currentPassword)) {
            $_SESSION['flash_message'] = 'Current password is incorrect.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (strlen($newPassword) < 8) {
            $_SESSION['flash_message'] = 'New password must be at least 8 characters.';
            $_SESSION['flash_type'] = 'danger';
        } elseif ($newPassword !== $confirmPassword) {
            $_SESSION['flash_message'] = 'New password and confirmation do not match.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            setSetting('admin_password_hash', password_hash($newPassword, PASSWORD_DEFAULT));
            $_SESSION['flash_message'] = 'Admin password updated successfully.';
            $_SESSION['flash_type'] = 'success';
        }

        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_daily_limit'])) {
        $newLimit = (int)($_POST['daily_limit'] ?? 5);
        $newLimit = max(1, min(200, $newLimit));
        setSetting('daily_limit', (string)$newLimit);
        $_SESSION['flash_message'] = 'Daily workflow generation limit updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_seo_settings'])) {
        $homeTitle = trim((string)($_POST['seo_home_title'] ?? ''));
        $homeDescription = trim((string)($_POST['seo_home_description'] ?? ''));
        $articleTitleSuffix = trim((string)($_POST['seo_article_title_suffix'] ?? ''));
        $defaultRobots = trim((string)($_POST['seo_default_robots'] ?? 'index,follow'));
        $defaultOgImage = trim((string)($_POST['seo_default_og_image'] ?? ''));
        $twitterSite = trim((string)($_POST['seo_twitter_site'] ?? ''));
        $imageAltSuffix = trim((string)($_POST['seo_image_alt_suffix'] ?? ''));
        $imageTitleSuffix = trim((string)($_POST['seo_image_title_suffix'] ?? ''));

        if (mb_strlen($homeTitle) > 120) {
            $homeTitle = mb_substr($homeTitle, 0, 120);
        }
        if (mb_strlen($homeDescription) > 160) {
            $homeDescription = mb_substr($homeDescription, 0, 160);
        }
        if (mb_strlen($articleTitleSuffix) > 80) {
            $articleTitleSuffix = mb_substr($articleTitleSuffix, 0, 80);
        }
        if (!in_array($defaultRobots, ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'], true)) {
            $defaultRobots = 'index,follow';
        }
        if (mb_strlen($defaultOgImage) > 500) {
            $defaultOgImage = mb_substr($defaultOgImage, 0, 500);
        }
        if ($twitterSite !== '' && mb_substr($twitterSite, 0, 1) !== '@') {
            $twitterSite = '@' . ltrim($twitterSite, '@');
        }
        if (mb_strlen($twitterSite) > 40) {
            $twitterSite = mb_substr($twitterSite, 0, 40);
        }
        if (mb_strlen($imageAltSuffix) > 80) {
            $imageAltSuffix = mb_substr($imageAltSuffix, 0, 80);
        }
        if (mb_strlen($imageTitleSuffix) > 80) {
            $imageTitleSuffix = mb_substr($imageTitleSuffix, 0, 80);
        }

        setSetting('seo_home_title', $homeTitle);
        setSetting('seo_home_description', $homeDescription);
        setSetting('seo_article_title_suffix', $articleTitleSuffix);
        setSetting('seo_default_robots', $defaultRobots);
        setSetting('seo_default_og_image', $defaultOgImage);
        setSetting('seo_twitter_site', $twitterSite);
        setSetting('seo_image_alt_suffix', $imageAltSuffix);
        setSetting('seo_image_title_suffix', $imageTitleSuffix);

        $_SESSION['flash_message'] = 'SEO settings updated successfully.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_scripts_settings'])) {
        $googleAnalyticsId = strtoupper(trim((string)($_POST['google_analytics_id'] ?? '')));
        $googleTagManagerId = strtoupper(trim((string)($_POST['google_tag_manager_id'] ?? '')));
        $googleSiteVerification = trim((string)($_POST['google_site_verification'] ?? ''));
        $bingSiteVerification = trim((string)($_POST['bing_site_verification'] ?? ''));
        $metaPixelId = preg_replace('/[^0-9]/', '', trim((string)($_POST['meta_pixel_id'] ?? '')));
        $customHeadScripts = trim((string)($_POST['custom_head_scripts'] ?? ''));
        $customBodyScripts = trim((string)($_POST['custom_body_scripts'] ?? ''));

        if ($googleAnalyticsId !== '' && !preg_match('/^(G-[A-Z0-9]+|GT-[A-Z0-9]+|UA-\d+-\d+|AW-\d+)$/', $googleAnalyticsId)) {
            $googleAnalyticsId = '';
        }
        if ($googleTagManagerId !== '' && !preg_match('/^GTM-[A-Z0-9]+$/', $googleTagManagerId)) {
            $googleTagManagerId = '';
        }
        if (mb_strlen($googleSiteVerification) > 255) {
            $googleSiteVerification = mb_substr($googleSiteVerification, 0, 255);
        }
        if (mb_strlen($bingSiteVerification) > 255) {
            $bingSiteVerification = mb_substr($bingSiteVerification, 0, 255);
        }
        if ($metaPixelId !== '' && mb_strlen($metaPixelId) > 30) {
            $metaPixelId = mb_substr($metaPixelId, 0, 30);
        }
        if (mb_strlen($customHeadScripts) > 20000) {
            $customHeadScripts = mb_substr($customHeadScripts, 0, 20000);
        }
        if (mb_strlen($customBodyScripts) > 20000) {
            $customBodyScripts = mb_substr($customBodyScripts, 0, 20000);
        }

        setSetting('google_analytics_id', $googleAnalyticsId);
        setSetting('google_tag_manager_id', $googleTagManagerId);
        setSetting('google_site_verification', $googleSiteVerification);
        setSetting('bing_site_verification', $bingSiteVerification);
        setSetting('meta_pixel_id', $metaPixelId);
        setSetting('custom_head_scripts', $customHeadScripts);
        setSetting('custom_body_scripts', $customBodyScripts);

        $_SESSION['flash_message'] = 'Scripts settings updated successfully.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }


    if (isset($_POST['update_ads_settings'])) {
        $adsEnabled = isset($_POST['ads_enabled']) ? 1 : 0;
        $adsMode = trim((string)($_POST['ads_injection_mode'] ?? 'smart'));
        if (!in_array($adsMode, ['smart', 'interval'], true)) {
            $adsMode = 'smart';
        }

        $adsInterval = (int)($_POST['ads_paragraph_interval'] ?? 4);
        $adsInterval = max(2, min(10, $adsInterval));

        $adsMaxUnits = (int)($_POST['ads_max_units_per_article'] ?? 2);
        $adsMaxUnits = max(1, min(6, $adsMaxUnits));

        $adsMinWords = (int)($_POST['ads_min_words_before_first_injection'] ?? 180);
        $adsMinWords = max(80, min(600, $adsMinWords));

        $adsMinArticleWords = (int)($_POST['ads_min_article_words'] ?? 420);
        $adsMinArticleWords = max(120, min(3000, $adsMinArticleWords));

        $adsBlockedTitleKeywords = trim((string)($_POST['ads_blocked_title_keywords'] ?? ''));
        if (mb_strlen($adsBlockedTitleKeywords) > 300) {
            $adsBlockedTitleKeywords = mb_substr($adsBlockedTitleKeywords, 0, 300);
        }

        $adsBlockedCategories = trim((string)($_POST['ads_blocked_categories'] ?? ''));
        if (mb_strlen($adsBlockedCategories) > 300) {
            $adsBlockedCategories = mb_substr($adsBlockedCategories, 0, 300);
        }

        $adsLabel = trim((string)($_POST['ads_label_text'] ?? 'Sponsored'));
        if ($adsLabel === '') {
            $adsLabel = 'Sponsored';
        }
        if (mb_strlen($adsLabel) > 40) {
            $adsLabel = mb_substr($adsLabel, 0, 40);
        }

        $adsHtmlCode = trim((string)($_POST['ads_html_code'] ?? ''));
        if ($adsHtmlCode === '') {
            $adsHtmlCode = '<div class="ad-unit-inner">Place your ad code here</div>';
        }
        if (mb_strlen($adsHtmlCode) > 12000) {
            $adsHtmlCode = mb_substr($adsHtmlCode, 0, 12000);
        }

        setSetting('ads_enabled', (string)$adsEnabled);
        setSetting('ads_injection_mode', $adsMode);
        setSetting('ads_paragraph_interval', (string)$adsInterval);
        setSetting('ads_max_units_per_article', (string)$adsMaxUnits);
        setSetting('ads_min_words_before_first_injection', (string)$adsMinWords);
        setSetting('ads_min_article_words', (string)$adsMinArticleWords);
        setSetting('ads_blocked_title_keywords', $adsBlockedTitleKeywords);
        setSetting('ads_blocked_categories', $adsBlockedCategories);
        setSetting('ads_label_text', $adsLabel);
        setSetting('ads_html_code', $adsHtmlCode);

        $_SESSION['flash_message'] = 'Ad placement controls updated successfully.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_fetch_settings'])) {
        $timeout = (int)($_POST['fetch_timeout_seconds'] ?? 12);
        $timeout = max(3, min(45, $timeout));
        $retryAttempts = (int)($_POST['fetch_retry_attempts'] ?? 3);
        $retryAttempts = max(1, min(5, $retryAttempts));
        $retryBackoffMs = (int)($_POST['fetch_retry_backoff_ms'] ?? 350);
        $retryBackoffMs = max(100, min(3000, $retryBackoffMs));
        $sourceCooldown = (int)($_POST['queue_source_cooldown_seconds'] ?? 180);
        $sourceCooldown = max(30, min(7200, $sourceCooldown));
        $userAgent = trim((string)($_POST['fetch_user_agent'] ?? ''));
        if ($userAgent === '') {
            $userAgent = 'Mozilla/5.0 (compatible; VitoBot/1.0; +https://example.com/bot)';
        }
        if (mb_strlen($userAgent) > 255) {
            $userAgent = mb_substr($userAgent, 0, 255);
        }

        setSetting('fetch_timeout_seconds', (string)$timeout);
        setSetting('fetch_retry_attempts', (string)$retryAttempts);
        setSetting('fetch_retry_backoff_ms', (string)$retryBackoffMs);
        setSetting('queue_source_cooldown_seconds', (string)$sourceCooldown);
        setSetting('fetch_user_agent', $userAgent);
        $_SESSION['flash_message'] = 'Fetcher timeout, retries, cooldown, and user-agent updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_pipeline_settings'])) {
        $minWords = (int)($_POST['min_words'] ?? 3000);
        $minWords = max(300, min(12000, $minWords));

        $cacheTtl = (int)($_POST['url_cache_ttl_seconds'] ?? 900);
        $cacheTtl = max(60, min(86400, $cacheTtl));

        $batchSize = (int)($_POST['workflow_batch_size'] ?? 8);
        $batchSize = max(1, min(50, $batchSize));

        $queueRetryDelay = (int)($_POST['queue_retry_delay_seconds'] ?? 60);
        $queueRetryDelay = max(5, min(7200, $queueRetryDelay));

        $queueMaxAttempts = (int)($_POST['queue_max_attempts'] ?? 3);
        $queueMaxAttempts = max(1, min(20, $queueMaxAttempts));

        $intervalMinutes = (int)($_POST['auto_publish_interval_minutes'] ?? 180);
        $intervalMinutes = max(1, min(1440, $intervalMinutes));

        $visitExcludedIps = trim((string)($_POST['visit_excluded_ips'] ?? ''));
        if (mb_strlen($visitExcludedIps) > 4000) {
            $visitExcludedIps = mb_substr($visitExcludedIps, 0, 4000);
        }
        $visitExcludedIps = normalizeExcludedIpRules($visitExcludedIps);

        setSetting('min_words', (string)$minWords);
        setSetting('url_cache_ttl_seconds', (string)$cacheTtl);
        setSetting('workflow_batch_size', (string)$batchSize);
        setSetting('queue_retry_delay_seconds', (string)$queueRetryDelay);
        setSetting('queue_max_attempts', (string)$queueMaxAttempts);
        setSetting('auto_publish_interval_minutes', (string)$intervalMinutes);
        setSetting('visit_excluded_ips', $visitExcludedIps);

        // Keep minute + second based scheduler settings synchronized.
        setSetting('auto_publish_interval_seconds', (string)($intervalMinutes * 60));

        $_SESSION['flash_message'] = 'Pipeline configuration updated successfully.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }


    if (isset($_POST['update_auto_scheduler'])) {
        $enabled = isset($_POST['auto_ai_enabled']) ? 1 : 0;
        $interval = (int)($_POST['auto_publish_interval_seconds'] ?? 10800);
        $interval = max(10, min(86400, $interval));

        setSetting('auto_ai_enabled', (string)$enabled);
        setSetting('auto_publish_interval_seconds', (string)$interval);

        $_SESSION['flash_message'] = 'Automatic AI publishing settings updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_auto_title_settings'])) {
        $mode = trim((string)($_POST['auto_title_mode'] ?? 'template'));
        if (!in_array($mode, ['template', 'list'], true)) {
            $mode = 'template';
        }

        $minYearOffset = (int)($_POST['auto_title_min_year_offset'] ?? 0);
        $maxYearOffset = (int)($_POST['auto_title_max_year_offset'] ?? 1);
        $minYearOffset = max(-1, min(2, $minYearOffset));
        $maxYearOffset = max(-1, min(3, $maxYearOffset));
        if ($maxYearOffset < $minYearOffset) {
            [$minYearOffset, $maxYearOffset] = [$maxYearOffset, $minYearOffset];
        }

        $fields = [
            'auto_title_brands',
            'auto_title_models',
            'auto_title_modifiers',
            'auto_title_audiences',
            'auto_title_angles',
            'auto_title_templates',
            'auto_title_fixed_titles',
        ];

        setSetting('auto_title_mode', $mode);
        setSetting('auto_title_min_year_offset', (string)$minYearOffset);
        setSetting('auto_title_max_year_offset', (string)$maxYearOffset);

        foreach ($fields as $fieldKey) {
            $raw = trim((string)($_POST[$fieldKey] ?? ''));
            if (mb_strlen($raw) > 10000) {
                $raw = mb_substr($raw, 0, 10000);
            }

            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $clean = [];
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $clean[] = $line;
                }
            }

            if ($fieldKey === 'auto_title_templates') {
                $allowedVars = ['{year}', '{brand}', '{model}', '{modifier}', '{angle}', '{audience}'];
                $validated = [];
                foreach ($clean as $tpl) {
                    $hasVar = false;
                    foreach ($allowedVars as $var) {
                        if (mb_strpos($tpl, $var) !== false) {
                            $hasVar = true;
                            break;
                        }
                    }
                    if ($hasVar) {
                        $validated[] = $tpl;
                    }
                }
                $clean = $validated;
            }

            $value = implode("\n", array_values(array_unique($clean)));
            setSetting($fieldKey, $value);
        }

        $_SESSION['flash_message'] = 'Auto title generation controls updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['preview_auto_titles'])) {
        $samples = [];
        for ($i = 0; $i < 5; $i++) {
            $samples[] = generateAutoTitle();
        }
        $_SESSION['flash_message'] = 'Title preview: ' . implode(' | ', $samples);
        $_SESSION['flash_type'] = 'info';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['reset_auto_title_defaults'])) {
        $defaults = getAutoTitleDefaultSettings();
        foreach ($defaults as $k => $v) {
            setSetting($k, (string)$v);
        }
        $_SESSION['flash_message'] = 'Auto title controls reset to defaults.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['auto_generate_now'])) {
        $result = publishAutoArticleBySchedule(true);
        if (($result['published'] ?? 0) === 1) {
            $_SESSION['flash_message'] = 'Auto-generated and published: ' . ($result['title'] ?? 'New article');
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Automatic generation failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['add_titles'])) {
        $rawTitles = array_map('trim', explode("\n", $_POST['titles'] ?? ''));
        $titles = array_values(array_unique(array_filter($rawTitles, fn($t) => mb_strlen($t) >= 5)));
        $generated = 0;

        foreach ($titles as $title) {
            if (!articleExists($title)) {
                $data = generateArticle($title);
                if (saveArticle($title, $data)) {
                    $generated++;
                }
            }
        }

        $_SESSION['flash_message'] = "Generated {$generated} new article(s).";
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['generate_demo_pack'])) {
        $demoTitles = [
            '2026 Porsche Taycan Turbo GT Track Review',
            'Best Hybrid SUVs for Families in 2026',
            'Mercedes-AMG C63 Daily Driving Impressions',
            'How Fast Charging Changed EV Road Trips',
            'Budget Performance Cars Worth Buying This Year',
        ];
        $generated = 0;
        foreach ($demoTitles as $title) {
            if (!articleExists($title)) {
                $data = generateArticle($title);
                if (saveArticle($title, $data)) {
                    $generated++;
                }
            }
        }

        $_SESSION['flash_message'] = "Demo content pack generated: {$generated} new article(s).";
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['run_content_workflow'])) {
        $result = runSelectedContentWorkflow();
        $workflowName = ($result['workflow'] ?? 'rss') === 'web' ? 'Normal Sites' : 'RSS';
        $published = (int)($result['published'] ?? 0);
        $sourcesCount = (int)($result['sources_count'] ?? 0);

        $_SESSION['flash_message'] = "Workflow {$workflowName}: published {$published} new article(s) from {$sourcesCount} source(s).";
        $_SESSION['flash_type'] = 'info';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['add_rss'])) {
        $singleUrl = trim($_POST['rss_url'] ?? '');
        $bulkInput = trim($_POST['rss_urls'] ?? '');
        $bulkUrls = $bulkInput === '' ? [] : preg_split('/\r\n|\r|\n/', $bulkInput);

        $rawUrls = [];
        if ($singleUrl !== '') {
            $rawUrls[] = $singleUrl;
        }
        foreach ($bulkUrls as $rawUrl) {
            $rawUrl = trim((string)$rawUrl);
            if ($rawUrl !== '') {
                $rawUrls[] = $rawUrl;
            }
        }

        $rawUrls = array_values(array_unique($rawUrls));
        if (!$rawUrls) {
            $_SESSION['flash_message'] = 'Please enter at least one RSS/XML URL.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin.php');
            exit;
        }

        $inserted = 0;
        $invalid = 0;
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO rss_sources (url) VALUES (?)");
        foreach ($rawUrls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $invalid++;
                continue;
            }

            $stmt->execute([$url]);
            if ($stmt->rowCount() > 0) {
                $inserted++;
            }
        }

        $ignored = count($rawUrls) - $inserted - $invalid;
        if ($inserted > 0) {
            $_SESSION['flash_message'] = "Added {$inserted} RSS source(s)."
                . ($ignored > 0 ? " {$ignored} duplicate(s) skipped." : '')
                . ($invalid > 0 ? " {$invalid} invalid link(s) skipped." : '');
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = $invalid > 0
                ? "No RSS source was added. {$invalid} invalid link(s) detected."
                : 'No RSS source was added (all links already exist).';
            $_SESSION['flash_type'] = 'warning';
        }

        header('Location: admin.php');
        exit;
    }


    if (isset($_POST['update_content_workflow'])) {
        $workflow = trim((string)($_POST['content_workflow'] ?? 'rss'));
        if (!in_array($workflow, ['rss', 'web'], true)) {
            $workflow = 'rss';
        }

        setSetting('content_workflow', $workflow);
        $_SESSION['flash_message'] = 'Content workflow updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['add_web'])) {
        $singleUrl = trim($_POST['web_url'] ?? '');
        $bulkInput = trim($_POST['web_urls'] ?? '');
        $bulkUrls = $bulkInput === '' ? [] : preg_split('/\r\n|\r|\n/', $bulkInput);

        $rawUrls = [];
        if ($singleUrl !== '') {
            $rawUrls[] = $singleUrl;
        }
        foreach ($bulkUrls as $rawUrl) {
            $rawUrl = trim((string)$rawUrl);
            if ($rawUrl !== '') {
                $rawUrls[] = $rawUrl;
            }
        }

        $rawUrls = array_values(array_unique($rawUrls));
        if (!$rawUrls) {
            $_SESSION['flash_message'] = 'Please enter at least one normal website URL.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin.php');
            exit;
        }

        $inserted = 0;
        $invalid = 0;
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO web_sources (url) VALUES (?)");
        foreach ($rawUrls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $invalid++;
                continue;
            }

            $stmt->execute([$url]);
            if ($stmt->rowCount() > 0) {
                $inserted++;
            }
        }

        $ignored = count($rawUrls) - $inserted - $invalid;
        if ($inserted > 0) {
            $_SESSION['flash_message'] = "Added {$inserted} normal website source(s)."
                . ($ignored > 0 ? " {$ignored} duplicate(s) skipped." : '')
                . ($invalid > 0 ? " {$invalid} invalid link(s) skipped." : '');
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = $invalid > 0
                ? "No normal website source was added. {$invalid} invalid link(s) detected."
                : 'No normal website source was added (all links already exist).';
            $_SESSION['flash_type'] = 'warning';
        }

        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['bulk_update_articles'])) {
        $bulkAction = trim((string)($_POST['bulk_article_action'] ?? ''));
        $selectedIds = array_map('intval', $_POST['article_ids'] ?? []);
        $selectedIds = array_values(array_filter(array_unique($selectedIds), fn($id) => $id > 0));

        if (!$selectedIds) {
            $_SESSION['flash_message'] = 'Please select at least one article.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: admin.php');
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

        if ($bulkAction === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM articles WHERE id IN ($placeholders)");
            $stmt->execute($selectedIds);
            $_SESSION['flash_message'] = 'Selected articles deleted.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: admin.php');
            exit;
        }

        if ($bulkAction === 'set_category') {
            $bulkCategory = trim((string)($_POST['bulk_category'] ?? ''));
            $params = array_merge([$bulkCategory], $selectedIds);
            $stmt = $pdo->prepare("UPDATE articles SET category = ? WHERE id IN ($placeholders)");
            $stmt->execute($params);
            $_SESSION['flash_message'] = 'Category updated for selected articles.';
            $_SESSION['flash_type'] = 'success';
            header('Location: admin.php');
            exit;
        }

        $_SESSION['flash_message'] = 'Invalid bulk action selected.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_rss'])) {
        $rssId = (int)($_POST['rss_id'] ?? 0);
        $rssUrl = trim((string)($_POST['rss_url'] ?? ''));
        if ($rssId <= 0 || !filter_var($rssUrl, FILTER_VALIDATE_URL)) {
            $_SESSION['flash_message'] = 'Invalid RSS source update request.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin.php');
            exit;
        }

        $duplicateStmt = $pdo->prepare("SELECT id FROM rss_sources WHERE url = ? AND id != ? LIMIT 1");
        $duplicateStmt->execute([$rssUrl, $rssId]);
        if ($duplicateStmt->fetchColumn()) {
            $_SESSION['flash_message'] = 'RSS URL already exists.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: admin.php');
            exit;
        }

        $stmt = $pdo->prepare("UPDATE rss_sources SET url = ? WHERE id = ?");
        $stmt->execute([$rssUrl, $rssId]);
        $_SESSION['flash_message'] = 'RSS source updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['update_web'])) {
        $webId = (int)($_POST['web_id'] ?? 0);
        $webUrl = trim((string)($_POST['web_url'] ?? ''));
        if ($webId <= 0 || !filter_var($webUrl, FILTER_VALIDATE_URL)) {
            $_SESSION['flash_message'] = 'Invalid website source update request.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin.php');
            exit;
        }

        $duplicateStmt = $pdo->prepare("SELECT id FROM web_sources WHERE url = ? AND id != ? LIMIT 1");
        $duplicateStmt->execute([$webUrl, $webId]);
        if ($duplicateStmt->fetchColumn()) {
            $_SESSION['flash_message'] = 'Website URL already exists.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: admin.php');
            exit;
        }

        $stmt = $pdo->prepare("UPDATE web_sources SET url = ? WHERE id = ?");
        $stmt->execute([$webUrl, $webId]);
        $_SESSION['flash_message'] = 'Website source updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['delete_web'])) {
        $stmt = $pdo->prepare("DELETE FROM web_sources WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_web']]);
        $_SESSION['flash_message'] = 'Normal website source removed.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['delete_article'])) {
        $stmt = $pdo->prepare("DELETE FROM articles WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_article']]);
        $_SESSION['flash_message'] = 'Article deleted.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['delete_rss'])) {
        $stmt = $pdo->prepare("DELETE FROM rss_sources WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_rss']]);
        $_SESSION['flash_message'] = 'RSS source removed.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['save_article'])) {
        $articleId = (int)($_POST['article_id'] ?? 0);
        $title = trim((string)($_POST['article_title'] ?? ''));
        $slugInput = trim((string)($_POST['article_slug'] ?? ''));
        $slug = $slugInput !== '' ? slugify($slugInput) : slugify($title);
        $category = trim((string)($_POST['article_category'] ?? ''));
        $excerpt = trim((string)($_POST['article_excerpt'] ?? ''));
        $image = trim((string)($_POST['article_image'] ?? ''));
        $content = trim((string)($_POST['article_content'] ?? ''));

        if ($articleId <= 0 || $title === '' || $content === '' || $slug === '') {
            $_SESSION['flash_message'] = 'Article update failed. Title, slug, and content are required.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin.php');
            exit;
        }

        $duplicateStmt = $pdo->prepare("SELECT id FROM articles WHERE (title = :title OR slug = :slug) AND id != :id LIMIT 1");
        $duplicateStmt->execute([
            'title' => $title,
            'slug' => $slug,
            'id' => $articleId,
        ]);
        if ($duplicateStmt->fetchColumn()) {
            $_SESSION['flash_message'] = 'Article update failed. Another article already uses this title or slug.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin.php');
            exit;
        }

        $stmt = $pdo->prepare("UPDATE articles SET title = ?, slug = ?, category = ?, excerpt = ?, image = ?, content = ? WHERE id = ?");
        $stmt->execute([$title, $slug, $category, $excerpt, $image, $content, $articleId]);
        $_SESSION['flash_message'] = 'Article updated successfully.';
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['save_setting'])) {
        $settingKey = trim((string)($_POST['setting_key'] ?? ''));
        $settingValue = trim((string)($_POST['setting_value'] ?? ''));
        $originalKey = trim((string)($_POST['original_setting_key'] ?? ''));

        if (!preg_match('/^[a-z0-9_\-.]{2,80}$/i', $settingKey)) {
            $_SESSION['flash_message'] = 'Invalid setting key. Use letters, numbers, dots, dashes, and underscores only.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin.php');
            exit;
        }

        $protectedSettings = ['pipeline_defaults_v2_applied'];
        if ($originalKey !== '' && in_array($originalKey, $protectedSettings, true) && $originalKey !== $settingKey) {
            $_SESSION['flash_message'] = 'This system setting key cannot be renamed.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: admin.php');
            exit;
        }

        if ($originalKey !== '' && $originalKey !== $settingKey) {
            $existsStmt = $pdo->prepare("SELECT key FROM settings WHERE key = ? LIMIT 1");
            $existsStmt->execute([$settingKey]);
            if ($existsStmt->fetchColumn()) {
                $_SESSION['flash_message'] = 'Cannot rename setting. Target key already exists.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: admin.php');
                exit;
            }

            $renameStmt = $pdo->prepare("UPDATE settings SET key = ?, value = ? WHERE key = ?");
            $renameStmt->execute([$settingKey, $settingValue, $originalKey]);
        } else {
            setSetting($settingKey, $settingValue);
        }

        $_SESSION['flash_message'] = "Setting '{$settingKey}' saved.";
        $_SESSION['flash_type'] = 'success';
        header('Location: admin.php');
        exit;
    }

    if (isset($_POST['delete_setting'])) {
        $settingKey = trim((string)($_POST['delete_setting'] ?? ''));
        $protectedSettings = ['pipeline_defaults_v2_applied'];
        if (in_array($settingKey, $protectedSettings, true)) {
            $_SESSION['flash_message'] = 'This system setting is protected and cannot be deleted.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: admin.php');
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM settings WHERE key = ?");
        $stmt->execute([$settingKey]);
        $_SESSION['flash_message'] = 'Setting removed.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: admin.php');
        exit;
    }
}

$message = $_SESSION['flash_message'];
$messageType = $_SESSION['flash_type'];
$_SESSION['flash_message'] = null;
$_SESSION['flash_type'] = 'info';

$articleSearch = trim($_GET['qa'] ?? '');
$rssSearch = trim($_GET['qr'] ?? '');
$webSearch = trim($_GET['qw'] ?? '');
$articleCategory = trim($_GET['cat'] ?? '');

if (isset($_GET['export']) && $_GET['export'] === 'articles_json') {
    $exportRows = $pdo->query("SELECT title, slug, excerpt, category, published_at FROM articles ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=articles-export.json');
    echo json_encode($exportRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'articles_csv') {
    $exportRows = $pdo->query("SELECT title, slug, category, published_at FROM articles ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=articles-export.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['title', 'slug', 'category', 'published_at']);
    foreach ($exportRows as $row) {
        fputcsv($out, [$row['title'], $row['slug'], $row['category'], $row['published_at']]);
    }
    fclose($out);
    exit;
}

$totalArticles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalSources = (int)$pdo->query("SELECT COUNT(*) FROM rss_sources")->fetchColumn();
$totalWebSources = (int)$pdo->query("SELECT COUNT(*) FROM web_sources")->fetchColumn();
$latestDate = $pdo->query("SELECT MAX(published_at) FROM articles")->fetchColumn();
$pageVisitStats = getPageVisitStats(7);
$totalTrackedViews = 0;
$totalTrackedVisitors = 0;
foreach ($pageVisitStats as $visitRow) {
    $totalTrackedViews += (int)($visitRow['total_views'] ?? 0);
    $totalTrackedVisitors += (int)($visitRow['unique_visitors'] ?? 0);
}
$dailyLimit = (int)getSetting('daily_limit', 5);
$seoHomeTitle = (string)getSetting('seo_home_title', SITE_TITLE);
$seoHomeDescription = (string)getSetting('seo_home_description', 'Automotive reviews, guides, and practical car ownership tips.');
$seoArticleTitleSuffix = (string)getSetting('seo_article_title_suffix', SITE_TITLE);
$seoDefaultRobots = (string)getSetting('seo_default_robots', 'index,follow');
$seoDefaultOgImage = (string)getSetting('seo_default_og_image', '');
$seoTwitterSite = (string)getSetting('seo_twitter_site', '');
$seoImageAltSuffix = (string)getSetting('seo_image_alt_suffix', ' - car image');
$seoImageTitleSuffix = (string)getSetting('seo_image_title_suffix', ' - photo');
$googleAnalyticsId = (string)getSetting('google_analytics_id', '');
$googleTagManagerId = (string)getSetting('google_tag_manager_id', '');
$googleSiteVerification = (string)getSetting('google_site_verification', '');
$bingSiteVerification = (string)getSetting('bing_site_verification', '');
$metaPixelId = (string)getSetting('meta_pixel_id', '');
$customHeadScripts = (string)getSetting('custom_head_scripts', '');
$customBodyScripts = (string)getSetting('custom_body_scripts', '');
$siteBaseUrl = getSiteBaseUrl();
$sitemapUrl = $siteBaseUrl !== '' ? ($siteBaseUrl . '/sitemap.php') : '/sitemap.php';

$adsEnabled = getSettingInt('ads_enabled', 0, 0, 1) === 1;
$adsInjectionMode = (string)getSetting('ads_injection_mode', 'smart');
if (!in_array($adsInjectionMode, ['smart', 'interval'], true)) {
    $adsInjectionMode = 'smart';
}
$adsParagraphInterval = getSettingInt('ads_paragraph_interval', 4, 2, 10);
$adsMaxUnits = getSettingInt('ads_max_units_per_article', 2, 1, 6);
$adsMinWordsBeforeFirstInjection = getSettingInt('ads_min_words_before_first_injection', 180, 80, 600);
$adsMinArticleWords = getSettingInt('ads_min_article_words', 420, 120, 3000);
$adsBlockedTitleKeywords = (string)getSetting('ads_blocked_title_keywords', '');
$adsBlockedCategories = (string)getSetting('ads_blocked_categories', '');
$adsLabelText = (string)getSetting('ads_label_text', 'Sponsored');
$adsHtmlCode = (string)getSetting('ads_html_code', '<div class="ad-unit-inner">Place your ad code here</div>');
$minWords = getSettingInt('min_words', 3000, 300, 12000);
$urlCacheTtlSeconds = getSettingInt('url_cache_ttl_seconds', 900, 60, 86400);
$workflowBatchSize = getSettingInt('workflow_batch_size', 8, 1, 50);
$queueRetryDelaySeconds = getSettingInt('queue_retry_delay_seconds', 60, 5, 7200);
$queueMaxAttempts = getSettingInt('queue_max_attempts', 3, 1, 20);
$fetchTimeoutSeconds = getSettingInt('fetch_timeout_seconds', 12, 3, 45);
$fetchRetryAttempts = getSettingInt('fetch_retry_attempts', 3, 1, 5);
$fetchRetryBackoffMs = getSettingInt('fetch_retry_backoff_ms', 350, 100, 3000);
$queueSourceCooldownSeconds = getSettingInt('queue_source_cooldown_seconds', 180, 30, 7200);
$fetchUserAgent = (string)getSetting('fetch_user_agent', 'Mozilla/5.0 (compatible; VitoBot/1.0; +https://example.com/bot)');
$visitExcludedIps = (string)getSetting('visit_excluded_ips', '');
$detectedVisitorIp = getVisitorIpAddress();
$selectedWorkflow = getSelectedContentWorkflow();
$workflowSummary = getContentWorkflowSummary();
$autoAiEnabled = getSettingInt('auto_ai_enabled', 1, 0, 1);
$autoPublishInterval = getAutoPublishIntervalSeconds();
$autoPublishIntervalMinutes = max(1, (int)round($autoPublishInterval / 60));
$autoPublishLastRun = (string)getSetting('auto_publish_last_run_at', '1970-01-01 00:00:00');
$autoTitleDefaults = getAutoTitleDefaultSettings();
$autoTitleMode = (string)getSetting('auto_title_mode', $autoTitleDefaults['auto_title_mode']);
if (!in_array($autoTitleMode, ['template', 'list'], true)) {
    $autoTitleMode = 'template';
}
$autoTitleMinYearOffset = getSettingInt('auto_title_min_year_offset', (int)$autoTitleDefaults['auto_title_min_year_offset'], -1, 2);
$autoTitleMaxYearOffset = getSettingInt('auto_title_max_year_offset', (int)$autoTitleDefaults['auto_title_max_year_offset'], -1, 3);
$autoTitleBrands = (string)getSetting('auto_title_brands', $autoTitleDefaults['auto_title_brands']);
$autoTitleModels = (string)getSetting('auto_title_models', $autoTitleDefaults['auto_title_models']);
$autoTitleModifiers = (string)getSetting('auto_title_modifiers', $autoTitleDefaults['auto_title_modifiers']);
$autoTitleAudiences = (string)getSetting('auto_title_audiences', $autoTitleDefaults['auto_title_audiences']);
$autoTitleAngles = (string)getSetting('auto_title_angles', $autoTitleDefaults['auto_title_angles']);
$autoTitleTemplates = (string)getSetting('auto_title_templates', $autoTitleDefaults['auto_title_templates']);
$autoTitleFixedTitles = (string)getSetting('auto_title_fixed_titles', $autoTitleDefaults['auto_title_fixed_titles']);
$cronUrl = getCronEndpointUrl();
$categoryOptions = $pdo->query("SELECT DISTINCT category FROM articles WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

$articleSql = "SELECT id, title, slug, category, excerpt, image, content, published_at FROM articles";
$articleParams = [];
$articleClauses = [];
if ($articleSearch !== '') {
    $articleClauses[] = "title LIKE :title";
    $articleParams['title'] = '%' . $articleSearch . '%';
}
if ($articleCategory !== '') {
    $articleClauses[] = "category = :cat";
    $articleParams['cat'] = $articleCategory;
}
if ($articleClauses) {
    $articleSql .= ' WHERE ' . implode(' AND ', $articleClauses);
}
$articleSql .= " ORDER BY id DESC LIMIT 20";
$articleStmt = $pdo->prepare($articleSql);
foreach ($articleParams as $key => $value) {
    $articleStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$articleStmt->execute();
$articles = $articleStmt->fetchAll(PDO::FETCH_ASSOC);

$rssSql = "SELECT id, url FROM rss_sources";
$rssParams = [];
if ($rssSearch !== '') {
    $rssSql .= " WHERE url LIKE :url";
    $rssParams['url'] = '%' . $rssSearch . '%';
}
$rssSql .= " ORDER BY id DESC";
$rssStmt = $pdo->prepare($rssSql);
foreach ($rssParams as $key => $value) {
    $rssStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$rssStmt->execute();
$rssRows = $rssStmt->fetchAll(PDO::FETCH_ASSOC);

$webSql = "SELECT id, url FROM web_sources";
$webParams = [];
if ($webSearch !== '') {
    $webSql .= " WHERE url LIKE :url";
    $webParams['url'] = '%' . $webSearch . '%';
}
$webSql .= " ORDER BY id DESC";
$webStmt = $pdo->prepare($webSql);
foreach ($webParams as $key => $value) {
    $webStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$webStmt->execute();
$webRows = $webStmt->fetchAll(PDO::FETCH_ASSOC);

$settingsSearch = trim($_GET['qs'] ?? '');
$settingsSql = "SELECT key, value FROM settings";
$settingsParams = [];
if ($settingsSearch !== '') {
    $settingsSql .= " WHERE key LIKE :setting_key";
    $settingsParams['setting_key'] = '%' . $settingsSearch . '%';
}
$settingsSql .= " ORDER BY key ASC";
$settingsStmt = $pdo->prepare($settingsSql);
foreach ($settingsParams as $key => $value) {
    $settingsStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$settingsStmt->execute();
$settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Panel - <?= e(SITE_TITLE) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            --bs-heading-color: #f8fafc;
            background: radial-gradient(circle at 15% 10%, #1f6f54 0%, #14532d 45%, #0b2e1f 100%);
            background-attachment: fixed;
            color: #f8fafc;
        }
        .text-secondary,
        .text-light-emphasis,
        .text-muted,
        small {
            color: #cbd5e1 !important;
        }
        .section-card {
            background: #4a273b;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .stat-card h3,
        .stat-card h6 {
            margin-bottom: 0;
        }
        .table td,
        .table th {
            vertical-align: middle;
        }
        .list-group-item {
            background: #4a273b;
            color: #f8f9fa;
            border-color: rgba(255, 255, 255, 0.08);
        }
        .workflow-badge {
            font-size: 0.75rem;
            letter-spacing: 0.2px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .mini-analytics {
            border: 1px solid rgba(249, 115, 22, 0.35);
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(249, 115, 22, 0.2));
        }
        .mini-analytics .table {
            --bs-table-bg: transparent;
            --bs-table-border-color: rgba(255, 255, 255, 0.08);
            margin-bottom: 0;
        }
        .mini-analytics .progress {
            height: 6px;
            background-color: rgba(255, 255, 255, 0.12);
        }
        .admin-tabs .nav-link {
            color: #cbd5e1;
            border-color: rgba(255, 255, 255, 0.12);
            background: rgba(148, 163, 184, 0.08);
        }
        .admin-tabs .nav-link.active {
            color: #fff;
            background: rgba(220, 38, 38, 0.25);
            border-color: rgba(252, 165, 165, 0.6);
        }
        .admin-tabs .nav-link:hover {
            color: #fff;
        }
        .dashboard-sidebar {
            position: sticky;
            top: 1rem;
            max-height: calc(100vh - 2rem);
            overflow: hidden;
        }
        .dashboard-sidebar .nav-link {
            color: #ffb4b5;
            border: 1px solid rgba(248, 113, 113, 0.35);
            background: rgba(220, 38, 38, 0.08);
            margin-bottom: 0.5rem;
            border-radius: 0.6rem;
            transition: all 0.2s ease;
        }
        .dashboard-sidebar .nav-link:hover,
        .dashboard-sidebar .nav-link.active {
            color: #fff5f5;
            background: rgba(220, 38, 38, 0.25);
            border-color: rgba(252, 165, 165, 0.6);
        }
        .panel-nav-btn {
            text-align: left;
            border: 1px solid rgba(248, 113, 113, 0.35);
            color: #ffb4b5;
            background: rgba(220, 38, 38, 0.08);
        }
        .panel-nav-btn:hover,
        .panel-nav-btn.active {
            color: #fff5f5;
            border-color: rgba(252, 165, 165, 0.6);
            background: rgba(220, 38, 38, 0.25);
        }
        .panel-nav-btn:focus-visible {
            outline: 2px solid rgba(252, 165, 165, 0.85);
            outline-offset: 2px;
        }
        #control-panel-search {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(248, 113, 113, 0.3);
            color: #fff;
        }
        #control-panel-search::placeholder {
            color: #ffb4b5;
        }

        .panel-nav-toolbar {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.65rem;
        }
        .panel-nav-toolbar .btn {
            flex: 1;
        }
        #control-panel-nav {
            max-height: calc(100vh - 17rem);
            overflow-y: auto;
            padding-right: 0.2rem;
        }
        .section-counter {
            display: inline-block;
            font-size: 0.75rem;
            border: 1px solid rgba(148, 163, 184, 0.45);
            border-radius: 999px;
            padding: 0.2rem 0.55rem;
            margin-bottom: 0.5rem;
            color: #e2e8f0;
        }
        .panel-nav-empty {
            border: 1px dashed rgba(248, 113, 113, 0.45);
            border-radius: 0.6rem;
            padding: 0.6rem;
            color: #fecaca;
            font-size: 0.82rem;
            text-align: center;
        }
        #active-control-panel .section-card {
            margin-bottom: 1rem;
        }
        .btn-primary,
        .btn-outline-info,
        .bg-info,
        .text-bg-primary {
            background-color: #dc2626 !important;
            border-color: #f87171 !important;
            color: #fff !important;
        }
        .progress-bar.bg-info {
            background-color: #ef4444 !important;
        }
        .ads-preview .inline-ad-unit {
            margin: 0;
            border: 1px solid rgba(251, 146, 60, 0.65);
            background: rgba(255, 247, 237, 0.1);
            border-radius: 0.75rem;
            padding: 0.85rem;
        }
        .ads-preview .inline-ad-label {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.72rem;
            font-weight: 700;
            color: #fdba74;
            margin-bottom: 0.45rem;
        }
        .ads-preview .ad-unit-inner {
            border: 1px dashed rgba(251, 146, 60, 0.8);
            border-radius: 0.65rem;
            padding: 0.75rem;
            color: #fed7aa;
            text-align: center;
            min-height: 72px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #pipeline-config-section h5,
        #pipeline-config-section h6,
        #pipeline-config-section .form-label,
        #auto-scheduler-section h5,
        #auto-scheduler-section h6,
        #auto-scheduler-section .form-label {
            color: #ff4d4f !important;
        }
    </style>
</head>
<body class="text-light">
<div class="container py-4 py-lg-5">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="mb-1"><i class="bi bi-speedometer2"></i> <?= e(SITE_TITLE) ?> Control Panel</h1>
            <p class="text-secondary mb-0">Manage article generation, RSS/normal sources, and publishing workflow.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-light" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Public Site</a>
            <form method="post" class="mb-0">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button class="btn btn-danger" name="logout" value="1"><i class="bi bi-box-arrow-right"></i> Logout</button>
            </form>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?> shadow-sm"><?= e($message) ?></div>
    <?php endif; ?>

    <?php
    $workflowHealth = $workflowSummary['health'] ?? 'ready';
    $workflowAlertClass = $workflowHealth === 'ready' ? 'success' : 'warning';
    $workflowAlertText = $workflowHealth === 'ready'
        ? 'Workflow is ready. Scheduled and manual runs will follow the selected source type.'
        : ($workflowHealth === 'missing_sources'
            ? 'No sources found for the selected workflow. Add sources below before running.'
            : 'Auto scheduler is disabled. Manual workflow runs still work normally.');
    ?>
    <div class="alert alert-<?= $workflowAlertClass ?> d-flex flex-wrap align-items-center gap-2 shadow-sm" role="status">
        <span class="badge text-bg-dark workflow-badge">Workflow: <?= e($workflowSummary['selected_workflow_label']) ?></span>
        <span class="badge text-bg-secondary workflow-badge">Sources: <?= (int)$workflowSummary['selected_sources'] ?></span>
        <span class="badge text-bg-secondary workflow-badge">Daily limit: <?= (int)$workflowSummary['daily_limit'] ?></span>
        <span class="ms-1"><?= e($workflowAlertText) ?></span>
    </div>

    <div class="row g-3 mb-4" id="overview-stats">
        <div class="col-sm-6 col-lg-3">
            <div class="card section-card stat-card h-100">
                <div class="card-body">
                    <small class="text-light-emphasis">Total Articles</small>
                    <h3><?= $totalArticles ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card section-card stat-card h-100">
                <div class="card-body">
                    <small class="text-light-emphasis">RSS / Web Sources</small>
                    <h3><?= $totalSources ?> / <?= $totalWebSources ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card section-card stat-card h-100">
                <div class="card-body">
                    <small class="text-light-emphasis">Daily Limit</small>
                    <h3><?= $dailyLimit ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card section-card stat-card h-100">
                <div class="card-body">
                    <small class="text-light-emphasis">Latest Publish</small>
                    <h6><?= e($latestDate ?: 'N/A') ?></h6>
                    <small class="text-secondary d-block mt-2">Visitors: <?= (int)$totalTrackedVisitors ?> • Views: <?= (int)$totalTrackedViews ?></small>
                </div>
            </div>
        </div>
    </div>

    <div class="card section-card mini-analytics mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h6 class="mb-0"><i class="bi bi-graph-up-arrow"></i> Visitor Analytics by Page</h6>
                <small class="text-light-emphasis">Compact view · top <?= count($pageVisitStats) ?> pages</small>
            </div>

            <?php if (!$pageVisitStats): ?>
                <small class="text-secondary">No visit data yet. Open the public pages and stats will appear automatically.</small>
            <?php else: ?>
                <?php $maxViews = max(1, (int)$pageVisitStats[0]['total_views']); ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle text-light">
                        <thead>
                        <tr>
                            <th>Page</th>
                            <th class="text-center">Unique</th>
                            <th class="text-center">Views</th>
                            <th class="text-center">Last 24h</th>
                            <th style="width: 180px;">Trend</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pageVisitStats as $visitRow): ?>
                            <?php $ratio = min(100, (int)round(((int)$visitRow['total_views'] / $maxViews) * 100)); ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?= e($visitRow['page_label']) ?></span>
                                    <small class="text-secondary d-block"><?= e($visitRow['page_key']) ?></small>
                                </td>
                                <td class="text-center"><span class="badge text-bg-secondary"><?= (int)$visitRow['unique_visitors'] ?></span></td>
                                <td class="text-center"><span class="badge text-bg-primary"><?= (int)$visitRow['total_views'] ?></span></td>
                                <td class="text-center"><span class="badge text-bg-dark"><?= (int)$visitRow['visitors_24h'] ?></span></td>
                                <td>
                                    <div class="progress" role="progressbar" aria-label="Page views trend" aria-valuenow="<?= $ratio ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar bg-info" style="width: <?= $ratio ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <aside class="col-lg-3">
            <div class="card section-card dashboard-sidebar">
                <div class="card-body">
                    <h5 class="mb-3"><i class="bi bi-layout-sidebar"></i> Dashboard Sections</h5>
                    <hr class="border-secondary-subtle my-3">
                    <h6 class="mb-2"><i class="bi bi-ui-checks-grid"></i> Control Buttons</h6>
                    <input type="search" id="control-panel-search" class="form-control form-control-sm mb-2" placeholder="Search sections..." aria-label="Search dashboard sections">
                    <span class="section-counter" id="section-counter">0/0 sections</span>
                    <small class="d-block text-secondary mb-2" id="active-section-label">Active: —</small>
                    <div class="panel-nav-toolbar">
                        <button type="button" class="btn btn-sm btn-outline-light" id="panel-prev-btn"><i class="bi bi-arrow-up"></i> Prev</button>
                        <button type="button" class="btn btn-sm btn-outline-light" id="panel-next-btn">Next <i class="bi bi-arrow-down"></i></button>
                    </div>
                    <div class="d-grid gap-2" id="control-panel-nav"></div>
                    <div class="panel-nav-empty d-none" id="control-panel-empty">No matching sections found.</div>
                </div>
            </div>

        </aside>

        <div class="col-lg-9">
            <div class="row g-4">
        <div class="col-xl-4 d-none" id="control-cards-source">
            <div class="card section-card mb-3" id="publishing-settings">
                <div class="card-body">
                    <h5><i class="bi bi-sliders"></i> Daily Publishing Limit</h5>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-8">
                            <label class="form-label">Daily Workflow Limit</label>
                            <input type="number" name="daily_limit" class="form-control" min="1" max="200" value="<?= $dailyLimit ?>">
                        </div>
                        <div class="col-4">
                            <button name="update_daily_limit" value="1" class="btn btn-outline-light w-100">Save</button>
                        </div>
                    </form>
                    <small class="text-secondary">Controls max articles generated per selected workflow run.</small>
                </div>
            </div>

            <div class="card section-card mb-3" id="seo-settings">
                <div class="card-body">
                    <h5><i class="bi bi-search"></i> SEO Settings</h5>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-12">
                            <label class="form-label">Homepage Title</label>
                            <input type="text" name="seo_home_title" class="form-control" maxlength="120" value="<?= e($seoHomeTitle) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Homepage Meta Description</label>
                            <textarea name="seo_home_description" class="form-control" rows="3" maxlength="160"><?= e($seoHomeDescription) ?></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Article Title Suffix</label>
                            <input type="text" name="seo_article_title_suffix" class="form-control" maxlength="80" value="<?= e($seoArticleTitleSuffix) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Default Robots</label>
                            <select name="seo_default_robots" class="form-select">
                                <option value="index,follow" <?= $seoDefaultRobots === 'index,follow' ? 'selected' : '' ?>>index,follow</option>
                                <option value="noindex,follow" <?= $seoDefaultRobots === 'noindex,follow' ? 'selected' : '' ?>>noindex,follow</option>
                                <option value="index,nofollow" <?= $seoDefaultRobots === 'index,nofollow' ? 'selected' : '' ?>>index,nofollow</option>
                                <option value="noindex,nofollow" <?= $seoDefaultRobots === 'noindex,nofollow' ? 'selected' : '' ?>>noindex,nofollow</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Default Social Image URL (OG/Twitter)</label>
                            <input type="url" name="seo_default_og_image" class="form-control" maxlength="500" value="<?= e($seoDefaultOgImage) ?>" placeholder="https://example.com/cover.jpg">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Twitter Site Username (optional)</label>
                            <input type="text" name="seo_twitter_site" class="form-control" maxlength="40" value="<?= e($seoTwitterSite) ?>" placeholder="@yourbrand">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Auto Image ALT Suffix</label>
                            <input type="text" name="seo_image_alt_suffix" class="form-control" maxlength="80" value="<?= e($seoImageAltSuffix) ?>" placeholder="- car image">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Auto Image Title Suffix</label>
                            <input type="text" name="seo_image_title_suffix" class="form-control" maxlength="80" value="<?= e($seoImageTitleSuffix) ?>" placeholder="- photo">
                        </div>
                        <div class="col-12">
                            <button name="update_seo_settings" value="1" class="btn btn-outline-light w-100">Save SEO Settings</button>
                        </div>
                    </form>
                    <small class="text-secondary">Manage global metadata for homepage, article title suffix, robots rules, and social sharing tags.</small>
                </div>
            </div>

            <div class="card section-card mb-3" id="ads-settings">
                <div class="card-body">
                    <h5><i class="bi bi-badge-ad"></i> Smart Ads Manager</h5>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="ads_enabled" name="ads_enabled" value="1" <?= $adsEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label" for="ads_enabled">Enable ad injection in article pages</label>
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Injection Strategy</label>
                            <select name="ads_injection_mode" class="form-select">
                                <option value="smart" <?= $adsInjectionMode === 'smart' ? 'selected' : '' ?>>AI Smart Placement (recommended)</option>
                                <option value="interval" <?= $adsInjectionMode === 'interval' ? 'selected' : '' ?>>Fixed Paragraph Interval</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Max Ads / Article</label>
                            <input type="number" name="ads_max_units_per_article" class="form-control" min="1" max="6" value="<?= (int)$adsMaxUnits ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Paragraph Interval</label>
                            <input type="number" name="ads_paragraph_interval" class="form-control" min="2" max="10" value="<?= (int)$adsParagraphInterval ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Min Words before First Ad</label>
                            <input type="number" name="ads_min_words_before_first_injection" class="form-control" min="80" max="600" value="<?= (int)$adsMinWordsBeforeFirstInjection ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Min Article Words (Enable Ads)</label>
                            <input type="number" name="ads_min_article_words" class="form-control" min="120" max="3000" value="<?= (int)$adsMinArticleWords ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Block Ads for Title Keywords (comma separated)</label>
                            <input type="text" name="ads_blocked_title_keywords" class="form-control" maxlength="300" value="<?= e($adsBlockedTitleKeywords) ?>" placeholder="opinion, breaking, live blog">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Block Ads for Categories (comma separated)</label>
                            <input type="text" name="ads_blocked_categories" class="form-control" maxlength="300" value="<?= e($adsBlockedCategories) ?>" placeholder="news, opinion, analysis">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Ad Label</label>
                            <input type="text" name="ads_label_text" class="form-control" maxlength="40" value="<?= e($adsLabelText) ?>" placeholder="Sponsored">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Ad HTML / Script Code</label>
                            <textarea name="ads_html_code" class="form-control" rows="5" placeholder="Paste AdSense or custom ad snippet"><?= e($adsHtmlCode) ?></textarea>
                        </div>
                        <div class="col-12">
                            <button name="update_ads_settings" value="1" class="btn btn-outline-light w-100">Save Ads Controls</button>
                        </div>
                    </form>
                    <small class="text-secondary">Smart mode uses content-aware rules, minimum article length checks, and optional title keyword blocking for safer monetization.</small>
                    <div class="mt-3 ads-preview">
                        <div class="inline-ad-unit">
                            <div class="inline-ad-label"><?= e($adsLabelText) ?> • Preview</div>
                            <?= $adsHtmlCode !== '' ? $adsHtmlCode : '<div class="ad-unit-inner">Place your ad code here</div>' ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card section-card mb-3" id="scripts-settings">
                <div class="card-body">
                    <h5><i class="bi bi-code-slash"></i> Scripts Settings</h5>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-12">
                            <label class="form-label">Google Analytics ID</label>
                            <input type="text" name="google_analytics_id" class="form-control" maxlength="30" value="<?= e($googleAnalyticsId) ?>" placeholder="G-XXXXXXXXXX">
                            <small class="text-secondary">Supports GA4, GT, UA, and Google Ads IDs.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Google Tag Manager ID</label>
                            <input type="text" name="google_tag_manager_id" class="form-control" maxlength="20" value="<?= e($googleTagManagerId) ?>" placeholder="GTM-XXXXXXX">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Google Site Verification</label>
                            <input type="text" name="google_site_verification" class="form-control" maxlength="255" value="<?= e($googleSiteVerification) ?>" placeholder="google-site-verification token">
                            <small class="text-secondary">Paste token from Search Console meta tag content.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Bing Site Verification</label>
                            <input type="text" name="bing_site_verification" class="form-control" maxlength="255" value="<?= e($bingSiteVerification) ?>" placeholder="msvalidate.01 token">
                            <small class="text-secondary">Paste token from Bing Webmaster Tools meta tag content.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Meta Pixel ID</label>
                            <input type="text" name="meta_pixel_id" class="form-control" maxlength="30" value="<?= e($metaPixelId) ?>" placeholder="123456789012345">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Custom &lt;head&gt; Scripts</label>
                            <textarea name="custom_head_scripts" class="form-control" rows="4" placeholder="&lt;script&gt;...&lt;/script&gt;"><?= e($customHeadScripts) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Custom Footer Scripts (before &lt;/body&gt;)</label>
                            <textarea name="custom_body_scripts" class="form-control" rows="4" placeholder="&lt;script&gt;...&lt;/script&gt;"><?= e($customBodyScripts) ?></textarea>
                        </div>
                        <div class="col-12">
                            <button name="update_scripts_settings" value="1" class="btn btn-outline-light w-100">Save Scripts Settings</button>
                        </div>
                    </form>
                    <small class="text-secondary">Use this section to inject tracking scripts globally across the site.</small>
                    <div class="alert alert-secondary mt-3 mb-0">
                        <div><strong>Sitemap URL:</strong> <code><?= e($sitemapUrl) ?></code></div>
                        <div class="small mt-2">Submit this URL in Google Search Console and Bing Webmaster Tools for faster indexing.</div>
                    </div>
                </div>
            </div>

            <div class="card section-card mb-3">
                <div class="card-body">
                    <h5><i class="bi bi-shield-check"></i> Fetch Protection Settings</h5>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-4">
                            <label class="form-label">Fetch Timeout (s)</label>
                            <input type="number" name="fetch_timeout_seconds" class="form-control" min="3" max="45" value="<?= (int)$fetchTimeoutSeconds ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Retry Attempts</label>
                            <input type="number" name="fetch_retry_attempts" class="form-control" min="1" max="5" value="<?= (int)$fetchRetryAttempts ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Retry Backoff (ms)</label>
                            <input type="number" name="fetch_retry_backoff_ms" class="form-control" min="100" max="3000" value="<?= (int)$fetchRetryBackoffMs ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Source Cooldown (s)</label>
                            <input type="number" name="queue_source_cooldown_seconds" class="form-control" min="30" max="7200" value="<?= (int)$queueSourceCooldownSeconds ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Fetcher User-Agent</label>
                            <input type="text" name="fetch_user_agent" class="form-control" maxlength="255" value="<?= e($fetchUserAgent) ?>">
                        </div>
                        <div class="col-12">
                            <button name="update_fetch_settings" value="1" class="btn btn-outline-light w-100">Update Fetch Settings</button>
                        </div>
                    </form>
                    <small class="text-secondary">Anti-block controls: timeout, retries with backoff, URL queue cooldown, and custom UA.</small>
                </div>
            </div>

            <div class="card section-card mb-3" id="pipeline-config-section">
                <div class="card-body">
                    <h5 class="text-danger"><i class="bi bi-cpu"></i> Core Pipeline Config</h5>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-6">
                            <label class="form-label">Minimum Article Words</label>
                            <input type="number" name="min_words" class="form-control" min="300" max="12000" value="<?= (int)$minWords ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">URL Cache TTL (s)</label>
                            <input type="number" name="url_cache_ttl_seconds" class="form-control" min="60" max="86400" value="<?= (int)$urlCacheTtlSeconds ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Workflow Batch Size</label>
                            <input type="number" name="workflow_batch_size" class="form-control" min="1" max="50" value="<?= (int)$workflowBatchSize ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Queue Retry Delay (s)</label>
                            <input type="number" name="queue_retry_delay_seconds" class="form-control" min="5" max="7200" value="<?= (int)$queueRetryDelaySeconds ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Queue Max Attempts</label>
                            <input type="number" name="queue_max_attempts" class="form-control" min="1" max="20" value="<?= (int)$queueMaxAttempts ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Auto Publish Interval (minutes)</label>
                            <input type="number" name="auto_publish_interval_minutes" class="form-control" min="1" max="1440" value="<?= (int)$autoPublishIntervalMinutes ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Exclude IPs From Visit Analytics</label>
                            <textarea name="visit_excluded_ips" class="form-control" rows="3" placeholder="127.0.0.1
203.0.113.4"><?= e($visitExcludedIps) ?></textarea>
                            <small class="text-secondary">One IP per line or separated by commas/spaces. Supports exact IPs and IPv4 CIDR (example: 203.0.113.0/24).</small>
                            <?php if ($detectedVisitorIp !== ''): ?>
                                <small class="d-block text-info mt-1">Detected current request IP: <code><?= e($detectedVisitorIp) ?></code></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <button name="update_pipeline_settings" value="1" class="btn btn-outline-info w-100">Update Pipeline Config</button>
                        </div>
                    </form>
                    <small class="text-secondary">Includes the main configuration values from <code>config.php</code> so you can manage them from one place.</small>
                </div>
            </div>

            <div class="card section-card mb-3">
                <div class="card-body">
                    <h5><i class="bi bi-diagram-3"></i> Content Workflow Selection</h5>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-8">
                            <label class="form-label">Selected Content Workflow</label>
                            <select name="content_workflow" class="form-select">
                                <option value="rss" <?= $selectedWorkflow === 'rss' ? 'selected' : '' ?>>RSS Workflow</option>
                                <option value="web" <?= $selectedWorkflow === 'web' ? 'selected' : '' ?>>Normal Sites Workflow (Symfony DomCrawler)</option>
                            </select>
                        </div>
                        <div class="col-4">
                            <button name="update_content_workflow" value="1" class="btn btn-outline-light w-100">Apply</button>
                        </div>
                    </form>
                    <small class="text-secondary">Cron and manual run will execute the selected workflow only.</small>
                </div>
            </div>

            <div class="card section-card mb-3" id="auto-scheduler-section">
                <div class="card-body">
                    <h5 class="text-danger"><i class="bi bi-robot"></i> AI Auto Publish Scheduler</h5>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="auto_ai_enabled" name="auto_ai_enabled" value="1" <?= $autoAiEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label" for="auto_ai_enabled">Enable fully automatic title + article publishing</label>
                            </div>
                        </div>
                        <div class="col-8">
                            <label class="form-label">Publish Every (seconds)</label>
                            <input type="number" name="auto_publish_interval_seconds" class="form-control" min="10" max="86400" value="<?= $autoPublishInterval ?>">
                        </div>
                        <div class="col-4">
                            <button name="update_auto_scheduler" value="1" class="btn btn-outline-warning w-100">Update</button>
                        </div>
                    </form>
                    <small class="text-secondary d-block mt-2">Set to 10 seconds for a fast demo. Last automatic publish run: <?= e($autoPublishLastRun) ?></small>

                    <hr class="border-secondary-subtle my-3">
                    <h6><i class="bi bi-type"></i> Auto Title Generator Controls</h6>
                    <form method="post" class="row g-2 mt-1">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-6">
                            <label class="form-label">Mode</label>
                            <select name="auto_title_mode" class="form-select">
                                <option value="template" <?= $autoTitleMode === 'template' ? 'selected' : '' ?>>Template + Variables</option>
                                <option value="list" <?= $autoTitleMode === 'list' ? 'selected' : '' ?>>Fixed Titles List</option>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label">Min Year Offset</label>
                            <input type="number" name="auto_title_min_year_offset" class="form-control" min="-1" max="2" value="<?= (int)$autoTitleMinYearOffset ?>">
                        </div>
                        <div class="col-3">
                            <label class="form-label">Max Year Offset</label>
                            <input type="number" name="auto_title_max_year_offset" class="form-control" min="-1" max="3" value="<?= (int)$autoTitleMaxYearOffset ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Brands (one per line)</label>
                            <textarea name="auto_title_brands" class="form-control" rows="4"><?= e($autoTitleBrands) ?></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Models (one per line)</label>
                            <textarea name="auto_title_models" class="form-control" rows="4"><?= e($autoTitleModels) ?></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label">SEO Modifiers (one per line)</label>
                            <textarea name="auto_title_modifiers" class="form-control" rows="4"><?= e($autoTitleModifiers) ?></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Audience Segments (one per line)</label>
                            <textarea name="auto_title_audiences" class="form-control" rows="4"><?= e($autoTitleAudiences) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Angles (one per line)</label>
                            <textarea name="auto_title_angles" class="form-control" rows="4"><?= e($autoTitleAngles) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Templates (one per line)</label>
                            <textarea name="auto_title_templates" class="form-control" rows="4"><?= e($autoTitleTemplates) ?></textarea>
                            <small class="text-secondary">Allowed variables: <code>{year}</code>, <code>{brand}</code>, <code>{model}</code>, <code>{modifier}</code>, <code>{angle}</code>, <code>{audience}</code>.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Fixed Titles (one per line, used when mode = Fixed Titles List)</label>
                            <textarea name="auto_title_fixed_titles" class="form-control" rows="4"><?= e($autoTitleFixedTitles) ?></textarea>
                        </div>
                        <div class="col-12">
                            <button name="update_auto_title_settings" value="1" class="btn btn-outline-warning w-100">Save Title Generation Controls</button>
                        </div>
                        <div class="col-6">
                            <button name="preview_auto_titles" value="1" class="btn btn-outline-info w-100">Preview 5 Generated Titles</button>
                        </div>
                        <div class="col-6">
                            <button name="reset_auto_title_defaults" value="1" class="btn btn-outline-secondary w-100" onclick="return confirm('Reset all title controls to defaults?');">Reset to Defaults</button>

                        </div>
                    </form>

                    <div class="alert alert-secondary mt-3 mb-2">
                        <div class="small text-uppercase text-muted mb-1">Hosting Cron URL</div>
                        <code class="d-block text-break"><?= e($cronUrl) ?></code>
                        <small class="text-secondary d-block mt-2">Set your hosting cron job to call this URL every 10 seconds (or the smallest interval your provider allows).</small>
                        <small class="text-secondary d-block">No token required. This URL supports HTTPS proxy headers and subfolder deployments automatically.</small>
                    </div>
                    <form method="post" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <button name="auto_generate_now" value="1" class="btn btn-outline-success w-100">
                            <i class="bi bi-magic"></i> Generate Title + Publish Now
                        </button>
                    </form>

                    <form method="post" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <button name="generate_demo_pack" value="1" class="btn btn-outline-info w-100">
                            <i class="bi bi-stars"></i> Generate Demo Content Pack
                        </button>
                    </form>
                </div>
            </div>

            <div class="card section-card mb-3" id="admin-password-settings">
                <div class="card-body">
                    <h5><i class="bi bi-key"></i> Change Admin Password</h5>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-12">
                            <input type="password" name="current_password" class="form-control" placeholder="Current password" autocomplete="current-password" required>
                        </div>
                        <div class="col-12">
                            <input type="password" name="new_password" class="form-control" placeholder="New password (min 8 chars)" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="col-12">
                            <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button name="update_admin_password" value="1" class="btn btn-outline-light">Save New Password</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card section-card mb-3">
                <div class="card-body">
                    <h5><i class="bi bi-gear-wide-connected"></i> System Constants (Read Only)</h5>
                    <div class="small">
                        <div><span class="text-secondary">SITE_TITLE:</span> <code><?= e(SITE_TITLE) ?></code></div>
                        <div><span class="text-secondary">DB_FILE:</span> <code><?= e(DB_FILE) ?></code></div>
                        <div><span class="text-secondary">Password Hash:</span> <code><?= e(substr(PASSWORD_HASH, 0, 20)) ?>...</code></div>
                    </div>
                </div>
            </div>

            <div class="card section-card mb-3" id="sources-management">
                <div class="card-body">
                    <h5><i class="bi bi-pencil-square"></i> Add Titles Manually</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <textarea name="titles" class="form-control" rows="6" placeholder="One title per line"></textarea>
                        <button name="add_titles" class="btn btn-success mt-3 w-100">Add to Queue & Generate</button>
                    </form>
                </div>
            </div>

            <div class="card section-card">
                <div class="card-body">
                    <h5><i class="bi bi-rss"></i> Add RSS Source</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="url" name="rss_url" class="form-control" placeholder="https://example.com/feed.xml">
                        <textarea name="rss_urls" class="form-control mt-2" rows="5" placeholder="Paste multiple RSS/XML links (one per line)"></textarea>
                        <small class="text-secondary d-block mt-2">You can add a single URL above or paste a full XML links list.</small>
                        <button name="add_rss" class="btn btn-outline-light mt-3 w-100">Save Source(s)</button>
                    </form>
                </div>
            </div>

            <div class="card section-card mt-3">
                <div class="card-body">
                    <h5><i class="bi bi-globe2"></i> Add Normal Website Source</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="url" name="web_url" class="form-control" placeholder="https://example.com/news/">
                        <textarea name="web_urls" class="form-control mt-2" rows="5" placeholder="Paste multiple normal website links (one per line)"></textarea>
                        <small class="text-secondary d-block mt-2">Used by Normal Sites workflow with Symfony DomCrawler + CSS selectors.</small>
                        <button name="add_web" class="btn btn-outline-light mt-3 w-100">Save Website Source(s)</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-12" id="content-data">
            <form method="post" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button name="run_content_workflow" value="1" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-arrow-repeat"></i> Run Selected Content Workflow & Generate
                </button>
            </form>
            <div class="d-flex gap-2 mb-3">
                <a href="admin.php?export=articles_json" class="btn btn-outline-light w-100"><i class="bi bi-filetype-json"></i> Export JSON</a>
                <a href="admin.php?export=articles_csv" class="btn btn-outline-light w-100"><i class="bi bi-filetype-csv"></i> Export CSV</a>
            </div>

            <div id="active-control-panel"></div>

            <ul class="nav nav-pills admin-tabs gap-2 mb-3" id="adminDataTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="articles-tab" data-bs-toggle="pill" data-bs-target="#articles-pane" type="button" role="tab" aria-controls="articles-pane" aria-selected="true">
                        <i class="bi bi-newspaper me-1"></i> Articles
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="rss-tab" data-bs-toggle="pill" data-bs-target="#rss-pane" type="button" role="tab" aria-controls="rss-pane" aria-selected="false">
                        <i class="bi bi-rss me-1"></i> RSS Sources
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="web-tab" data-bs-toggle="pill" data-bs-target="#web-pane" type="button" role="tab" aria-controls="web-pane" aria-selected="false">
                        <i class="bi bi-globe2 me-1"></i> Web Sources
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="settings-tab" data-bs-toggle="pill" data-bs-target="#settings-pane" type="button" role="tab" aria-controls="settings-pane" aria-selected="false">
                        <i class="bi bi-gear me-1"></i> Settings
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="articles-pane" role="tabpanel" aria-labelledby="articles-tab" tabindex="0">
                    <div class="card section-card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Recent Articles</h5>
                                <form method="get" class="d-flex gap-2">
                                    <input type="text" name="qa" class="form-control form-control-sm" placeholder="Search title" value="<?= e($articleSearch) ?>">
                                    <select name="cat" class="form-select form-select-sm">
                                        <option value="">All categories</option>
                                        <?php foreach ($categoryOptions as $cat): ?>
                                            <option value="<?= e($cat) ?>" <?= $articleCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-sm btn-outline-light">Filter</button>
                                </form>
                            </div>

                            <form method="post" class="row g-2 align-items-end mb-3" id="articlesBulkForm">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <div class="col-md-5">
                                    <label class="form-label">Bulk Action</label>
                                    <select class="form-select form-select-sm" name="bulk_article_action">
                                        <option value="set_category">Set Category</option>
                                        <option value="delete">Delete Selected</option>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Category (for Set Category)</label>
                                    <input type="text" class="form-control form-control-sm" name="bulk_category" placeholder="e.g. Reviews">
                                </div>
                                <div class="col-md-2">
                                    <button name="bulk_update_articles" value="1" class="btn btn-outline-warning w-100 btn-sm">Apply</button>
                                </div>
                            </form>

                            <div class="table-responsive rounded shadow-sm">
                                <table class="table table-dark table-striped align-middle mb-0">
                                    <thead>
                                    <tr><th style="width:42px;"><input type="checkbox" id="select-all-articles" class="form-check-input"></th><th>Title</th><th>Category</th><th>Date</th><th>Action</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php if (!$articles): ?>
                                        <tr><td colspan="5" class="text-center text-secondary">No articles found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($articles as $row): ?>
                                            <tr>
                                                <td><input type="checkbox" class="form-check-input article-check" name="article_ids[]" value="<?= (int)$row['id'] ?>" form="articlesBulkForm"></td>
                                                <td>
                                                    <div class="fw-semibold"><?= e($row['title']) ?></div>
                                                    <small class="text-secondary">/<?= e($row['slug']) ?></small>
                                                </td>
                                                <td><span class="badge text-bg-secondary"><?= e($row['category'] ?: 'General') ?></span></td>
                                                <td><?= e($row['published_at']) ?></td>
                                                <td class="d-flex flex-wrap gap-2">
                                                    <a href="index.php?slug=<?= e($row['slug']) ?>" target="_blank" class="btn btn-sm btn-info">View</a>
                                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="collapse" data-bs-target="#edit-article-<?= (int)$row['id'] ?>" aria-expanded="false">Edit</button>
                                                    <form method="post" onsubmit="return confirm('Delete this article?')">
                                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                        <button name="delete_article" value="<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-danger">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <tr class="collapse" id="edit-article-<?= (int)$row['id'] ?>">
                                                <td colspan="5">
                                                    <form method="post" class="row g-2">
                                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                        <input type="hidden" name="article_id" value="<?= (int)$row['id'] ?>">
                                                        <div class="col-12">
                                                            <label class="form-label">Title</label>
                                                            <input type="text" name="article_title" class="form-control" required value="<?= e($row['title']) ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Slug</label>
                                                            <input type="text" name="article_slug" class="form-control" required value="<?= e($row['slug']) ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Category</label>
                                                            <input type="text" name="article_category" class="form-control" value="<?= e($row['category']) ?>">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Image URL</label>
                                                            <input type="url" name="article_image" class="form-control" value="<?= e($row['image']) ?>">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Excerpt</label>
                                                            <textarea name="article_excerpt" class="form-control" rows="2"><?= e($row['excerpt']) ?></textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Content (HTML)</label>
                                                            <textarea name="article_content" class="form-control" rows="8" required><?= e($row['content']) ?></textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <button name="save_article" value="1" class="btn btn-success w-100">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="rss-pane" role="tabpanel" aria-labelledby="rss-tab" tabindex="0">
                    <div class="card section-card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">RSS Sources</h5>
                                <form method="get" class="d-flex gap-2">
                                    <input type="text" name="qr" class="form-control form-control-sm" placeholder="Search URL" value="<?= e($rssSearch) ?>">
                                    <button class="btn btn-sm btn-outline-light">Search</button>
                                </form>
                            </div>

                            <ul class="list-group shadow-sm">
                                <?php if (!$rssRows): ?>
                                    <li class="list-group-item text-center text-secondary">No RSS sources found.</li>
                                <?php else: ?>
                                    <?php foreach ($rssRows as $rss): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="text-break pe-2"><?= e($rss['url']) ?></span>
                                            <div class="d-flex gap-2">
                                                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="collapse" data-bs-target="#edit-rss-<?= (int)$rss['id'] ?>" aria-expanded="false">Edit</button>
                                                <form method="post" onsubmit="return confirm('Remove this source?')">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                    <button name="delete_rss" value="<?= (int)$rss['id'] ?>" class="btn btn-sm btn-outline-danger">Remove</button>
                                                </form>
                                            </div>
                                        </li>
                                        <li class="list-group-item collapse" id="edit-rss-<?= (int)$rss['id'] ?>">
                                            <form method="post" class="row g-2 align-items-center">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="rss_id" value="<?= (int)$rss['id'] ?>">
                                                <div class="col-md-10">
                                                    <input type="url" name="rss_url" class="form-control form-control-sm" required value="<?= e($rss['url']) ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <button name="update_rss" value="1" class="btn btn-sm btn-outline-success w-100">Save</button>
                                                </div>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="web-pane" role="tabpanel" aria-labelledby="web-tab" tabindex="0">
                    <div class="card section-card mt-0">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Normal Website Sources</h5>
                                <form method="get" class="d-flex gap-2">
                                    <input type="text" name="qw" class="form-control form-control-sm" placeholder="Search URL" value="<?= e($webSearch) ?>">
                                    <button class="btn btn-sm btn-outline-light">Search</button>
                                </form>
                            </div>

                            <ul class="list-group shadow-sm">
                                <?php if (!$webRows): ?>
                                    <li class="list-group-item text-center text-secondary">No normal website sources found.</li>
                                <?php else: ?>
                                    <?php foreach ($webRows as $web): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="text-break pe-2"><?= e($web['url']) ?></span>
                                            <div class="d-flex gap-2">
                                                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="collapse" data-bs-target="#edit-web-<?= (int)$web['id'] ?>" aria-expanded="false">Edit</button>
                                                <form method="post" onsubmit="return confirm('Remove this source?')">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                    <button name="delete_web" value="<?= (int)$web['id'] ?>" class="btn btn-sm btn-outline-danger">Remove</button>
                                                </form>
                                            </div>
                                        </li>
                                        <li class="list-group-item collapse" id="edit-web-<?= (int)$web['id'] ?>">
                                            <form method="post" class="row g-2 align-items-center">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="web_id" value="<?= (int)$web['id'] ?>">
                                                <div class="col-md-10">
                                                    <input type="url" name="web_url" class="form-control form-control-sm" required value="<?= e($web['url']) ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <button name="update_web" value="1" class="btn btn-sm btn-outline-success w-100">Save</button>
                                                </div>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="settings-pane" role="tabpanel" aria-labelledby="settings-tab" tabindex="0">
                    <div class="card section-card mt-0">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">All Runtime Settings</h5>
                                <form method="get" class="d-flex gap-2">
                                    <input type="text" name="qs" class="form-control form-control-sm" placeholder="Search key" value="<?= e($settingsSearch) ?>">
                                    <button class="btn btn-sm btn-outline-light">Search</button>
                                </form>
                            </div>

                            <form method="post" class="row g-2 mb-3">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <div class="col-md-4">
                                    <input type="text" name="setting_key" class="form-control" placeholder="setting_key" required>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" name="setting_value" class="form-control" placeholder="value">
                                </div>
                                <div class="col-md-2">
                                    <button name="save_setting" value="1" class="btn btn-outline-success w-100">Save</button>
                                </div>
                            </form>

                            <ul class="list-group shadow-sm">
                                <?php if (!$settingsRows): ?>
                                    <li class="list-group-item text-center text-secondary">No settings found.</li>
                                <?php else: ?>
                                    <?php foreach ($settingsRows as $setting): ?>
                                        <li class="list-group-item">
                                            <form method="post" class="row g-2 align-items-center">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="original_setting_key" value="<?= e($setting['key']) ?>">
                                                <div class="col-md-4">
                                                    <input type="text" name="setting_key" class="form-control form-control-sm" value="<?= e($setting['key']) ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <input type="text" name="setting_value" class="form-control form-control-sm" value="<?= e($setting['value']) ?>">
                                                </div>
                                                <div class="col-md-2 d-flex gap-2">
                                                    <button name="save_setting" value="1" class="btn btn-sm btn-outline-success flex-fill">Update</button>
                                                    <button name="delete_setting" value="<?= e($setting['key']) ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this setting?')">×</button>
                                                </div>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                </div>
            </div>
        </div>
            </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const sourceCards = Array.from(document.querySelectorAll('#control-cards-source .section-card'));
        const panelNav = document.getElementById('control-panel-nav');
        const panelSearch = document.getElementById('control-panel-search');
        const activeSectionLabel = document.getElementById('active-section-label');
        const activePanel = document.getElementById('active-control-panel');
        const prevBtn = document.getElementById('panel-prev-btn');
        const nextBtn = document.getElementById('panel-next-btn');
        const emptyState = document.getElementById('control-panel-empty');
        const sectionCounter = document.getElementById('section-counter');
        let currentIndex = 0;

        function getVisibleButtons() {
            return Array.from(panelNav.querySelectorAll('button')).filter(function (btn) {
                return !btn.classList.contains('d-none');
            });
        }

        function updateCounter() {
            if (!sectionCounter || !panelNav) return;
            const visibleButtons = getVisibleButtons();
            const total = sourceCards.length;

            if (!visibleButtons.length) {
                sectionCounter.textContent = '0/' + total + ' sections';
                return;
            }

            const activeVisiblePosition = visibleButtons.findIndex(function (btn) {
                return Number(btn.dataset.index) === currentIndex;
            });
            const position = activeVisiblePosition >= 0 ? activeVisiblePosition + 1 : 1;
            sectionCounter.textContent = position + '/' + visibleButtons.length + ' sections';
        }

        function updateNavState() {
            if (!panelNav) return;
            const visibleButtons = getVisibleButtons();
            const hasAny = visibleButtons.length > 0;

            if (prevBtn) prevBtn.disabled = !hasAny;
            if (nextBtn) nextBtn.disabled = !hasAny;
            if (emptyState) emptyState.classList.toggle('d-none', hasAny);
            updateCounter();
        }

        function renderPanel(index) {
            if (!activePanel || !sourceCards[index]) return;
            currentIndex = index;
            activePanel.innerHTML = '';
            activePanel.appendChild(sourceCards[index]);

            panelNav.querySelectorAll('button').forEach(function (btn) {
                const isActive = Number(btn.dataset.index) === index;
                btn.classList.toggle('active', isActive);
            });

            if (activeSectionLabel) {
                const titleEl = sourceCards[index].querySelector('h5');
                const label = titleEl ? titleEl.innerText.trim() : 'Section ' + (index + 1);
                activeSectionLabel.textContent = 'Active: ' + label;
            }

            updateCounter();
        }

        function navigateVisible(direction) {
            const visibleButtons = getVisibleButtons();
            if (!visibleButtons.length) return;

            let currentVisibleIndex = visibleButtons.findIndex(function (btn) {
                return Number(btn.dataset.index) === currentIndex;
            });

            if (currentVisibleIndex < 0) {
                currentVisibleIndex = 0;
            }

            const nextVisibleIndex = (currentVisibleIndex + direction + visibleButtons.length) % visibleButtons.length;
            renderPanel(Number(visibleButtons[nextVisibleIndex].dataset.index));
        }

        if (panelNav && activePanel && sourceCards.length) {
            sourceCards.forEach(function (card, index) {
                const titleEl = card.querySelector('h5');
                const label = titleEl ? titleEl.innerText.trim() : 'Section ' + (index + 1);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn panel-nav-btn';
                btn.textContent = label;
                btn.dataset.index = String(index);
                btn.dataset.label = label.toLowerCase();
                btn.addEventListener('click', function () {
                    renderPanel(index);
                });
                panelNav.appendChild(btn);
            });

            if (panelSearch) {
                panelSearch.addEventListener('input', function () {
                    const query = panelSearch.value.trim().toLowerCase();
                    const buttons = Array.from(panelNav.querySelectorAll('button'));
                    let firstVisibleIndex = null;

                    buttons.forEach(function (btn) {
                        const matches = query === '' || btn.dataset.label.includes(query);
                        btn.classList.toggle('d-none', !matches);
                        if (matches && firstVisibleIndex === null) {
                            firstVisibleIndex = Number(btn.dataset.index);
                        }
                    });

                    const activeBtn = panelNav.querySelector('button.active');
                    const activeHidden = activeBtn && activeBtn.classList.contains('d-none');
                    if (firstVisibleIndex !== null && activeHidden) {
                        renderPanel(firstVisibleIndex);
                    }

                    updateNavState();
                });
            }

            if (prevBtn) {
                prevBtn.addEventListener('click', function () {
                    navigateVisible(-1);
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function () {
                    navigateVisible(1);
                });
            }

            document.addEventListener('keydown', function (event) {
                if (!panelNav || !sourceCards.length) return;
                if (event.key === '/' && document.activeElement !== panelSearch) {
                    event.preventDefault();
                    panelSearch && panelSearch.focus();
                    return;
                }

                if (['INPUT', 'TEXTAREA', 'SELECT'].includes((document.activeElement && document.activeElement.tagName) || '')) {
                    return;
                }

                if (event.key === ']') {
                    event.preventDefault();
                    navigateVisible(1);
                } else if (event.key === '[') {
                    event.preventDefault();
                    navigateVisible(-1);
                }
            });

            renderPanel(0);
            updateNavState();
        }

        const selectAll = document.getElementById('select-all-articles');
        if (!selectAll) return;
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.article-check').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
        });
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
