Neptune Robot Recall — Pixel Art Mode v2

This package ADDS a new pixel-art presentation and does not overwrite the
existing Robot Recall game.

New routes after installation:
  /admin/robot-recall-pixel.php   Alternate host/control page
  /play/pixel.php                Player/join page
  /play/pixel-screen.php         Big-screen page

The new pages reuse the existing Robot Recall game, database, room codes,
answers, scoring, and host controls. Only the visual presentation and logo
rendering are changed.

Pixel logos:
  - Source: existing persistent local Neptune team-logo cache
  - Generated lazily on first display
  - Saved under uploads/team-logos/pixel/org-N/frcTEAM.png
  - Nearest-neighbour scaling only; no smoothing
  - Tiny FIRST/TBA 40x40 avatars preserve their native pixel grid
  - Higher-resolution custom logos are reduced to a 48px logical grid first
  - Output is 768x768 transparent PNG
  - Regenerates automatically when the source logo changes
  - Works offline after generation because both source and generated images are local

No SQL migration is required.
