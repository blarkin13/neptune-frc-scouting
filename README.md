# Neptune

**FRC Scouting, Strategy & Analytics Platform**

Neptune is a web-based scouting platform built for the **FIRST Robotics Competition (FRC)**. It combines live match scouting, pit scouting, match administration, robot analytics, team-controlled data sharing, and The Blue Alliance integration in a single platform.

Neptune is designed to be used by any FRC team or robotics organization rather than being tied to a specific team.

> **Development Status:** Neptune is currently under active development. Features, database structures, and installation procedures may change.

---

## What Neptune Does

Neptune is built around the complete scouting workflow at an FRC event.

### Match Scouting

Scouts are assigned to robots directly from the official event match schedule.

During a match, Neptune provides:

* Synchronized match timer
* Autonomous, transition, teleop, and endgame phases
* Configurable game-action buttons
* Swipe right for a successful action
* Swipe left for a failed action
* Immediate real-time database recording
* Current action count
* Current scouting score
* Last-action confirmation
* Repeated action entry without reopening the action
* Live administrative monitoring

The game interface is generated dynamically from the selected game's configuration rather than being hard-coded for one FRC season.

### Match Control

Administrators can:

* Prepare the next match
* Start matches for all connected scouts
* Monitor scout activity in real time
* View every recorded action
* Remove erroneous scouting actions
* Add actions after a match
* Review completed matches
* Re-scout restarted matches

If an official match is restarted, Neptune can void the original scouting run and begin a clean scouting run while retaining the old data for administrative audit purposes.

---

## Pit Scouting

Neptune includes event-based pit scouting that can begin **before the official match schedule is released**.

Features include:

* Event team roster
* Searchable team cards
* Draft and completed pit reports
* Drivetrain information
* Robot dimensions and weight
* Intake and scoring capabilities
* Autonomous routines
* Endgame capabilities
* Preferred strategic roles
* Defense information
* Reliability and known problems
* Alliance-partner notes
* Robot photographs
* Game-specific custom questions

Pit scouting data is combined with observed match performance in Neptune's robot analytics.

---

## Game Builder

FRC games are configured through Neptune rather than hard-coded into the scouting interface.

Administrators can create and edit game definitions including:

* Autonomous duration
* Auto-to-Teleop transition pause
* Teleop duration
* Endgame timing
* Action name
* Action code
* Point value
* Action category
* Location
* Button color
* Button shape
* Button size

Action codes are automatically generated from their names and made unique when necessary.

The scouting interface uses a four-column grid supporting rectangular and circular action buttons of multiple sizes.

Game configurations are stored as JSON and can be loaded and edited for future use.

---

## The Blue Alliance Integration

Neptune uses **The Blue Alliance API** to obtain official FRC event information.

For official events Neptune can import:

* Event information
* Event team roster
* Qualification and playoff schedules
* Red and Blue alliances
* Field stations
* Match results
* Alliance scores

Once a schedule is available, Neptune uses the official match data as the source of truth for robot assignments.

Scouts do not manually type robot numbers for normal FRC matches.

---

## Current Event Workflow

Neptune separates the existence of an event from the availability of its match schedule.

An event can progress through stages such as:

```text
Planned
   ↓
Pit Scouting Open
   ↓
Schedule Ready
   ↓
Matches Running
   ↓
Complete
```

This allows a team to begin pit scouting as soon as they arrive at an event, even if FIRST has not yet published the match schedule.

When the schedule becomes available, Neptune can refresh the same event through The Blue Alliance without replacing existing pit data.

---

## Analytics

Neptune includes robot and event analytics based on actual scouting observations.

Examples include:

* Points per match
* Average scoring cycle time
* Offensive success percentage
* Defensive actions per match
* Matches scouted
* Total recorded actions
* Endgame performance
* Match-by-match history

Robot cards can be opened to display a detailed **Robot Intelligence** view combining match statistics with pit scouting information.

---

## Pit Display

Neptune includes a large-screen Pit Match Board intended for televisions or monitors in the team pit.

The board can display:

* Previous matches
* Upcoming matches
* Red and Blue alliances
* Robot scouting statistics
* Pit scouting information
* Detailed robot intelligence

Robot cards are interactive so strategy members can quickly inspect a team's complete scouting profile.

---

## Multi-Team Organizations

Neptune supports organizations containing one or more FRC teams.

For example, a robotics program operating multiple FRC teams can manage them from a single Neptune installation while maintaining team-specific information.

Users belong to an organization and can be assigned roles and team memberships.

---

## Team Data Sharing

Scouting data remains owned by the team that collected it.

Neptune's architecture allows teams to selectively share scouting information with other Neptune teams while retaining ownership of the original observations.

The long-term goal is to allow cooperating FRC teams to build more complete scouting datasets without requiring every organization to independently scout every robot.

---

# Technology

Neptune currently uses:

* PHP
* MySQL / MariaDB
* JavaScript
* HTML/CSS
* JSON game definitions
* Font Awesome
* The Blue Alliance API

It is intentionally designed to run on ordinary PHP/MySQL web hosting without requiring a large application framework.

---

# Server Structure

A typical hosted installation looks like:

```text
/home/ACCOUNT/

├── neptune_secure/
│   ├── bootstrap.php
│   ├── config.php
│   ├── connection.php
│   └── tba.php
│
└── public_html/
    └── Neptune/
        ├── admin/
        ├── analytics/
        ├── api/
        ├── assets/
        ├── dashboard/
        ├── games/
        ├── images/
        ├── pit/
        ├── scout/
        └── index.php
```

Sensitive configuration is deliberately stored **outside the public web root**.

---

# Installation

## Requirements

Recommended environment:

* PHP 8.x
* MySQL 8.x or compatible MariaDB
* Apache or compatible PHP web server
* PDO MySQL extension
* PHP sessions
* cURL support
* HTTPS
* The Blue Alliance API key

---

## Hosted Installation

1. Create a MySQL database for Neptune.

2. Import the current Neptune schema from the `/sql/` directory.

3. Upload:

```text
public_html/Neptune/
```

to your website's public web directory.

4. Place:

```text
neptune_secure/
```

outside `public_html`.

5. Copy:

```text
neptune_secure/config.example.php
```

to:

```text
neptune_secure/config.php
```

6. Configure your database credentials and The Blue Alliance API key.

7. Open:

```text
/Neptune/admin/install.php
```

to create the first organization and owner account.

8. Remove or disable `admin/install.php` after initialization.

---

## Local Installation

Neptune can also run locally using a PHP/MySQL development environment such as:

* XAMPP
* MAMP
* WAMP
* Docker
* Native PHP + MySQL

Create the Neptune database, import the schema, configure `neptune_secure/config.php`, and point your local web server at the directory containing the Neptune public application.

The exact path depends on the local web-server configuration.

---

# Security

Neptune is designed so credentials are not placed in the public web directory.

**Never commit `neptune_secure/config.php` to GitHub.**

The repository should use:

```text
neptune_secure/config.example.php
```

with placeholder values.

Other security features include:

* Password hashing with PHP `password_hash()`
* Forced temporary-password replacement
* Session authentication
* CSRF protection
* Prepared SQL statements
* Organization-level access restrictions
* Soft deletion/auditing of scouting actions
* Private API credentials
* Organization-scoped user administration

---

# First Setup

After installing Neptune:

1. Create your organization.
2. Create or add your FRC team.
3. Add users and scouts.
4. Configure the current FRC game.
5. Create or import an event.
6. Add/import the event roster.
7. Begin pit scouting.
8. Sync the official schedule when it becomes available.
9. Open Match Control.
10. Begin live scouting.
11. Review Robot Analytics and strategy data.

---

# Contributing

Neptune is currently early in development.

Bug reports, interface suggestions, FRC scouting ideas, and contributions are welcome as the project matures.

Because the database and application structure may still change, production installations should back up their database before applying development updates.

---

# Disclaimer

Neptune is an independent community project.

It is **not affiliated with, endorsed by, or maintained by FIRST® or The Blue Alliance**.

FIRST®, FIRST Robotics Competition®, FRC®, and related marks are trademarks of their respective owners.

The Blue Alliance is an independent community-developed resource.

---

## License

License information will be added as the project approaches its first public release.
