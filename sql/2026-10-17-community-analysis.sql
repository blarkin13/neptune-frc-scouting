-- Apply explicitly before enabling community_analysis. No changes to org scouting.
CREATE TABLE IF NOT EXISTS community_matches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 match_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 title VARCHAR(255) NOT NULL,
 roster_json TEXT NOT NULL,
 source_url TEXT NOT NULL,
 source_sha256 CHAR(64) NOT NULL,
 duration_ms INT UNSIGNED NOT NULL,
 rules_text TEXT NOT NULL,
 ready TINYINT NOT NULL DEFAULT 0,
 created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS community_devices (
 id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 credential_revision CHAR(64) NOT NULL,
 expires_at BIGINT NOT NULL,
 revoked TINYINT NOT NULL DEFAULT 0,
 created_at BIGINT NOT NULL,
 KEY owner_devices(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS community_attempts (
 id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY,
 match_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 device_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 request_key VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 status VARCHAR(24) NOT NULL,
 created_at BIGINT NOT NULL,
 UNIQUE KEY claim_retry(user_id,request_key),
 KEY match_attempts(match_id),
 FOREIGN KEY(match_id) REFERENCES community_matches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS community_leases (
 match_id BIGINT UNSIGNED PRIMARY KEY,
 attempt_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL UNIQUE,
 device_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 expires_at BIGINT NOT NULL,
 stage VARCHAR(100) NOT NULL,
 FOREIGN KEY(match_id) REFERENCES community_matches(id),
 FOREIGN KEY(attempt_id) REFERENCES community_attempts(id),
 KEY expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS community_submissions (
 id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY,
 attempt_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 match_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 payload_json MEDIUMTEXT NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 review_state VARCHAR(24) NOT NULL DEFAULT 'pending',
 reviewer_id BIGINT UNSIGNED NULL,
 review_note VARCHAR(2000) NULL,
 reviewed_at BIGINT NULL,
 created_at BIGINT NOT NULL,
 FOREIGN KEY(attempt_id) REFERENCES community_attempts(id),
 KEY review_queue(review_state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS community_publications (
 match_id BIGINT UNSIGNED PRIMARY KEY,
 submission_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 published_at BIGINT NOT NULL,
 FOREIGN KEY(match_id) REFERENCES community_matches(id),
 FOREIGN KEY(submission_id) REFERENCES community_submissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
