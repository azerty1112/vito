<?php
require_once 'config.php';

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

function slugify($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function e($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function fetchUrlBody($url, $timeoutSeconds = 10) {
    $context = stream_context_create([
        'http' => [
            'timeout' => max(1, (int)$timeoutSeconds),
            'user_agent' => 'vito-rss-fetcher/1.0',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    return is_string($body) ? $body : null;
}

function extractFeedTitlesWithSymfonyCrawler($xmlString, $limit = 50) {
    if (!class_exists('Symfony\\Component\\DomCrawler\\Crawler')) {
        return [];
    }

    $limit = max(1, (int)$limit);
    $crawler = new Symfony\Component\DomCrawler\Crawler();

    try {
        $crawler->addXmlContent($xmlString, 'UTF-8');
    } catch (Throwable $e) {
        return [];
    }

    $selectors = [
        'channel > item > title',
        'feed > entry > title',
        'rdf\:RDF > item > title',
        'item > title',
        'entry > title',
    ];

    $titles = [];
    foreach ($selectors as $selector) {
        $nodes = $crawler->filter($selector);
        if ($nodes->count() === 0) {
            continue;
        }

        foreach ($nodes as $node) {
            $title = trim((string)$node->textContent);
            if ($title !== '') {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break 2;
            }
        }
    }

    return array_values(array_unique($titles));
}

function extractFeedTitlesWithSimpleXml($xmlString, $limit = 50) {
    $limit = max(1, (int)$limit);
    $xml = @simplexml_load_string($xmlString);
    if (!$xml) {
        return [];
    }

    $titles = [];

    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $title = trim((string)$item->title);
            if ($title !== '') {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break;
            }
        }
    }

    if (count($titles) < $limit && isset($xml->entry)) {
        foreach ($xml->entry as $entry) {
            $title = trim((string)$entry->title);
            if ($title !== '') {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break;
            }
        }
    }

    return array_values(array_unique($titles));
}

function extractFeedTitles($url, $limit = 50) {
    $xmlString = fetchUrlBody($url);
    if (!is_string($xmlString) || trim($xmlString) === '') {
        return [];
    }

    $titles = extractFeedTitlesWithSymfonyCrawler($xmlString, $limit);
    if ($titles) {
        return $titles;
    }

    return extractFeedTitlesWithSimpleXml($xmlString, $limit);
}


function extractTitlesFromNormalPageWithSymfonyCrawler($htmlString, $limit = 50) {
    if (!class_exists('Symfony\Component\DomCrawler\Crawler')) {
        return [];
    }

    $limit = max(1, (int)$limit);
    $crawler = new Symfony\Component\DomCrawler\Crawler();

    try {
        $crawler->addHtmlContent($htmlString, 'UTF-8');
    } catch (Throwable $e) {
        return [];
    }

    $selectors = [
        'article h1 a, article h2 a, article h3 a',
        'main h1 a, main h2 a, main h3 a',
        '.post-title a, .entry-title a',
        'h1 a, h2 a, h3 a',
    ];

    $titles = [];
    foreach ($selectors as $selector) {
        $nodes = $crawler->filter($selector);
        if ($nodes->count() === 0) {
            continue;
        }

        foreach ($nodes as $node) {
            $title = trim((string)$node->textContent);
            if ($title !== '' && mb_strlen($title) >= 5) {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break 2;
            }
        }
    }

    if (!$titles) {
        foreach ($crawler->filter('title') as $titleNode) {
            $title = trim((string)$titleNode->textContent);
            if ($title !== '' && mb_strlen($title) >= 5) {
                $titles[] = $title;
            }
            if (count($titles) >= $limit) {
                break;
            }
        }
    }

    return array_values(array_unique($titles));
}

function extractTitlesFromNormalPage($url, $limit = 50) {
    $htmlString = fetchUrlBody($url);
    if (!is_string($htmlString) || trim($htmlString) === '') {
        return [];
    }

    return extractTitlesFromNormalPageWithSymfonyCrawler($htmlString, $limit);
}

function getSelectedContentWorkflow() {
    $allowed = ['rss', 'web'];
    $workflow = trim((string)getSetting('content_workflow', 'rss'));
    return in_array($workflow, $allowed, true) ? $workflow : 'rss';
}

function runRssWorkflow($limit = null) {
    $pdo = db_connect();
    $sources = $pdo->query("SELECT url FROM rss_sources ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    $limit = $limit === null ? max(1, (int)getSetting('daily_limit', 5)) : max(1, (int)$limit);

    $count = 0;
    $sourceStats = [];

    foreach ($sources as $url) {
        if ($count >= $limit) {
            break;
        }

        $titles = extractFeedTitles($url, max(10, $limit * 3));
        $publishedFromSource = 0;

        foreach ($titles as $title) {
            if ($count >= $limit) {
                break;
            }

            if ($title !== '' && !articleExists($title)) {
                $data = generateArticle($title);
                if (saveArticle($title, $data)) {
                    $count++;
                    $publishedFromSource++;
                }
            }
        }

        $sourceStats[] = [
            'url' => $url,
            'fetched_titles' => count($titles),
            'published' => $publishedFromSource,
        ];
    }

    return [
        'workflow' => 'rss',
        'sources_count' => count($sources),
        'published' => $count,
        'stats' => $sourceStats,
    ];
}

function runWebWorkflow($limit = null) {
    $pdo = db_connect();
    $sources = $pdo->query("SELECT url FROM web_sources ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    $limit = $limit === null ? max(1, (int)getSetting('daily_limit', 5)) : max(1, (int)$limit);

    $count = 0;
    $sourceStats = [];

    foreach ($sources as $url) {
        if ($count >= $limit) {
            break;
        }

        $titles = extractTitlesFromNormalPage($url, max(10, $limit * 3));
        $publishedFromSource = 0;

        foreach ($titles as $title) {
            if ($count >= $limit) {
                break;
            }

            if ($title !== '' && !articleExists($title)) {
                $data = generateArticle($title);
                if (saveArticle($title, $data)) {
                    $count++;
                    $publishedFromSource++;
                }
            }
        }

        $sourceStats[] = [
            'url' => $url,
            'fetched_titles' => count($titles),
            'published' => $publishedFromSource,
        ];
    }

    return [
        'workflow' => 'web',
        'sources_count' => count($sources),
        'published' => $count,
        'stats' => $sourceStats,
    ];
}

function runSelectedContentWorkflow($limit = null) {
    $selected = getSelectedContentWorkflow();
    if ($selected === 'web') {
        return runWebWorkflow($limit);
    }

    return runRssWorkflow($limit);
}


function normalizeDateInput($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}


function getSettingInt($key, $default = 0, $min = null, $max = null) {
    $value = (int)getSetting($key, (string)$default);
    if ($min !== null) {
        $value = max((int)$min, $value);
    }
    if ($max !== null) {
        $value = min((int)$max, $value);
    }
    return $value;
}


function getAutoPublishIntervalSeconds() {
    $secondsRaw = getSetting('auto_publish_interval_seconds', null);
    if ($secondsRaw !== null && $secondsRaw !== '') {
        return getSettingInt('auto_publish_interval_seconds', 10800, 10, 86400);
    }

    $minutesRaw = getSetting('auto_publish_interval_minutes', null);
    if ($minutesRaw !== null && $minutesRaw !== '') {
        $minutes = getSettingInt('auto_publish_interval_minutes', 180, 1, 1440);
        return max(10, min(86400, $minutes * 60));
    }

    return 10800;
}


function getCurrentBaseUrl() {
    $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $isHttps = $forwardedProto !== ''
        ? strtolower($forwardedProto) === 'https'
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443));

    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $scriptName = trim((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = str_replace('\\', '/', dirname($scriptName));
    if ($basePath === '.' || $basePath === '/') {
        $basePath = '';
    }
    $basePath = rtrim($basePath, '/');

    return $scheme . '://' . $host . $basePath;
}

function getCronEndpointUrl() {
    return getCurrentBaseUrl() . '/cron.php';
}

function getAutoPublishSchedulerMeta() {
    $lastRun = strtotime((string)getSetting('auto_publish_last_run_at', '1970-01-01 00:00:00')) ?: 0;
    $interval = getAutoPublishIntervalSeconds();
    $nextRun = $lastRun + $interval;
    $remaining = max(0, $nextRun - time());

    return [
        'interval_seconds' => $interval,
        'last_run_at' => date('Y-m-d H:i:s', $lastRun),
        'next_run_at' => date('Y-m-d H:i:s', $nextRun),
        'remaining_seconds' => $remaining,
    ];
}

function classifyVehicleProfile($title) {
    $titleLower = mb_strtolower($title, 'UTF-8');
    $map = [
        'SUV' => ['suv', 'crossover'],
        'Sedan' => ['sedan', 'saloon'],
        'Coupe' => ['coupe'],
        'Hatchback' => ['hatch', 'hatchback'],
        'Truck' => ['pickup', 'truck'],
    ];

    foreach ($map as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($titleLower, $keyword) !== false) {
                return $label;
            }
        }
    }

    return 'Vehicle';
}

function buildBuyerPersonaSection($title, $isEV, $bodyType) {
    $useCase = $isEV ? 'daily charging rhythm, home charging access, and route planning confidence' : 'annual mileage, fuel cost sensitivity, and service-network convenience';
    $personaA = "<li><strong>Urban Professional:</strong> Best if your priority is refinement, technology usability, and stress-free commuting in mixed traffic.</li>";
    $personaB = "<li><strong>Family-Oriented Driver:</strong> Strong candidate when cabin practicality, comfort, and predictable ownership costs matter most.</li>";
    $personaC = "<li><strong>Enthusiast Pragmatist:</strong> Suitable for buyers who want engaging performance without sacrificing real-world comfort and reliability.</li>";

    return "<h2>Who Should Buy This {$bodyType}?</h2>
"
        . "<p>Before committing to the {$title}, align your decision with real usage patterns: {$useCase}. The strongest purchase decisions come from fit, not hype.</p>
"
        . "<ul>{$personaA}{$personaB}{$personaC}</ul>
";
}

function buildFaqSection($title, $isEV) {
    $runningCost = $isEV
        ? 'In many markets, charging remains cheaper per kilometer than fuel, especially with home charging and off-peak tariffs.'
        : 'Running costs depend on driving style and service intervals, but predictable maintenance plans can stabilize yearly expenses.';

    $faq = [
        ['Is the ' . $title . ' good for daily use?', 'Yes. Its strongest argument is consistency in comfort, usability, and technology behavior across routine driving scenarios.'],
        ['How does it compare with rivals?', 'It competes best when buyers value balanced engineering and ownership confidence rather than headline numbers alone.'],
        ['What about long-term cost?', $runningCost],
    ];

    $html = "<h2>Frequently Asked Questions</h2>
";
    foreach ($faq as [$q, $a]) {
        $html .= "<h3>{$q}</h3>
<p>{$a}</p>
";
    }

    return $html;
}

function generateAutoTitle() {
    $year = (int)date('Y') + rand(0, 1);
    $brands = ['Toyota', 'BMW', 'Mercedes', 'Audi', 'Porsche', 'Tesla', 'Hyundai', 'Kia', 'Ford', 'Nissan'];
    $models = ['SUV', 'Sedan', 'Coupe', 'EV Crossover', 'Hybrid SUV', 'Performance Hatchback', 'Electric Sedan'];
    $angles = [
        'Full Review and Buyer Guide',
        'Long-Term Ownership Analysis',
        'Real-World Efficiency Test',
        'Daily Driving Impression',
        'Smart Technology Deep Dive',
        'Comparison and Value Breakdown'
    ];

    return sprintf(
        '%d %s %s %s',
        $year,
        $brands[array_rand($brands)],
        $models[array_rand($models)],
        $angles[array_rand($angles)]
    );
}

function generateUniqueAutoTitle($maxAttempts = 12) {
    $attempts = max(1, (int)$maxAttempts);
    for ($i = 0; $i < $attempts; $i++) {
        $title = generateAutoTitle();
        if (!articleExists($title)) {
            return $title;
        }
    }

    return null;
}

function publishAutoArticleBySchedule($force = false) {
    $enabled = getSettingInt('auto_ai_enabled', 1, 0, 1);
    if (!$force && $enabled !== 1) {
        return ['published' => 0, 'reason' => 'disabled'];
    }

    $intervalSeconds = getAutoPublishIntervalSeconds();
    $lastRun = strtotime((string)getSetting('auto_publish_last_run_at', '1970-01-01 00:00:00')) ?: 0;
    $now = time();

    if (!$force && ($now - $lastRun) < $intervalSeconds) {
        return ['published' => 0, 'reason' => 'not_due'];
    }

    $title = generateUniqueAutoTitle();
    if ($title === null) {
        return ['published' => 0, 'reason' => 'title_generation_failed'];
    }

    $data = generateArticle($title);
    if (!saveArticle($title, $data)) {
        return ['published' => 0, 'reason' => 'duplicate_title_or_content'];
    }
    setSetting('auto_publish_last_run_at', date('Y-m-d H:i:s', $now));

    return ['published' => 1, 'reason' => 'ok', 'title' => $title];
}

function getSetting($key, $default = null) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

function setSetting($key, $value) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([$key, (string)$value]);
}

function articleExists($title) {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT id FROM articles WHERE title = ?");
    $stmt->execute([$title]);
    return $stmt->fetch() !== false;
}

function normalizeTextForComparison($text) {
    $text = mb_strtolower((string)$text, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

function articleTitleVariantExists($title) {
    $pdo = db_connect();
    $baseSlug = slugify($title);
    if ($baseSlug === '') {
        return false;
    }

    $stmt = $pdo->prepare("SELECT 1 FROM articles WHERE slug = ? OR slug LIKE ? LIMIT 1");
    $stmt->execute([$baseSlug, $baseSlug . '-%']);
    return $stmt->fetchColumn() !== false;
}

function articleContentExists($content) {
    $normalizedContent = normalizeTextForComparison(strip_tags((string)$content));
    if ($normalizedContent === '') {
        return false;
    }

    $pdo = db_connect();
    $stmt = $pdo->query("SELECT content FROM articles ORDER BY id DESC LIMIT 200");
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rows as $storedContent) {
        $storedNormalized = normalizeTextForComparison(strip_tags((string)$storedContent));
        if ($storedNormalized !== '' && hash_equals($storedNormalized, $normalizedContent)) {
            return true;
        }
    }

    return false;
}

function isDuplicateArticlePayload($title, $content) {
    return articleExists($title)
        || articleTitleVariantExists($title)
        || articleContentExists($content);
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

function verifyAdminPassword($password) {
    if (!is_string($password) || $password === '') {
        return false;
    }

    $adminPassword = getenv('ADMIN_PASSWORD');
    if (is_string($adminPassword) && $adminPassword !== '' && hash_equals($adminPassword, $password)) {
        return true;
    }

    return password_verify($password, PASSWORD_HASH);
}

function getRandomIntro($title) {
    $intros = [
        "The $title arrives at a time when buyers expect more than raw performance—they expect intelligence, consistency, and real ownership value.",
        "The $title reflects a modern automotive philosophy where design, software, efficiency, and durability must all work together.",
        "With the $title, the brand is clearly targeting drivers who care about emotional appeal and practical decision-making in equal measure.",
        "The $title enters a competitive segment, and its real strength is how it balances premium character with day-to-day usability."
    ];

    return $intros[array_rand($intros)];
}

function pickRandomFrom(array $items) {
    return $items[array_rand($items)];
}

function buildAnalyticalParagraph($title, $sectionTitle, $focus, $isEV, $perspective) {
    $energyContext = $isEV
        ? 'its electric architecture, battery management logic, and charging ecosystem'
        : 'its engine calibration, transmission strategy, and thermal durability';

    $openingBank = [
        "In the context of {$sectionTitle}, the {$title} deserves attention for {$focus}.",
        "Looking at {$sectionTitle} through a practical lens, {$focus} becomes one of the most relevant points for {$title}.",
        "When analysts evaluate {$sectionTitle}, they usually start with {$focus}, and the {$title} performs in a convincing way."
    ];

    $analysisBank = [
        "The most credible part of this story is not a single headline figure, but the consistency of behavior across daily scenarios like traffic, highway cruising, and weekend travel.",
        "What separates mature products from average ones is repeatability, and here the vehicle keeps a stable character even when road quality, weather, and load conditions change.",
        "Instead of over-optimizing for lab-style results, the package appears tuned for real-world confidence where comfort, control, and predictability matter every day."
    ];

    $perspectiveBank = [
        "From an owner perspective, this means fewer compromises between comfort and capability, and a lower chance of buyer regret after the first months of excitement.",
        "For mixed-use drivers, this creates a meaningful advantage: the car feels refined in city conditions yet remains composed when pushed on open roads.",
        "From a long-term standpoint, this balance supports stronger perceived quality because the driving experience remains coherent rather than fragmented."
    ];

    $closingBank = [
        "That broader coherence is reinforced by {$energyContext}, which helps the {$title} translate engineering choices into tangible daily benefits.",
        "The result is a clearer value proposition: the {$title} is not merely impressive on paper, it is understandable and rewarding in normal ownership use.",
        "Ultimately, this is where product intelligence appears—different systems collaborate naturally instead of competing for attention."
    ];

    $paragraph = pickRandomFrom($openingBank) . ' '
        . pickRandomFrom($analysisBank) . ' '
        . pickRandomFrom($perspectiveBank) . ' '
        . pickRandomFrom($closingBank);

    if ($perspective !== '') {
        $paragraph .= ' ' . $perspective;
    }

    return "<p>{$paragraph}</p>";
}

function buildSectionContent($title, $sectionTitle, array $focusPoints, $isEV) {
    $perspectives = [
        'This is especially important in segments where buyers compare six or seven alternatives before committing.',
        'In competitive markets, small gains in usability often influence purchase decisions more than aggressive marketing claims.',
        'For families and frequent commuters, these details can be more valuable than short-term novelty features.'
    ];

    $paragraphs = [];
    foreach ($focusPoints as $index => $focus) {
        $paragraphs[] = buildAnalyticalParagraph(
            $title,
            $sectionTitle,
            $focus,
            $isEV,
            $perspectives[$index % count($perspectives)]
        );
    }

    return $paragraphs;
}

function ensureMinimumWordCount($html, $title, $minimumWords = 2100) {
    $expansionLibrary = [
        "<p>Beyond specifications, the {$title} should be evaluated through lifecycle quality: software stability over updates, ease of service access, parts availability, and dealership competence. These factors rarely appear in launch headlines, but they shape ownership satisfaction in a decisive way. Buyers who prioritize this complete picture are usually the ones who feel most confident in their purchase years after delivery.</p>",
        "<p>A final strategic angle concerns resale narrative. Vehicles that keep a clear identity, avoid over-complicated interfaces, and maintain predictable reliability tend to preserve value more effectively. The {$title} appears aligned with that principle by emphasizing balance rather than gimmicks, which is often the smarter long-term formula in a rapidly changing automotive market.</p>",
        "<p>From a product-planning perspective, the {$title} also signals where the brand may be heading next: deeper integration between hardware and software, stronger efficiency discipline, and a clearer user-first philosophy. If this direction continues, future iterations could build on an already credible foundation while reducing the remaining trade-offs that naturally exist in every vehicle category.</p>"
    ];

    $currentWords = str_word_count(strip_tags($html));
    $i = 0;
    while ($currentWords < $minimumWords) {
        $html .= "\n" . $expansionLibrary[$i % count($expansionLibrary)];
        $currentWords = str_word_count(strip_tags($html));
        $i++;
    }

    return $html;
}

function generateArticle($title) {
    $model = trim(preg_replace('/\b(202[0-9]|20[0-9]{2})\b/', '', $title));
    $isEV = stripos($title, 'EV') !== false || stripos($title, 'electric') !== false;
    $bodyType = classifyVehicleProfile($title);

    $content = "<h1>" . htmlspecialchars($title) . "</h1>\n";
    $content .= "<p class='text-muted'>Published " . date('F j, Y') . " • AutoCar Niche</p>\n";
    $content .= "<img src='https://loremflickr.com/800/450/car," . urlencode(strtolower($model)) . "' class='img-fluid rounded mb-4' alt='" . htmlspecialchars($title) . "'>\n";
    $content .= "<p>" . getRandomIntro($title) . " This review follows an editorial structure designed to deliver deep analysis, clear comparisons, and practical buying guidance.</p>\n";
    $content .= "<p><strong>Quick Take:</strong> The {$title} is a {$bodyType}-class product focused on balanced performance, everyday usability, and ownership predictability rather than one-dimensional headline metrics.</p>\n";

    $sections = [
        'Executive Summary and Market Position' => [
            'segment fit and target audience clarity',
            'how the model differentiates against direct rivals',
            'the real value story behind headline marketing claims'
        ],
        'Exterior Design, Proportion, and Visual Character' => [
            'surface treatment, stance, and brand identity execution',
            'aerodynamic decisions that influence both style and efficiency',
            'why design coherence affects owner satisfaction over time'
        ],
        'Cabin Quality, Space, and Human-Centered Ergonomics' => [
            'seat comfort, posture support, and long-distance usability',
            'dashboard hierarchy, physical controls, and interaction clarity',
            'perceived quality through materials, fit, and acoustic control'
        ],
        'Powertrain Intelligence, Performance Delivery, and Efficiency' => [
            'response quality under partial and full throttle situations',
            'efficiency behavior in urban, mixed, and highway duty cycles',
            'engineering trade-offs between excitement and sustainability'
        ],
        'Ride Comfort, Handling Balance, and Braking Confidence' => [
            'suspension tuning over varied road surfaces',
            'steering communication and directional stability at speed',
            'predictable braking behavior in repeated real-world use'
        ],
        'Technology Stack, Infotainment, and Connectivity Experience' => [
            'interface speed, readability, and cognitive simplicity',
            'smartphone integration and navigation reliability in practice',
            'software maturity, update path, and feature longevity'
        ],
        'Safety Systems, Driver Assistance, and Durability Outlook' => [
            'calibration quality of active safety interventions',
            'passive safety confidence and structural reassurance',
            'maintenance predictability and long-term reliability perception'
        ],
        'Ownership Economics, Trim Strategy, and Buyer Recommendations' => [
            'cost of ownership across fuel or charging, service, and insurance',
            'which configuration levels provide the strongest value density',
            'how to shortlist based on real priorities rather than hype'
        ]
    ];

    foreach ($sections as $sectionTitle => $focusPoints) {
        $content .= "<h2>{$sectionTitle}</h2>\n";
        foreach (buildSectionContent($title, $sectionTitle, $focusPoints, $isEV) as $paragraph) {
            $content .= $paragraph . "\n";
        }
    }

    $horsepower = rand(260, 640);
    $zeroToSixty = number_format(rand(34, 67) / 10, 1);
    $efficiencyLine = $isEV
        ? rand(420, 680) . ' km estimated range (mixed use)'
        : rand(6, 11) . ' L/100km combined estimate';
    $drivetrain = $isEV ? 'Dual Electric Motors (AWD)' : '2.5L Turbo + Advanced Automatic Transmission';

    $content .= "<h2>Technical Snapshot</h2>\n";
    $content .= "<table class='table table-bordered'><tr><th>Powertrain</th><td>{$drivetrain}</td></tr><tr><th>Output</th><td>{$horsepower} hp</td></tr><tr><th>0-60 mph</th><td>{$zeroToSixty} seconds</td></tr><tr><th>Efficiency</th><td>{$efficiencyLine}</td></tr><tr><th>Editorial Category</th><td>Auto</td></tr></table>\n";

    $content .= buildBuyerPersonaSection($title, $isEV, $bodyType);

    $content .= "<h2>Strengths and Trade-Offs</h2>\n";
    $content .= "<ul><li><strong>Strengths:</strong> Cohesive engineering balance, strong day-to-day usability, mature technology integration, and a clear long-term value narrative.</li><li><strong>Trade-Offs:</strong> Higher entry price in premium trims, optional packages that may overlap in features, and availability pressure in high-demand regions.</li></ul>\n";

    $content .= buildFaqSection($title, $isEV);

    $content .= "<h2>Final Editorial Verdict</h2>\n";
    $content .= "<p class='mt-3'>The {$title} succeeds because it behaves like a complete product, not a collection of isolated features. It combines emotional appeal with practical intelligence, and that combination is exactly what modern buyers need in an uncertain, fast-evolving market. If your priority is a vehicle that remains convincing beyond launch-week excitement, this model is a serious and well-justified candidate.</p>";

    $minimumWords = getSettingInt('min_words', 1600, 900, 3200);
    $content = ensureMinimumWordCount($content, $title, $minimumWords);

    $plainText = trim(strip_tags($content));
    $excerpt = mb_substr($plainText, 0, 340);
    if (mb_strlen($plainText) > 340) {
        $excerpt .= '...';
    }

    return [
        'content' => $content,
        'excerpt' => $excerpt,
        'image' => "https://loremflickr.com/800/450/car," . urlencode(strtolower($model))
    ];
}

function saveArticle($title, $data) {
    if (isDuplicateArticlePayload($title, $data['content'] ?? '')) {
        return false;
    }

    $pdo = db_connect();
    $slug = generateUniqueSlug($title);
    $stmt = $pdo->prepare("INSERT INTO articles (title, slug, content, image, excerpt, published_at, category) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$title, $slug, $data['content'], $data['image'], $data['excerpt'], date('Y-m-d H:i:s'), 'Auto']);
    return true;
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
