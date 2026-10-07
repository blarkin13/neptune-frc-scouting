<?php
/**
 * Central registry for Neptune training screenshots.
 * Add future screenshots here once, then reference them by key anywhere in the
 * User Guide, Event Manual, Training Home, or CBT pages.
 */
function neptune_training_media(): array {
    return [
        'command-center' => ['file'=>'command-center.jpg','title'=>'Command Center','alt'=>'Neptune Command Center showing Event Operations cards including Match Control, Live Monitor, Event Setup, TBA Sync, and Pit Games.'],
        'event-setup' => ['file'=>'event-setup.jpg','title'=>'Event Setup','alt'=>'Neptune Event Setup page showing event creation fields and configured events.'],
        'event-roster' => ['file'=>'event-roster.jpg','title'=>'Event roster and status','alt'=>'Neptune Event Setup detail showing Test Event status, roster entry, roster counts, and Event Roster.'],
        'tba-sync' => ['file'=>'tba-sync.jpg','title'=>'The Blue Alliance Sync','alt'=>'Neptune TBA Sync page showing Find TBA Events and manual events waiting for a TBA link.'],
        'tba-events' => ['file'=>'tba-events.jpg','title'=>'Imported TBA events','alt'=>'Neptune TBA Sync imported events cards showing roster, schedule, pre-scout, and pit completion status.'],
        'game-builder' => ['file'=>'game-builder.jpg','title'=>'Game Builder','alt'=>'Neptune Game Builder showing match timing, action designer, scoring values, button appearance, and live Scout Grid preview.'],
        'pit-form-builder' => ['file'=>'pit-form-builder.jpg','title'=>'Pit Form Builder','alt'=>'Neptune Pit Form Builder showing published revision, game selection, a game-specific question, type, options, ordering, and Save Pit Draft.'],
        'pre-scout-builder' => ['file'=>'pre-scout-builder.jpg','title'=>'Pre-Scout Form Builder','alt'=>'Neptune Pre-Scout Form Builder showing published revision and several configured game-specific pre-scout questions.'],
        'pre-scout-team' => ['file'=>'pre-scout-team.jpg','title'=>'Pre-Scouting team intelligence','alt'=>'Neptune Pre-Scouting page for team 148 showing season performance, EPA, Auto, Teleop, Endgame, OPR, team reference, and Neptune season knowledge.'],
        'pit-basics' => ['file'=>'pit-basics.jpg','title'=>'Pit Scouting — robot basics','alt'=>'Neptune Pit Scouting page for team 6369 showing Robot Basics, Game Piece Handling, Save Draft, and Mark Complete.'],
        'pit-auton-endgame' => ['file'=>'pit-auton-endgame.jpg','title'=>'Pit Scouting — autonomous and endgame','alt'=>'Neptune Pit Scouting sections showing autonomous routines and endgame capability fields.'],
        'pit-strategy-reliability' => ['file'=>'pit-strategy-reliability.jpg','title'=>'Pit Scouting — strategy and reliability','alt'=>'Neptune Pit Scouting sections showing preferred roles, defense tolerance, alliance notes, and reliability fields.'],
        'pit-auton-path' => ['file'=>'pit-auton-path.jpg','title'=>'Pit Scouting — autonomous path','alt'=>'Neptune Pit Scouting page showing game-specific question and the Autonomous Path drawing tool on a field image.'],
        'pit-photos' => ['file'=>'pit-photos.jpg','title'=>'Pit Scouting — robot photos','alt'=>'Neptune Pit Scouting Robot Photos section showing front, back, side, mechanism photo slots and a saved photo.'],
        'tag-match' => ['file'=>'tag-match.jpg','title'=>'Tag Scouting — choose context and robot','alt'=>'Neptune Tag Scouting Match context showing event and match selection, red and blue alliance robot cards, and the selected observation robot.'],
        'tag-observations' => ['file'=>'tag-observations.jpg','title'=>'Tag Scouting — observation tags','alt'=>'Neptune Tag Scouting observation panel showing selected Auto, Offense, Defense, and Endgame tags.'],
        'live-match-scouting' => ['file'=>'live-match-scouting.jpg','title'=>'Live Match Scouting','alt'=>'Neptune mobile Match Scouting interface showing match, robot assignment, timer, action and points totals, and the action grid.'],
        'action-swipe' => ['file'=>'action-swipe.jpg','title'=>'Record an action result','alt'=>'Neptune mobile scouting action popup showing swipe left for Failure and swipe right for Success.'],
        'match-control' => ['file'=>'match-control.jpg','title'=>'Match Control','alt'=>'Neptune Match Control showing a running match, robot assignments, action totals, next scheduled matches, Pause, End, Make Ready, and Monitor controls.'],
        'live-monitor' => ['file'=>'live-monitor.jpg','title'=>'Live Monitor','alt'=>'Neptune Live Monitor showing a running match, six robot stations, connection status, match controls, latest actions, and Add or Correct Action.'],
    ];
}

function neptune_training_media_item(string $key): ?array {
    $all = neptune_training_media();
    if (!isset($all[$key])) return null;
    $item = $all[$key];
    $item['key'] = $key;
    $assetPath = dirname(__DIR__).'/assets/help/training/'.$item['file'];
    $version = is_file($assetPath) ? (string)filemtime($assetPath) : '5';
    $item['url'] = base_url('assets/help/training/'.$item['file']).'?v='.rawurlencode($version);
    return $item;
}

function neptune_training_figure(string $key, string $caption = '', string $class = ''): void {
    $item = neptune_training_media_item($key);
    if (!$item) return;
    $caption = $caption !== '' ? $caption : $item['title'];
    ?>
    <figure class="ntp-shot <?=e($class)?>">
      <button type="button" class="ntp-shot-button" data-training-image="<?=e($item['url'])?>" data-training-title="<?=e($item['title'])?>" aria-label="Open larger image: <?=e($item['title'])?>">
        <img src="<?=e($item['url'])?>" alt="<?=e($item['alt'])?>" loading="lazy" decoding="async">
        <span class="ntp-shot-zoom"><i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i> Enlarge</span>
      </button>
      <figcaption><b><?=e($item['title'])?></b><span><?=e($caption)?></span></figcaption>
    </figure>
    <?php
}

function neptune_training_gallery(array $items, string $class = ''): void {
    ?><div class="ntp-shot-grid <?=e($class)?>"><?php
    foreach ($items as $row) {
        if (is_string($row)) {
            neptune_training_figure($row);
            continue;
        }
        $key = (string)($row[0] ?? '');
        $caption = (string)($row[1] ?? '');
        $figureClass = (string)($row[2] ?? '');
        if ($key !== '') neptune_training_figure($key, $caption, $figureClass);
    }
    ?></div><?php
}
