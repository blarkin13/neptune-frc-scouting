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

Neptune is the umbrella FRC Scouting & Strategy Platform. The application is organized into named modules:

* **TRIDENT** — Match Scouting, Pit Scouting, and Pre-Scouting
* **SATURN** — Command Center, Match Control, and Administration
* **AUGUR** — Analytics, Strategy, and Robot Intelligence
* **MERCURY** — Live Updates, WebSockets, and Device Synchronization
* **VULCAN** — Game Builder, Form Builder, and System Configuration
* **SALT** — Database and Data Storage

The module names describe functional boundaries inside the same Neptune platform; they do not require separate installations.

## Navigation

The main menu uses functional names rather than module codenames:

* **Scouting** opens **TRIDENT**, where users choose Match Scouting, Pit Scouting, or Pre-Scouting.
* **Analytics & Strategy** opens **AUGUR**, where users choose Robot Intelligence or the Match Board.
* **Command Center** opens **SATURN**, with Event Operations, VULCAN configuration tools, and organization administration grouped separately.

Module names remain visible as secondary branding inside each application.

---

## Features

### TRIDENT · Live match scouting

* Administrator-controlled match Ready / Start / End workflow
* Official match and robot assignments imported from The Blue Alliance
* Synchronized match timer with Autonomous, transition pause, Teleop, and Endgame phases
* Configurable action buttons generated from game JSON
* Swipe right for Success and left for Failure
* Repeated swipes of the same action without reopening the action dialog
* Real-time database writes
* Current action count, scouting score, and last-action confirmation
* Live administrative monitoring
* Complete match action history with administrative soft-delete
* Re-scout support for restarted FRC matches; superseded runs are excluded from analytics but retained for audit history

### VULCAN · Game Builder

Game definitions are data-driven instead of hard-coded for a single season.

Administrators can configure:

* Autonomous duration
* Auto-to-Teleop transition pause
* Teleop duration
* Endgame timing
* Action names and automatically generated unique codes
* Points
* Categories and locations
* Button colors
* Rectangular button sizes: `1x1`, `1x2`, `2x1`, `1x4`, `4x1`
* Circular button sizes: `1x1`, `2x2`, `4x4`
* Four-column snap-to-grid layout

Existing game definitions can be loaded, edited, previewed, and saved as JSON.

### TRIDENT · Pre-Scouting

Neptune includes an event-based pre-scouting workflow for contacting/researching teams before an event. The event roster comes from The Blue Alliance whenever possible, and each team can be tracked as *Not Started*, *Contacted*, *Response Received*, *No Response*, or *Unavailable*.

The event overview uses a spreadsheet-style table so a tablet/laptop can show team number, location, season record, EPA breakdown, cached prior-event OPR values, event/alliance history, assigned scout, and completion status. TBA supplies team/location/event information and prior-event OPR/alliance data when available. Statbotics is an optional public source for season record and EPA; Neptune continues working if Statbotics is unavailable.

Pre-scout answers are stored twice: as an event-specific snapshot and as reusable season knowledge for the same robot/game. When a team appears at another event in the same FRC season, Neptune can pre-fill known robot answers from earlier pre-scouting and compatible pit scouting while still allowing the new event information to be corrected. The assigned **Scout** remains event-specific and is not carried forward as robot knowledge.

Questions are game-defined from **Command → Pre-Scout Form Builder**. The included 2026 REBUILT template mirrors the team's existing spreadsheet workflow: Auto, Trench, Hopper Size, Shooter, Climb, Throughput, Drive Notes, Defence / CounterDefence, and Archetype.

### TRIDENT · Current Event & Pit Scouting

Neptune does not require a published match schedule before an event can be used.

An event can move through:
`Planned` ➔ `Pit Scouting Open` ➔ `Schedule Ready` ➔ `Matches Running` ➔ `Complete`

Pit scouting can begin from an event roster before qualification schedules are released. When TBA publishes the schedule, Neptune adds matches to the existing event without replacing pit data.

Pit scouting includes:

* Searchable event team roster
* Draft / Complete status
* Drivetrain and robot basics
* Intake and scoring capabilities
* Autonomous information
* Endgame information
* Preferred roles and defense information
* Reliability / known issues
* Alliance-partner notes
* Robot photographs from a phone camera or desktop file picker
* Automatic photo resizing (1800 px max) with AVIF preferred, then WebP/JPEG fallback
* Game-specific questions configured in the Pit Form Builder

---

## The Blue Alliance integration

Neptune uses The Blue Alliance API v3 for official FRC information, including:

* Team events
* Event roster
* Qualification and playoff schedules
* Red / Blue alliances and field stations
* Match scores and results

Roster sync and schedule sync are independent, so pit scouting can be used before the match schedule is available.

---

## AUGUR · Analytics

Neptune combines observed match data with pit scouting information.

Robot analytics include values such as:

* Points per match
* Average scoring cycle time
* Offensive success percentage
* Defensive actions per match
* Matches scouted
* Total scouting actions / points
* Event match history
* Pit scouting responses and robot photos

Robot Cards and the Pit Match Board open a shared **Robot Intelligence** modal with detailed robot information.

---

## Pit Match Board

A large-screen display intended for pit TVs can show past and upcoming matches, the robots involved, and scouting statistics. Robot tiles are clickable for detailed Robot Intelligence.

---

## Multi-organization / multi-team support

* Multiple organizations can use one Neptune installation
* Users are scoped to their organization
* Organizations may contain multiple FRC teams
* Temporary passwords require a password change after first login
* Scouting data retains an owning team
* Controlled team-to-team data sharing is supported by the database/application model

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
| `NEPTUNE_SERVER_NAME` | `neptune.local` | Apache ServerName |
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
