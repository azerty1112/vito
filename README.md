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
