<?php
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
$u = require_role(['owner']);
$platformOrgId = max(1, (int)($config['app']['platform_organization_id'] ?? 1));
if ((int)($u['organization_id'] ?? 0) !== $platformOrgId) {
    http_response_code(403);
    $accessDeniedUser = $u;
    $accessDeniedMessage = 'Interface Styling is reserved for the Neptune platform owner.';
    include dirname(__DIR__) . '/errors/403.php';
    exit;
}

$pageTitle = 'Interface Styling';
$moduleName = 'MERCURY';
$cssPath = dirname(__DIR__) . '/assets/css/app.css';
$markerStart = '/* NEPTUNE THEME PALETTE:START';
$markerEnd = '/* NEPTUNE THEME PALETTE:END */';

$darkFields = [
    'bg'=>'Page background','bg2'=>'Page background 2','panel'=>'Panel','panel2'=>'Panel secondary','line'=>'Borders','text'=>'Primary text','muted'=>'Muted text','bg-glow'=>'Background glow','shadow'=>'Shadow',
];
$brandFields = [
    'c1-blue'=>'Primary button','accent'=>'Links / focus','c1-red'=>'Competition red','c1-green'=>'Competition green','good'=>'Success','bad'=>'Error','warn'=>'Warning','ready'=>'Ready / alliance highlight','alliance-blue'=>'Blue alliance','alliance-blue-deep'=>'Blue alliance deep','solid-text'=>'Text on solid colors',
];
$moduleFields = [
    'module-trident'=>'TRIDENT','module-augur'=>'AUGUR','module-saturn'=>'SATURN','module-vulcan'=>'VULCAN','module-system'=>'MERCURY','module-org'=>'Organization','module-ops'=>'Operations',
];
$accessFields = [
    'access-strategy'=>'Strategy+','access-admin'=>'Admin+','access-owner'=>'Platform owner',
];
$utilityFields = [
    'c1-border'=>'Neutral button border','c1-neutral-bg'=>'Neutral background','c1-selected-bg'=>'Selected background','black'=>'Black utility','ink-strong'=>'Strong ink','overlay-strong'=>'Strong overlay','overlay-medium'=>'Medium overlay',
];
$lightFields = [
    'bg'=>'Page background','bg2'=>'Page background 2','panel'=>'Panel','panel2'=>'Panel secondary','line'=>'Borders','text'=>'Primary text','muted'=>'Muted text','c1-blue'=>'Primary button / icon tiles','solid-text'=>'Text / icons on primary','accent'=>'Links / focus','good'=>'Success','bad'=>'Error','warn'=>'Warning','bg-glow'=>'Background glow','shadow'=>'Shadow',
];

$defaults = [
 'dark'=>[
  'c1-blue'=>'#004977','c1-red'=>'#D03027','c1-green'=>'#27814e','accent'=>'#0b79b7','good'=>'#3da66e','bad'=>'#e04a43','warn'=>'#7db7dc','ready'=>'#2c91cf','solid-text'=>'#ffffff',
  'bg'=>'#05080b','bg2'=>'#080d12','panel'=>'#0b1118','panel2'=>'#101923','line'=>'#24323f','text'=>'#f4f7fa','muted'=>'#91a1b1','bg-glow'=>'#0b2336','shadow'=>'#00000088',
  'alliance-blue'=>'#1d6c9f','alliance-blue-deep'=>'#1f6fa5','c1-border'=>'#d1d5db','c1-neutral-bg'=>'#f7f3eb','c1-selected-bg'=>'#e3e8f0','black'=>'#000000','ink-strong'=>'#111111','overlay-strong'=>'#000000cc','overlay-medium'=>'#000000bb',
  'module-trident'=>'#0b79b7','module-augur'=>'#6d62aa','module-saturn'=>'#8d6b32','module-vulcan'=>'#a2523f','module-system'=>'#657482','module-org'=>'#3f7a67','module-ops'=>'#0b79b7',
  'access-strategy'=>'#7289a7','access-admin'=>'#a27f47','access-owner'=>'#9aaabd',
 ],
 'light'=>[
  'bg'=>'#f7f3eb','bg2'=>'#eef1f3','panel'=>'#ffffff','panel2'=>'#f1f3f5','line'=>'#d1d5db','text'=>'#17212a','muted'=>'#5e6b76','c1-blue'=>'#004977','solid-text'=>'#ffffff','accent'=>'#004977','good'=>'#27814e','bad'=>'#D03027','warn'=>'#286b94','bg-glow'=>'#eef1f3','shadow'=>'#22222233',
 ],
];

function styling_extract_scope(string $css, string $selector): array {
    $pattern = $selector === ':root'
        ? '/:root\s*\{(.*?)\}/s'
        : '/html\[data-theme="light"\]\s*\{(.*?)\}/s';
    if (!preg_match($pattern, $css, $m)) return [];
    preg_match_all('/--([a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{3,8})\s*;/', $m[1], $pairs, PREG_SET_ORDER);
    $out=[];
    foreach($pairs as $p) $out[$p[1]]=$p[2];
    return $out;
}
function styling_valid_hex(string $v): bool {
    return (bool)preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', trim($v));
}
function styling_palette_css(array $dark, array $light): string {
    $d = fn(string $k) => $dark[$k];
    $l = fn(string $k) => $light[$k];
    return "/* NEPTUNE THEME PALETTE:START\n   This is the single color source for app.css + neptune-ui.css.\n   You can edit these variables manually or use Command Center > MERCURY > System Maintenance > Interface Styling.\n*/\n".
":root{\n".
"  /* Core brand + status */\n".
"  --c1-blue:{$d('c1-blue')};\n  --c1-red:{$d('c1-red')};\n  --c1-green:{$d('c1-green')};\n  --accent:{$d('accent')};\n  --good:{$d('good')};\n  --bad:{$d('bad')};\n  --warn:{$d('warn')};\n  --ready:{$d('ready')};\n  --solid-text:{$d('solid-text')};\n\n".
"  /* Dark mode foundation */\n".
"  --bg:{$d('bg')};\n  --bg2:{$d('bg2')};\n  --panel:{$d('panel')};\n  --panel2:{$d('panel2')};\n  --line:{$d('line')};\n  --text:{$d('text')};\n  --muted:{$d('muted')};\n  --bg-glow:{$d('bg-glow')};\n  --shadow:{$d('shadow')};\n\n".
"  /* Competition colors */\n".
"  --alliance-blue:{$d('alliance-blue')};\n  --alliance-blue-deep:{$d('alliance-blue-deep')};\n\n".
"  /* Shared neutral/utility colors */\n".
"  --c1-border:{$d('c1-border')};\n  --c1-neutral-bg:{$d('c1-neutral-bg')};\n  --c1-selected-bg:{$d('c1-selected-bg')};\n  --black:{$d('black')};\n  --ink-strong:{$d('ink-strong')};\n  --overlay-strong:{$d('overlay-strong')};\n  --overlay-medium:{$d('overlay-medium')};\n  --action-text:var(--solid-text);\n\n".
"  /* Neptune subsystem accents */\n".
"  --module-trident:{$d('module-trident')};\n  --module-augur:{$d('module-augur')};\n  --module-saturn:{$d('module-saturn')};\n  --module-vulcan:{$d('module-vulcan')};\n  --module-system:{$d('module-system')};\n  --module-org:{$d('module-org')};\n  --module-ops:{$d('module-ops')};\n\n".
"  /* Access-level accents */\n".
"  --access-strategy:{$d('access-strategy')};\n  --access-admin:{$d('access-admin')};\n  --access-owner:{$d('access-owner')};\n}\n".
"html[data-theme=\"light\"]{\n  /* Light mode foundation */\n".
"  --bg:{$l('bg')};\n  --bg2:{$l('bg2')};\n  --panel:{$l('panel')};\n  --panel2:{$l('panel2')};\n  --line:{$l('line')};\n  --text:{$l('text')};\n  --muted:{$l('muted')};\n  --c1-blue:{$l('c1-blue')};\n  --solid-text:{$l('solid-text')};\n  --accent:{$l('accent')};\n  --good:{$l('good')};\n  --bad:{$l('bad')};\n  --warn:{$l('warn')};\n  --bg-glow:{$l('bg-glow')};\n  --shadow:{$l('shadow')};\n}\n/* NEPTUNE THEME PALETTE:END */";
}

$error='';
$css=is_file($cssPath)?(string)file_get_contents($cssPath):'';
$current=$defaults;
if($css!==''){
    $start=strpos($css,$markerStart);$end=strpos($css,$markerEnd);
    if($start!==false && $end!==false && $end>$start){
        $block=substr($css,$start,$end-$start+strlen($markerEnd));
        $current['dark']=array_replace($current['dark'],styling_extract_scope($block,':root'));
        $current['light']=array_replace($current['light'],styling_extract_scope($block,'light'));
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(!is_file($cssPath) || !is_writable($cssPath)) throw new RuntimeException('app.css is not writable by PHP. Check Neptune file permissions.');
        $dark=$current['dark'];$light=$current['light'];
        foreach(array_keys($dark) as $k){
            $v=trim((string)($_POST['dark'][$k]??''));
            if(!styling_valid_hex($v)) throw new RuntimeException('Invalid color for '.$k.'. Use #RGB, #RGBA, #RRGGBB, or #RRGGBBAA.');
            $dark[$k]=$v;
        }
        foreach(array_keys($light) as $k){
            $v=trim((string)($_POST['light'][$k]??''));
            if(!styling_valid_hex($v)) throw new RuntimeException('Invalid light color for '.$k.'.');
            $light[$k]=$v;
        }
        $full=(string)file_get_contents($cssPath);
        $start=strpos($full,$markerStart);$end=strpos($full,$markerEnd);
        if($start===false || $end===false || $end<=$start) throw new RuntimeException('Theme palette markers are missing from app.css. Install the theme patch again.');
        $end += strlen($markerEnd);
        $newBlock=styling_palette_css($dark,$light);
        $updated=substr($full,0,$start).$newBlock.substr($full,$end);

        $backupDir='/var/www/neptune/file-manager-backups/theme';
        if((is_dir($backupDir) || @mkdir($backupDir,0775,true)) && is_writable($backupDir)){
            @file_put_contents($backupDir.'/app.css.'.gmdate('Ymd-His').'.bak',$full,LOCK_EX);
        }
        $tmp=tempnam(dirname($cssPath),'theme-');
        if($tmp===false) throw new RuntimeException('Could not create a temporary theme file.');
        if(file_put_contents($tmp,$updated,LOCK_EX)===false){@unlink($tmp);throw new RuntimeException('Could not write the updated theme.');}
        @chmod($tmp,0664);
        if(!@rename($tmp,$cssPath)){@unlink($tmp);throw new RuntimeException('Could not replace app.css with the updated theme.');}
        clearstatcache(true,$cssPath);
        header('Location: '.base_url('admin/styling.php?saved=1&t='.time()));
        exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}

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

include dirname(__DIR__) . '/partials_header.php';
?>
<style>
.style-page{max-width:1260px;margin:0 auto}.style-head{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:18px}.style-head h1{margin:0 0 5px}.style-head p{margin:0;color:var(--muted);max-width:830px;line-height:1.5}.style-layout{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:16px;align-items:start}.style-groups{display:grid;gap:16px}.palette-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 12px}.palette-field{display:grid;grid-template-columns:36px minmax(0,1fr);gap:8px;align-items:end}.palette-field label{grid-column:1/-1;margin:0;color:var(--muted);font-size:.72rem;font-weight:850}.palette-picker{width:36px;height:36px;padding:2px;border:1px solid var(--line);border-radius:5px;background:var(--panel2);cursor:pointer}.palette-picker::-webkit-color-swatch-wrapper{padding:0}.palette-picker::-webkit-color-swatch{border:0;border-radius:3px}.palette-hex{min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.82rem}.palette-field.alpha{grid-template-columns:1fr}.style-card h2{display:flex;align-items:center;gap:8px;margin-bottom:5px}.style-card>p{margin:0 0 14px;color:var(--muted);font-size:.82rem;line-height:1.4}.preset-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.preset-button{display:block;width:100%;text-align:left;background:var(--panel2);color:var(--text);border:1px solid var(--line);padding:10px;border-radius:7px}.preset-button b{display:block}.preset-note{display:block;margin-top:3px;color:var(--muted);font-size:.7rem;line-height:1.3}.preset-swatches{display:flex;gap:4px;margin-top:7px}.preset-swatch{width:22px;height:9px;border-radius:99px;border:1px solid color-mix(in srgb,var(--line) 75%,transparent)}.style-preview{position:sticky;top:98px;display:grid;gap:12px}.preview-shell{padding:14px;border:1px solid var(--preview-line);border-radius:10px;background:linear-gradient(180deg,var(--preview-bg),var(--preview-bg2));color:var(--preview-text)}.preview-shell .p-card{padding:13px;border:1px solid var(--preview-line);border-radius:8px;background:var(--preview-panel)}.preview-shell .p-muted{color:var(--preview-muted);font-size:.78rem}.preview-shell .p-row{display:flex;gap:7px;flex-wrap:wrap;margin-top:11px}.preview-shell .p-button{padding:7px 9px;border-radius:4px;background:var(--preview-primary);color:var(--preview-solid);font-size:.75rem;font-weight:850}.preview-shell .p-chip{padding:5px 7px;border-radius:99px;background:var(--preview-panel2);border:1px solid var(--preview-line);font-size:.68rem}.preview-modules{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;margin-top:12px}.preview-modules span{height:5px;border-radius:99px}.style-save{display:flex;justify-content:flex-end;gap:9px;position:sticky;bottom:10px;padding:12px;border:1px solid var(--line);border-radius:9px;background:color-mix(in srgb,var(--panel) 95%,transparent);backdrop-filter:blur(10px);z-index:5}.global-warning{border-left:4px solid var(--warn)}@media(max-width:980px){.style-layout{grid-template-columns:1fr}.style-preview{position:static}}@media(max-width:650px){.palette-grid,.preset-grid{grid-template-columns:1fr}.style-head{align-items:stretch;flex-direction:column}}
</style>
<section class="style-page">
  <header class="style-head">
    <div><h1>Interface Styling</h1><p>One global palette controls Neptune's shared CSS. Changes apply to every organization. app.css contains the only color-variable section; neptune-ui.css consumes those variables.</p></div>
    <a class="btn secondary" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a>
  </header>
  <?php if(isset($_GET['saved'])):?><div class="notice good" style="margin-bottom:14px"><b>Palette saved.</b> Neptune is now using the updated colors.</div><?php endif;?>
  <?php if($error):?><div class="notice bad" style="margin-bottom:14px"><?=e($error)?></div><?php endif;?>
  <div class="notice global-warning" style="margin-bottom:14px"><b>Global system setting.</b> This changes Neptune's appearance for every organization. It does not change any scouting data or organization branding.</div>

  <form method="post" id="themeForm">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <div class="style-layout">
      <div class="style-groups">
        <section class="card style-card"><h2><i class="fa-solid fa-swatchbook"></i> Presets</h2><p>Load a starting palette, preview it, then save when you are ready.</p><div class="preset-grid">
          <?php foreach($presets as $name=>$preset): $sw=[$preset['bg']??$current['dark']['bg'],$preset['panel']??$current['dark']['panel'],$preset['c1-blue']??$current['dark']['c1-blue'],$preset['accent']??$current['dark']['accent']];?>
          <button class="preset-button" type="button" data-preset="<?=e($name)?>"><b><?=e($name)?></b><?php if(!empty($presetMeta[$name])):?><small class="preset-note"><?=e($presetMeta[$name])?></small><?php endif;?><span class="preset-swatches"><?php foreach($sw as $c):?><i class="preset-swatch" style="background:<?=e($c)?>"></i><?php endforeach;?></span></button>
          <?php endforeach;?>
        </div></section>

        <?php
        $renderGroup=function(string $title,string $desc,string $scope,array $fields) use (&$current){ ?>
        <section class="card style-card"><h2><?=e($title)?></h2><p><?=e($desc)?></p><div class="palette-grid">
          <?php foreach($fields as $key=>$label): $value=$current[$scope][$key]; $alpha=in_array(strlen($value),[5,9],true); ?>
          <div class="palette-field <?=$alpha?'alpha':''?>" data-scope="<?=e($scope)?>" data-key="<?=e($key)?>">
            <label><?=e($label)?></label>
            <?php if(!$alpha):?><input class="palette-picker" type="color" value="<?=e(strlen($value)===4 ? '#'.str_repeat($value[1],2).str_repeat($value[2],2).str_repeat($value[3],2) : $value)?>" aria-label="<?=e($label)?> color picker"><?php endif;?>
            <input class="palette-hex" name="<?=e($scope)?>[<?=e($key)?>]" value="<?=e($value)?>" spellcheck="false" autocomplete="off">
          </div>
          <?php endforeach;?>
        </div></section><?php };
        $renderGroup('Dark foundation','Page, panels, borders and text in Night mode.','dark',$darkFields);
        $renderGroup('Brand, match & status','Buttons, focus, alliances and status feedback.','dark',$brandFields);
        $renderGroup('Subsystem accents','Identity colors used by TRIDENT, AUGUR, SATURN, VULCAN, MERCURY and Command Center cards.','dark',$moduleFields);
        $renderGroup('Access badges','Small access-level labels shown on launch cards.','dark',$accessFields);
        $renderGroup('Light foundation','The equivalent palette used when Day mode is active.','light',$lightFields);
        $renderGroup('Advanced utility colors','Shared overlays and neutral colors. Usually these can stay unchanged.','dark',$utilityFields);
        ?>
        <div class="style-save"><a class="btn secondary" href="<?=e(base_url('admin/styling.php'))?>">Reload Current</a><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Global Palette</button></div>
      </div>

      <aside class="style-preview">
        <section class="card"><h2 style="margin-bottom:4px">Live Preview</h2><p class="muted" style="margin-top:0;font-size:.82rem">Updates as you edit. Nothing is saved until you press Save Global Palette.</p></section>
        <div id="previewDark" class="preview-shell"><div class="p-card"><b>Night mode</b><div class="p-muted">Cards, controls and module accents</div><div class="p-row"><span class="p-button">Primary action</span><span class="p-chip">Secondary</span></div><div class="preview-modules"><span data-module-color="module-trident"></span><span data-module-color="module-augur"></span><span data-module-color="module-saturn"></span><span data-module-color="module-vulcan"></span></div></div></div>
        <div id="previewLight" class="preview-shell"><div class="p-card"><b>Day mode</b><div class="p-muted">Same Neptune layout with the light foundation</div><div class="p-row"><span class="p-button">Primary action</span><span class="p-chip">Secondary</span></div><div class="preview-modules"><span data-module-color="module-trident"></span><span data-module-color="module-augur"></span><span data-module-color="module-saturn"></span><span data-module-color="module-vulcan"></span></div></div></div>
      </aside>
    </div>
  </form>
</section>
<script>
(()=>{
 const presets=<?=json_encode($presets,JSON_UNESCAPED_SLASHES)?>;
 const presetLight=<?=json_encode($presetLight,JSON_UNESCAPED_SLASHES)?>;
 const defaults=<?=json_encode($defaults,JSON_UNESCAPED_SLASHES)?>;
 const form=document.getElementById('themeForm');
 const fields=[...form.querySelectorAll('.palette-field')];
 const norm=v=>v.trim();
 function field(scope,key){return form.querySelector(`.palette-field[data-scope="${scope}"][data-key="${key}"] .palette-hex`)}
 function setField(scope,key,value){const t=field(scope,key);if(!t)return;t.value=value;const p=t.parentElement.querySelector('.palette-picker');if(p&&/^#[0-9a-f]{6}$/i.test(value))p.value=value;}
 function read(scope,key,fallback){const t=field(scope,key);return t?norm(t.value):fallback;}
 function paintPreview(el,scope){
   const d='dark';
   el.style.setProperty('--preview-bg',read(scope,'bg',defaults[scope].bg));
   el.style.setProperty('--preview-bg2',read(scope,'bg2',defaults[scope].bg2));
   el.style.setProperty('--preview-panel',read(scope,'panel',defaults[scope].panel));
   el.style.setProperty('--preview-panel2',read(scope,'panel2',defaults[scope].panel2));
   el.style.setProperty('--preview-line',read(scope,'line',defaults[scope].line));
   el.style.setProperty('--preview-text',read(scope,'text',defaults[scope].text));
   el.style.setProperty('--preview-muted',read(scope,'muted',defaults[scope].muted));
   el.style.setProperty('--preview-primary',read(scope,'c1-blue',defaults[scope]['c1-blue']||defaults.dark['c1-blue']));
   el.style.setProperty('--preview-solid',read(scope,'solid-text',defaults[scope]['solid-text']||defaults.dark['solid-text']));
   el.querySelectorAll('[data-module-color]').forEach(x=>x.style.background=read('dark',x.dataset.moduleColor,defaults.dark[x.dataset.moduleColor]));
 }
 function preview(){paintPreview(document.getElementById('previewDark'),'dark');paintPreview(document.getElementById('previewLight'),'light');}
 fields.forEach(box=>{const t=box.querySelector('.palette-hex'),p=box.querySelector('.palette-picker');t.addEventListener('input',()=>{if(p&&/^#[0-9a-f]{6}$/i.test(t.value))p.value=t.value;preview();});if(p)p.addEventListener('input',()=>{t.value=p.value;preview();});});
 document.querySelectorAll('[data-preset]').forEach(btn=>btn.addEventListener('click',()=>{const name=btn.dataset.preset;const p=presets[name]||{};const lp=presetLight[name]||{};Object.entries(p).forEach(([k,v])=>setField('dark',k,v));Object.entries(lp).forEach(([k,v])=>setField('light',k,v));preview();if(window.NeptuneUI)NeptuneUI.toast('Loaded '+name+' for preview. Save to apply it globally.','info');}));
 preview();
})();
</script>
<?php include dirname(__DIR__) . '/partials_footer.php'; ?>
