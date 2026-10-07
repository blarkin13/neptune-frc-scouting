<?php
declare(strict_types=1);

function neptune_theme_catalog(): array {
$defaults = [
 'dark'=>[
  'c1-blue'=>'#004977','c1-red'=>'#D03027','c1-green'=>'#27814e','accent'=>'#0b79b7','good'=>'#3da66e','bad'=>'#e04a43','warn'=>'#7db7dc','ready'=>'#2c91cf','solid-text'=>'#ffffff',
  'game-primary'=>'#004977','game-secondary'=>'#0b79b7','game-intake'=>'#3f7a67','game-defense'=>'#e04a43','game-coop'=>'#3da66e','game-endgame'=>'#6d62aa','game-special'=>'#8d6b32','game-utility'=>'#657482',
  'bg'=>'#05080b','bg2'=>'#080d12','panel'=>'#0b1118','panel2'=>'#101923','line'=>'#24323f','text'=>'#f4f7fa','muted'=>'#91a1b1','bg-glow'=>'#0b2336','shadow'=>'#00000088',
  'alliance-blue'=>'#1d6c9f','alliance-blue-deep'=>'#1f6fa5','c1-border'=>'#d1d5db','c1-neutral-bg'=>'#f7f3eb','c1-selected-bg'=>'#e3e8f0','black'=>'#000000','ink-strong'=>'#111111','overlay-strong'=>'#000000cc','overlay-medium'=>'#000000bb',
  'module-trident'=>'#0b79b7','module-augur'=>'#6d62aa','module-saturn'=>'#8d6b32','module-vulcan'=>'#a2523f','module-system'=>'#657482','module-org'=>'#3f7a67','module-ops'=>'#0b79b7',
  'access-strategy'=>'#7289a7','access-admin'=>'#a27f47','access-owner'=>'#9aaabd',
 ],
 'light'=>[
  'bg'=>'#f7f3eb','bg2'=>'#eef1f3','panel'=>'#ffffff','panel2'=>'#f1f3f5','line'=>'#d1d5db','text'=>'#17212a','muted'=>'#5e6b76','c1-blue'=>'#004977','solid-text'=>'#ffffff','accent'=>'#004977','good'=>'#27814e','bad'=>'#D03027','warn'=>'#286b94','bg-glow'=>'#eef1f3','shadow'=>'#22222233',
 ],
];

$presets=[
 'Neptune Default'=>[],
 'Deep Ocean'=>['c1-blue'=>'#166B8F','accent'=>'#1685B5','bg'=>'#071019','bg2'=>'#0A151F','panel'=>'#0D1B27','panel2'=>'#122432','line'=>'#294354','text'=>'#F2F7FA','muted'=>'#91A7B5','bg-glow'=>'#0D3550','ready'=>'#42B8D8','alliance-blue'=>'#166B8F','alliance-blue-deep'=>'#125A78','module-trident'=>'#1685B5','module-augur'=>'#7167B2','module-saturn'=>'#987039','module-vulcan'=>'#A85B49','module-system'=>'#6B7F8F','module-org'=>'#3F816A','module-ops'=>'#1685B5'],
 'Steel Blue'=>['c1-blue'=>'#526D82','accent'=>'#6E91AA','bg'=>'#0B1117','bg2'=>'#101820','panel'=>'#151F28','panel2'=>'#1B2934','line'=>'#344A59','text'=>'#EDF3F6','muted'=>'#9AABB5','bg-glow'=>'#183041','ready'=>'#7FB3D5','alliance-blue'=>'#526D82','alliance-blue-deep'=>'#405A6F','module-trident'=>'#6E91AA','module-augur'=>'#737699','module-saturn'=>'#8D7551','module-vulcan'=>'#956456','module-system'=>'#71828F','module-org'=>'#58796F','module-ops'=>'#6E91AA'],
 'Teal'=>['c1-blue'=>'#167D7F','accent'=>'#2BA8A0','bg'=>'#071211','bg2'=>'#0B1918','panel'=>'#0F211F','panel2'=>'#142B29','line'=>'#2C4C49','text'=>'#F0F7F6','muted'=>'#92AAA7','bg-glow'=>'#0B3A39','ready'=>'#42C2B8','alliance-blue'=>'#167D7F','alliance-blue-deep'=>'#115F61','module-trident'=>'#2BA8A0','module-augur'=>'#756DB1','module-saturn'=>'#9A753C','module-vulcan'=>'#A95D4B','module-system'=>'#607E7B','module-org'=>'#3E816A','module-ops'=>'#2BA8A0'],
 'Navy + Cyan'=>['c1-blue'=>'#2456A6','accent'=>'#46AEE8','bg'=>'#080E19','bg2'=>'#0C1422','panel'=>'#111C2D','panel2'=>'#17243A','line'=>'#2D405E','text'=>'#F2F6FC','muted'=>'#96A6BC','bg-glow'=>'#163C66','ready'=>'#46C7E8','alliance-blue'=>'#2456A6','alliance-blue-deep'=>'#1D4688','module-trident'=>'#46AEE8','module-augur'=>'#716ED0','module-saturn'=>'#A0793D','module-vulcan'=>'#AD5D4A','module-system'=>'#657E9A','module-org'=>'#43846F','module-ops'=>'#46AEE8'],
 'Indigo'=>['c1-blue'=>'#5559A6','accent'=>'#777AE0','bg'=>'#0C0D17','bg2'=>'#121323','panel'=>'#18192B','panel2'=>'#20213A','line'=>'#393B5C','text'=>'#F5F4FB','muted'=>'#A5A3BC','bg-glow'=>'#252755','ready'=>'#8C8FE8','alliance-blue'=>'#5559A6','alliance-blue-deep'=>'#444887','module-trident'=>'#777AE0','module-augur'=>'#8B73D8','module-saturn'=>'#9D7747','module-vulcan'=>'#A85D63','module-system'=>'#72738E','module-org'=>'#557D73','module-ops'=>'#777AE0'],
 'Blue + Orange'=>['c1-blue'=>'#276FBF','accent'=>'#4A9BE8','bg'=>'#0A0F16','bg2'=>'#101720','panel'=>'#151E29','panel2'=>'#1C2835','line'=>'#344657','text'=>'#F4F7FA','muted'=>'#99A8B6','bg-glow'=>'#173755','ready'=>'#5AB7E8','alliance-blue'=>'#276FBF','alliance-blue-deep'=>'#1E5899','module-trident'=>'#4A9BE8','module-augur'=>'#756FC0','module-saturn'=>'#E8923C','module-vulcan'=>'#BC6548','module-system'=>'#78838D','module-org'=>'#4B826E','module-ops'=>'#E8923C'],
 'Halloween'=>['c1-blue'=>'#F47A1F','accent'=>'#A855F7','c1-green'=>'#6FAE45','good'=>'#78C850','bad'=>'#E45756','warn'=>'#F7B32B','ready'=>'#B56CFF','bg'=>'#0D0910','bg2'=>'#140B18','panel'=>'#1B1022','panel2'=>'#25152F','line'=>'#4B2A59','text'=>'#FFF5E8','muted'=>'#C5A7CA','bg-glow'=>'#3A123F','module-trident'=>'#FF8A2A','module-augur'=>'#9B5DE5','module-saturn'=>'#F6C453','module-vulcan'=>'#E4572E','module-system'=>'#8A7194','module-org'=>'#78C850','module-ops'=>'#FF8A2A','access-strategy'=>'#9B7AB8','access-admin'=>'#D58A3A','access-owner'=>'#C3A6D4'],
 'STEM Gals'=>['c1-blue'=>'#D84D9D','accent'=>'#8A6BFF','c1-green'=>'#23A89B','good'=>'#23B5A5','bad'=>'#E65A86','warn'=>'#F0B24A','ready'=>'#6BD8D0','bg'=>'#0D0D18','bg2'=>'#121225','panel'=>'#181729','panel2'=>'#211F38','line'=>'#403D63','text'=>'#F7F5FF','muted'=>'#B2AECE','bg-glow'=>'#31194E','module-trident'=>'#D84D9D','module-augur'=>'#8A6BFF','module-saturn'=>'#E59B45','module-vulcan'=>'#C95A8E','module-system'=>'#7775A0','module-org'=>'#23B5A5','module-ops'=>'#8A6BFF','access-strategy'=>'#8583B9','access-admin'=>'#BD6E9D','access-owner'=>'#AFA9D4'],
 'Galactic'=>['c1-blue'=>'#D9B44A','accent'=>'#5BB7FF','c1-green'=>'#45A77D','good'=>'#67D6A3','bad'=>'#E05A5A','warn'=>'#F0C05A','ready'=>'#72C7FF','bg'=>'#07090D','bg2'=>'#0D1118','panel'=>'#131923','panel2'=>'#1A2230','line'=>'#354156','text'=>'#F4F6FA','muted'=>'#A9B1C0','bg-glow'=>'#132946','module-trident'=>'#5BB7FF','module-augur'=>'#8D7CE8','module-saturn'=>'#D9B44A','module-vulcan'=>'#D95C52','module-system'=>'#76849A','module-org'=>'#52A77E','module-ops'=>'#D9B44A','access-strategy'=>'#718DB3','access-admin'=>'#B79548','access-owner'=>'#AEB8C8'],
 'Obsidian'=>['c1-blue'=>'#F5F5F5','solid-text'=>'#050505','accent'=>'#FFFFFF','c1-green'=>'#AFAFAF','good'=>'#6FD08C','bad'=>'#FF6B6B','warn'=>'#E6B85C','ready'=>'#FFFFFF','bg'=>'#000000','bg2'=>'#030303','panel'=>'#070707','panel2'=>'#0E0E0E','line'=>'#2A2A2A','text'=>'#FFFFFF','muted'=>'#A6A6A6','bg-glow'=>'#111111','alliance-blue'=>'#BFC7D5','alliance-blue-deep'=>'#7E8795','module-trident'=>'#FFFFFF','module-augur'=>'#D8D8D8','module-saturn'=>'#BEBEBE','module-vulcan'=>'#A8A8A8','module-system'=>'#8F8F8F','module-org'=>'#D0D0D0','module-ops'=>'#FFFFFF','access-strategy'=>'#9F9F9F','access-admin'=>'#C8C8C8','access-owner'=>'#FFFFFF'],
];
$presets['Neptune Default']=$defaults['dark'];
// Each preset gets a dedicated Game Builder palette derived from colors already present in that preset.
// This keeps the action palette coordinated without inventing an unrelated second theme.
foreach($presets as $presetName=>$presetValues){
    $resolved=array_replace($defaults['dark'],$presetValues);
    $presets[$presetName]=array_replace($presetValues,[
        'game-primary'=>$resolved['c1-blue'],
        'game-secondary'=>$resolved['accent'],
        'game-intake'=>$resolved['module-org'],
        'game-defense'=>$resolved['bad'],
        'game-coop'=>$resolved['good'],
        'game-endgame'=>$resolved['module-augur'],
        'game-special'=>$resolved['module-saturn'],
        'game-utility'=>$resolved['module-system'],
    ]);
}
$presetLight=[
 'Neptune Default'=>$defaults['light'],
 'Deep Ocean'=>['c1-blue'=>'#166B8F','solid-text'=>'#FFFFFF','bg'=>'#F4F8FA','bg2'=>'#EAF1F4','panel'=>'#FFFFFF','panel2'=>'#F0F5F7','line'=>'#CBD8DE','text'=>'#14232C','muted'=>'#60717B','accent'=>'#166B8F','good'=>'#27814E','bad'=>'#C7443E','warn'=>'#7A5A16','bg-glow'=>'#DCEBF1','shadow'=>'#10212B1A'],
 'Steel Blue'=>['c1-blue'=>'#405A6F','solid-text'=>'#FFFFFF','bg'=>'#F5F7F8','bg2'=>'#EDEFF1','panel'=>'#FFFFFF','panel2'=>'#F1F3F4','line'=>'#CDD5DA','text'=>'#1B252C','muted'=>'#65717A','accent'=>'#526D82','good'=>'#2F7B58','bad'=>'#B84640','warn'=>'#846420','bg-glow'=>'#E3E9ED','shadow'=>'#17222A1A'],
 'Teal'=>['c1-blue'=>'#167D7F','solid-text'=>'#FFFFFF','bg'=>'#F3F9F8','bg2'=>'#E7F2F0','panel'=>'#FFFFFF','panel2'=>'#EDF6F4','line'=>'#C8DCD9','text'=>'#152624','muted'=>'#607572','accent'=>'#167D7F','good'=>'#2C7D67','bad'=>'#BD4843','warn'=>'#7E651C','bg-glow'=>'#DAEFEB','shadow'=>'#1025211A'],
 'Navy + Cyan'=>['c1-blue'=>'#2456A6','solid-text'=>'#FFFFFF','bg'=>'#F4F7FB','bg2'=>'#E9EFF7','panel'=>'#FFFFFF','panel2'=>'#EEF3F9','line'=>'#CBD6E5','text'=>'#172235','muted'=>'#62718A','accent'=>'#2456A6','good'=>'#2D7A59','bad'=>'#BD4843','warn'=>'#80611B','bg-glow'=>'#DDEAF8','shadow'=>'#101C321A'],
 'Indigo'=>['c1-blue'=>'#5559A6','solid-text'=>'#FFFFFF','bg'=>'#F7F6FB','bg2'=>'#EEEDF6','panel'=>'#FFFFFF','panel2'=>'#F2F1F8','line'=>'#D6D3E5','text'=>'#201F31','muted'=>'#6E6C82','accent'=>'#5559A6','good'=>'#37775D','bad'=>'#B94A48','warn'=>'#81631F','bg-glow'=>'#E7E5F5','shadow'=>'#1A19301A'],
 'Blue + Orange'=>['c1-blue'=>'#276FBF','solid-text'=>'#FFFFFF','bg'=>'#F6F8FA','bg2'=>'#EBF0F5','panel'=>'#FFFFFF','panel2'=>'#EFF3F7','line'=>'#CFD8E2','text'=>'#1C2630','muted'=>'#687683','accent'=>'#276FBF','good'=>'#31775B','bad'=>'#B94742','warn'=>'#9A641B','bg-glow'=>'#E0ECF7','shadow'=>'#17222E1A'],
 'Halloween'=>['c1-blue'=>'#20151F','solid-text'=>'#FFFFFF','bg'=>'#FFF7ED','bg2'=>'#F7EAFB','panel'=>'#FFFFFF','panel2'=>'#F8EEF9','line'=>'#DCCAE3','text'=>'#261B29','muted'=>'#715E76','accent'=>'#8A3FC5','good'=>'#4F8F45','bad'=>'#C94B4B','warn'=>'#C86E12','bg-glow'=>'#F3D8FF','shadow'=>'#2B153322'],
 'STEM Gals'=>['c1-blue'=>'#5A2A6E','solid-text'=>'#FFFFFF','bg'=>'#FFF6FB','bg2'=>'#F2F0FF','panel'=>'#FFFFFF','panel2'=>'#FAF4FF','line'=>'#DDD6EF','text'=>'#281F38','muted'=>'#6F6685','accent'=>'#7B61FF','good'=>'#188A7C','bad'=>'#C63B71','warn'=>'#A66A18','bg-glow'=>'#F4DDF4','shadow'=>'#2F1D3A22'],
 'Galactic'=>['c1-blue'=>'#171B23','solid-text'=>'#FFFFFF','bg'=>'#F3F6FA','bg2'=>'#E9EEF5','panel'=>'#FFFFFF','panel2'=>'#EEF2F7','line'=>'#CCD5E1','text'=>'#171B23','muted'=>'#687385','accent'=>'#286FA8','good'=>'#2E8665','bad'=>'#B94141','warn'=>'#9A7220','bg-glow'=>'#DCE9F8','shadow'=>'#0D142022'],
 'Obsidian'=>['c1-blue'=>'#080808','solid-text'=>'#FFFFFF','bg'=>'#FFFFFF','bg2'=>'#FAFAFA','panel'=>'#FFFFFF','panel2'=>'#F3F3F3','line'=>'#D4D4D4','text'=>'#050505','muted'=>'#5E5E5E','accent'=>'#111111','good'=>'#237A49','bad'=>'#B33A3A','warn'=>'#8C6519','bg-glow'=>'#ECECEC','shadow'=>'#0000001F'],
];
$presetMeta=[
 'Neptune Default'=>'Original Neptune palette',
 'Deep Ocean'=>'Richer ocean blues',
 'Steel Blue'=>'Subdued and professional',
 'Teal'=>'Modern teal system',
 'Navy + Cyan'=>'Technical robotics blue',
 'Indigo'=>'Strategy-focused violet',
 'Blue + Orange'=>'Competition contrast',
 'Halloween'=>'Orange, purple and monster green',
 'STEM Gals'=>'Magenta, violet and teal',
 'Galactic'=>'Space-opera gold, blue and red',
 'Obsidian'=>'Near-black surfaces with crisp white highlights',
];
    return ['defaults'=>$defaults,'presets'=>$presets,'preset_light'=>$presetLight,'meta'=>$presetMeta];
}
function neptune_theme_slugify(string $v): string {
    $v=strtolower(trim($v)); $v=preg_replace('/[^a-z0-9]+/','-',$v)??''; $v=trim($v,'-');
    return $v!==''?substr($v,0,100):'theme';
}
function neptune_theme_valid_hex(string $v): bool { return (bool)preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/',trim($v)); }
function neptune_theme_normalize(array $palette): array {
    $c=neptune_theme_catalog(); $out=$c['defaults'];
    foreach(['dark','light'] as $scope){
        $src=is_array($palette[$scope]??null)?$palette[$scope]:[];
        foreach($out[$scope] as $k=>$fallback){
            $v=trim((string)($src[$k]??$fallback));
            if(!neptune_theme_valid_hex($v)) throw new RuntimeException('Invalid color for '.$scope.'.'.$k.'.');
            $out[$scope][$k]=strtoupper($v);
        }
    }
    return $out;
}
function neptune_theme_builtin(string $key): ?array {
    $c=neptune_theme_catalog();
    foreach($c['presets'] as $name=>$dark){
        if(neptune_theme_slugify($name)!==$key) continue;
        return ['type'=>'builtin','key'=>$key,'name'=>$name,'description'=>(string)($c['meta'][$name]??''),'palette'=>neptune_theme_normalize([
            'dark'=>array_replace($c['defaults']['dark'],$dark),
            'light'=>array_replace($c['defaults']['light'],is_array($c['preset_light'][$name]??null)?$c['preset_light'][$name]:[]),
        ])];
    }
    return null;
}
function neptune_theme_builtins(): array {
    $c=neptune_theme_catalog(); $out=[];
    foreach(array_keys($c['presets']) as $name){ $k=neptune_theme_slugify($name); $t=neptune_theme_builtin($k); if($t)$out[$k]=$t; }
    return $out;
}
function neptune_theme_ensure_schema(PDO $pdo): void {
    static $done=false; if($done)return;
    neptune_migration_apply($pdo,'themes.organization-ui.v1','Neptune_Organization_Theme_Manager_v1_Maintenance_Console.zip',function()use($pdo):void{
        $pdo->exec("CREATE TABLE IF NOT EXISTS organization_themes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            settings_json LONGTEXT NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_org_theme_slug (organization_id,slug),
            KEY idx_org_theme_org (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS organization_ui_settings (
            organization_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            active_theme_type VARCHAR(16) NOT NULL DEFAULT 'builtin',
            active_theme_key VARCHAR(120) NOT NULL DEFAULT 'neptune-default',
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    });
    $done=true;
}
function neptune_theme_custom(PDO $pdo,int $org,int $id): ?array {
    neptune_theme_ensure_schema($pdo); if($id<=0)return null;
    $s=$pdo->prepare('SELECT * FROM organization_themes WHERE organization_id=? AND id=? LIMIT 1');$s->execute([$org,$id]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return null;
    try{$r['palette']=neptune_theme_normalize(json_decode((string)$r['settings_json'],true,64,JSON_THROW_ON_ERROR));}catch(Throwable $e){return null;}
    return $r;
}
function neptune_theme_custom_list(PDO $pdo,int $org): array {
    neptune_theme_ensure_schema($pdo);$s=$pdo->prepare('SELECT * FROM organization_themes WHERE organization_id=? ORDER BY name,id');$s->execute([$org]);$out=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){try{$r['palette']=neptune_theme_normalize(json_decode((string)$r['settings_json'],true,64,JSON_THROW_ON_ERROR));$out[]=$r;}catch(Throwable $e){error_log('[Neptune themes] bad theme '.$r['id']);}}
    return $out;
}
function neptune_theme_unique_slug(PDO $pdo,int $org,string $name,int $exclude=0): string {
    $base=neptune_theme_slugify($name);$slug=$base;$n=2;
    while(true){ $sql='SELECT id FROM organization_themes WHERE organization_id=? AND slug=?';$args=[$org,$slug];if($exclude>0){$sql.=' AND id<>?';$args[]=$exclude;}$sql.=' LIMIT 1';$s=$pdo->prepare($sql);$s->execute($args);if(!$s->fetchColumn())return $slug;$slug=substr($base,0,92).'-'.$n++; }
}
function neptune_theme_save(PDO $pdo,int $org,int $userId,string $name,array $palette,int $id=0): int {
    neptune_theme_ensure_schema($pdo);$name=trim($name);if($name==='')throw new RuntimeException('Enter a theme name.');if(mb_strlen($name)>120)throw new RuntimeException('Theme name is too long.');$palette=neptune_theme_normalize($palette);
    if($id>0){if(!neptune_theme_custom($pdo,$org,$id))throw new RuntimeException('Theme not found.');$slug=neptune_theme_unique_slug($pdo,$org,$name,$id);$s=$pdo->prepare('UPDATE organization_themes SET name=?,slug=?,settings_json=?,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=?');$s->execute([$name,$slug,json_encode($palette,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$userId,$id,$org]);return $id;}
    $slug=neptune_theme_unique_slug($pdo,$org,$name);$s=$pdo->prepare('INSERT INTO organization_themes(organization_id,name,slug,settings_json,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');$s->execute([$org,$name,$slug,json_encode($palette,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$userId,$userId]);return (int)$pdo->lastInsertId();
}
function neptune_theme_set_active(PDO $pdo,int $org,int $userId,string $type,string $key): void {
    neptune_theme_ensure_schema($pdo);
    if($type==='builtin'){if(!neptune_theme_builtin($key))throw new RuntimeException('Theme not found.');}
    elseif($type==='custom'){$id=(int)$key;if(!neptune_theme_custom($pdo,$org,$id))throw new RuntimeException('Theme not found.');$key=(string)$id;}
    else throw new RuntimeException('Invalid theme type.');
    $s=$pdo->prepare("INSERT INTO organization_ui_settings(organization_id,active_theme_type,active_theme_key,updated_by,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE active_theme_type=VALUES(active_theme_type),active_theme_key=VALUES(active_theme_key),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)");$s->execute([$org,$type,$key,$userId]);
}
function neptune_theme_active(PDO $pdo,int $org): array {
    neptune_theme_ensure_schema($pdo);$fallback=neptune_theme_builtin('neptune-default');if(!$fallback)throw new RuntimeException('Neptune Default is unavailable.');
    $s=$pdo->prepare('SELECT active_theme_type,active_theme_key FROM organization_ui_settings WHERE organization_id=? LIMIT 1');$s->execute([$org]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return $fallback;
    if($r['active_theme_type']==='builtin')return neptune_theme_builtin((string)$r['active_theme_key'])?:$fallback;
    if($r['active_theme_type']==='custom'){$t=neptune_theme_custom($pdo,$org,(int)$r['active_theme_key']);if($t)return ['type'=>'custom','key'=>(string)$t['id'],'name'=>$t['name'],'description'=>'Organization-created theme','palette'=>$t['palette']];}
    return $fallback;
}
function neptune_theme_delete(PDO $pdo,int $org,int $id): void {
    $active=neptune_theme_active($pdo,$org);if($active['type']==='custom'&&(int)$active['key']===$id)throw new RuntimeException('Apply another theme before deleting the active theme.');$s=$pdo->prepare('DELETE FROM organization_themes WHERE organization_id=? AND id=?');$s->execute([$org,$id]);if($s->rowCount()<1)throw new RuntimeException('Theme not found.');
}
function neptune_theme_css(array $palette): string {
    $p=neptune_theme_normalize($palette);$css=':root{';foreach($p['dark'] as $k=>$v)$css.='--'.$k.':'.$v.';';$css.='--action-text:var(--solid-text);}html[data-theme="light"]{';foreach($p['light'] as $k=>$v)$css.='--'.$k.':'.$v.';';return $css.'}';
}
function neptune_theme_active_css(PDO $pdo,int $org): string {return $org>0?neptune_theme_css(neptune_theme_active($pdo,$org)['palette']):'';}
