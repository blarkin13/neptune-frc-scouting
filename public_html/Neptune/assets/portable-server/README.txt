Neptune Portable Organization Server

This package can run on macOS or supported Linux systems.

macOS
-----
1. Unzip the package.
2. If macOS blocks "Install Neptune.command", open Terminal and paste:

     xattr -dr com.apple.quarantine ~/Downloads/Neptune_Portable_*

   Press Return, then double-click Install Neptune.command again.

   If the folder is not in Downloads, type:
     xattr -dr com.apple.quarantine 
   then DRAG the Neptune folder from Finder into Terminal and press Return.

3. The installer uses Homebrew Apache/PHP and installs Neptune into Homebrew's normal var/www/Neptune directory.

Apple Silicon default:
  /opt/homebrew/var/www/Neptune

Intel Homebrew default:
  /usr/local/var/www/Neptune

On macOS Neptune uses its own private database instance. If Homebrew MySQL or MariaDB is already installed, Neptune reuses only those binaries with a separate Neptune-only data directory/socket/port. It does NOT need existing DB credentials and does not touch existing databases.

Linux
-----
Run:
  sudo bash "Install Neptune.sh"

Automatic dependency installation currently supports:
- Debian / Ubuntu (apt)
- Fedora / RHEL family (dnf)

Neptune installs into:
  /var/www/html/Neptune

The protected application files stay outside the public web directory at:
  /var/www/neptune_secure

Offline operation
-----------------
After installation, Internet is not required. Connect the server and scouting tablets to the same router/switch.
The installer prints the LAN URL, normally:
  http://SERVER-IP/Neptune/
or on Homebrew Apache:
  http://SERVER-IP:8080/Neptune/

Organization packages contain every event for that Neptune organization. There is no event picker.

Google-linked accounts
----------------------
Because Google OAuth cannot work without Internet, the installer creates temporary LOCAL-ONLY passwords for active Google-linked accounts and sets them to change password at first login.

The generated credentials are written to:
  Neptune Offline Accounts.txt

These local passwords never sync back to the cloud.

Cloud synchronization
---------------------
After Internet returns, sign in locally as Owner/Admin:
  Command Center -> Portable Organization Server -> Sync Organization to AWS

Sync is organization-bound and processes every local event.
Passwords, roles, Google linkage, MFA, invites and authentication settings are never synchronized back to AWS.

Destroy
-------
Run Destroy Neptune.command / Destroy Neptune.sh.
It removes Neptune's local app/database/user but leaves pre-existing Apache, PHP, MariaDB/MySQL and Homebrew installations intact.


INSTALL LOCATION AND STARTUP
----------------------------
The installer reads Homebrew Apache's actual DocumentRoot and installs Neptune
directly under:

  <Apache DocumentRoot>/Neptune

On a default Apple Silicon Homebrew Apache install this is:

  /opt/homebrew/var/www/Neptune

On Linux the normal default is:

  /var/www/html/Neptune

The installer validates Apache configuration, starts/restarts Apache, waits until
/Neptune/index.php answers over HTTP, prints both the local and LAN index
addresses, and automatically opens the local index in the default browser.
It will not print "NEPTUNE IS READY" unless the HTTP check succeeds.


CLEANING OLD / FAILED INSTALLS
------------------------------
Double-click:
  Clean Old Neptune.command

or run:
  bash "Clean Old Neptune.sh"

The cleaner works even when an earlier install failed before Neptune finished
writing its install manifest. It removes only Neptune portable/local data:

- <Apache DocumentRoot>/Neptune
- neptune_secure
- Neptune's private SQL data directory
- generated local DB password/config
- portable cloud sync credential
- generated local MFA encryption key
- "Neptune Offline Accounts.txt"
- Neptune's Apache include

It does NOT uninstall Apache/PHP/MySQL/MariaDB/Homebrew and does not delete
unrelated databases.

The installer also detects an old/partial install and offers this cleanup before
creating the new private database.

LARGE ORGANIZATIONS
-------------------
Organization snapshots are imported one JSON row at a time. The importer no
longer loads the complete organization.json file into PHP memory.


SCHEMA CONSISTENCY
------------------
Organization packages now include the database structure from the running
Neptune production database at the moment the package is built. This includes
columns/tables created by Neptune application migrations that may not yet exist
in an older repository SQL dump.

The importer now fails instead of silently skipping a snapshot table when the
local schema does not match the package. A successful installation therefore
means the packaged organization data was imported against the matching schema.


REPAIRING AN INSTALLED SERVER
-----------------------------
If Neptune was installed but the browser shows a PHP permission/fatal error,
run:

  Repair Neptune.command

The repair keeps config.php private and grants only the Apache worker account
read access, restarts Apache, runs a real page health check, prints the local
and LAN index URLs, and opens Neptune in the browser.


PORTABLE LOGIN AND STATIC ASSETS
--------------------------------
Portable installs preserve existing Neptune password hashes. A temporary local
password is generated only for an account that production can prove was created
as a Google-only account and never established a local password.

The local Apache configuration aliases /assets, /images, and
/manifest.webmanifest to the Neptune application so root-relative cloud asset
URLs still work when Neptune is mounted locally at /Neptune.


PORTABLE /NEPTUNE ASSET PATHS
-----------------------------
The hosted Neptune site uses several root-relative asset paths such as
/assets/... and /images/.... The portable installer now rewrites those paths in
the local copy to /Neptune/assets/... and /Neptune/images/... so the complete
theme, logo, icons and JavaScript load correctly under the local /Neptune URL.

Repair Neptune.command applies the same correction to an already-installed
portable server.
