# Neptune requirements

## Server

- Ubuntu 22.04/24.04 LTS or Debian
- Apache 2.4
- PHP 8.x with PDO MySQL, cURL, mbstring, XML, GD and ZIP
- MariaDB 10.6+
- Writable runtime storage under `/var/www/neptune` for uploads, maintenance backups, game assets and Spot media

## Browser clients

Modern Chrome/Chromium, Safari, or Firefox. Match Scouting's Connection Guard
uses IndexedDB for queued action protection.

## Optional external services

- The Blue Alliance API (TBA key required for live TBA sync)
- Statbotics public API (no key; Neptune should remain usable when unavailable)
- AWS/Internet connectivity for cloud-hosted operation and field-to-cloud sync

These external services are data sources, not requirements for a local Apache/MariaDB install to start.
