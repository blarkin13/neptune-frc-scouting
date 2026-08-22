-- Neptune v4 - temporary password enforcement
-- Back up the Neptune database before applying.

ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER active,
  ADD COLUMN password_changed_at DATETIME NULL AFTER last_login_at;

-- Older Neptune versions labeled admin-created passwords as temporary but did
-- not store that fact. There is no reliable way to identify which existing
-- non-owner accounts still use those temporary passwords, so force a one-time
-- reset for all existing non-owner users during this upgrade.
UPDATE users
SET must_change_password = 1
WHERE role <> 'owner';

-- Owner accounts were created with user-selected passwords, not temporary ones.
UPDATE users
SET password_changed_at = COALESCE(password_changed_at, created_at)
WHERE role = 'owner';
