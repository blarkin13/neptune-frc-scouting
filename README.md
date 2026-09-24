# Neptune FRC Scouting Platform

*FRC Scouting, Pit Scouting, Match Control, Analytics & Strategy Platform*

Neptune is a web-based scouting and strategy platform for the FIRST Robotics Competition (FRC). It combines live match scouting, pit scouting, pre-scouting, match administration, analytics, strategy tools, team-controlled data sharing, and external FRC data integration in one PHP/MariaDB application.

**Development status:** Neptune is under active development. The repository is intended to support clean installation and development, while existing competition/production servers should be updated only with reviewed maintenance patches.

---

## Quick install

On a **clean Ubuntu/Debian server**:

```bash
git clone https://github.com/blarkin13/neptune-frc-scouting.git
cd neptune-frc-scouting
sudo ./install.sh

```

The installer sets up Apache, PHP, MariaDB, the Neptune application, protected configuration, and the complete database schema.

After installation, open:
`[http://neptune.local/register.php](http://neptune.local/register.php)`
and create the first organization/owner account.

**See:**

* `INSTALL.md` — full installation, configuration, database, HTTPS, and verification instructions
* `REQUIREMENTS.md` — supported server and browser requirements

> **Important:** `install.sh` is for a clean server. Do **not** run it over an existing Neptune AWS, field, pit, or production deployment.

---

## Neptune modules

Neptune is the umbrella FRC scouting, analytics, strategy, and event-operations platform. The current application is organized into six named modules:

* **TRIDENT · Scouting** — Match Scouting, Spot Scouting, Pit Scouting, and Pre-Scouting.
* **SATURN · Command Center** — Event operations, Match Control, Live Monitor, Event Setup, TBA Sync, organization administration, and data-sharing controls.
* **AUGUR · Analytics & Strategy** — Robot intelligence, match analysis, Public EPA, Neptune EPA, predictions, Match Strategy, and Alliance Selection.
* **MERCURY · System Maintenance** — System Check, Interface Styling, File Manager, AUGUR data maintenance, patch installation/rollback, and diagnostics.
* **VULCAN · Builders & Configuration** — Game Builder, Pit Form Builder, and Pre-Scout Form Builder.
* **SALT · Data Layer** — Raw scouting data, Database Lab, and the underlying organization-scoped data store.

The names describe functional areas inside one Neptune installation; they are not separate applications or services.

---

## Main navigation

The dashboard uses functional names with module names as secondary branding:

* **Scouting · TRIDENT** — Match, Spot, Pit, and Pre-Scouting.
* **Command Center · SATURN** — Event Operations, organization administration, VULCAN builders, and role-gated system tools.
* **Analytics & Strategy · AUGUR** — Robot Lookup, Match Board, Robot Intelligence, EPA Ratings, Match Strategy, and Alliance Selection.
* **System Maintenance · MERCURY** — health, styling, files, updates, AUGUR maintenance, and diagnostics.
* **Builders & Configuration · VULCAN** — season/game configuration and scouting-form builders.
* **Data Layer · SALT** — raw data and Strategy+ Database Lab access.

---

## Features

### TRIDENT · Match Scouting

* Administrator/strategy-controlled Ready, Start, Pause, End, and re-scout workflow
* Official match and robot assignments imported from The Blue Alliance
* Synchronized Autonomous, transition, Teleop, and Endgame timing
* Game-defined action buttons generated from season configuration
* Swipe right for Success and left for Failure
* Repeated action entry without reopening the action dialog
* Real-time writes to Neptune with action history and soft-delete auditing
* IndexedDB connection protection for queued scouting requests during temporary network interruption
* Re-scout support that preserves run history while excluding superseded runs from normal analytics
* Live visibility through SATURN's Live Monitor

### TRIDENT · Spot Scouting

Spot Scouting is the quick-observation layer for information that does not fit structured match or pit forms.

* Tag robots during matches, in the pits, or as general observations
* Add free-form notes
* Attach photos and supported video media
* Associate observations with teams and match/field context
* Follow active matches by field
* Organization-specific/customizable Spot Scouting tags
* Spot observations are surfaced inside Robot Intelligence and Alliance Selection

### TRIDENT · Pit Scouting

* Searchable event team roster
* Draft / Complete status
* Drivetrain and robot basics
* Intake and scoring capabilities
* Autonomous information
* Endgame information
* Preferred roles and defense information
* Reliability and known-issue notes
* Alliance-partner notes
* Robot photographs from phone camera or desktop file picker
* Automatic image optimization with AVIF preferred and WebP/JPEG fallback
* Game-specific questions configured through VULCAN's Pit Form Builder

*Pit scouting can begin as soon as an event roster exists; Neptune does not require the qualification schedule to be published first.*

### TRIDENT · Pre-Scouting

Neptune includes an event-based pre-scouting workflow for researching/contacting teams before competition.

* Event roster integration
* Status tracking such as Not Started, Contacted, Response Received, No Response, and Unavailable
* Team/location and historical-event context from locally available TBA data
* Season-level robot knowledge that can be reused at later events using the same game
* Event-specific scout assignments and responses
* Configurable questions through VULCAN's Pre-Scout Form Builder
* Integration with Robot Intelligence and Match Strategy

### SATURN · Command Center & Event Operations

SATURN runs the event and organization workflow:

* **Match Control** — Ready, start, pause, end, and re-scout matches while preserving run history
* **Live Monitor** — connected scouts, robot assignments, actions, and current match state
* **Event Setup** — create events, select games, load rosters, and control Current Event
* **TBA Sync** — import/refresh official event rosters, schedules, and event links
* **Teams & Users** — organization teams, accounts, roles, and access
* **Data Sharing** — explicit partner-team permissions for shared scouting and analytics data

### VULCAN · Builders & Configuration

VULCAN makes Neptune season-independent rather than hard-coding one FRC game.

**Game Builder** can configure:

* Autonomous, transition, Teleop, and Endgame timing
* Scoring/action names and codes
* Point values
* Categories and locations
* Button colors and shapes
* Four-column snap-to-grid scouting layouts
* Field/background assets

VULCAN also contains:

* **Pit Form Builder** — game-specific pit capability questions
* **Pre-Scout Form Builder** — season research fields and templates

### AUGUR · Robot Intelligence

AUGUR combines Neptune's organization-scoped scouting with public FRC data stored locally by Neptune. Core tools include:

* **Robot Lookup** — search any robot by team number/name and combine identity, pit, pre-scout, match, EPA, and event history
* **Robot Intelligence / Robot Cards** — scouting-derived performance, notes, photos, Spot Scouting, Public EPA, and Neptune EPA
* **Match Board** — six-robot past/upcoming matchup view with direct Robot Intelligence access
* Sorting and comparison by points/match, cycle time, success rate, defense, matches scouted, Public EPA, and Neptune EPA

### AUGUR · Public EPA

**Public EPA** is Neptune's public-data rating layer. AUGUR maintains a local archive of public match information and calculates overall plus phase-specific ratings such as:

* Overall Public EPA
* Autonomous EPA
* Teleop EPA
* Endgame EPA

The Public EPA pages can be viewed independently of an organization's private scouting data and expose a public ratings API. Prediction pages read the locally archived EPA data rather than requiring a live external request for every prediction.

### AUGUR · Neptune EPA

**Neptune EPA** is different from Public EPA. It is an organization-private AUGUR estimate that blends the public baseline with the organization's own scouting-derived performance.

The current model can use factors including:

* Public EPA baseline
* Full-event observed offense
* Recent observed offense
* High-end output
* Current-event trend

The result is used as an offensive robot-strength reference in Robot Intelligence, Match Strategy, and Alliance Selection. Matchup-specific defense and qualification-form settings are applied by the shared AUGUR prediction model when comparing alliances rather than simply changing the displayed offensive Neptune EPA.

### AUGUR · Shared Prediction Model

Match Strategy and Alliance Selection use the same organization/event AUGUR model settings so the two strategy tools do not disagree about how robots are evaluated.

The prediction system can provide:

* Predicted alliance scores
* Win probability
* Estimated score range
* Model confidence
* Public EPA baseline
* Neptune EPA
* Scouting-based fallback when public EPA is unavailable
* Configurable offensive and defensive weighting

Historical Match Strategy predictions use pre-match data rules so later results do not leak backward into the prediction shown for an earlier match.

### AUGUR · Match Strategy

Match Strategy turns scouting intelligence into a saved drive-team plan for a specific match. It includes:

* AUGUR matchup prediction and confidence
* Alliance-role suggestions with strategy override
* Autonomous information and conflict planning
* Opponent Watch and defense-target prioritization
* Phase-by-phase assignments for Autonomous, Teleop, and Endgame
* Claimed-vs-observed endgame reliability
* Key objectives
* Autonomous notes
* Drive-team notes
* Confirmation checks for autonomous paths and endgame responsibilities

### AUGUR · Alliance Selection

Alliance Selection is a live draft/pick-list workspace rather than a static spreadsheet. It includes:

* Official TBA qualification rankings for captain context
* Alliance Board and Team Pool
* Multiple draft scenarios
* Pick lists
* Robot Intelligence detail without leaving the board
* Public EPA and Neptune EPA
* Shared AUGUR prediction settings
* Alliance offensive-potential comparison
* Alliance tags
* Spot Scouting
* Alliance history
* Tracking for selections, declines, unavailable/broken robots, and captain movement
* Offline preparation of the event's Alliance Selection data for the device

### SALT · Data Layer & Database Lab

SALT exposes Neptune's underlying data in controlled ways:

* Raw scouting data views
* **Database Lab** for Strategy+ users
* Read-only manual SQL/query exploration
* Organization/tenant scoping for non-platform-owner organizations
* Platform-owner visibility for installation-wide diagnostics where permitted

### MERCURY · System Maintenance

MERCURY is the maintenance/tooling layer. Current tools include:

* **System Check** — PHP, image-processing, storage, configuration, and application capabilities
* **Interface Styling** — centralized palette/presets for Neptune's interface
* **File Manager** — platform-owner file browsing and maintenance
* **AUGUR Data Maintenance** — refresh team directory, backfill/recalculate Public EPA, and rebuild local OPR
* **Maintenance Console** — server health, patch ZIP installation, rollback, and restricted diagnostics

---

## The Blue Alliance integration

Neptune uses The Blue Alliance API v3 as an external data source for information such as:

* Event rosters
* Qualification and playoff schedules
* Red/Blue alliances and field stations
* Match scores/results
* Qualification rankings and event links where used by Neptune

Roster and schedule synchronization are separate, allowing pit/pre-scout workflows before a match schedule is available.

---

## Multi-organization / multi-team support

* Multiple organizations can use one Neptune installation
* Users are scoped to their organization
* Organizations can contain multiple FRC teams
* Role-gated owner/admin/strategy/scouter access
* Scouting data retains organization/team ownership
* Controlled team-to-team data sharing
* Organization-scoped analytics and Database Lab behavior

---

## Technology

* PHP 8.x
* MariaDB 10.6+ / PDO MySQL
* Apache 2.4
* JavaScript
* HTML / CSS
* JSON game definitions
* IndexedDB for queued Match Scouting requests
* Locally bundled Font Awesome
* The Blue Alliance API v3
* Optional Statbotics data

Neptune intentionally avoids requiring a large PHP framework so it can run on a conventional Apache/PHP/MariaDB server.

---

## Repository layout

```text
neptune-frc-scouting/
├── install.sh
├── INSTALL.md
├── REQUIREMENTS.md
├── neptune_secure/
│   ├── bootstrap.php
│   ├── config.example.php
│   ├── connection.php
│   ├── image.php
│   ├── statbotics.php
│   └── tba.php
├── public_html/
│   └── Neptune/
│       ├── admin/
│       ├── analytics/
│       ├── api/
│       ├── assets/
│       ├── dashboard/
│       ├── games/
│       ├── help/
│       ├── images/
│       ├── pit/
│       ├── prescout/
│       ├── scout/
│       ├── spot/
│       ├── scouting.php
│       └── index.php
├── scripts/
│   ├── install-neptune.sh
│   ├── verify-install.sh
│   └── field-server/
└── sql/
    ├── install_schema.sql
    ├── EXPECTED_TABLES.txt
    └── ...

```

`neptune_secure` is deployed **outside the public web root** and contains private runtime configuration. The real `neptune_secure/config.php` must never be committed.

---

## Requirements

The supported clean-install target is:

* Ubuntu 22.04/24.04 LTS or current Debian
* x86_64 or ARM64
* root or `sudo` access
* Internet access during initial installation for `apt`
* Apache 2.4
* PHP 8.x with PDO MySQL, cURL, mbstring, XML, GD, and ZIP
* MariaDB 10.6+
* Modern Chrome/Chromium, Safari, or Firefox

**Optional services:**

* **The Blue Alliance API** — required only for live TBA synchronization
* **Statbotics** — optional analytics/pre-scout data source
* **AWS/Internet connectivity** — optional for cloud-hosted operation and field-to-cloud synchronization

See `REQUIREMENTS.md` for the maintained requirements list.

---

## Clean Ubuntu/Debian installation

Clone Neptune and run the installer:

```bash
git clone https://github.com/blarkin13/neptune-frc-scouting.git
cd neptune-frc-scouting
sudo ./install.sh

```

By default Neptune is deployed to:

* `/var/www/neptune/public_html/Neptune`
* `/var/www/neptune/neptune_secure`

The installer:

1. Installs Apache, PHP, MariaDB, and required PHP extensions.
2. Creates the Neptune MariaDB database and application account.
3. Generates a random local database password.
4. Imports the authoritative clean-install schema from `sql/install_schema.sql`.
5. Deploys the application and protected configuration.
6. Configures the Apache site.
7. Enables required Apache modules.
8. Validates PHP syntax.
9. Verifies the required database tables against `sql/EXPECTED_TABLES.txt`.
10. Runs an installation health check.

Then visit:
`[http://neptune.local/register.php](http://neptune.local/register.php)`
to create the first organization and owner.

---

## Optional installer settings

The installer supports environment variables such as:

```bash
sudo env \
  NEPTUNE_SERVER_NAME=neptune.example.org \
  NEPTUNE_TIMEZONE=America/Chicago \
  NEPTUNE_TBA_KEY='YOUR_TBA_KEY' \
  ./install.sh

```

**Common settings include:**

| Variable | Default | Purpose |
| --- | --- | --- |
| `NEPTUNE_ROOT` | `/var/www/neptune` | Deployment root |
| `NEPTUNE_DB_NAME` | `neptune` | MariaDB database |
| `NEPTUNE_DB_USER` | `neptune_app` | MariaDB application user |
| `NEPTUNE_SERVER_NAME` | `neptune.local` | Apache `ServerName` |
| `NEPTUNE_BASE_URL` | *empty* | URL prefix when Neptune is not the site root |
| `NEPTUNE_TIMEZONE` | `UTC` | Application timezone |
| `NEPTUNE_TBA_KEY` | *empty* | Optional The Blue Alliance API key |

See `INSTALL.md` for the complete installation reference.

---

## Database

The authoritative schema for a **new installation** is:
`sql/install_schema.sql`

The installer and verification script use:
`sql/EXPECTED_TABLES.txt`
as the required table manifest.

Historical migration SQL files remain in `sql/` for existing installations and upgrade work. They are not a substitute for `install_schema.sql` on a new server.

---

## Verify an installation

On an installed server:

```bash
sudo /var/www/neptune/scripts/verify-install.sh

```

The verification script checks the application environment and required MariaDB tables.

---

## Existing Neptune servers

Do **not** run the clean installer over an existing deployment.

Existing AWS, competition, development, field, and pit servers should be updated using reviewed maintenance packages and database migrations appropriate to that installed version.

Before any event deployment:

1. Back up the database.
2. Back up the application/configuration.
3. Apply the update away from competition use.
4. Run Neptune System Check and installation/application validation.
5. Confirm scouting and analytics workflows before declaring the build event-ready.

---

## Offline and competition use

Neptune includes an offline field-server foundation and Match Scouting connection protection. Match Scouting uses IndexedDB to protect queued requests during temporary network interruption.

The repository also contains field-server tooling under:
`scripts/field-server/`

External services such as TBA and Statbotics should be treated as data sources rather than requirements for local Match Scouting. Full field/pit/cloud offline synchronization remains an area of active development and should be validated against current FRC event rules before deployment.

---

## Configuration and secrets

The repository provides:
`neptune_secure/config.example.php`

The installer generates the real private configuration at:
`/var/www/neptune/neptune_secure/config.php`

> **Note:** Never commit real database passwords, API keys, private configuration, database dumps, or backup files containing secrets.

---

## Pit photo camera and image optimization

Pit Scouting uses a single photo control that works across devices:

* **Phone/tablet:** the browser can offer the rear camera or an existing photo.
* **Desktop/laptop:** the same control opens the normal file picker.

Neptune validates the uploaded image, corrects phone orientation where supported, strips unnecessary metadata, and resizes it to a maximum dimension of 1800 px.

Output priority is `AVIF` ➔ `WebP` ➔ `JPEG`, based on the capabilities of the PHP host.
HEIC/HEIF files can be decoded when the host's ImageMagick build supports them. If not, the scout is asked to use a JPEG/Most Compatible phone format.

Owners/admins can open **Command → System Check** to see whether AVIF, WebP, Imagick, GD, HEIC decoding, and the current PHP upload limits are available. AVIF is an optimization, not a hard requirement; Neptune automatically falls back when it is unavailable.

---

## Event-day workflow

A typical FRC event workflow is:

1. Create/select the season's game in **Game Builder**.
2. Create the event and make it the **Current Event**.
3. Import or paste the event roster and begin **Pit Scouting**.
4. When TBA publishes the schedule, use **TBA Sync** to import/refresh it.
5. Use **Match Control** to make the next match Ready and start it.
6. Scouts select their assigned field station and record actions.
7. Use **Live Monitor** to watch activity and correct erroneous actions.
8. Review **Robot Analytics**, **Robot Intelligence**, and the **Pit Match Board**.

---

## Security notes

* Passwords use PHP `password_hash()` / `password_verify()`.
* Admin-created temporary passwords require a first-login reset.
* Forms use CSRF protection.
* SQL uses prepared statements.
* Operational queries are organization-scoped.
* API endpoints require authenticated sessions.
* Database and TBA credentials remain outside `public_html`.
* Deleted scouting actions are retained as auditable soft-deletes and excluded from normal analytics.

---

## Legacy data import

Neptune includes an administrative legacy importer for earlier Stat Owl-style scouting data. Legacy database credentials are optional and belong only in your private `config.php`.

*Back up both databases before running a historical import.*

---

## Contributing

Neptune is still evolving. Bug reports, scouting workflow ideas, UI improvements, and code contributions are welcome.

Before deploying development updates to an event system, back up the Neptune database and test the update away from competition use.

---

## Disclaimer

Neptune is an independent community project and is **not** affiliated with, endorsed by, or maintained by FIRST® or The Blue Alliance.

FIRST®, FIRST Robotics Competition®, FRC®, and related marks belong to their respective owners. The Blue Alliance is an independent community-developed resource.

---

## License

See the repository's `LICENSE` file for the license terms applicable to this project.
