# Neptune

**FRC Scouting, Pit Scouting, Event Operations, Analytics & Strategy Platform**

Neptune is a web-based scouting and strategy platform for the **FIRST Robotics Competition (FRC)**. It combines live match scouting, tag scouting, pit scouting, pre-scouting, event operations, robot intelligence, alliance strategy, organization management, controlled data sharing, and The Blue Alliance integration in one PHP/MySQL application.

> **Current release:** Neptune Public 1.0 (`v1.0.0`)

## What Neptune includes

Neptune is organized into functional modules:

- **TRIDENT** — Match Scouting, Tag Scouting, Pit Scouting, and Pre-Scouting
- **SATURN** — Command Center, Event Operations, Match Control, and Administration
- **AUGUR** — Analytics, Robot Intelligence, Ratings, and Strategy
- **MERCURY** — System Maintenance, Diagnostics, Platform Administration, and File Operations
- **VULCAN** — Game Builder, Pit Form Builder, and Pre-Scout Form Builder
- **SALT** — Database access and organization-scoped data tools

The module names are organizational labels inside one Neptune application; they do not require separate installations.

## Main navigation

### Scouting · TRIDENT

The Scouting hub provides:

- **Match Scouting** — detailed live action scouting with success/failure tracking, scoring, timing, cycles, autonomous, teleop, and endgame context
- **Tag Scouting** — fast Match, Pit, and Team observations using shared tags, notes, scoring contribution, photos, and video
- **Pit Scouting** — robot capabilities, configuration, reliability, photos, and game-specific pit questions
- **Pre-Scouting** — team research, outreach tracking, and reusable season knowledge before an event

### Analytics & Strategy · AUGUR

The Analytics & Strategy hub includes:

- **Database Lab** — Strategy+ read-only query builder, SQL, and CSV export with tenant scoping
- **Robot Lookup** — search by team number or name and combine TBA identity, pit scouting, pre-scouting, and event match data
- **Robot Intelligence** — scouting-derived robot performance, cycles, scoring, autonomous, defense, pit data, notes, and photos
- **Robot Stats Table** — event-wide sortable comparison of offense, defense, Neptune EPA, Neptune D-EPA, and scouting coverage
- **Match Board** — six-robot matchup view for past and upcoming matches
- **Pit Operations Board** — pit readiness, queue timing, checklists, alliance context, next-match briefing, and TV mode
- **EPA Ratings** — public TBA-derived overall, Auto, Teleop, and Endgame EPA archive with REST API access
- **D-EPA Ratings** — defensive suppression ratings, including scouting-confirmed Neptune D-EPA
- **Match Strategy** — roles, autonomous assignments, opponent priorities, endgame responsibilities, and saved plans
- **Alliance Selection** — pick-list workspace, robot comparisons, declines/broken-robot tracking, scenarios, and live draft support

### Command Center · SATURN

The Command Center is the operational and administrative hub.

#### Event Operations

Strategy+ tools include:

- **Match Control** — Ready, Start, Pause, End, and re-scout match workflows
- **Live Monitor** — connected scouts, assignments, current match state, and recorded actions
- **Event Setup** — create events, select games, manage rosters, and set the current event
- **Manual Match Schedule** — create schedules when an event is not available from TBA
- **TBA Sync** — import/refresh official rosters, schedules, and event links
- **Pit Games** — optional interactive pit activities such as Robot Recall, reaction challenges, and card-magic displays

#### VULCAN Builders

Admin+ tools include:

- **Game Builder** — scoring actions, field layout, button configuration, points, and match timing
- **Pit Form Builder** — game-specific pit capability questions
- **Pre-Scout Form Builder** — pre-event research fields and reusable season templates

#### Organization

Neptune supports multiple organizations and multiple FRC teams within an organization.

Organization tools include:

- **Teams & Users** — accounts, roles, teams, invitations, temporary passwords, Google linking, deactivate/reactivate, and editable FRC teams
- **Interface Styling** — organization-scoped reusable themes with dark/light presentation
- organization-scoped sharing and access controls

#### System / Platform

Platform-owner and system tools include:

- **System Check**
- **File Manager**
- **Maintenance Console**
- **Platform Administration**
- **AUGUR Operations**
- production backup status and maintenance history

Platform-only tools require strong authentication.

## Live match scouting

TRIDENT match scouting supports:

- Administrator-controlled Ready / Start / Pause / End workflow
- official field-station and robot assignments
- Autonomous, transition, Teleop, and Endgame phases
- game-defined action buttons generated from JSON
- swipe right for Success and left for Failure
- repeated action entry without reopening the action dialog
- current score/action counts and last-action confirmation
- live administrative monitoring
- action history with auditable soft-delete
- re-scout support for replayed/restarted FRC matches while retaining prior runs for audit history

## Game Builder

Neptune is game-driven rather than hard-coded to one FRC season.

Game definitions can include:

- Autonomous duration
- Auto-to-Teleop transition pause
- Teleop duration
- Endgame timing
- scoring/action names and unique codes
- point values
- categories and locations
- button colors
- rectangular sizes: 1x1, 1x2, 2x1, 1x4, 4x1
- circular sizes: 1x1, 2x2, 4x4
- four-column snap-to-grid layouts

Existing game definitions can be loaded, edited, previewed, and saved as JSON.

## Pre-Scouting

Pre-Scouting is event-based and can begin before teams arrive at competition.

Neptune can track each team as:

- Not Started
- Contacted
- Response Received
- No Response
- Unavailable

The overview can combine team/location/event data from The Blue Alliance with optional public Statbotics EPA/season information.

Pre-scout answers are stored as both:

1. an event-specific snapshot, and
2. reusable season knowledge for the same robot/game.

This allows known robot information to carry forward while event-specific assignments and responses remain local to the event.

## Pit Scouting

Pit scouting can begin before an official qualification schedule exists.

Features include:

- searchable event roster
- Draft / Complete status
- drivetrain and robot basics
- intake/scoring capabilities
- autonomous and endgame information
- preferred roles and defense information
- reliability / known issues
- alliance-partner notes
- phone camera or desktop upload
- automatic image resizing
- AVIF preferred with WebP/JPEG fallback
- game-specific questions from the Pit Form Builder

## Event lifecycle

Neptune can operate an event before TBA publishes a match schedule.

A typical event progresses through:

```text
Planned
  -> Pit Scouting Open
  -> Schedule Ready
  -> Matches Running
  -> Complete
```

Roster sync and schedule sync are independent. Pit scouting data is retained when a schedule later arrives.

## The Blue Alliance integration

Neptune uses **The Blue Alliance API v3** for official FRC information such as:

- team events
- event rosters
- qualification and playoff schedules
- red / blue alliances and field stations
- match scores and results
- event links and identity data

Manual schedules remain available when an event is not yet available from TBA.

## Multi-organization and role model

Neptune supports multiple organizations on one installation while keeping operational data scoped to the owning organization.

Roles:

- **Scout**
- **Strategy**
- **Admin**
- **Owner**

User administration follows a role ceiling:

- Strategy can manage Scouts and assign roles up to Strategy
- Admin can manage Scouts/Strategy and assign roles up to Admin
- Owner can manage lower roles and assign roles up to Owner
- users cannot change their own role or status

Other multi-tenant behavior includes:

- organization name/slug + username + password sign-in
- optional Google sign-in
- exact verified-email linking to existing users
- optional approved Workspace auto-provisioning as Scout
- one-time invitation links
- organization lookup on the public password sign-in page
- suspension/reactivation at the platform level
- tenant data export from Platform Administration
- organization-scoped Interface Styling
- organization-scoped Database Lab operational data
- team-controlled data sharing

## Authentication and Platform Owner MFA

Google OAuth is optional for normal Neptune use.

Platform Owner accounts require stronger authentication for high-value tools such as File Manager, Maintenance Console, Platform Administration, and platform operations.

Supported Platform Owner paths are:

- **Google sign-in**, or
- **Neptune password + TOTP authenticator code**

Password-based Platform Owner access includes:

- Google Authenticator QR enrollment
- manual setup-key fallback
- one-time recovery codes
- encrypted TOTP seeds at rest
- replay protection for previously used TOTP time steps
- tracked migration: `security.platform-owner-mfa.v1`

A self-hosted installation does **not** require Gmail or Google Workspace.

## Technology

- PHP 8.x
- MySQL 8.x / compatible MariaDB
- PDO MySQL
- JavaScript
- HTML / CSS
- JSON game definitions
- Font Awesome
- The Blue Alliance API v3
- optional Statbotics public data for pre-scout context

Neptune intentionally avoids requiring a large PHP framework.

## Repository layout

```text
neptune-frc-scouting/
├── .github/
│   └── workflows/
│       └── php-lint.yml
├── neptune_secure/
│   ├── bootstrap.php
│   ├── config.example.php
│   ├── platform-mfa.php
│   └── ...
├── public_html/
│   └── Neptune/
│       ├── admin/
│       ├── analytics/
│       ├── api/
│       ├── assets/
│       ├── auth/
│       ├── dashboard/
│       ├── docs/
│       ├── games/
│       ├── images/
│       ├── pit/
│       ├── prescout/
│       ├── scout/
│       ├── scouting.php
│       └── index.php
├── scripts/
├── sql/
│   └── neptune_schema.sql
├── LICENSE
└── README.md
```

`neptune_secure` belongs **outside the public web root** in a hosted installation.

## Requirements

Recommended:

- PHP 8.x
- PDO MySQL
- PHP sessions
- cURL
- MySQL 8.x or compatible MariaDB
- Apache, Nginx, or another PHP-capable web server
- HTTPS for production
- The Blue Alliance API key
- Imagick/ImageMagick or PHP GD recommended for pit-photo optimization

## Fresh self-hosted installation

1. Clone or download the repository.
2. Create an empty MySQL database.
3. Import `sql/neptune_schema.sql`.
4. Place `public_html/Neptune/` below your public web root.
5. Place `neptune_secure/` beside `public_html`, outside the web root.
6. Copy `neptune_secure/config.example.php` to `neptune_secure/config.php`.
7. Configure database settings, base URL/timezone, TBA key, and application settings.
8. Open `/Neptune/register.php`.
9. On an empty database, Neptune displays **Set Up Neptune** and creates the first organization and Platform Owner.
10. Sign in and run **System Check**.

Neptune does **not** use `/Neptune/admin/install.php`.

### Important application settings

`config.example.php` includes:

```php
'app' => [
    'base_url' => '/Neptune',
    'session_name' => 'NEPTUNESESSID',
    'timezone' => 'UTC',

    'platform_organization_id' => 1,
    'allow_public_registration' => false,
    'platform_owner_auth' => 'strong',
    'mfa_encryption_key' => '',
],
```

For a private school/team installation, `allow_public_registration` should normally remain `false`.

The first organization can still be created on an empty database even when public registration is disabled.

For Platform Owner access:

```php
'platform_owner_auth' => 'strong',
```

means:

- Google sign-in, or
- password + TOTP/recovery code.

Use `google` only when the installation intentionally requires Google-only Platform Owner access.

A dedicated stable MFA encryption key is recommended for new installations. It can be stored in private config or supplied through `NEPTUNE_MFA_ENCRYPTION_KEY`.

## Google sign-in

Google OAuth/OIDC is optional.

When configured through environment variables, Neptune can use:

```text
GOOGLE_OAUTH_CLIENT_ID="..."
GOOGLE_OAUTH_CLIENT_SECRET="..."
GOOGLE_OAUTH_REDIRECT_URI="https://your-host/Neptune/auth/google-callback.php"
```

Google verifies identity; Neptune remains the source of truth for organization membership, roles, team assignments, and account status.

## Updates and database migrations

Neptune update packages are intended to be self-contained.

Schema-changing updates use the migration ledger:

```text
neptune_migrations
------------------
migration_key
applied_at
package
```

Maintenance Console reports installed update history and applied database migrations.

File rollback restores files only; it does **not** automatically reverse database migrations. Migrations should remain idempotent and forward-safe.

## Production backups

The hosted production deployment includes optional encrypted GitHub-based backup tooling.

The production backup workflow can:

- create a consistent MySQL/MariaDB logical dump
- archive uploads and game JSON
- write manifests and SHA-256 checksums
- encrypt the recovery archive before it leaves the server
- upload encrypted recovery archives to a private GitHub backup repository
- keep daily/weekly/monthly recovery points
- expose backup status to Maintenance Console
- provide a documented restore command with a pre-restore safety backup

Production secrets are intentionally kept outside the source repository and outside the encrypted backup archive.

See `public_html/Neptune/docs/PRODUCTION_BACKUP_AND_RESTORE.md` for the current restore procedure.

## Security

Public 1.0 includes:

- `password_hash()` / `password_verify()`
- forced reset for temporary passwords
- public login rate limiting
- registration rate limiting
- non-enumerating invalid-login responses
- generic public error handling without raw PHP/SQL/filesystem details
- CSRF protection
- session regeneration after authentication
- prepared SQL statements
- organization-scoped operational queries
- strong authentication for Platform Owner tools
- organization-bound offline-sync credentials
- secrets outside `public_html`
- executable-script blocking in uploads
- web blocking for backup/deployment artifacts such as `.bak*`, `.before*`, ZIPs, SQL dumps, logs, env files, and hidden files
- auditable soft-delete for scouting actions

## Continuous integration

GitHub Actions runs a PHP syntax check on pushes and pull requests to `main`.

The workflow lints every tracked PHP file with:

```text
php -l
```

Public 1.0 was tagged only after the PHP lint workflow passed.

## Event-day workflow

A typical competition workflow is:

1. Build/select the current game in **Game Builder**.
2. Create the event in **Event Setup** and make it current.
3. Load/import the event roster.
4. Begin **Pit Scouting** and **Pre-Scouting** as needed.
5. Sync the official TBA schedule when available, or use **Manual Match Schedule**.
6. Use **Match Control** to Ready and start matches.
7. Scouts record observations in Match Scouting or Tag Scouting.
8. Use **Live Monitor** during event operations.
9. Review Robot Lookup, Robot Intelligence, Robot Stats Table, Match Board, Pit Operations Board, Match Strategy, and Alliance Selection.

## In-app training

Neptune includes a Quick Walkthrough and role-oriented training/help so users can learn the event workflow from inside the application.

## Contributing

Bug reports, scouting workflow ideas, UI improvements, and code contributions are welcome.

Before deploying development changes to an event system, back up the Neptune database and test away from live competition use.

## Disclaimer

Neptune is an independent community project and is **not affiliated with, endorsed by, or maintained by FIRST® or The Blue Alliance**.

FIRST®, FIRST Robotics Competition®, FRC®, and related marks belong to their respective owners. The Blue Alliance is an independent community-developed resource.

## License

Neptune is released under the **MIT License**. See `LICENSE` for the full license text.
