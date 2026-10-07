# Neptune Public 1.0 security hardening

This release hardens four public-hosting boundaries.

## Web-root deployment artifacts

The application `.htaccess` denies direct HTTP access to ZIPs, SQL/INI/log/env
files, `.bak*`, `.before*`, editor swap/backup files, hidden files, and the
retired `add_neptune_admins.php` utility.

Historical deployment artifacts should still be moved out of the web root.
The companion server-cleanup helper archives them under `/var/backups/neptune-webroot-cleanup/`.

## Uploads

`uploads/.htaccess` explicitly denies executable/script extensions. Neptune's
normal image validation/re-encoding remains the primary control; this is a
second web-server boundary.

## Platform-owner browser tools

File Manager, Maintenance Console, Platform Administration, and platform-owner
AUGUR Operations require a Google-authenticated Neptune session. A local
password session is insufficient for those high-value tools.

Neptune cannot turn on Google 2-Step Verification. Enforce 2-Step Verification
for the platform owner's Google/Workspace account in Google administration.

## Offline sync tenant binding

Offline field-server keys are now tied to an organization.

Preferred multi-tenant format in `/etc/scout/offline-sync.env`:

    NEPTUNE_OFFLINE_SYNC_KEY_ORG_1=<secret-for-org-1>
    NEPTUNE_OFFLINE_SYNC_KEY_ORG_2=<different-secret-for-org-2>

Backward-compatible single-server format:

    NEPTUNE_OFFLINE_SYNC_KEY=<existing-secret>
    NEPTUNE_OFFLINE_SYNC_ORGANIZATION_ID=1

The old unbound `NEPTUNE_OFFLINE_SYNC_KEY` by itself is deliberately rejected.
The request still includes `organization_id`, but that ID only selects the
organization-specific credential; a key for one organization cannot authorize
writes to another.
