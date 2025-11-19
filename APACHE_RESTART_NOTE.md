# ⚠️ IMPORTANT: Apache Restart After SQLite Installation

## Issue
After installing `php8.2-sqlite3` extension, Apache was still using the old PHP configuration without SQLite support.

## Solution
Restarted Apache to load the new PHP extension:

```bash
systemctl restart apache2
```

## How to Verify SQLite is Loaded in Apache

```bash
# Check PDO drivers available to Apache's PHP
curl -s http://localhost/phpinfo.php | grep "PDO drivers"

# Should show: mysql, sqlite, sqlsrv
```

## When to Restart Apache

Restart Apache whenever you:
- Install new PHP extensions
- Modify PHP configuration files (`/etc/php/8.2/apache2/php.ini`)
- Change `.env` file (config is cached, run `php artisan config:clear` too)

## Quick Restart Command
```bash
systemctl restart apache2
```
