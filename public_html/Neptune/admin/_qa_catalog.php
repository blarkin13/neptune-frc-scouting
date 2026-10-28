<?php
/** QA module definitions. An unavailable verifier must never be advertised as passing. */
declare(strict_types=1);
function nq_modules(): array {
    return [
        'traditional'=>[
            'title'=>'Traditional Match Scouting','description'=>'Start a QA match, connect a scout, save successful/failed offense and defense, undo an action, and end the match.',
            'slot'=>1,'mode'=>'automated','roles'=>['scout','strategy','admin','owner'],
            'access'=>'Tester: Neptune account in this organization, assigned to the QA robot/station. Match start and end are automatic: the tester\'s Start button starts the isolated QA match and Neptune ends it when the other steps pass. QA manager: owner/admin/strategy.',
            'scope'=>'QA Test Event 1 · AWS or QA stage','requires'=>'Published game revision with offense buttons; READY QA match; two people recommended.',
        ],
        'tag'=>[
            'title'=>'Match Tag Scouting','description'=>'Save the tester’s scoring share and selected tags, verify source records and linked unified observation.',
            'slot'=>1,'mode'=>'automated','roles'=>['scout','strategy','admin','owner'],
            'access'=>'Tester: Neptune scout access for their own QA observation. QA manager: owner/admin/strategy for evidence review.',
            'scope'=>'QA Test Event 1 · AWS or QA stage','requires'=>'QA match and robot; tested account must create or change its own Tag report.',
        ],
        'analytics'=>[
            'title'=>'Analytics Traditional → Tag fallback','description'=>'Check Neptune\'s real analytics code against raw QA records: Traditional totals, Tag-estimate fallback, source precedence (no double counting) and points per match.',
            'slot'=>1,'mode'=>'automated','roles'=>['strategy','admin','owner'],
            'access'=>'Tester: strategy, admin or owner who can open AUGUR Robot Stats. The QA system sets a QA alliance score automatically when a Tag-estimate needs one.',
            'scope'=>'QA Test Event 1 · AUGUR','requires'=>'A finished QA match (Traditional and/or Tag module) for the chosen robot. Pick an ENDED QA Test Event 1 match.',
        ],
        'data_quality'=>[
            'title'=>'Data Quality and correction audit','description'=>'Review the "Incomplete match" finding a real QA match produces, record a follow-up, resolve it, and verify the audit trail and that reviewing never edits scouting data.',
            'slot'=>1,'mode'=>'automated','roles'=>['strategy','admin','owner'],
            'access'=>'Tester: strategy, admin or owner with Data Quality review access. No extra admin step is needed.',
            'scope'=>'QA Test Event 1 · AUGUR Data Quality','requires'=>'An ENDED QA Test Event 1 match where some, but not all, robots were scouted (any finished Traditional or Tag QA run).',
        ],
        'assignment'=>[
            'title'=>'Assignments and live match monitor','description'=>'Neptune assigns the tester a station, they acknowledge it, scout the match, and the assignment closes by itself when the match ends. Checks the assignment, notification, audit trail, live-monitor evidence and auto-completion.',
            'slot'=>1,'mode'=>'automated','roles'=>['scout','strategy','admin','owner'],
            'access'=>'Tester: scout for the assigned station. The admin-level steps (create the assignment, start and end the QA match) are performed automatically by the QA system.',
            'scope'=>'QA Test Event 1 · Assignment Center','requires'=>'A READY QA Test Event 1 match. Reassignment and Tag-mode auto-close are not covered yet.',
        ],
        'connection_guard'=>[
            'title'=>'Connection Guard: offline and reconnect','description'=>'Scout online, lose the connection mid-match and keep scouting, reconnect, and verify every action the device sent was stored exactly once: none lost, none duplicated, none sent around the guard.',
            'slot'=>1,'mode'=>'automated','roles'=>['scout','strategy','admin','owner'],
            'access'=>'Tester: Neptune account in this organization on the device being tested (phone, tablet or laptop). Start and end of the QA match are automatic. Keep this QA page open in another tab or window: it reads the device\'s Connection Guard ledger and reports it to the verifier.',
            'scope'=>'QA Test Event 1 · Connection Guard','requires'=>'READY QA match; a device whose Wi-Fi or network can be turned off and on. Safari private windows are not supported (no IndexedDB).',
        ],
        'offline'=>[
            'title'=>'Offline scouting and reconnect','description'=>'Disconnect a portable server, scout locally, reconnect, verify queue, identical IDs, conflicts and duplicate-free cloud writes.',
            'slot'=>2,'mode'=>'planned','roles'=>['scout','strategy','admin','owner'],
            'access'=>'Tester: local portable Neptune login and scout access. Operator: portable host/network access and authorized sync trigger. AWS QA verifier access.',
            'scope'=>'QA Test Event 2 · Portable ↔ AWS','requires'=>'Portable host telemetry, offline test agent and cloud/local database reconciliation not yet implemented.',
        ],
        'portable'=>[
            'title'=>'Portable Mac/Linux install and recovery','description'=>'Validate install, in-place reinstall, login, backup/restore, media transfer, and synchronization.',
            'slot'=>2,'mode'=>'planned','roles'=>['admin','owner'],
            'access'=>'Tester: authorized Mac/Linux host operator with installer access and separate QA database/organization credentials. No production SSH required by default.',
            'scope'=>'QA Test Event 2 · Portable host','requires'=>'Host-side agent and isolated installation environment, not yet implemented.',
        ],
        'sync_health'=>[
            'title'=>'Sync, WebSocket and system health','description'=>'Track pending rows, errors, conflicts, presence, WebSocket dropout and polling recovery against server telemetry.',
            'slot'=>2,'mode'=>'planned','roles'=>['admin','owner'],
            'access'=>'Tester: admin/owner to view maintenance health; portable operator for link interruption and sync.',
            'scope'=>'QA Test Event 2 · System Health','requires'=>'Instrumented telemetry and health verifier not yet implemented.',
        ],
        'security'=>[
            'title'=>'Organization isolation and privileges','description'=>'Check cross-org scope denial, role boundaries, session revocation and denied sensitive queries using approved test identities.',
            'slot'=>2,'mode'=>'planned','roles'=>['owner'],
            'access'=>'Tester: authorized platform owner/security QA operator, with separate designated identities in two test organizations. Never use real users’ credentials.',
            'scope'=>'QA Test Event 2 · Security','requires'=>'Approved multi-organization test accounts and isolated tenant fixtures; no automated probe yet.',
        ],
        'release'=>[
            'title'=>'Release Control, stale update and rollback','description'=>'Exercise promotion gate, differing base checksums, application smoke tests, rollback and evidence.',
            'slot'=>2,'mode'=>'planned','roles'=>['owner','admin'],
            'access'=>'Tester: release operator authorized on QA stage and Release Control. Production deployment permission is not required for this test.',
            'scope'=>'QA Test Event 2 · QA stage / RC','requires'=>'Isolated staging release and rollback test agent not yet implemented.',
        ],
    ];
}
function nq_module_catalog_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_qa_modules (
      module_key VARCHAR(32) NOT NULL PRIMARY KEY,
      title VARCHAR(160) NOT NULL,
      description TEXT NOT NULL,
      qa_event_slot TINYINT UNSIGNED NOT NULL,
      verifier_mode VARCHAR(16) NOT NULL,
      tester_roles_json TEXT NOT NULL,
      required_access TEXT NOT NULL,
      prerequisites TEXT NOT NULL,
      scope_label VARCHAR(190) NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $sql='INSERT INTO neptune_qa_modules (module_key,title,description,qa_event_slot,verifier_mode,tester_roles_json,required_access,prerequisites,scope_label) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),qa_event_slot=VALUES(qa_event_slot),verifier_mode=VALUES(verifier_mode),tester_roles_json=VALUES(tester_roles_json),required_access=VALUES(required_access),prerequisites=VALUES(prerequisites),scope_label=VALUES(scope_label)';
    $stmt=$pdo->prepare($sql);
    foreach(nq_modules() as $key=>$module){$stmt->execute([$key,$module['title'],$module['description'],$module['slot'],$module['mode'],json_encode($module['roles']),$module['access'],$module['requires'],$module['scope']]);}
}
function nq_module_for_run(string $scenario): ?array {
    $items=nq_modules();return isset($items[$scenario]) ? ['key'=>$scenario]+$items[$scenario]:null;
}
function nq_qa_slot(array $event): ?int {
    if(!empty($event['tba_event_key']))return null;
    foreach([1,2] as $slot){
        if((string)($event['name']??$event['event_name']??'')==='QA Test Event '.$slot && (string)($event['event_code']??'')==='NEPTUNE_QA_'.$slot)return $slot;
    }
    return null;
}
