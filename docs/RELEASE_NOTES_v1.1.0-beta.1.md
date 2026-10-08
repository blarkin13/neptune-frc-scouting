# Neptune v1.1.0-beta.1 — Portable organization server

**Pre-release**: Cross-platform offline server + manual AWS sync status. Follows Neptune Public 1.0.

## Included

- Portable-server package generator (clean and private organization-scoped packages).
- Cross-platform installation assets for macOS Intel/Apple Silicon and Ubuntu/Debian/Fedora-family Linux.
- **Windows 10/11 WSL2/Ubuntu beta launchers**, bundled into freshly generated portable packages. Native Windows hosting is **not** implemented.
- Local AWS reachability indicator (refreshes every 60 seconds), UTC last-successful-upload timestamp, and manual organization-wide push.
- Organization-scoped cloud sync APIs/client and supported media synchronization.
- Self-contained source required to build portable packages on compatible Neptune hosts.

## Important limitations

- **No automatic uploads**. The authorized user must initiate AWS sync.
- **No unsynced edits count** yet. “Changes waiting” remains “Not calculated.”
- A temporary organization sync credential is generated per private export and expires after 30 days.
- Windows WSL2 networking, Windows firewall/tablet LAN reachability, and end-to-end sync have **not** been verified on actual Windows field hardware.
- Portable organization exports contain private account hashes/scouting data/credentials: **never publish them on GitHub**.

## Public prerelease asset

`Neptune_Portable_Windows_Sync_Status_Maintenance_Console.zip` is a code-only Maintenance Console patch containing exactly:

```
neptune_secure/portable.php
public_html/Neptune/admin/portable.php
```

This patch assumes the live AWS install already has the supporting portable service, endpoints, and scripts. A new full self-host installation must use the complete repository source code, not just the patch.

## Verification

The publishing workflow lints included PHP source, syntax-checks the shell scripts, verifies the Maintenance Console ZIP content/integrity, and produces a SHA-256 checksum. Hardware and end-to-end testing are still required.
