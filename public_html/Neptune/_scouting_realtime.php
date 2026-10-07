<?php
/**
 * Shared MERCURY realtime helpers for Match Scouting.
 *
 * Robot Recall uses public rooms 100000-999999. Match Scouting reserves the
 * leading-zero range 000001-099999 and requires a short-lived HMAC token.
 * The relay carries notification pokes only; authoritative data still comes
 * from Neptune's authenticated HTTP APIs.
 */

const NEPTUNE_REALTIME_SECRET_FILE = '/etc/neptune/realtime.secret';
const NEPTUNE_REALTIME_SCOPE_SCOUTING = 'scouting';
const NEPTUNE_REALTIME_TOKEN_VERSION = 'v1';
const NEPTUNE_REALTIME_TOKEN_TTL = 300;

function neptune_scouting_realtime_room(int $organizationId): string {
    if ($organizationId < 1 || $organizationId > 99999) {
        throw new RuntimeException('Organization ID is outside the supported realtime room range.');
    }
    return str_pad((string)$organizationId, 6, '0', STR_PAD_LEFT);
}

function neptune_realtime_secret(): string {
    static $secret = null;
    if ($secret !== null) return $secret;

    $raw = @file_get_contents(NEPTUNE_REALTIME_SECRET_FILE);
    $secret = is_string($raw) ? trim($raw) : '';
    if (strlen($secret) < 32) $secret = '';
    return $secret;
}

function neptune_scouting_realtime_credentials(int $organizationId): array {
    $secret = neptune_realtime_secret();
    if ($secret === '') {
        throw new RuntimeException('Neptune realtime private-room secret is unavailable.');
    }

    $room = neptune_scouting_realtime_room($organizationId);
    $expires = time() + NEPTUNE_REALTIME_TOKEN_TTL;
    $message = NEPTUNE_REALTIME_TOKEN_VERSION . '|' . NEPTUNE_REALTIME_SCOPE_SCOUTING . '|' . $room . '|' . $expires;
    $token = hash_hmac('sha256', $message, $secret);

    return [
        'room' => $room,
        'scope' => NEPTUNE_REALTIME_SCOPE_SCOUTING,
        'expires' => $expires,
        'token' => $token,
    ];
}

function neptune_scouting_lobby_snapshot(PDO $pdo,int $organizationId): array {
    $s=$pdo->prepare("SELECT m.id,m.state,m.run_number,m.started_at,m.paused_at,m.ended_at,
      GROUP_CONCAT(CONCAT(COALESCE(mt.alliance,''),':',COALESCE(mt.station,0),':',COALESCE(mt.frc_team_number,0))
        ORDER BY FIELD(mt.alliance,'Red','Blue'),mt.station SEPARATOR '|') AS teams
      FROM matches m
      LEFT JOIN match_teams mt ON mt.match_id=m.id
      WHERE m.organization_id=? AND m.state IN ('ready','running','paused')
      GROUP BY m.id,m.state,m.run_number,m.started_at,m.paused_at,m.ended_at
      ORDER BY FIELD(m.state,'running','paused','ready'),m.id DESC");
    $s->execute([$organizationId]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    return [
        'version'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
        'matches'=>$rows,
    ];
}
