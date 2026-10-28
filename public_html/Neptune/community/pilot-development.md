# Community Match Analysis — developer pilot

This branch adds the first vertical slice of the community-analysis blueprint. It is disabled by default and has not been deployed. It does not replace existing organization scouting.

## Implemented

- Independent public match catalog and approved-source metadata; no organization notes are copied.
- Explicit user-selected claims, one active match per user and helper, with no fixed global contributor cap.
- Five-minute reservations, heartbeats, expired-claim recovery, owner/device/lease verification and idempotent claims/submissions.
- Local-only Python CLI helper: lists work, claims the selected match, verifies source SHA-256, extracts timestamped frames with FFmpeg, analyzes short image sequences using a compatible loopback vision runtime, composes a draft and submits it for review.
- Browser page for public summaries, available matches, pilot token generation/revocation and independent review.
- Immutable submissions, rejected-job rework and reviewed publication. Organization admins do not inherit publishing permission.
- New versioned result contract with unknowns and timestamp/roster validation. No score or tag output is accepted in this first schema.
- Scoped helper credentials hashed in the database, expiring after 30 days; account/password/organization changes and explicit revocation invalidate access.

## Prerequisites and limits

Server uses the existing PHP 8.2+ PDO MySQL/MariaDB installation. Pilot SQL is additive. The working repository specifies MariaDB 10.6+. The first helper needs Python 3.10+, FFmpeg/FFprobe and an independently installed compatible local vision runtime. It has no paid API adapter, no provider API key and no external inference fallback. The endpoint must be a literal loopback address. Running a remotely backed proxy on localhost is not approved; configure an actual local model.

This is a developer CLI with a basic Tk desktop front end, not the finished signed desktop installer. The desktop front end has been syntax-checked; interactive platform testing remains. Launch it with `python3 helper/neptune_helper_gui.py` when Python includes Tk. It lets users find matches, select one and click Start; it does not choose work automatically. Model licensing, minimum hardware and FRC accuracy remain release gates. A one-frame probe with the installed qwen2.5vl:3b model read alliance totals correctly but also confused team numbers with points in its explanation. It is not approved for unattended analysis. Local frame sampling is every two seconds, so brief actions can be missed. No independent six-robot tracker or automated ground-truth verification is implemented. Human review is mandatory. Tokens and a client's local-inference declaration are not proof that an untrusted contributor is honest.

Local state persists the claim and completed result for upload retries; a mid-analysis restart currently reprocesses the video rather than resuming every segment. Expired claims cannot be renewed. If reassigned, retain any draft locally and contact the pilot maintainer; a polished recovery UI is still needed. Do not use separate copies of the CLI with the same state directory concurrently.

## Installation on an isolated staging copy

1. Back up application and database. Apply `sql/2026-10-17-community-analysis.sql` to the staging database explicitly. Do not restore the uploaded production database merely to test this feature.
2. Add the `community_analysis` block from `neptune_secure/config.example.php` to the deployment's private config. Generate a dedicated secret; do not reuse a password or expose it publicly. Leave disabled until migration succeeds.
3. Set contributor and reviewer user-ID allowlists. Reviewer must differ from contributor. This release uses existing team accounts; public non-team signup is a later milestone.
4. Enable the flag. Visit `/Neptune/community/index.php`. The public library is anonymous; setup/contribution/review require the appropriate login and explicit grants.
5. Import an approved match with the CLI below. A watch-page URL alone is not a downloadable video. Use a direct HTTPS source that contributors may retrieve; do not use private organization footage or credentials in URLs.
6. Pair a pilot helper by visiting `?view=setup` as an allowed contributor, creating a token and storing it privately. Never share the token or paste it into a public issue. Production pairing will use browser authorization rather than manual copying.
7. Start the compatible local vision runtime. No cloud runtime or paid fallback.
8. List work and explicitly choose one match. A contributor's workstation handles inference; the hosted site coordinates work and stores results.
9. As a different allowed reviewer, visit `?view=review`, open the source video, inspect timestamps and decide Publish or Reject. Publishing makes the summary and submitted observations public across organizations.

Example approved metadata (illustrative; use real verified data):

```json
{
  "match_key": "2026-example-qm1",
  "title": "Example event — Qualification 1",
  "roster": {"red": [1,2,3], "blue": [4,5,6]},
  "source_url": "https://your-approved-media-host.example/match.mp4",
  "source_sha256": "replace-with-64-lowercase-hex-characters",
  "duration_ms": 160000,
  "rules": "Verified season rules, phase timings, scoring eligibility and outcome definitions.",
  "public_distribution_approved": true
}
```

```sh
php scripts/community-analysis-import.php /path/to/approved-match.json
```

Set `NEPTUNE_HELPER_TOKEN` in your local environment securely, then:

```sh
python3 helper/neptune_helper.py --site https://your-neptune-host.example/Neptune
python3 helper/neptune_helper.py --site https://your-neptune-host.example/Neptune --match 1 --model your-installed-local-vision-model --endpoint http://127.0.0.1:11434/v1/chat/completions
```

Use `--release` to release a saved reservation without starting inference, or press Control-C while processing. A completed local draft remains in the private state directory. The helper does not automatically choose another match. Video and temporary frames are removed when its temporary processing directory closes. Raw sources are not uploaded by the helper.

## API

`/Neptune/api/community-analysis.php?action=...`:

- Anonymous GET: `public` (published catalog), `published&match_id=N` (one reviewed report).
- Helper-authenticated GET: `work` (`after` cursor optional).
- Helper-authenticated POST JSON: `claim`, `heartbeat`, `release`, `submit`.
- Bearer helper tokens cannot create devices, review, publish or read private scouting.
- Claim requires `match_id` and a stable 16–64 character `request_key`.
- Remaining mutations carry `attempt_id` and `lease_token`; submit also carries `payload`.
- JSON request limit: 1 MiB. Public serializers expose only explicit publication fields.

## Testing

```sh
php tests/community-analysis/run.php
python3 -m unittest discover -s helper -p 'test_*.py'
```

The PHP test suite defaults to an isolated SQLite database and checks lease ownership, conflicts, expiry/reassignment, immutable/retryable submissions, public isolation, review restrictions and more than 200 independent active reservations. These are behavioral tests, not a 200-concurrent-client performance benchmark.

The same suite can use an EMPTY disposable MySQL/MariaDB database via `COMMUNITY_TEST_DSN`, `COMMUNITY_TEST_USER` and `COMMUNITY_TEST_PASSWORD`. It installs the actual production migration there. Never point it at production. CI defines a MariaDB 10.6 service for this purpose. That CI workflow must actually run before its result can be claimed.

## Backup compatibility inspected October 17, 2026

The supplied app-root archive and database archive were inspected locally without restoring them. Database inspection streamed table definitions only, not user/scouting records. Confirmed `users.organization_id`, `matches.organization_id` and org-scoped scouting; the new public tables do not alter those relationships. `organizations.platform_status` and user credential fields match the pilot auth joins. The supplied public app differs from repository main in `scout/impact.php` and `api/portable-sync.php`, so this branch adds files and changes only the config EXAMPLE; it does not overwrite those production changes. The archive omits `neptune_secure`, so the live bootstrap/config/auth version must still be checked before staging deployment.

## Not yet implemented

Signed native installers, automatic model installation/benchmarking, secure browser pairing, standalone community accounts, public revision editing/withdrawal/report-abuse tools, canonical mappings to org event pages, signed model manifests, aggregate monitoring, per-contributor rate limits, full segment checkpoint resume, 200-client load testing (the MariaDB CI suite includes a 16-client claim race), independent robot tracking, tag scouting and scoring contribution. Do not open this developer pilot to untrusted public registration until these controls and model evaluation are completed.

## Rollback

Disable the config flag to stop both helper and public feature routes; retain additive tables for recovery. Re-enable with the same secret to preserve valid claims, or rotate secret to invalidate all attempt tokens deliberately. Existing Neptune scouting is unaffected. To revoke a helper without disabling the feature use its owner's Setup page. No live migrations, account grants or deployments were performed by creating this branch.
