# Neptune Production Backup and Restore

## Purpose

Production backups are stored off the Neptune EC2 filesystem in the private GitHub repository:

`blarkin13/neptune-production-backups`

The application source repository and the production backup repository are separate.

## Schedule

`neptune-production-backup.timer` runs nightly at **08:15 UTC** with up to five minutes of randomized delay. The timer is persistent, so a missed run caused by a reboot is executed after the server returns.

## Backup contents

Each GitHub Release contains:

- an AES-256 encrypted archive
- a SHA-256 checksum for that encrypted archive

Inside the encrypted archive are:

- `database.sql.gz` — MySQL/MariaDB logical dump
- `uploads.tar.gz` — `/var/www/neptune/public_html/Neptune/uploads`
- `games.tar.gz` — game JSON files
- `manifest.json`
- `SHA256SUMS`

The backup deliberately does **not** include:

- `/etc/scout/db.env`
- `/etc/scout/neptune.env`
- `/etc/neptune/github-backup.env`
- `/etc/neptune/backup.key`

Those remain server secrets. The backup encryption key must be retained somewhere off the EC2 server.

## Retention

The automated job keeps approximately:

- all nightly backups for 14 days
- one weekly backup for the following 8 weeks
- one monthly backup for up to 12 months

Older GitHub Releases and their backup tags are deleted automatically.

## Manual backup

Run:

```bash
sudo /opt/neptune/bin/neptune-backup-github
```

Check the result:

```bash
cat /var/lib/neptune-backup/status.json
journalctl -u neptune-production-backup.service -n 100 --no-pager
```

## Restore latest backup

**This is destructive to the current Neptune database/uploads.**

Run:

```bash
sudo /opt/neptune/bin/neptune-restore-github latest --yes
```

The restore tool first creates a local pre-restore safety copy under:

```text
/var/backups/neptune-pre-restore/
```

It then verifies the GitHub backup checksum, decrypts it, verifies the internal checksums, stops Apache, restores the database/uploads/games, and starts Apache again.

## Restore a specific backup

Use the GitHub Release tag:

```bash
sudo /opt/neptune/bin/neptune-restore-github neptune-backup-YYYYMMDD-HHMMSSZ --yes
```

## Disaster recovery on a replacement server

Before running the restore tool on a replacement host, recreate:

```text
/etc/scout/db.env
/etc/neptune/github-backup.env
/etc/neptune/backup.key
```

Install the required command-line tools and the Neptune application, then run the restore command.

The encryption key is mandatory. A GitHub backup cannot be decrypted without it.

## Systemd commands

```bash
systemctl status neptune-production-backup.timer
systemctl list-timers neptune-production-backup.timer
systemctl start neptune-production-backup.service
journalctl -u neptune-production-backup.service -n 100 --no-pager
```

## Security

The GitHub token is root-readable only and is scoped to the private backup repository. The encryption key is root-readable only. Database contents and uploaded files are encrypted before they leave the EC2 server.
