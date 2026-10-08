# Neptune portable organization server (beta)

Neptune's portable organization server is a **local PHP + MariaDB + Apache** instance intended for scouting at FRC events without an Internet connection. It runs on a single host on the scouting LAN; tablets use a browser and share its database.

## Supported hosts

| Host | Installation | Status |
|---|---|---|
| macOS Intel | Homebrew-based `.command` and Bash scripts | Installer provided; validate on target hardware |
| macOS Apple Silicon | Homebrew-based `.command` and Bash scripts | Installer provided; validate on target hardware |
| Ubuntu/Debian | `apt` + systemd installer | Installer provided; validate on target hardware |
| Fedora/RHEL family | `dnf` + systemd installer | Installer provided; validate on target hardware |
| Windows 10/11 | PowerShell launcher running Ubuntu in WSL2 | **Beta; no native Windows service** |

For Windows, extract the **complete generated portable package** and follow its `WINDOWS-SETUP.txt`. WSL2 localhost access **does not prove** that tablets can connect to the server. Test Windows firewall, router/client isolation, and WSL networking on the actual scouting network before a competition.

## Hosted deployment

The platform/organization Owner signs into the hosted Neptune installation and opens **`admin/portable.php`**. Two package options are available:

- **Clean server** — application code, installer tools, and a fresh live schema, **without** any organization's accounts or scouting records.
- **Organization server** — the complete selected organization's events, selected users, teams, forms, scouting records, and relevant media, plus a temporary **organization-bound** sync token. **Treat this ZIP as secret.** It includes password hashes and other private data. Do not upload it to GitHub, email it broadly, or attach it to a public release.

Portable packages are created dynamically from the **running hosting installation**; GitHub source archives and Maintenance Console patches are **not** organization packages.

## Local use

1. Extract the generated portable ZIP, preserving its layout.
2. Run `Install Neptune.command` (Mac) or `sudo bash "Install Neptune.sh"` (Linux). For Windows use the WSL2 PowerShell launchers in the generated ZIP.
3. Run `Start Neptune.command`, `Start Neptune.sh`, or the Windows starter, as appropriate.
4. Log in via the URL reported by the installer/start script. On Linux/Windows this is commonly `http://localhost/Neptune/`; on Mac it depends on Homebrew Apache's port.
5. Connect scout devices to the same wired/Wi-Fi LAN and open the server's LAN IP plus Neptune's actual HTTP port. Do not use localhost on tablets.

## AWS cloud synchronization

- Changes are stored locally until an authorized Owner/Admin deliberately presses **Sync Organization to AWS**. Connectivity does **not** start an automatic upload.
- The local sync screen checks the configured AWS endpoint approximately once per minute, indicates reachability, and shows the last successful sync timestamp (UTC).
- **The unsynced changes count is not implemented.** The display deliberately says “Not calculated.”
- The client uploads event/scouting/strategy updates, and supported media, to the original hosted organization's scoped endpoints. User passwords, Google login links, MFA, and roles are intentionally excluded from cloud writeback.
- The organization-bound sync credential expires 30 days after creation. Download a fresh organization package if the credential expires.
- This feature has **not been validated end-to-end on every host platform or with multi-device field LAN connectivity**. Make a backup and run a full disposable-organization sync test before competition use.

## Maintenance Console patch vs source release

Neptune's **Maintenance Console** patch ZIP must contain these two root-relative paths, with no outer folder:

```text
neptune_secure/portable.php
public_html/Neptune/admin/portable.php
```

It is a patch for a host that **already has** the portable sync-client, cloud endpoints, and portable assets. A full new self-host installation uses this source repository, which also includes those supporting files. The prerelease workflow creates a public *code-only* Maintenance Console ZIP as a release attachment; it never packages any organization's private ZIP.

## Installation layout assumption

The portable package builder reads files from `/var/www/neptune/public_html/Neptune`, `/var/www/neptune/neptune_secure`, and `/var/www/neptune/public_html/Neptune/assets/portable-server`. Deployments with a different layout need their builder path adapted.
