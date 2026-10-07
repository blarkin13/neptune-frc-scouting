Neptune - Current Installation and Update Guide
================================================

This file describes the current application. Neptune does NOT use
/Neptune/admin/install.php.

FRESH INSTALLATION
------------------

1. Requirements
   - PHP 8.x
   - PDO MySQL
   - cURL
   - PHP sessions
   - MySQL 8.x or compatible MariaDB
   - Apache/Nginx or another PHP-capable web server
   - HTTPS for production
   - The Blue Alliance API key
   - GD or Imagick recommended for pit-photo optimization

2. Directory layout

   public_html/Neptune/     Public web application
   neptune_secure/          Private PHP configuration/helpers; keep outside web root
   sql/                     Baseline database schema
   scripts/                 Host/support scripts

3. Database

   Create a database and import:

     sql/neptune_schema.sql

   This is the baseline schema for a fresh installation.

4. Private configuration

   Copy:

     neptune_secure/config.example.php

   to:

     neptune_secure/config.php

   Enter the database settings, base URL/timezone as needed, and TBA API key.
   Never commit live credentials.

5. First organization

   Open:

     /Neptune/register.php

   or open Neptune's public sign-in page and choose "Register an organization".

   Create the first organization and owner there. The first organization is the
   platform organization and its owners receive the platform-only administration
   and maintenance tools.

   There is no admin/install.php step and no installer file to remove afterward.

6. Optional Google sign-in

   Current Google/OIDC support reads these values from:

     /etc/scout/neptune.env

   GOOGLE_OAUTH_CLIENT_ID="..."
   GOOGLE_OAUTH_CLIENT_SECRET="..."
   GOOGLE_OAUTH_REDIRECT_URI="https://your-host/Neptune/auth/google-callback.php"

   If these settings are absent, password sign-in remains available.

7. Validate the installation

   Sign in as the first owner and check:
   - Command Center -> System Check
   - Command Center -> Platform Administration
   - Command Center -> Maintenance Console
   - Teams & Users

PASSWORD / GOOGLE LOGIN
-----------------------

Password sign-in uses:
  organization name or Neptune slug + username + password

The public login page does not expose a global organization dropdown.

Google sign-in does not ask the user to select an organization first. Neptune
routes only to memberships tied to that verified identity, an approved Workspace
domain, or a valid invitation.

Forgot Password uses Google verification before allowing a new local Neptune
password. Owners/admins can also issue temporary passwords in Teams & Users.

UPDATES AND DATABASE MIGRATIONS
-------------------------------

Neptune intentionally keeps updates self-contained rather than using a large
migration framework.

Schema-changing updates use the shared migration helper and record:

  neptune_migrations
  ------------------
  migration_key
  applied_at
  package

Maintenance Console shows:
  - Files updated
  - DB migrations applied
  - Installed update history
  - DB migration history

A Maintenance Console file rollback restores files only. It does NOT automatically
reverse database migrations. Schema migrations should therefore remain idempotent
and forward-safe.

Do not manually replay old migration SQL unless a package specifically instructs
you to do so.

PRODUCTION HANDOFF
------------------

The platform owner should know how to:
  - manage tenant organizations in Platform Administration
  - recover an organization owner
  - suspend/reactivate a tenant
  - export tenant data
  - manage files through the platform-owner File Manager
  - install/rollback patch files through Maintenance Console
  - review neptune_migrations
  - manage users/roles/temporary passwords/invitations through Teams & Users

For normal account recovery, direct MySQL edits should not be necessary.
