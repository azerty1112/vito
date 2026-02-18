# vito

Simple PHP automotive blog generator with:

- Public homepage listing generated articles.
- Search by title/excerpt.
- Pagination on public article list.
- Single article view with related posts and estimated reading time.
- Admin panel for manual article generation.
- RSS fetch automation using configurable `daily_limit`.
- RSS source management (add/remove).
- Article management (view/delete).
- CSRF protection for admin actions.
- JSON API for public articles and site stats (`api.php`).

## Quick start

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

Supported query params for `api.php` (articles endpoint): `q`, `category`, `sort`, `page`, `per_page`.
