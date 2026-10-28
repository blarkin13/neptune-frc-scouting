<?php
/**
 * Synthetic Tag Scouting rows are model estimates imported from traditional
 * scouting_actions (observer 900000001, note "Synthetic Tag Scouting estimate ...").
 * They are not human observations, so the Tag feed, exports, dashboard counts
 * and portable sync all exclude them. Analytics that intentionally count them
 * (data-quality, metrics) use their own predicates.
 */

function neptune_tag_synthetic_user_id(): int { return 900000001; }

function neptune_tag_synthetic_note_prefix(): string { return 'Synthetic Tag Scouting estimate'; }

/** SQL predicate that keeps only human tag_scouting_observations rows. */
function neptune_tag_human_observation_sql(string $alias='o'): string {
    return "({$alias}.note IS NULL OR {$alias}.note NOT LIKE 'Synthetic Tag Scouting estimate%')";
}

/** SQL predicate that keeps only human tag_scouting_match_data rows. */
function neptune_tag_human_match_data_sql(string $alias='d'): string {
    return "({$alias}.user_id<>".neptune_tag_synthetic_user_id()
        ." AND ({$alias}.note IS NULL OR {$alias}.note NOT LIKE 'Synthetic Tag Scouting estimate%'))";
}

/** PHP check for an observation or match-data row (array from DB or a sync payload). */
function neptune_tag_row_is_synthetic(array $row): bool {
    if(isset($row['user_id']) && (int)$row['user_id']===neptune_tag_synthetic_user_id()) return true;
    return str_starts_with((string)($row['note']??''), neptune_tag_synthetic_note_prefix());
}
