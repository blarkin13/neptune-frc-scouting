<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';

@set_time_limit(0);

function td_table_ready(PDO $pdo): bool {
    $s=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_epa_team_directory'");
    return (int)$s->fetchColumn()>0;
}
function td_fetch(string $path,int $ttl=86400): array {
    $rows=tba_get($path,$ttl);
    return is_array($rows)?$rows:[];
}
function td_elapsed(float $start): string {
    $s=max(0,(int)round(microtime(true)-$start));
    $h=intdiv($s,3600);$m=intdiv($s%3600,60);$sec=$s%60;
    return $h>0?sprintf('%d:%02d:%02d',$h,$m,$sec):sprintf('%d:%02d',$m,$sec);
}
function td_upsert(PDOStatement $upsert,int $year,array $team): bool {
    $num=(int)($team['team_number']??0);
    if($num<=0)return false;
    $upsert->execute([
        $year,
        $num,
        $team['key']??('frc'.$num),
        isset($team['nickname'])?trim((string)$team['nickname']):null,
        isset($team['name'])?trim((string)$team['name']):null,
        isset($team['city'])?trim((string)$team['city']):null,
        isset($team['state_prov'])?trim((string)$team['state_prov']):null,
        isset($team['country'])?trim((string)$team['country']):null,
        isset($team['rookie_year'])?(int)$team['rookie_year']:null,
    ]);
    return true;
}

if(!td_table_ready($pdo)){
    fwrite(STDERR,"augur_epa_team_directory is not installed. Run the team-directory SQL first.\n");
    exit(2);
}

$current=(int)date('Y');
$start=isset($argv[1])?(int)$argv[1]:$current;
$end=isset($argv[2])?(int)$argv[2]:$start;
if($start>$end)[$start,$end]=[$end,$start];

$upsert=$pdo->prepare("INSERT INTO augur_epa_team_directory
    (season_year,frc_team_number,tba_team_key,nickname,name,city,state_prov,country,rookie_year)
    VALUES(?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      tba_team_key=VALUES(tba_team_key),
      nickname=CASE WHEN VALUES(nickname) IS NOT NULL AND VALUES(nickname)<>'' THEN VALUES(nickname) ELSE nickname END,
      name=CASE WHEN VALUES(name) IS NOT NULL AND VALUES(name)<>'' THEN VALUES(name) ELSE name END,
      city=CASE WHEN VALUES(city) IS NOT NULL AND VALUES(city)<>'' THEN VALUES(city) ELSE city END,
      state_prov=CASE WHEN VALUES(state_prov) IS NOT NULL AND VALUES(state_prov)<>'' THEN VALUES(state_prov) ELSE state_prov END,
      country=CASE WHEN VALUES(country) IS NOT NULL AND VALUES(country)<>'' THEN VALUES(country) ELSE country END,
      rookie_year=COALESCE(VALUES(rookie_year),rookie_year),
      updated_at=CURRENT_TIMESTAMP");

$allStart=microtime(true);
echo "Neptune AUGUR team directory COMPLETE sync {$start}-{$end}\n";
echo "Bulk season directory first, then direct TBA repair for every EPA-rated team with missing identity/location.\n\n";

for($year=$start;$year<=$end;$year++){
    $yearStart=microtime(true);
    $bulkCount=0;
    echo "[{$year}] Bulk TBA team directory";

    try{
        for($page=0;$page<64;$page++){
            $rows=td_fetch("teams/{$year}/{$page}/simple",86400);
            if(!$rows)break;

            $pdo->beginTransaction();
            try{
                foreach($rows as $team){
                    if(td_upsert($upsert,$year,$team))$bulkCount++;
                }
                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }

            echo '.';
            if(count($rows)<500)break;
            usleep(100000);
        }
        echo " {$bulkCount} rows\n";
    }catch(Throwable $e){
        echo " ERROR: {$e->getMessage()}\n";
    }

    // The ratings archive is the authoritative list of teams that must have
    // metadata on the public page. This catches any team omitted by the
    // paginated season directory or left with incomplete metadata.
    $sql="SELECT DISTINCT frc_team_number
          FROM (
            SELECT frc_team_number FROM augur_epa_season_ratings WHERE season_year=?
            UNION
            SELECT frc_team_number FROM augur_epa_event_ratings WHERE season_year=?
          ) rated
          ORDER BY frc_team_number";
    $s=$pdo->prepare($sql);
    $s->execute([$year,$year]);
    $rated=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));

    $check=$pdo->prepare("SELECT nickname,name,city,country
        FROM augur_epa_team_directory
        WHERE season_year=? AND frc_team_number=? LIMIT 1");

    $need=[];
    foreach($rated as $num){
        $check->execute([$year,$num]);
        $r=$check->fetch();
        if(!$r
           || (trim((string)($r['nickname']??''))==='' && trim((string)($r['name']??''))==='')
           || trim((string)($r['city']??''))===''
           || trim((string)($r['country']??''))===''){
            $need[]=$num;
        }
    }

    echo "[{$year}] EPA-rated teams: ".count($rated)."; incomplete metadata after bulk sync: ".count($need)."\n";

    $repaired=0;$stillIncomplete=0;$errors=0;
    foreach($need as $i=>$num){
        echo "[{$year}] Repair ".($i+1)."/".count($need)." frc{$num} ... ";
        try{
            $team=td_fetch("team/frc{$num}/simple",86400);
            if($team && isset($team['team_number'])){
                td_upsert($upsert,$year,$team);
                $repaired++;

                $name=trim((string)($team['nickname']??$team['name']??''));
                $city=trim((string)($team['city']??''));
                $country=trim((string)($team['country']??''));
                if($name===''||$city===''||$country===''){
                    $stillIncomplete++;
                    echo "TBA returned partial metadata\n";
                }else{
                    echo "{$name} · {$city}, {$country}\n";
                }
            }else{
                $stillIncomplete++;
                echo "no TBA team record\n";
            }
        }catch(Throwable $e){
            $errors++;
            echo "ERROR: {$e->getMessage()}\n";
        }
        usleep(120000);
    }

    // Final verification against the ratings tables.
    $verify=$pdo->prepare("SELECT COUNT(*)
        FROM (
          SELECT DISTINCT frc_team_number
          FROM (
            SELECT frc_team_number FROM augur_epa_season_ratings WHERE season_year=?
            UNION
            SELECT frc_team_number FROM augur_epa_event_ratings WHERE season_year=?
          ) x
        ) rated
        LEFT JOIN augur_epa_team_directory d
          ON d.season_year=? AND d.frc_team_number=rated.frc_team_number
        WHERE d.frc_team_number IS NULL
           OR (COALESCE(NULLIF(TRIM(d.nickname),''),NULLIF(TRIM(d.name),'')) IS NULL)
           OR NULLIF(TRIM(d.city),'') IS NULL
           OR NULLIF(TRIM(d.country),'') IS NULL");
    $verify->execute([$year,$year,$year]);
    $remaining=(int)$verify->fetchColumn();

    echo "[{$year}] Complete: bulk={$bulkCount}, direct-repaired={$repaired}, TBA-partial={$stillIncomplete}, errors={$errors}, remaining-incomplete={$remaining} (".td_elapsed($yearStart).")\n\n";
}

echo "Done in ".td_elapsed($allStart).".\n";
