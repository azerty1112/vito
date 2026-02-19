# vito

Simple PHP automotive blog generator with:

- Public homepage listing generated articles.
- Search by title/excerpt.
- Quick category chips + view switcher (grid/list).
- Configurable public pagination size (6/9/12/18 per page).
- Pagination on public article list.
- Single article view with related posts and estimated reading time.
- Admin panel for manual and fully automatic AI article generation (auto title + scheduled publishing).
- RSS workflow automation using configurable `daily_limit` with Symfony DomCrawler + CSS Selector feed parsing (RSS/Atom).
- Normal websites workflow using Symfony DomCrawler + CSS selectors to extract article titles from regular news pages.
- Workflow selector (`rss` or `web`) used by both admin manual run and `cron.php`.
- RSS source management (add/remove).
- Article management (view/delete).
- CSRF protection for admin actions.
- JSON API for public articles and site stats (`api.php`) including workflow health summary.
- Smart scalable title generation with SEO modifiers.
- URL fetching with anti-block behavior (custom UA + retry backoff) and SQLite caching.
- Queue-based scraping to avoid source overload.
- Content pipeline for cleaning/normalizing titles, merge+deduplicate, and SEO block generation.
- Multi-format persistence for each article (DB + exported HTML + exported JSON in `data/exports/`).

## Quick start

Install PHP dependencies (required for Symfony feed crawling):

```bash
composer install
```

```bash
php -S 0.0.0.0:8000
```

Open:

- `http://localhost:8000/index.php`
- `http://localhost:8000/admin.php`

Default admin password: `admin123`

For production, set an environment variable before running:

```bash
ADMIN_PASSWORD="your-strong-password" php -S 0.0.0.0:8000
```

## API endpoints

Run the server then test:

```bash
curl "http://localhost:8000/api.php"
curl "http://localhost:8000/api.php?endpoint=stats"
curl "http://localhost:8000/api.php?endpoint=article&slug=your-article-slug"
```

Supported query params for `api.php` (articles endpoint): `q`, `category`, `published_from`, `published_to`, `sort` (`newest`, `oldest`, `title_asc`, `title_desc`, `relevance`), `page`, `per_page`.

### Automatic AI publishing

From `admin.php`, you can also choose the active **Content Workflow** (`RSS Workflow` or `Normal Sites Workflow`). The same selected workflow is executed when you click run manually and when `cron.php` is called.

From `admin.php`, you can enable **AI Auto Publish Scheduler** to:

- Generate a title automatically.
- Generate the full article from that title without manual input.
- Publish automatically every configured interval (seconds).

Note: scheduler runs when site endpoints are visited (`index.php`, `api.php`, `admin.php`) or when clicking **Generate Title + Publish Now** in admin.

For production, use your hosting Cron Job to call:

```
https://your-domain.com/cron.php
```

You can copy the full URL directly from the admin panel (**AI Auto Publish Scheduler** section). No token is required. The generated URL supports HTTPS behind proxy and subfolder installs.

For a quick demo, set **Publish Every (seconds)** to `10` and call `cron.php` every 10 seconds from your hosting cron/external cron service.

You can also run it from server CLI:

```bash
php cron.php
```

Optional (recommended for shared hosting like Namecheap): set a cron key in `settings` (`cron_access_key`) and call:

```
https://your-domain.com/cron.php?key=YOUR_SECRET_KEY
```


### Better article quality

Generated articles now include:
- a quick executive takeaway,
- a buyer persona section (who should buy),
- an FAQ block,
- and dynamic minimum word count based on the `min_words` setting.
