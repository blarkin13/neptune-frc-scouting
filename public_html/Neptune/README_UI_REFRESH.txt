Neptune Unified UI Refresh
==========================

What this patch changes
- Global breadcrumbs on authenticated pages, except scout/match.php.
- Shared Neptune module styling for TRIDENT, AUGUR, SATURN, and VULCAN.
- Shared hub-card design across Scouting, Analytics & Strategy, and Command Center.
- Database Lab remains the first AUGUR card for owners.
- Global Neptune toast/confirmation UI.
- Removes native JS confirm/alert usage from Match Control, Live Monitor, Event Setup, and Pre-Scout Builder.
- Legacy alert() calls that survive elsewhere are rendered as Neptune error toasts.

No SQL changes are required.
