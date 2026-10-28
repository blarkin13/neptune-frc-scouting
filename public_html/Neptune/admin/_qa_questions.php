<?php
/** QA tester debrief: structured questions whose answers can be counted, compared and acted on.
 *  Every answer is validated against this catalog on the server; free text is length-limited and never executed. */
declare(strict_types=1);

function nq_exit_reasons(): array {
    return [
        'blocked'    => 'I was blocked by a bug or error',
        'unclear'    => 'The instructions were unclear',
        'no_access'  => 'I did not have the access I needed',
        'wrong_setup'=> 'The match or robot was set up wrong',
        'time'       => 'I ran out of time',
        'mistake'    => 'I started it by mistake',
        'other'      => 'Another reason',
        'reset'      => 'The QA data was reset before the test finished',
    ];
}
/** Reasons a tester may pick ("reset" is only ever set by the system). */
function nq_exit_reasons_choosable(): array { $r = nq_exit_reasons(); unset($r['reset']); return $r; }
function nq_bug_categories(): array {
    return [
        'bug'        => 'Something is broken or gave an error',
        'confusing'  => 'Something is confusing or unclear',
        'slow'       => 'Something is slow or took too many taps',
        'missing'    => 'Something I expected is missing',
        'suggestion' => 'An idea to make it better',
    ];
}
/** Impact answers map to the legacy severity column so older reports and filters keep working. */
function nq_impact_levels(): array {
    return [
        'blocked'    => ['label' => 'I could not continue',                'severity' => 'blocker'],
        'workaround' => ['label' => 'I found a workaround',                'severity' => 'high'],
        'slowed'     => ['label' => 'It slowed me down or annoyed me',     'severity' => 'medium'],
        'cosmetic'   => ['label' => 'Minor / looks off but works',         'severity' => 'low'],
    ];
}
function nq_repeat_levels(): array {
    return ['always' => 'Every time I try', 'sometimes' => 'Sometimes', 'once' => 'Only once', 'unknown' => 'Not sure'];
}
function nq_devices(): array {
    return ['phone' => 'Phone', 'tablet' => 'Tablet', 'chromebook' => 'Chromebook', 'laptop' => 'Laptop', 'desktop' => 'Desktop computer'];
}
function nq_scale(string $low, string $high): array {
    return ['type' => 'scale', 'low' => $low, 'high' => $high];
}
/** Questions asked for every module, then the module's own. */
function nq_question_set(string $scenario): array {
    $common = [
        ['key' => 'c_unaided', 'label' => 'Could you finish this test without asking anyone for help?', 'type' => 'choice', 'options' => [
            'alone' => 'Yes, on my own', 'hint' => 'Yes, after re-reading the instructions', 'help' => 'Only with help from a person', 'no' => 'No, I could not finish']],
        ['key' => 'c_clear', 'label' => 'How clear were the written instructions on this page?'] + nq_scale('Very confusing', 'Perfectly clear'),
        ['key' => 'c_slowest', 'label' => 'Which step was the slowest or most frustrating?', 'type' => 'step'],
        ['key' => 'c_time', 'label' => 'Compared with what you expected, how long did this take?', 'type' => 'choice', 'options' => [
            'faster' => 'Faster', 'expected' => 'About what I expected', 'slower' => 'Slower', 'much_slower' => 'Much slower']],
        ['key' => 'c_trust', 'label' => 'Would you trust this feature during a real match?', 'type' => 'choice', 'options' => [
            'yes' => 'Yes', 'changes' => 'Only after changes', 'no' => 'No']],
        ['key' => 'c_device', 'label' => 'Which device did you use?', 'type' => 'choice', 'options' => nq_devices()],
        ['key' => 'c_change', 'label' => 'If you could change ONE thing, what would it be?', 'type' => 'text'],
        ['key' => 'c_missing', 'label' => 'Was anything missing that you expected to see?', 'type' => 'text'],
    ];
    $specific = [
        'traditional' => [
            ['key' => 't_buttons', 'label' => 'Were the action buttons easy to tap accurately?'] + nq_scale('Hard to hit', 'Very easy'),
            ['key' => 't_saved', 'label' => 'Was it always clear that your action was saved?', 'type' => 'choice', 'options' => ['yes' => 'Yes, always', 'unsure' => 'Not always', 'no' => 'No, I was guessing']],
            ['key' => 't_undo', 'label' => 'How easy was Undo to find and use?', 'type' => 'choice', 'options' => ['easy' => 'Easy', 'found' => 'Found it eventually', 'hard' => 'Hard to find', 'never' => 'I never found it']],
            ['key' => 't_timer', 'label' => 'Was the match timer and phase (auto / teleop) clear?', 'type' => 'choice', 'options' => ['clear' => 'Clear', 'confusing' => 'Confusing', 'unnoticed' => 'I did not notice it']],
            ['key' => 't_lost', 'label' => 'Did any action get lost or recorded twice?', 'type' => 'choice', 'options' => ['none' => 'No', 'missing' => 'An action was missing', 'duplicate' => 'An action appeared twice', 'unsure' => 'I could not tell']],
        ],
        'connection_guard' => [
            ['key' => 'cg_badge', 'label' => 'When the connection dropped, was it clear your actions were still being saved?'] + nq_scale('Not at all', 'Completely clear'),
            ['key' => 'cg_sync', 'label' => 'After the connection came back, did you see the queued actions send (SYNCED)?', 'type' => 'choice', 'options' => [
                'yes' => 'Yes', 'unsure' => 'Not sure', 'no' => 'No, they stayed waiting']],
            ['key' => 'cg_keep', 'label' => 'Did you keep scouting normally while offline, or did you stop?', 'type' => 'choice', 'options' => [
                'normal' => 'Kept scouting normally', 'hesitant' => 'Kept going but was unsure', 'stopped' => 'Stopped until it came back']],
        ],
        'tag' => [
            ['key' => 'g_speed', 'label' => 'How quickly could you pick tags while a match was happening?'] + nq_scale('Far too slow', 'Fast enough'),
            ['key' => 'g_tags', 'label' => 'Were the tag choices the right ones?', 'type' => 'choice', 'options' => ['right' => 'Yes', 'missing' => 'Some I needed were missing', 'many' => 'Too many to scan']],
            ['key' => 'g_share', 'label' => 'Was the scoring-share percentage easy to understand and set?'] + nq_scale('Confusing', 'Obvious'),
            ['key' => 'g_autosave', 'label' => 'Did autosave give you confidence that your report was kept?', 'type' => 'choice', 'options' => ['yes' => 'Yes', 'unsure' => 'Not sure', 'no' => 'No']],
        ],
        'assignment' => [
            ['key' => 'a_notice', 'label' => 'How did you find out about your assignment?', 'type' => 'choice', 'options' => [
                'notification' => 'The Neptune notification', 'account' => 'My Account page', 'told' => 'Someone told me', 'missed' => 'I did not notice it']],
            ['key' => 'a_clear', 'label' => 'Was it clear which robot and station you were assigned?'] + nq_scale('Not clear', 'Completely clear'),
            ['key' => 'a_ack', 'label' => 'How easy was it to acknowledge the assignment?'] + nq_scale('Hard', 'Very easy'),
            ['key' => 'a_done', 'label' => 'When the match ended, did the assignment closing by itself feel right?', 'type' => 'choice', 'options' => [
                'yes' => 'Yes, that is what I expected', 'surprised' => 'It surprised me', 'wanted_button' => 'I would rather press a button']],
        ],
        'analytics' => [
            ['key' => 'n_find', 'label' => 'How easy was it to find your robot in the stats table?'] + nq_scale('Hard', 'Very easy'),
            ['key' => 'n_source', 'label' => 'Did it make sense when a number came from a Tag estimate (*) instead of Traditional scouting?', 'type' => 'choice', 'options' => [
                'yes' => 'Yes', 'partly' => 'Partly', 'no' => 'No', 'unnoticed' => 'I did not see the marker']],
            ['key' => 'n_decide', 'label' => 'Could you make a pick-list or strategy decision from this table?', 'type' => 'choice', 'options' => [
                'yes' => 'Yes', 'partly' => 'Partly, I would need more', 'no' => 'No']],
        ],
        'data_quality' => [
            ['key' => 'd_flag', 'label' => 'Was the finding easy to understand?'] + nq_scale('Confusing', 'Very clear'),
            ['key' => 'd_next', 'label' => 'Was it clear what you should do next?', 'type' => 'choice', 'options' => ['yes' => 'Yes', 'partly' => 'Somewhat', 'no' => 'No']],
            ['key' => 'd_options', 'label' => 'Were the review choices (follow-up / reviewed / dismissed) easy to choose between?'] + nq_scale('Confusing', 'Obvious'),
            ['key' => 'd_evidence', 'label' => 'Was there enough evidence on the page to decide?'] + nq_scale('Not enough', 'Plenty'),
        ],
    ];
    $set = array_merge($specific[$scenario] ?? [], $common);
    foreach ($set as &$q) { $q += ['type' => 'scale']; if ($q['type'] === 'scale') { $q += ['low' => '1', 'high' => '5']; } }
    unset($q);
    return $set;
}
/** Per-module answer checks the verifier can compare with the database (the tester reports what they see). */
function nq_checks(string $scenario): array {
    return match ($scenario) {
        'analytics' => [['key' => 'k_star', 'label' => 'In the Robot Stats Table, is there a * (Tag estimate marker) next to this robot\'s Pts/Match?', 'options' => ['yes' => 'Yes, there is a *', 'no' => 'No *']]],
        default => [],
    };
}
function nq_question_index(string $scenario, array $steps = []): array {
    $index = [];
    foreach (nq_question_set($scenario) as $q) { $index[$q['key']] = $q; }
    foreach (nq_checks($scenario) as $c) { $index[$c['key']] = $c + ['type' => 'choice']; }
    return $index;
}
/** Returns the normalized stored value or throws InvalidArgumentException. */
function nq_clean_answer(array $q, string $raw, array $stepKeys = []): string {
    $raw = trim($raw);
    $type = (string)($q['type'] ?? 'scale');
    if ($type === 'text') {
        if (mb_strlen($raw) > 600) { throw new InvalidArgumentException('Answers are limited to 600 characters.'); }
        return $raw;
    }
    if ($type === 'scale') {
        if (!preg_match('/^[1-5]$/D', $raw)) { throw new InvalidArgumentException('Choose a rating from 1 to 5.'); }
        return $raw;
    }
    if ($type === 'step') {
        if ($raw === 'none' || in_array($raw, $stepKeys, true)) { return $raw; }
        throw new InvalidArgumentException('Choose one of the listed steps.');
    }
    if (!array_key_exists($raw, (array)($q['options'] ?? []))) { throw new InvalidArgumentException('Choose one of the listed answers.'); }
    return $raw;
}
