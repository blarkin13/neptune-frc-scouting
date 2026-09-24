# Neptune

**FRC Scouting, Pit Scouting, Match Control, Analytics & Strategy Platform**

Neptune is a web-based scouting platform for the **FIRST Robotics Competition (FRC)**. It combines live match scouting, pit scouting, match administration, robot analytics, team-controlled data sharing, and The Blue Alliance integration in one PHP/MySQL application.

> **Development status:** Neptune is under active development. Features, database structures, and installation procedures may change.


## Neptune modules

Neptune is the umbrella FRC Scouting & Strategy Platform. The application is organized into named modules:

- **TRIDENT** — Match Scouting, Pit Scouting, and Pre-Scouting
- **SATURN** — Command Center, Match Control, and Administration
- **AUGUR** — Analytics, Strategy, and Robot Intelligence
- **MERCURY** — Live Updates, WebSockets, and Device Synchronization
- **VULCAN** — Game Builder, Form Builder, and System Configuration
- **SALT** — Database and Data Storage

The module names describe functional boundaries inside the same Neptune platform; they do not require separate installations.


### Navigation

The main menu uses functional names rather than module codenames:

- **Scouting** opens **TRIDENT**, where users choose Match Scouting, Pit Scouting, or Pre-Scouting.
- **Analytics & Strategy** opens **AUGUR**, where users choose Robot Intelligence or the Match Board.
- **Command Center** opens **SATURN**, with Event Operations, VULCAN configuration tools, and organization administration grouped separately.

Module names remain visible as secondary branding inside each application.

## Features

### TRIDENT · Live match scouting

- Administrator-controlled match Ready / Start / End workflow
- Official match and robot assignments imported from The Blue Alliance
- Synchronized match timer with Autonomous, transition pause, Teleop, and Endgame phases
- Configurable action buttons generated from game JSON
- Swipe right for Success and left for Failure
- Repeated swipes of the same action without reopening the action dialog
- Real-time database writes
- Current action count, scouting score, and last-action confirmation
- Live administrative monitoring
- Complete match action history with administrative soft-delete
- Re-scout support for restarted FRC matches; superseded runs are excluded from analytics but retained for audit history

### VULCAN · Game Builder

Game definitions are data-driven instead of hard-coded for a single season.

Administrators can configure:

- Autonomous duration
- Auto-to-Teleop transition pause
- Teleop duration
- Endgame timing
- Action names and automatically generated unique codes
- Points
- Categories and locations
- Button colors
- Rectangular button sizes: 1x1, 1x2, 2x1, 1x4, 4x1
- Circular button sizes: 1x1, 2x2, 4x4
- Four-column snap-to-grid layout

Existing game definitions can be loaded, edited, previewed, and saved as JSON.

### TRIDENT · Pre-Scouting

Neptune includes an event-based pre-scouting workflow for contacting/researching teams before an event. The event roster comes from The Blue Alliance whenever possible, and each team can be tracked as **Not Started**, **Contacted**, **Response Received**, **No Response**, or **Unavailable**.

The event overview uses a spreadsheet-style table so a tablet/laptop can show team number, location, season record, EPA breakdown, cached prior-event OPR values, event/alliance history, assigned scout, and completion status. TBA supplies team/location/event information and prior-event OPR/alliance data when available. Statbotics is an optional public source for season record and EPA; Neptune continues working if Statbotics is unavailable.

Pre-scout answers are stored twice: as an event-specific snapshot and as reusable season knowledge for the same robot/game. When a team appears at another event in the same FRC season, Neptune can pre-fill known robot answers from earlier pre-scouting and compatible pit scouting while still allowing the new event information to be corrected. The assigned **Scout** remains event-specific and is not carried forward as robot knowledge.

Questions are game-defined from **Command → Pre-Scout Form Builder**. The included 2026 REBUILT template mirrors the team's existing spreadsheet workflow: Auto, Trench, Hopper Size, Shooter, Climb, Throughput, Drive Notes, Defence / CounterDefence, and Archetype.

### TRIDENT · Current Event & Pit Scouting

Neptune does not require a published match schedule before an event can be used.

An event can move through:

```text
Planned
  -> Pit Scouting Open
  -> Schedule Ready
  -> Matches Running
  -> Complete
```

Pit scouting can begin from an event roster before qualification schedules are released. When TBA publishes the schedule, Neptune adds matches to the existing event without replacing pit data.

Pit scouting includes:

- Searchable event team roster
- Draft / Complete status
- Drivetrain and robot basics
- Intake and scoring capabilities
- Autonomous information
- Endgame information
- Preferred roles and defense information
- Reliability / known issues
- Alliance-partner notes
- Robot photographs from a phone camera or desktop file picker
- Automatic photo resizing (1800 px max) with AVIF preferred, then WebP/JPEG fallback
- Game-specific questions configured in the Pit Form Builder

### The Blue Alliance integration

Neptune uses **The Blue Alliance API v3** for official FRC information, including:

- Team events
- Event roster
- Qualification and playoff schedules
- Red / Blue alliances and field stations
- Match scores and results

Roster sync and schedule sync are independent, so pit scouting can be used before the match schedule is available.

### AUGUR · Analytics

Neptune combines observed match data with pit scouting information.

Robot analytics include values such as:

- Points per match
- Average scoring cycle time
- Offensive success percentage
- Defensive actions per match
- Matches scouted
- Total scouting actions / points
- Event match history
- Pit scouting responses and robot photos

Robot Cards and the Pit Match Board open a shared **Robot Intelligence** modal with detailed robot information.

### Pit Match Board

A large-screen display intended for pit TVs can show past and upcoming matches, the robots involved, and scouting statistics. Robot tiles are clickable for detailed Robot Intelligence.

### Multi-organization / multi-team support

- Multiple organizations can use one Neptune installation
- Users are scoped to their organization
- Organizations may contain multiple FRC teams
- Temporary passwords require a password change after first login
- Scouting data retains an owning team
- Controlled team-to-team data sharing is supported by the database/application model

## Technology

- PHP 8.x
- MySQL 8.x / compatible MariaDB
- JavaScript
- HTML / CSS
- JSON game definitions
- Font Awesome
- The Blue Alliance API v3

Neptune intentionally avoids requiring a large PHP framework so it can run on ordinary PHP/MySQL hosting.

## Repository layout

```text
neptune-frc-scouting/
├── neptune_secure/
│   ├── bootstrap.php
│   ├── config.example.php
│   ├── connection.php
│   ├── legacy_connection.php
│   ├── image.php
│   └── tba.php
├── public_html/
│   └── Neptune/
│       ├── admin/
│       ├── analytics/
│       ├── api/
│       ├── assets/
│       ├── dashboard/
│       ├── games/
│       ├── images/
│       ├── pit/
│       ├── prescout/
│       ├── scout/
│       ├── scouting.php          # TRIDENT scouting selector
│       └── index.php
└── sql/
    └── neptune_schema.sql
```

`neptune_secure` is intended to live **outside the public web root** in a hosted installation.

## Requirements

Recommended:

- PHP 8.x
- PDO MySQL extension
- PHP sessions
- cURL extension
- MySQL 8.x or compatible MariaDB
- Apache or another PHP-capable web server
- HTTPS for a production deployment
- A The Blue Alliance API key
- Recommended for pit-photo optimization: Imagick/ImageMagick or PHP GD

## Fresh hosted installation

Clone Neptune with:

```bash
git clone https://github.com/blarkin13/neptune-frc-scouting.git
```

Or download the repository ZIP from GitHub.

1. Clone or download this repository.
2. Create a MySQL database for Neptune.
3. Import `sql/neptune_schema.sql`.
4. Place `public_html/Neptune/` below your public web root.
5. Place `neptune_secure/` beside `public_html`, **not inside it**.
6. Copy:

   ```text
   neptune_secure/config.example.php
   ```

   to:

   ```text
   neptune_secure/config.php
   ```

7. Replace the `XXX` values in `config.php` with your database settings and TBA API key.
8. Open `/Neptune/admin/install.php` to create the first organization and owner account.
9. Remove, rename, or otherwise disable `admin/install.php` after initialization.

**New installations import `sql/neptune_schema.sql` only.** It is the authoritative current Neptune schema.

## Example configuration

The repository contains `neptune_secure/config.example.php`:

```php
<?php
return [
    'app' => [
        'base_url' => '/Neptune',
        'session_name' => 'NEPTUNESESSID',
        'timezone' => 'UTC',
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'XXX',
        'user' => 'XXX',
        'pass' => 'XXX',
        'charset' => 'utf8mb4',
    ],
    'legacy_db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'XXX',
        'user' => 'XXX',
        'pass' => 'XXX',
        'charset' => 'utf8mb4',
    ],
    'tba' => [
        'auth_key' => 'XXX',
        'base_url' => 'https://www.thebluealliance.com/api/v3',
    ],
    'statbotics' => [
        'base_url' => 'https://api.statbotics.io/v3',
    ],
];
```

**Do not commit your real `neptune_secure/config.php`.** It is excluded by `.gitignore`.

## Local installation

Neptune can run locally with environments such as XAMPP, MAMP, WAMP, Docker, or a native PHP/MySQL installation.

A typical local setup is:

1. Start PHP/Apache and MySQL.
2. Create a database for Neptune.
3. Import `sql/neptune_schema.sql`.
4. Copy `config.example.php` to `config.php` and fill in the local credentials.
5. Configure the web server so `/Neptune` maps to `public_html/Neptune` while `neptune_secure` remains outside the served directory.
6. Visit `/Neptune/admin/install.php` and create the initial organization/owner.


## Pit photo camera and image optimization

Pit Scouting uses a single photo control that works across devices:

- **Phone/tablet:** the browser can offer the rear camera or an existing photo.
- **Desktop/laptop:** the same control opens the normal file picker.
- Neptune validates the uploaded image, corrects phone orientation where supported, strips unnecessary metadata, and resizes it to a maximum dimension of 1800 px.
- Output priority is **AVIF → WebP → JPEG**, based on the capabilities of the PHP host.
- HEIC/HEIF files can be decoded when the host's ImageMagick build supports them. If not, the scout is asked to use a JPEG/Most Compatible phone format.

Owners/admins can open **Command → System Check** to see whether AVIF, WebP, Imagick, GD, HEIC decoding, and the current PHP upload limits are available. AVIF is an optimization, not a hard requirement; Neptune automatically falls back when it is unavailable.

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

## Security notes

- Passwords use PHP `password_hash()` / `password_verify()`.
- Admin-created temporary passwords require a first-login reset.
- Forms use CSRF protection.
- SQL uses prepared statements.
- Operational queries are organization-scoped.
- API endpoints require authenticated sessions.
- Database and TBA credentials remain outside `public_html`.
- Deleted scouting actions are retained as auditable soft-deletes and excluded from normal analytics.

## Legacy data import

Neptune includes an administrative legacy importer for earlier Stat Owl-style scouting data. Legacy database credentials are optional and belong only in your private `config.php`.

Back up both databases before running a historical import.

## Contributing

Neptune is still evolving. Bug reports, scouting workflow ideas, UI improvements, and code contributions are welcome.

Before deploying development updates to an event system, back up the Neptune database and test the update away from competition use.

## Disclaimer

Neptune is an independent community project and is **not affiliated with, endorsed by, or maintained by FIRST® or The Blue Alliance**.

FIRST®, FIRST Robotics Competition®, FRC®, and related marks belong to their respective owners. The Blue Alliance is an independent community-developed resource.

## License

A project license has not yet been selected for this temporary repository.
