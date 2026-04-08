# Development & Deployment

## Environments

| URL | Document root | Database | Config file |
|---|---|---|---|
| `http://localhost/energie.test` | `/Users/erikr/Git/Energie/web` | `energie_dev` | `energie-config-dev.ini` |
| `http://localhost/energie` | `/Library/WebServer/Documents/Energie/web` | `energie` | `energie-config.ini` |

Dev serves directly from the Git working tree — no deploy step needed for PHP changes during development. Production is updated explicitly by running `deploy.sh`.

---

## Apache Configuration

Two `Alias` directives in `/opt/homebrew/etc/httpd/httpd.conf`:

```apache
Alias /energie      /Library/WebServer/Documents/Energie/web
Alias /energie.test /Users/erikr/Git/Energie/web
```

Each alias requires a `<Directory>` block with `AllowOverride All` to respect `.htaccess` files.

`$base` URL prefix is derived dynamically in `inc/db.php`:
```php
$base = '/' . explode('/', ltrim($_SERVER['SCRIPT_NAME'], '/'))[0];
```
This extracts the first path segment (`energie` or `energie.test`) from the script name, so no hardcoded URLs appear anywhere in the PHP code. All generated links, asset URLs, and API calls use `$base` as their prefix.

---

## Config Files

Two INI files at `/opt/homebrew/etc/`:

```
energie-config.ini      → production (database = energie)
energie-config-dev.ini  → development (database = energie_dev)
```

`inc/db.php` selects between them based on `$base`. `inc/initialize.php` always reads `energie-config.ini` for non-DB constants (SMTP, APP_BASE_URL, auth DB credentials). This means SMTP and auth settings are shared between dev and prod — emails sent from dev use the same outbound server and land in the same auth DB.

**Config file structure:**

```ini
[db]
host     = localhost
user     = energie
password = …
database = energie       ; or energie_dev

[auth]
host     = localhost
user     = …
password = …
database = jardyx_auth

[smtp]
host      = …
port      = 587
user      = …
password  = …
from      = energie@example.com
from_name = Energie

[app]
base_url = http://localhost/energie

[slack]
bot_token  = xoxb-…
channel_id = C…
```

`config.ini` (in the project root) is used exclusively by `energie.py` and is gitignored — copy from `config.ini.example` and fill in your credentials. Python only reads `[db]` and `[slack]`; other sections are ignored.

---

## deploy.sh

Running `./deploy.sh` from the project root:

1. **Syncs files** — rsync `web/`, `inc/`, `vendor/` from Git working tree to `/Library/WebServer/Documents/Energie/` using `--delete --copy-links`
   - `--delete` removes files from prod that no longer exist in dev
   - `--copy-links` dereferences the `vendor/erikr/auth` symlink so real files are copied
2. **Refreshes dev DB** — dumps `energie` (prod) and restores into `energie_dev` (dev)

```bash
mysqldump \
  --single-transaction --no-tablespaces \
  -u"$DB_USER" -p"$DB_PASS" \
  "$DB_PROD" \
  | mysql -u"$DB_USER" -p"$DB_PASS" "$DB_DEV"
```

`--single-transaction` avoids table locks (InnoDB consistent read). `--no-tablespaces` avoids needing the `PROCESS` privilege.

After deploying, `http://localhost/energie.test` reflects the latest production data, so you can test against a realistic dataset without touching prod.

---

## Database Management

### Initial setup

```sql
-- Run as root
CREATE DATABASE energie     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE energie_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'energie'@'localhost' IDENTIFIED BY '…';
GRANT SELECT, INSERT, UPDATE ON energie.*     TO 'energie'@'localhost';
GRANT ALL                   ON energie_dev.* TO 'energie'@'localhost';
FLUSH PRIVILEGES;
```

### Refreshing dev from prod manually

```bash
mysqldump --single-transaction --no-tablespaces \
  -u energie -p… energie \
  | mysql -u energie -p… energie_dev
```

Or simply run `./deploy.sh`, which does this automatically.

### Adding a new tariff period

Via the admin panel (planned) or directly:
```sql
INSERT INTO tariff_config (valid_from, provider_surcharge_ct, electricity_tax_ct,
  renewable_tax_ct, meter_fee_eur, renewable_fee_eur,
  consumption_tax_rate, vat_rate, yearly_kwh_estimate)
VALUES ('2026-07-01', 1.90, 0.10, 0.796, 4.695, 19.02, 0.07, 0.20, 3000.00);
```

---

## Slack Notifications

`python energie.py notify` sends Slack briefings. Called from cron on a schedule:

```cron
# Every day at 07:00
0 7 * * * cd /path/to/Energie && python energie.py notify
```

The `notify` command is conditional:
- **Daily briefing** — always, for the most recent day in `daily_summary`
- **Weekly briefing** — only on Tuesdays (ISO weekday 1)
- **Monthly briefing** — only on the 2nd of the month

Each briefing posts a text summary plus (for weekly and monthly) a `matplotlib` chart image uploaded via `files.upload_v2`. Chart colours match the web UI palette (`#e94560` for cost bars, `#68d391` for consumption line).

---

## Python Dependencies

```
mysql-connector-python   DB connection
requests                 Hofer API HTTP calls
openpyxl                 XLSX file parsing
matplotlib               Slack chart generation
slack_sdk                Slack Web API client
certifi                  SSL certificates for Slack client
```

No virtual environment is strictly required if these are installed globally, but a venv is recommended for isolation.

---

## Composer / PHP Dependencies

```bash
composer install          # install from composer.lock
```

The only Composer dependency is `erikr/auth`, symlinked as a local path repository. `deploy.sh` dereferences the symlink via `--copy-links` so production gets the actual files, not a symlink that would be broken outside the dev environment.
