# Neptune v1.1.0-beta.2 — Current Production Sync + Portable Server

**Pre-release** following Neptune Public 1.0.

Beta 2 brings the public repository up to the current October 8, 2026 Neptune production source snapshot while retaining the cross-platform portable organization server introduced in beta 1.

## Highlights

- Current Neptune production application source synchronized to the public repository.
- Portable organization server remains available for offline/local event operation.
- macOS portable support for both Apple Silicon and Intel systems.
- Linux portable support for Ubuntu/Debian and Fedora/RHEL-family systems.
- Windows 10/11 portable server support through WSL2/Ubuntu remains beta.
- Manual organization-wide synchronization from the portable server back to hosted Neptune.
- AWS/cloud reachability and last-successful-sync status on the portable server.
- Organization-scoped offline sync credentials.
- Current database schema and migration source synchronized with production.
- Field-practice signup source and supporting field-practice pages included.
- Robot Lookup, sharing, UI/footer, installation documentation, and related current-production source updates included.

## Portable server behavior

The preferred competition workflow remains hosted Neptune when reliable Internet is available.

The portable server is the fallback for events where Internet is unavailable or unreliable:

1. Prepare the event and organization in hosted Neptune.
2. Download a fresh **Organization Server** package before the event.
3. Install and test the portable server before arriving.
4. Run scouting on the local wired network when needed.
5. Back up the portable server.
6. Restore Internet and manually sync the organization back to hosted Neptune.

## Important limitations

- Portable uploads are **manual**. Internet connectivity returning does not automatically upload scouting data.
- Windows WSL2 networking, Windows Firewall behavior, and wired tablet/phone LAN access still require testing on the actual Windows field hardware.
- Portable organization packages contain private organization data, password hashes, and temporary sync credentials. **Never publish an organization export to GitHub.**
- The public Maintenance Console release asset is code-only and does not contain organization data or credentials.

## Public prerelease asset

`Neptune_Portable_Windows_Sync_Status_Maintenance_Console.zip`

The Maintenance Console ZIP contains exactly:

```text
neptune_secure/portable.php
public_html/Neptune/admin/portable.php
```

A complete new/self-hosted Neptune installation should use the repository source rather than only this patch.

## Validation

- GitHub PHP syntax validation passed on the production-source synchronization commit.
- The beta publishing workflow validates the portable PHP source.
- Portable `.sh` and `.command` shell scripts are syntax-checked.
- The Maintenance Console ZIP is integrity-tested.
- `SHA256SUMS.txt` is generated for the release asset.

Hardware and end-to-end field testing remain required before considering the Windows portable path production-ready.
