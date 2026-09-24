# Neptune installation

This repository is intended to be installable on a clean Ubuntu/Debian server.
The installer sets up Apache, PHP, MariaDB, the Neptune application tree, the
protected configuration directory, and the complete Neptune database schema.

## Supported clean-install target

- Ubuntu 22.04/24.04 LTS or current Debian
- x86_64 or ARM64
- root/sudo access
- Internet access during installation for `apt`
- Apache 2.4
- PHP 8.x
- MariaDB 10.6+

The runtime can operate without Internet for local scouting features, subject
to the separate Neptune offline-readiness work and cached external data.

## One-command install

```bash
git clone https://github.com/blarkin13/neptune-frc-scouting.git
cd neptune-frc-scouting
sudo ./install.sh
```

By default Neptune is installed to:

```text
/var/www/neptune/public_html/Neptune
/var/www/neptune/neptune_secure
```

The installer creates a MariaDB database named `neptune`, creates a random
local-only database password, imports `sql/install_schema.sql`, configures an
Apache vhost, validates every PHP file, verifies all required tables, and runs
a health check.

After installation, browse to:

```text
http://neptune.local/register.php
```

and create the first organization/owner account.

## Optional installer settings

Set environment variables before `sudo` when a different deployment is needed:

```bash
sudo env \
  NEPTUNE_SERVER_NAME=neptune.example.org \
  NEPTUNE_TIMEZONE=America/Chicago \
  NEPTUNE_TBA_KEY='YOUR_TBA_KEY' \
  ./install.sh
```

Available variables:

| Variable | Default | Purpose |
|---|---|---|
| `NEPTUNE_ROOT` | `/var/www/neptune` | Deployment root |
| `NEPTUNE_DB_NAME` | `neptune` | MariaDB database |
| `NEPTUNE_DB_USER` | `neptune_app` | MariaDB application user |
| `NEPTUNE_SERVER_NAME` | `neptune.local` | Apache `ServerName` |
| `NEPTUNE_BASE_URL` | empty | URL prefix; leave empty when Neptune is the site root |
| `NEPTUNE_TIMEZONE` | `UTC` | PHP/application timezone |
| `NEPTUNE_TBA_KEY` | empty | Optional Blue Alliance API key |

## What the installer installs

Packages:

```text
apache2
mariadb-server
php
libapache2-mod-php
php-mysql
php-curl
php-mbstring
php-xml
php-gd
php-zip
curl
unzip
rsync
openssl
ca-certificates
```

It also enables Apache `rewrite`, `headers`, and `expires` modules.

## Database

The authoritative clean-install schema is:

```text
sql/install_schema.sql
```

`sql/EXPECTED_TABLES.txt` contains the required table manifest used by the
installer and verification script. The current schema includes the core
multi-organization scouting tables plus Game Configuration revisions/sharing,
Alliance Selection state, Offline Sync tracking, AUGUR EPA/OPR tables, Match
Strategy, Connection Guard request receipts, and Spot Scouting.

## Verify an installed server

```bash
sudo /var/www/neptune/scripts/verify-install.sh
```

## Existing production servers

Do **not** run `install.sh` over an existing Neptune deployment. It intentionally
stops if `/var/www/neptune` is already populated. Existing systems should be
updated with reviewed maintenance patches/migrations instead.

## HTTPS

The installer creates an HTTP Apache vhost only. Internet-facing production
systems should add TLS (for example with an AWS load balancer/reverse proxy or
Certbot) and then set `trust_proxy` appropriately if HTTPS terminates upstream.

## Secrets

`neptune_secure/config.php` is generated during installation and is ignored by
Git. Database credentials are also stored at `/etc/scout/db.env` with mode 600.
Never commit either file.
