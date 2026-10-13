<?php
/**
 * Neptune server-side hero compositor.
 *
 * The 2026-09-29 hero refresh originally promoted legacy page headings with
 * JavaScript after DOMContentLoaded. That caused a visible first-paint flash.
 * This compositor applies the same classes/kicker/quick-guide markup to the
 * rendered HTML before it leaves PHP, preserving the existing final appearance
 * while removing the client-side layout swap.
 */

if (!function_exists('neptune_hero_buffer_start')) {
    function neptune_hero_buffer_start(): void
    {
        static $started = false;
        if ($started) return;
        $started = true;
        ob_start('neptune_hero_buffer_callback');
    }

    function neptune_hero_buffer_callback(string $html): string
    {
        try {
            return neptune_hero_transform_document($html);
        } catch (Throwable $e) {
            error_log('[Neptune hero compositor] '.$e->getMessage());
            return $html;
        }
    }

    function neptune_hero_mask_block(string $s): string
    {
        return str_repeat(' ', strlen($s));
    }

    function neptune_hero_classes(string $attrs): array
    {
        if (!preg_match('~\\bclass\\s*=\\s*(["\'])(.*?)\\1~is', $attrs, $m)) return [];
        return array_values(array_filter(preg_split('~\\s+~', trim($m[2])) ?: []));
    }

    function neptune_hero_has_class(array $el, string $class): bool
    {
        return in_array($class, $el['classes'] ?? [], true);
    }

    function neptune_hero_parse(string $html): array
    {
        $scan = preg_replace_callback(
            '~<!--.*?-->|<(script|style)\\b[^>]*>.*?</\\1\\s*>~is',
            static fn(array $m): string => neptune_hero_mask_block($m[0]),
            $html
        ) ?? $html;

        preg_match_all('~<(/?)([A-Za-z][A-Za-z0-9:-]*)([^>]*)>~s', $scan, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $void = array_flip(['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr']);
        $els = [];
        $stack = [];

        foreach ($tags as $t) {
            $full = $t[0][0];
            $start = $t[0][1];
            $closing = $t[1][0] === '/';
            $tag = strtolower($t[2][0]);
            $attrs = $t[3][0];

            if ($closing) {
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    $idx = $stack[$i];
                    if (($els[$idx]['tag'] ?? '') !== $tag) continue;
                    $els[$idx]['close_start'] = $start;
                    $els[$idx]['close_end'] = $start + strlen($full);
                    $stack = array_slice($stack, 0, $i);
                    break;
                }
                continue;
            }

            $idx = count($els);
            $els[$idx] = [
                'tag' => $tag,
                'start' => $start,
                'open_end' => $start + strlen($full),
                'close_start' => null,
                'close_end' => null,
                'attrs' => $attrs,
                'classes' => neptune_hero_classes($attrs),
                'parent' => $stack ? $stack[count($stack) - 1] : null,
            ];

            $selfClosing = str_ends_with(trim($attrs), '/');
            if (!isset($void[$tag]) && !$selfClosing) $stack[] = $idx;
        }
        return $els;
    }

    function neptune_hero_ancestors(array $els, int $idx): array
    {
        $out = [];
        $p = $els[$idx]['parent'] ?? null;
        while ($p !== null && isset($els[$p])) {
            $out[] = $p;
            $p = $els[$p]['parent'] ?? null;
        }
        return $out;
    }

    function neptune_hero_is_descendant(array $els, int $idx, int $ancestor): bool
    {
        $p = $idx;
        while ($p !== null && isset($els[$p])) {
            if ($p === $ancestor) return true;
            $p = $els[$p]['parent'] ?? null;
        }
        return false;
    }

    function neptune_hero_text(string $html, array $el): string
    {
        $a = (int)($el['open_end'] ?? 0);
        $b = (int)($el['close_start'] ?? $a);
        if ($b <= $a) return '';
        $s = substr($html, $a, $b - $a);
        $s = preg_replace('~<[^>]+>~', ' ', $s) ?? $s;
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('~\\s+~u', ' ', $s) ?? $s);
    }

    function neptune_hero_module_meta(string $module): array
    {
        return match (strtoupper($module)) {
            'TRIDENT' => ['fa-crosshairs', 'SCOUTING OPERATIONS'],
            'AUGUR'   => ['fa-chart-line', 'INTELLIGENCE & STRATEGY'],
            'SATURN'  => ['fa-satellite-dish', 'EVENT OPERATIONS'],
            'VULCAN'  => ['fa-hammer', 'BUILDERS & CONFIGURATION'],
            'MERCURY' => ['fa-gear', 'SYSTEM OPERATIONS'],
            'SALT'    => ['fa-database', 'ANALYTICS & DATA'],
            default   => ['fa-compass', 'PLATFORM'],
        };
    }

    function neptune_hero_category(string $title, string $module): string
    {
        $rules = [
            ['~match scouting~i','FIELD OPERATIONS'], ['~tag scouting~i','OBSERVATION SCOUTING'],
            ['~pit scouting~i','PIT SCOUTING'], ['~pre-scout~i','PRE-EVENT INTELLIGENCE'],
            ['~robot cards|robot lookup|robot intelligence~i','ROBOT INTELLIGENCE'],
            ['~match board~i','MATCH INTELLIGENCE'], ['~pit operations~i','PIT OPERATIONS'],
            ['~match strategy~i','MATCH STRATEGY'], ['~alliance selection~i','ALLIANCE STRATEGY'],
            ['~database lab|raw scouting data~i','DATA LAB'], ['~game builder~i','GAME DESIGN'],
            ['~pit form builder~i','PIT FORM DESIGN'], ['~pre-scout form builder~i','PRE-SCOUT DESIGN'],
            ['~event setup~i','EVENT ADMINISTRATION'], ['~match control~i','MATCH CONTROL'],
            ['~manual match schedule~i','SCHEDULE OPERATIONS'], ['~blue alliance sync~i','TBA INTEGRATION'],
            ['~live monitor~i','LIVE OPERATIONS'], ['~teams & users~i','ORGANIZATION ADMIN'],
            ['~data sharing~i','DATA SHARING'], ['~interface styling~i','INTERFACE STYLING'],
            ['~maintenance console~i','SYSTEM MAINTENANCE'], ['~system check~i','SYSTEM HEALTH'],
            ['~file manager~i','FILE OPERATIONS'], ['~training~i','TRAINING & CERTIFICATION'],
            ['~epa archive|epa phase mapping|epa ratings~i','EPA MODEL'], ['~d-epa~i','DEFENSE ANALYTICS'],
            ['~augur data maintenance~i','MODEL MAINTENANCE'], ['~command center~i','COMMAND CENTER'],
            ['~analytics & strategy~i','ANALYTICS & STRATEGY'], ['~^scouting$~i','SCOUTING HUB'],
        ];
        foreach ($rules as [$re, $label]) if (preg_match($re, $title)) return $label;
        [, $fallback] = neptune_hero_module_meta($module);
        return $fallback;
    }

    function neptune_hero_guide(string $title): array
    {
        $rules = [
            ['~match scouting~i', [['fa-location-crosshairs','Find your station'],['fa-robot','Confirm the robot'],['fa-hand-pointer','Start scouting']]],
            ['~tag scouting tags~i', [['fa-tags','Review tags'],['fa-plus','Add organization tags'],['fa-eye','Control visibility']]],
            ['~tag scouting~i', [['fa-robot','Choose a robot'],['fa-tags','Record what you see'],['fa-floppy-disk','Save observation']]],
            ['~pit scouting~i', [['fa-list','Choose a team'],['fa-clipboard-check','Complete pit form'],['fa-camera','Add robot photos']]],
            ['~pre-scout~i', [['fa-magnifying-glass','Choose a team'],['fa-address-card','Collect pre-event info'],['fa-check','Mark complete']]],
            ['~robot cards~i', [['fa-calendar-days','Pick an event'],['fa-table-cells-large','Compare robots'],['fa-robot','Open full details']]],
            ['~robot (lookup|intelligence)~i', [['fa-magnifying-glass','Find a robot'],['fa-chart-line','Review performance'],['fa-tags','Use scout context']]],
            ['~match board~i', [['fa-calendar-days','Pick an event'],['fa-people-group','Review six robots'],['fa-robot','Open intelligence']]],
            ['~pit operations board~i', [['fa-calendar-days','Select event'],['fa-screwdriver-wrench','Track readiness'],['fa-clock','Manage queue timing']]],
            ['~match strategy~i', [['fa-calendar-days','Choose a match'],['fa-chess','Build the plan'],['fa-users','Align the alliance']]],
            ['~alliance selection~i', [['fa-ranking-star','Build rankings'],['fa-people-group','Compare roles'],['fa-list-check','Prepare picks']]],
            ['~database lab~i', [['fa-table-list','Choose tables'],['fa-diagram-project','Build a query'],['fa-play','Run read-only']]],
            ['~raw scouting data~i', [['fa-filter','Filter records'],['fa-table','Review rows'],['fa-file-export','Export data']]],
            ['~game builder~i', [['fa-sliders','Set match rules'],['fa-shapes','Design actions'],['fa-cloud-arrow-up','Publish revision']]],
            ['~pit form builder~i', [['fa-list-check','Add questions'],['fa-grip','Arrange fields'],['fa-floppy-disk','Save configuration']]],
            ['~pre-scout form builder~i', [['fa-list-check','Add questions'],['fa-grip','Arrange fields'],['fa-floppy-disk','Save configuration']]],
            ['~event setup~i', [['fa-calendar-plus','Create event'],['fa-users','Load roster'],['fa-flag-checkered','Prepare schedule']]],
            ['~match control~i', [['fa-list-ol','Select match'],['fa-circle-play','Make ready / run'],['fa-rotate','Re-scout if needed']]],
            ['~manual match schedule~i', [['fa-plus','Add matches'],['fa-people-group','Assign six robots'],['fa-check','Verify schedule']]],
            ['~blue alliance sync~i', [['fa-cloud-arrow-down','Choose event'],['fa-rotate','Sync TBA data'],['fa-circle-check','Verify status']]],
            ['~live monitor~i', [['fa-calendar-days','Choose event'],['fa-eye','Watch scout activity'],['fa-triangle-exclamation','Resolve gaps']]],
            ['~teams & users~i', [['fa-users','Manage users'],['fa-user-shield','Set roles'],['fa-people-group','Assign teams']]],
            ['~data sharing~i', [['fa-handshake','Choose partner'],['fa-sliders','Set shared data'],['fa-shield-halved','Review access']]],
            ['~interface styling~i', [['fa-palette','Choose palette'],['fa-eye','Preview changes'],['fa-floppy-disk','Apply globally']]],
            ['~maintenance console~i', [['fa-file-zipper','Upload patch'],['fa-magnifying-glass','Inspect contents'],['fa-screwdriver-wrench','Install safely']]],
            ['~system check~i', [['fa-stethoscope','Run checks'],['fa-circle-exclamation','Review issues'],['fa-wrench','Fix configuration']]],
            ['~file manager~i', [['fa-folder-open','Browse files'],['fa-upload','Upload / organize'],['fa-shield-halved','Stay org scoped']]],
            ['~training records~i', [['fa-users','Review scouters'],['fa-graduation-cap','Check CBT results'],['fa-file-csv','Export records']]],
            ['~training center|operations, scouting.*training manual|walkthrough~i', [['fa-book-open','Study the guide'],['fa-graduation-cap','Complete CBT'],['fa-circle-check','Verify readiness']]],
            ['~epa ratings|d-epa ratings~i', [['fa-calendar-days','Choose scope'],['fa-chart-column','Compare ratings'],['fa-robot','Open robot details']]],
            ['~epa archive~i', [['fa-cloud-arrow-down','Discover events'],['fa-database','Build archive'],['fa-calculator','Recalculate ratings']]],
            ['~epa phase mapping~i', [['fa-calendar','Choose season'],['fa-diagram-project','Map phases'],['fa-floppy-disk','Save mapping']]],
            ['~augur data maintenance~i', [['fa-database','Review model data'],['fa-rotate','Run maintenance'],['fa-circle-check','Verify output']]],
            ['~command center~i', [['fa-flag-checkered','Run event operations'],['fa-hammer','Configure Neptune'],['fa-gear','Maintain system']]],
            ['~analytics & strategy~i', [['fa-database','Open data tools'],['fa-robot','Study robots'],['fa-chess','Build strategy']]],
            ['~^scouting$~i', [['fa-crosshairs','Scout matches'],['fa-clipboard-list','Scout pits'],['fa-tags','Capture observations']]],
        ];
        foreach ($rules as [$re, $steps]) if (preg_match($re, $title)) return $steps;
        return [['fa-circle-1','Choose context'],['fa-eye','Review information'],['fa-circle-check','Take action']];
    }

    function neptune_hero_open_tag(string $html, array $el, array $addClasses = [], array $attrs = []): string
    {
        $tag = substr($html, $el['start'], $el['open_end'] - $el['start']);
        if ($addClasses) {
            if (preg_match('~\\bclass\\s*=\\s*(["\'])(.*?)\\1~is', $tag, $m, PREG_OFFSET_CAPTURE)) {
                $existing = preg_split('~\\s+~', trim($m[2][0])) ?: [];
                foreach ($addClasses as $c) if (!in_array($c, $existing, true)) $existing[] = $c;
                $new = implode(' ', array_filter($existing));
                $off = $m[2][1];
                $tag = substr_replace($tag, $new, $off, strlen($m[2][0]));
            } else {
                $tag = preg_replace('~>\\s*$~', ' class="'.implode(' ', $addClasses).'">', $tag, 1) ?? $tag;
            }
        }
        foreach ($attrs as $name => $value) {
            if (preg_match('~\\b'.preg_quote($name,'~').'\\s*=~i', $tag)) continue;
            $safe = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $tag = preg_replace('~>\\s*$~', ' '.$name.'="'.$safe.'">', $tag, 1) ?? $tag;
        }
        return $tag;
    }

    function neptune_hero_transform_document(string $html): string
    {
        if ($html === '' || !str_contains($html, 'topbar-identity') || !str_contains($html, '<main')) return $html;
        // Look for actual markup. Inline scripts in the shared header contain
        // strings like 'home-hero-logo' that must not disable every page hero.
        if (preg_match('~<(?:section|div|article|header)\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\b(?:home-hero|auth-card|nd-mission-header)\b[^"\']*\1~i', $html)) return $html;

        // Heroes live at the top of Neptune pages. Parse only the initial document
        // window so large analytics/data tables do not pay a full-page DOM scan cost.
        $scanHtml = strlen($html) > 131072 ? substr($html, 0, 131072) : $html;
        $els = neptune_hero_parse($scanHtml);
        if (!$els) return $html;
        $children = [];
        foreach ($els as $i=>$el) {
            $p = $el['parent'] ?? null;
            if ($p !== null) $children[$p][] = $i;
        }

        $main = null;
        foreach ($els as $i => $el) {
            if ($el['tag'] === 'main' && in_array('wrap', $el['classes'], true)) { $main = $i; break; }
        }
        if ($main === null) return $html;

        $h1 = null;
        $excluded = ['modal','neptune-modal','robot-modal','auth-card','neptune-error-shell'];
        foreach ($els as $i => $el) {
            if ($el['tag'] !== 'h1' || !neptune_hero_is_descendant($els, $i, $main)) continue;
            $bad = false;
            foreach (array_merge([$i], neptune_hero_ancestors($els, $i)) as $a) {
                if (($els[$a]['tag'] ?? '') === 'dialog') { $bad = true; break; }
                foreach ($excluded as $c) if (in_array($c, $els[$a]['classes'] ?? [], true)) { $bad = true; break 2; }
                if (preg_match('~(?:^|\\s)hidden(?:\\s|=|$)~i', $els[$a]['attrs'] ?? '')) { $bad = true; break; }
            }
            if (!$bad) { $h1 = $i; break; }
        }
        if ($h1 === null) return $html;

        $title = neptune_hero_text($html, $els[$h1]);
        if ($title === '') return $html;

        $bodyModule = 'NEPTUNE';
        foreach ($els as $el) {
            if ($el['tag'] !== 'body') continue;
            if (preg_match('~\\bdata-module\\s*=\\s*(["\'])(.*?)\\1~is', $el['attrs'], $m) && trim($m[2]) !== '') $bodyModule = strtoupper(trim($m[2]));
            break;
        }

        $selectorClasses = [
            'match-scout-hero','vgb-hero','impact-hero','tag-context-hero','module-page-header','builder-page-header','dg-hero','style-head','page-head',
            'spot-page-head','asb-head','alliance-page-head','ar-head','dr-head','training-hero','cbt-hero','ng-hero','robot-page-titlebar','mb-titlebar','pit-board-titlebar'
        ];

        $hero = null;
        foreach (neptune_hero_ancestors($els, $h1) as $a) {
            if (array_intersect($selectorClasses, $els[$a]['classes'])) { $hero = $a; break; }
        }

        if ($hero === null) {
            foreach (neptune_hero_ancestors($els, $h1) as $a) {
                if (!in_array('toolbar', $els[$a]['classes'], true)) continue;
                $card = null;
                foreach (neptune_hero_ancestors($els, $a) as $up) if (in_array('card', $els[$up]['classes'], true)) { $card = $up; break; }
                if ($card === null || ($els[$card]['parent'] ?? null) === $main) { $hero = $a; break; }
            }
        }

        $generated = false;
        $wrapStart = $wrapEnd = null;
        if ($hero === null) {
            $parent = $els[$h1]['parent'] ?? null;
            $loose = $parent === $main || ($parent !== null && (in_array('module-page',$els[$parent]['classes'],true) || in_array('alliance-page',$els[$parent]['classes'],true)));
            if (!$loose) return $html;

            $siblings = $children[$parent] ?? [];
            $siblings = array_values(array_filter($siblings, static fn($i) => $els[$i]['start'] >= ($els[$parent]['open_end'] ?? 0)));
            usort($siblings, static fn($a,$b) => $els[$a]['start'] <=> $els[$b]['start']);
            $pos = array_search($h1, $siblings, true);
            if ($pos === false) return $html;
            $firstPos = $pos;
            if ($pos > 0) {
                $prev = $siblings[$pos-1];
                if (array_intersect(['module-eyebrow','module-code'], $els[$prev]['classes'])) $firstPos = $pos-1;
            }
            $lastPos = $pos;
            $moved = 1;
            for ($j=$pos+1; $j<count($siblings) && $moved<4; $j++) {
                $s = $siblings[$j];
                $isAllowed = $els[$s]['tag'] === 'p' || in_array('muted',$els[$s]['classes'],true) || in_array('page-subtitle',$els[$s]['classes'],true);
                if (!$isAllowed) break;
                $lastPos = $j; $moved++;
            }
            $wrapStart = $els[$siblings[$firstPos]]['start'];
            $endEl = $els[$siblings[$lastPos]];
            $wrapEnd = $endEl['close_end'] ?? $endEl['open_end'];
            $generated = true;
        }

        $heroForDesc = $hero;
        $descendants = [];
        if ($heroForDesc !== null) foreach ($els as $i=>$el) if ($i !== $heroForDesc && neptune_hero_is_descendant($els,$i,$heroForDesc)) $descendants[]=$i;

        $tagMods = [];
        if ($hero !== null) {
            $tagMods[$hero] = ['classes'=>['neptune-page-hero'], 'attrs'=>['data-neptune-hero'=>'server']];
        }

        // Existing-category behavior exactly mirrors the old JS compositor.
        $existingCategory = '';
        if ($hero !== null) {
            foreach ($descendants as $d) {
                if (in_array('module-code',$els[$d]['classes'],true)) {
                    $txt = neptune_hero_text($html,$els[$d]);
                    if (str_contains($txt,'·')) { $existingCategory = trim(implode('·',array_slice(explode('·',$txt),1))); break; }
                }
            }
            if ($existingCategory === '') {
                foreach ($descendants as $d) {
                    if ($els[$d]['tag'] !== 'small') continue;
                    $p = $els[$d]['parent'] ?? null;
                    if ($p !== null && in_array('module-eyebrow',$els[$p]['classes'],true)) { $existingCategory = neptune_hero_text($html,$els[$d]); break; }
                }
            }
            if ($existingCategory === '') {
                foreach ($descendants as $d) {
                    if (!array_intersect(['match-scout-kicker','vgb-kicker','dg-kicker'],$els[$d]['classes'])) continue;
                    $txt = neptune_hero_text($html,$els[$d]);
                    if (str_contains($txt,'·')) { $existingCategory = trim(implode('·',array_slice(explode('·',$txt),1))); break; }
                }
            }
        }

        $recognizedKicker = null;
        if ($hero !== null) {
            foreach ($descendants as $d) {
                if (array_intersect(['match-scout-kicker','vgb-kicker','dg-kicker','neptune-hero-kicker'],$els[$d]['classes'])) { $recognizedKicker=$d; break; }
            }
        }

        $insertions = [];
        if ($recognizedKicker !== null) {
            $tagMods[$recognizedKicker]['classes'][] = 'neptune-hero-kicker';
        } else {
            [$icon] = neptune_hero_module_meta($bodyModule);
            $category = strtoupper($existingCategory !== '' ? $existingCategory : neptune_hero_category($title,$bodyModule));
            $kicker = '<div class="neptune-hero-kicker"><i class="fa-solid '.htmlspecialchars($icon,ENT_QUOTES,'UTF-8').'" aria-hidden="true"></i><span>'.htmlspecialchars($bodyModule.' · '.$category,ENT_QUOTES,'UTF-8').'</span></div>';
            $insertions[] = [$els[$h1]['start'], $kicker];
        }

        $existingPillGroup = null;
        if ($hero !== null) {
            foreach ($descendants as $d) {
                if (array_intersect(['match-scout-steps','mb-key','vgb-status-row','neptune-hero-pills'],$els[$d]['classes'])) { $existingPillGroup=$d; break; }
            }
            if ($existingPillGroup === null) {
                foreach ($descendants as $d) {
                    if ($els[$d]['tag'] !== 'div') continue;
                    $kids=$children[$d] ?? [];
                    if (count($kids)<2 || count($kids)>7) continue;
                    $all=true; foreach($kids as $kid) if(!in_array('pill',$els[$kid]['classes'],true)){ $all=false; break; }
                    if($all){$existingPillGroup=$d;break;}
                }
            }
        }

        if ($existingPillGroup !== null) {
            $tagMods[$existingPillGroup]['classes'][]='neptune-hero-pills';
            foreach ($children[$existingPillGroup] ?? [] as $i) $tagMods[$i]['classes'][]='neptune-hero-pill';
        } else {
            $steps = neptune_hero_guide($title);
            $pillHtml = '<div class="neptune-hero-pills" aria-label="Page quick guide">';
            foreach ($steps as $i=>$step) {
                $pillHtml .= '<span class="neptune-hero-pill"><i class="fa-solid '.htmlspecialchars($step[0],ENT_QUOTES,'UTF-8').'" aria-hidden="true"></i><strong>'.($i+1).'</strong> '.htmlspecialchars($step[1],ENT_QUOTES,'UTF-8').'</span>';
            }
            $pillHtml .= '</div>';
            if ($generated && $wrapEnd !== null) {
                // The old client compositor moved the heading into the generated hero
                // before appending pills, so keep those pills inside the wrapper.
                $insertions[] = [$wrapEnd, $pillHtml];
            } else {
                $parent = $els[$h1]['parent'] ?? null;
                if ($parent !== null && ($els[$parent]['close_start'] ?? null) !== null) $insertions[] = [$els[$parent]['close_start'], $pillHtml];
            }
        }

        $mods = [];
        foreach ($tagMods as $idx=>$spec) {
            if (!isset($els[$idx])) continue;
            $classes = array_values(array_unique(array_filter($spec['classes'] ?? [])));
            $attrs = $spec['attrs'] ?? [];
            $mods[] = [$els[$idx]['start'], $els[$idx]['open_end'], neptune_hero_open_tag($html,$els[$idx],$classes,$attrs), 2];
        }
        foreach ($insertions as [$at,$text]) $mods[] = [$at,$at,$text, 1];
        if ($generated && $wrapStart !== null && $wrapEnd !== null) {
            $mods[] = [$wrapStart,$wrapStart,'<section class="neptune-page-hero neptune-page-hero-generated" data-neptune-hero="server">', 1];
            $mods[] = [$wrapEnd,$wrapEnd,'</section>', 0];
        }

        usort($mods, static function($a,$b){
            if($a[0]!==$b[0]) return $b[0] <=> $a[0];
            $alen=$a[1]-$a[0]; $blen=$b[1]-$b[0];
            if($alen!==$blen) return $blen <=> $alen;
            return ($a[3]??0) <=> ($b[3]??0);
        });
        foreach ($mods as $mod) { [$a,$b,$text]=$mod; $html = substr_replace($html,$text,$a,$b-$a); }

        /*
         * Mission Control visual treatment for authenticated deeper-page heroes.
         * Scoped to server-composited heroes only; never touches forms, tables,
         * modals, the dashboard, or the three main module landing pages.
         * One neutral-glass bubble field lives behind the full page.
         */
        $depthStyle = <<<'NEPTUNE_DEPTH_CSS'
<style id="neptune-mission-depth-heroes">
.neptune-page-hero[data-neptune-hero="server"]{
  --nd-depth-color:var(--module-accent-readable,var(--module-accent,var(--accent)));
  isolation:isolate;
  background:radial-gradient(ellipse at 84% 12%,color-mix(in srgb,var(--nd-depth-color) 10%,transparent),transparent 55%),linear-gradient(125deg,color-mix(in srgb,var(--panel) 19%,transparent),color-mix(in srgb,var(--panel) 12%,transparent))!important;
  border-color:color-mix(in srgb,var(--nd-depth-color) 34%,var(--line))!important;
  box-shadow:0 14px 35px color-mix(in srgb,var(--shadow) 35%,transparent)!important;
}
/* Full-viewport animated ocean, not a child of the hero. */
body>.nd-depth-field{
  position:fixed;inset:0;z-index:0;overflow:hidden;
  pointer-events:none;user-select:none;contain:paint;
}
body:has(>.nd-depth-field) main.wrap{position:relative;z-index:1}
body>.nd-depth-field>.nd-depth-rays{
  position:absolute;inset:-35%;opacity:.4;filter:blur(15px);
  background:repeating-conic-gradient(from 194deg at 50% -10%,transparent 0deg 10deg,rgba(192,224,241,.07) 11deg 13deg,transparent 14deg 28deg);
  animation:nd-depth-current 23s ease-in-out infinite alternate;
}
body>.nd-depth-field>.nd-depth-bubble{
  position:absolute;left:var(--nd-x);bottom:-26px;width:var(--nd-size);height:var(--nd-size);
  border:1px solid rgba(194,225,241,.58);
  border-radius:50%;opacity:0;
  background:radial-gradient(circle at 28% 21%,rgba(248,253,255,.32),transparent 55%);
  box-shadow:inset -1px -2px 4px rgba(104,158,187,.2),0 0 9px rgba(192,228,248,.14);
  animation:nd-depth-rise var(--nd-time) linear var(--nd-delay) infinite;
  will-change:transform,opacity;
}
html[data-theme="light"] body>.nd-depth-field>.nd-depth-bubble{border-color:rgba(58,104,132,.38);background:radial-gradient(circle at 28% 21%,rgba(255,255,255,.42),transparent 55%);box-shadow:inset -1px -2px 4px rgba(58,103,133,.15),0 0 8px rgba(76,120,150,.12)}
@keyframes nd-depth-rise{
  0%{opacity:0;transform:translate3d(0,0,0) scale(.65)}
  12%,82%{opacity:var(--nd-alpha)}
  100%{opacity:0;transform:translate3d(var(--nd-drift),-125vh,0) scale(1.08)}
}
@keyframes nd-depth-current{from{transform:translateX(-2%)}to{transform:translateX(2%)}}
/* Keep status chips readable against the much lighter hero background. */
.neptune-page-hero[data-neptune-hero="server"] .neptune-hero-pill{
  background:color-mix(in srgb,var(--panel) 82%,transparent)!important;
  border-color:color-mix(in srgb,var(--nd-depth-color) 28%,var(--line))!important;
}
@media(max-width:640px){body>.nd-depth-field>.nd-depth-rays{opacity:.2}}
@media(prefers-reduced-motion:reduce){
  body>.nd-depth-field>.nd-depth-bubble{display:none!important;animation:none!important}
  body>.nd-depth-field>.nd-depth-rays{animation:none!important}
}
</style>
NEPTUNE_DEPTH_CSS;
        $depthScript = <<<'NEPTUNE_DEPTH_JS'
<script id="neptune-mission-depth-script">
(()=>{
  'use strict';
  function init(){
    if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
    // Only add the field on pages with a server-generated deep-page hero.
    // The dashboard and primary section hubs manage their own ocean layer.
    if(!document.querySelector('main.wrap .neptune-page-hero[data-neptune-hero="server"]'))return;
    if(document.querySelector('body > .nd-depth-field')||document.querySelector('.nd-ocean'))return;
    const layer=document.createElement('div');layer.className='nd-depth-field';layer.setAttribute('aria-hidden','true');
    const rays=document.createElement('div');rays.className='nd-depth-rays';layer.appendChild(rays);
    const count=window.matchMedia&&window.matchMedia('(max-width:640px)').matches?15:32;
    for(let i=0;i<count;i++){
      const bubble=document.createElement('span');bubble.className='nd-depth-bubble';
      bubble.style.setProperty('--nd-x',(Math.random()*100).toFixed(1)+'%');
      bubble.style.setProperty('--nd-size',(4+Math.random()*13).toFixed(1)+'px');
      bubble.style.setProperty('--nd-time',(12+Math.random()*19).toFixed(1)+'s');
      bubble.style.setProperty('--nd-delay',(-Math.random()*31).toFixed(1)+'s');
      bubble.style.setProperty('--nd-drift',((Math.random()-.5)*125).toFixed(1)+'px');
      bubble.style.setProperty('--nd-alpha',(.32+Math.random()*.29).toFixed(2));
      layer.appendChild(bubble);
    }
    document.body.prepend(layer);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
</script>
NEPTUNE_DEPTH_JS;
        $html = str_replace('</head>', $depthStyle."\n".'</head>', $html);
        $html = str_replace('</body>', $depthScript."\n".'</body>', $html);
        return $html;
    }
}
