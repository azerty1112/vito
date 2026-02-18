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

    $content = "<h1>" . htmlspecialchars($title) . "</h1>\n";
    $content .= "<p class='text-muted'>Published " . date('F j, Y') . " • AutoCar Niche</p>\n";
    $content .= "<img src='https://loremflickr.com/800/450/car," . urlencode(strtolower($model)) . "' class='img-fluid rounded mb-4' alt='" . htmlspecialchars($title) . "'>\n";
    $content .= "<p>" . getRandomIntro($title) . " This review follows an editorial structure designed to deliver deep analysis, clear comparisons, and practical buying guidance.</p>\n";

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

    $content .= "<h2>Strengths and Trade-Offs</h2>\n";
    $content .= "<ul><li><strong>Strengths:</strong> Cohesive engineering balance, strong day-to-day usability, mature technology integration, and a clear long-term value narrative.</li><li><strong>Trade-Offs:</strong> Higher entry price in premium trims, optional packages that may overlap in features, and availability pressure in high-demand regions.</li></ul>\n";

    $content .= "<h2>Final Editorial Verdict</h2>\n";
    $content .= "<p class='mt-3'>The {$title} succeeds because it behaves like a complete product, not a collection of isolated features. It combines emotional appeal with practical intelligence, and that combination is exactly what modern buyers need in an uncertain, fast-evolving market. If your priority is a vehicle that remains convincing beyond launch-week excitement, this model is a serious and well-justified candidate.</p>";

    $content = ensureMinimumWordCount($content, $title, 2100);

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
