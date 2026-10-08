NEPTUNE PORTABLE MAC EVENT SERVER

What this package does
----------------------
Neptune runs locally on a Mac using Apache + PHP and a local MariaDB/MySQL
database. Scout tablets can use a private router/switch with no Internet/WAN
connection.

Typical event network:
  Mac Ethernet -> router/switch -> six wired tablets (USB-C Ethernet adapters)

The router only needs to provide the local LAN/DHCP. Internet is optional.

Install
-------
1. Before the event, while Internet is available if dependencies are missing,
   unzip this package.
2. Double-click "Install Neptune.command".
3. The installer:
   - reuses an existing compatible MariaDB/MySQL when possible;
   - otherwise installs MariaDB with Homebrew;
   - installs/reuses Homebrew Apache (httpd), PHP and qrencode;
   - creates a Neptune-only local DB user with a random password;
   - imports the Neptune schema;
   - imports the event snapshot when this is an Event package;
   - configures Apache with Neptune as the local site root;
   - generates a LAN QR code.
4. The installer prints both localhost and LAN URLs.

Existing MariaDB/MySQL
----------------------
Neptune does NOT reuse production AWS credentials.
If a compatible local DB server already exists, the installer reuses the
server and asks for an admin login only long enough to create:
  database: neptune_portable
  user:     neptune_local
  password: randomly generated locally

The database administrator password is not stored.

Offline operation
-----------------
Once installed, normal local scouting does not require Internet:
  - password sign-in
  - Teams & Users already in the snapshot
  - Event Setup data already in the snapshot
  - pit/pre-scouting
  - match scouting
  - Match Control / Live Monitor
  - local analytics and strategy from available local data
  - photos/media already included or captured locally

External services naturally cannot refresh while disconnected:
  - Google OAuth
  - TBA
  - Statbotics / other public APIs
  - cloud backup/sync

Every event user should have a working Neptune password before relying on an
offline field server.

Commands
--------
Start Neptune.command
  Starts the local services, refreshes the LAN QR, and opens Neptune.

Stop Neptune.command
  Stops only services Neptune started, or disables Neptune's Apache include
  when Apache was already running before Neptune.

Backup Neptune.command
  Creates a database + uploads backup ZIP on the Mac Desktop.

Sync to AWS.command
  Opens Neptune's Portable Event Server page. Use "Sync This Event to AWS"
  after Internet returns.

Destroy Neptune.command
  Offers:
    1. Stop
    2. Reset local Neptune
    3. Destroy local Neptune
  Reset/Destroy offer a backup first and require typed confirmation.
  Destroy removes the Neptune DB, Neptune DB user, files and Apache include.
  It does NOT uninstall MariaDB/MySQL, Apache, PHP or Homebrew.

Cloud sync
----------
Event packages contain a temporary event-bound sync credential. The cloud
stores only its SHA-256 hash. The token expires after 30 days.

Sync sends event operational changes back to the cloud using Neptune's
authenticated API. Actions are de-duplicated by UUID. Event tables use
upsert semantics. Pit/tag media is content-hashed before storage.

For safest results:
  - stop active local scouting before sync;
  - avoid editing the same event in cloud Neptune during the sync;
  - run a local backup before the final sync;
  - verify cloud counts/results before destroying the Mac server.

Security
--------
A Clean package contains no organizations, users, passwords, scouting data or
production secrets.

An Event package is PRIVATE. It contains organization account password hashes,
event/scouting data, referenced media and a temporary cloud sync credential.
Treat it like a database backup.

Neptune production/AWS database credentials are never copied to the Mac.
