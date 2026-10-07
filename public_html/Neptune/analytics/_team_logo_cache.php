<?php

/**
 * Persistent local team-logo cache for Robot Intelligence.
 *
 * Logos are organization + team scoped and intentionally persist across FRC
 * seasons. The $season argument is retained by the public helper signatures so
 * existing Robot Cards / Team Logo Cache callers remain compatible; it is used
 * only when asking TBA which seasons to inspect and for metadata/audit context.
 *
 * Older Neptune builds stored logos under org-{id}/{season}/. When a persistent
 * logo is missing, this helper automatically promotes the newest matching
 * legacy season cache into the organization-level cache.
 */

function neptune_team_logo_public_root(): string {
    return dirname(__DIR__);
}

function neptune_team_logo_rel_dir(int $org,int $season=0): string {
    return 'uploads/team-logos/org-'.$org;
}

function neptune_team_logo_abs_dir(int $org,int $season=0): string {
    return neptune_team_logo_public_root().'/'.neptune_team_logo_rel_dir($org,$season);
}

function neptune_team_logo_meta_path(int $org,int $season,int $team): string {
    return neptune_team_logo_abs_dir($org,$season).'/frc'.$team.'.meta.json';
}

function neptune_team_logo_prefix(int $team): string {
    return 'frc'.$team;
}

function neptune_team_logo_ensure_dir(int $org,int $season=0): string {
    $dir=neptune_team_logo_abs_dir($org,$season);
    if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir)){
        throw new RuntimeException('Could not create the team-logo cache directory.');
    }
    return $dir;
}

function neptune_team_logo_read_meta(int $org,int $season,int $team): array {
    $path=neptune_team_logo_meta_path($org,$season,$team);
    if(!is_file($path))return [];
    $raw=@file_get_contents($path);
    if($raw===false)return [];
    $meta=json_decode($raw,true);
    return is_array($meta)?$meta:[];
}

function neptune_team_logo_write_meta(int $org,int $season,int $team,array $meta): void {
    $dir=neptune_team_logo_ensure_dir($org,$season);
    $meta['organization_id']=$org;
    $meta['frc_team_number']=$team;
    $meta['cache_scope']='organization_team';
    if($season>0)$meta['last_used_season']=$season;
    unset($meta['season_year']);
    $meta['updated_at']=$meta['updated_at']??gmdate('c');
    $json=json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($json===false||file_put_contents($dir.'/frc'.$team.'.meta.json',$json,LOCK_EX)===false){
        throw new RuntimeException('Could not save team-logo cache metadata.');
    }
}

/** Return numeric legacy season directories, newest first. */
function neptune_team_logo_legacy_dirs(int $org): array {
    $orgDir=neptune_team_logo_abs_dir($org,0);
    if(!is_dir($orgDir))return [];
    $dirs=[];
    foreach(scandir($orgDir)?:[] as $name){
        if(!preg_match('/^\d{4}$/',$name))continue;
        $path=$orgDir.'/'.$name;
        if(is_dir($path))$dirs[(int)$name]=$path;
    }
    krsort($dirs,SORT_NUMERIC);
    return $dirs;
}

/**
 * Promote a logo from the old org/season layout into the persistent org/team
 * layout. This is deliberately best-effort so an old read-only cache never
 * prevents Robot Cards from loading.
 */
function neptune_team_logo_promote_legacy(int $org,int $season,int $team): void {
    $dir=neptune_team_logo_abs_dir($org,$season);
    if(is_file($dir.'/frc'.$team.'.meta.json'))return;
    foreach(['png','avif','webp','jpg','jpeg'] as $ext){
        if(is_file($dir.'/frc'.$team.'.'.$ext))return;
    }

    foreach(neptune_team_logo_legacy_dirs($org) as $legacySeason=>$legacyDir){
        $legacyMeta=[];
        $legacyMetaPath=$legacyDir.'/frc'.$team.'.meta.json';
        if(is_file($legacyMetaPath)){
            $raw=@file_get_contents($legacyMetaPath);
            $decoded=$raw!==false?json_decode($raw,true):null;
            if(is_array($decoded))$legacyMeta=$decoded;
        }

        $legacyFile=trim((string)($legacyMeta['file']??''));
        $sourcePath='';$targetFile='';
        if($legacyFile!==''&&preg_match('/^frc'.preg_quote((string)$team,'/').'\.(png|avif|webp|jpe?g)$/i',$legacyFile)){
            $candidate=$legacyDir.'/'.$legacyFile;
            if(is_file($candidate)){$sourcePath=$candidate;$targetFile=$legacyFile;}
        }
        if($sourcePath===''){
            foreach(['png','avif','webp','jpg','jpeg'] as $ext){
                $candidate=$legacyDir.'/frc'.$team.'.'.$ext;
                if(is_file($candidate)){$sourcePath=$candidate;$targetFile='frc'.$team.'.'.$ext;break;}
            }
        }
        if($sourcePath==='')continue;

        try{
            neptune_team_logo_ensure_dir($org,$season);
            $target=$dir.'/'.$targetFile;
            if(!@copy($sourcePath,$target))continue;
            @chmod($target,0644);
            $legacyMeta['file']=$targetFile;
            $legacyMeta['migrated_from_season']=$legacySeason;
            $legacyMeta['migrated_at']=gmdate('c');
            if(empty($legacyMeta['source_year'])&&($legacyMeta['source']??'')==='tba')$legacyMeta['source_year']=$legacySeason;
            neptune_team_logo_write_meta($org,$season,$team,$legacyMeta);

            // Clean only this team's legacy files after a successful promotion.
            foreach(scandir($legacyDir)?:[] as $name){
                if($name==='frc'.$team.'.meta.json'||preg_match('/^frc'.preg_quote((string)$team,'/').'\.(?:png|avif|webp|jpe?g)$/i',$name)){
                    $p=$legacyDir.'/'.$name;if(is_file($p))@unlink($p);
                }
            }
            return;
        }catch(Throwable $e){
            @unlink($dir.'/'.$targetFile);
            return;
        }
    }
}

function neptune_team_logo_clear_images(int $org,int $season,int $team): void {
    $dir=neptune_team_logo_abs_dir($org,$season);
    if(!is_dir($dir))return;
    $prefix=neptune_team_logo_prefix($team).'.';
    foreach(scandir($dir)?:[] as $name){
        if(!str_starts_with($name,$prefix))continue;
        if(str_ends_with($name,'.meta.json'))continue;
        $path=$dir.'/'.$name;
        if(is_file($path))@unlink($path);
    }
}

function neptune_team_logo_info(int $org,int $season,int $team): array {
    neptune_team_logo_promote_legacy($org,$season,$team);
    $meta=neptune_team_logo_read_meta($org,$season,$team);
    $relDir=neptune_team_logo_rel_dir($org,$season);
    $dir=neptune_team_logo_abs_dir($org,$season);
    $file=trim((string)($meta['file']??''));
    if($file!==''&&preg_match('/^frc'.preg_quote((string)$team,'/').'\.[A-Za-z0-9]+$/',$file)){
        $abs=$dir.'/'.$file;
        if(is_file($abs)){
            return [
                'exists'=>true,
                'path'=>$relDir.'/'.$file,
                'file'=>$file,
                'source'=>(string)($meta['source']??'local'),
                'source_year'=>$meta['source_year']??null,
                'updated_at'=>(string)($meta['updated_at']??''),
                'checked_at'=>(string)($meta['checked_at']??''),
                'cache_scope'=>'persistent',
                'version'=>(string)@filemtime($abs),
            ];
        }
    }

    // Recover gracefully if metadata was lost but the cached file remains.
    if(is_dir($dir)){
        foreach(['png','avif','webp','jpg','jpeg'] as $ext){
            $candidate='frc'.$team.'.'.$ext;
            $abs=$dir.'/'.$candidate;
            if(is_file($abs)){
                return [
                    'exists'=>true,
                    'path'=>$relDir.'/'.$candidate,
                    'file'=>$candidate,
                    'source'=>(string)($meta['source']??'local'),
                    'source_year'=>$meta['source_year']??null,
                    'updated_at'=>(string)($meta['updated_at']??''),
                    'checked_at'=>(string)($meta['checked_at']??''),
                    'cache_scope'=>'persistent',
                    'version'=>(string)@filemtime($abs),
                ];
            }
        }
    }

    // Shared fallback: organization 1 is Neptune's common logo library.
    // A tenant's own cached/custom logo always wins. Only when the tenant has
    // no logo at all do we reuse the matching team logo from org 1.
    if($org!==1){
        $shared=neptune_team_logo_info(1,$season,$team);
        if(!empty($shared['exists'])){
            $shared['cache_scope']='organization_1_fallback';
            $shared['fallback_organization_id']=1;
            return $shared;
        }
    }

    return [
        'exists'=>false,
        'path'=>'',
        'file'=>'',
        'source'=>(string)($meta['source']??''),
        'source_year'=>$meta['source_year']??null,
        'updated_at'=>(string)($meta['updated_at']??''),
        'checked_at'=>(string)($meta['checked_at']??''),
        'cache_scope'=>'persistent',
        'version'=>'',
    ];
}

function neptune_team_logo_map(int $org,int $season,array $teams): array {
    $out=[];
    foreach($teams as $team){
        $n=(int)$team;
        if($n>0)$out[$n]=neptune_team_logo_info($org,$season,$n);
    }
    return $out;
}

function neptune_team_logo_tba_avatar_bytes(array $media): ?string {
    if((string)($media['type']??'')!=='avatar')return null;
    $details=is_array($media['details']??null)?$media['details']:[];
    $b64=preg_replace('/\s+/','',trim((string)($details['base64Image']??'')));
    if($b64===''||strlen($b64)>4_500_000)return null;
    if(!preg_match('/^[A-Za-z0-9+\/=]+$/',$b64))return null;
    $bytes=base64_decode($b64,true);
    if($bytes===false||$bytes==='')return null;
    $image=@getimagesizefromstring($bytes);
    if(!$image||($image['mime']??'')!=='image/png')return null;
    return $bytes;
}

function neptune_team_logo_fetch_tba(int $org,int $season,int $team,bool $force=false,?array $yearsOverride=null): array {
    if(!function_exists('tba_get'))throw new RuntimeException('TBA support is not available.');
    $current=neptune_team_logo_info($org,$season,$team);
    if(!$force&&!empty($current['exists']))return $current;

    // If TBA has already told us there is no avatar, avoid asking again on every
    // page view. A manual refresh uses $force=true and bypasses this cooldown.
    if(!$force){
        $meta=neptune_team_logo_read_meta($org,$season,$team);
        if(($meta['source']??'')==='none'&&!empty($meta['checked_at'])){
            $checked=strtotime((string)$meta['checked_at']);
            if($checked!==false&&$checked>(time()-86400))return $current;
        }
    }

    $years=$yearsOverride===null
        ? [$season,$season-1,$season-2]
        : $yearsOverride;
    $years=array_values(array_unique(array_filter(array_map('intval',$years),static fn($y)=>$y>=1992&&$y<=((int)date('Y')+1))));
    if(!$years)$years=[$season];
    $foundBytes=null;$foundYear=null;
    foreach($years as $year){
        try{$rows=tba_get('team/frc'.$team.'/media/'.$year,86400*7);}catch(Throwable $e){
            // If the first/current-season request cannot reach TBA, surface it
            // so bulk sync can stop instead of hammering an offline connection.
            if($year===$season)throw $e;
            continue;
        }
        if(!is_array($rows))continue;
        foreach($rows as $row){
            if(!is_array($row))continue;
            $bytes=neptune_team_logo_tba_avatar_bytes($row);
            if($bytes!==null){$foundBytes=$bytes;$foundYear=$year;break 2;}
        }
    }

    if($foundBytes===null){
        neptune_team_logo_write_meta($org,$season,$team,[
            'source'=>'none',
            'checked_at'=>gmdate('c'),
            'updated_at'=>gmdate('c'),
            'message'=>'No TBA avatar found for the checked seasons.',
        ]);
        return neptune_team_logo_info($org,$season,$team);
    }

    $dir=neptune_team_logo_ensure_dir($org,$season);
    neptune_team_logo_clear_images($org,$season,$team);
    $file='frc'.$team.'.png';
    $tmp=$dir.'/.'.$file.'.'.bin2hex(random_bytes(4)).'.tmp';
    if(file_put_contents($tmp,$foundBytes,LOCK_EX)===false)throw new RuntimeException('Could not write the TBA team logo.');
    if(!@rename($tmp,$dir.'/'.$file)){@unlink($tmp);throw new RuntimeException('Could not finalize the TBA team logo.');}
    @chmod($dir.'/'.$file,0644);
    neptune_team_logo_write_meta($org,$season,$team,[
        'file'=>$file,
        'source'=>'tba',
        'source_year'=>$foundYear,
        'checked_at'=>gmdate('c'),
        'updated_at'=>gmdate('c'),
    ]);
    return neptune_team_logo_info($org,$season,$team);
}

function neptune_team_logo_store_upload(int $org,int $season,int $team,array $file,array $user): array {
    if(!function_exists('neptune_store_uploaded_image'))throw new RuntimeException('Image upload support is not available.');
    $dir=neptune_team_logo_ensure_dir($org,$season);
    $saved=neptune_store_uploaded_image($file,$dir,'frc'.$team.'-logo',1200,8*1024*1024);
    $sourceName=(string)($saved['filename']??'');
    if($sourceName===''||!is_file($dir.'/'.$sourceName))throw new RuntimeException('Could not save the team logo.');
    $ext=strtolower((string)pathinfo($sourceName,PATHINFO_EXTENSION));
    if(!in_array($ext,['png','avif','webp','jpg','jpeg'],true))$ext='jpg';
    $target='frc'.$team.'.'.$ext;

    foreach(scandir($dir)?:[] as $name){
        if($name===$sourceName||$name==='frc'.$team.'.meta.json')continue;
        if(preg_match('/^frc'.preg_quote((string)$team,'/').'\.(?:png|avif|webp|jpe?g)$/i',$name))@unlink($dir.'/'.$name);
    }
    if($sourceName!==$target){
        @unlink($dir.'/'.$target);
        if(!@rename($dir.'/'.$sourceName,$dir.'/'.$target))throw new RuntimeException('Could not finalize the replacement team logo.');
    }
    @chmod($dir.'/'.$target,0644);
    neptune_team_logo_write_meta($org,$season,$team,[
        'file'=>$target,
        'source'=>'custom',
        'uploaded_by'=>(int)($user['id']??0),
        'original_name'=>(string)($file['name']??''),
        'updated_at'=>gmdate('c'),
    ]);
    return neptune_team_logo_info($org,$season,$team);
}



/** Return every cached team number in the persistent organization logo library. */
function neptune_team_logo_cached_teams(int $org): array {
    $dir=neptune_team_logo_abs_dir($org,0);
    if(!is_dir($dir))return [];
    $teams=[];
    foreach(scandir($dir)?:[] as $name){
        if(preg_match('/^frc(\d+)\.(?:png|avif|webp|jpe?g)$/i',$name,$m)){
            $n=(int)$m[1];if($n>0)$teams[$n]=true;
        }
    }
    $out=array_keys($teams);sort($out,SORT_NUMERIC);return $out;
}

/**
 * Upscale one cached logo to a target width while preserving aspect ratio.
 * Existing files are replaced atomically in the same format. Transparency is
 * preserved for PNG/WebP/AVIF where the installed GD build supports it.
 */
function neptune_team_logo_upscale(int $org,int $season,int $team,int $targetWidth=1000,bool $sharpen=true): array {
    $targetWidth=max(64,min(2400,$targetWidth));
    $info=neptune_team_logo_info($org,$season,$team);
    if(empty($info['exists']))return ['ok'=>true,'status'=>'missing','team'=>$team,'message'=>'Logo is not cached.'];
    $dir=neptune_team_logo_abs_dir($org,$season);
    $file=(string)($info['file']??'');
    $path=$dir.'/'.$file;
    if($file===''||!is_file($path))return ['ok'=>false,'status'=>'error','team'=>$team,'message'=>'Cached logo file was not found.'];
    $size=@getimagesize($path);
    if(!$size||empty($size[0])||empty($size[1]))return ['ok'=>false,'status'=>'error','team'=>$team,'message'=>'Could not read logo dimensions.'];
    $w=(int)$size[0];$h=(int)$size[1];
    if($w>=$targetWidth)return ['ok'=>true,'status'=>'already_large','team'=>$team,'width'=>$w,'height'=>$h,'message'=>'Already '.$w.'px wide.'];
    if(!extension_loaded('gd'))return ['ok'=>false,'status'=>'error','team'=>$team,'message'=>'PHP GD is required for logo upscaling.'];

    $mime=strtolower((string)($size['mime']??''));
    $src=null;$writer=null;$quality=null;
    if($mime==='image/png'&&function_exists('imagecreatefrompng')){$src=@imagecreatefrompng($path);$writer='png';}
    elseif($mime==='image/jpeg'&&function_exists('imagecreatefromjpeg')){$src=@imagecreatefromjpeg($path);$writer='jpeg';}
    elseif($mime==='image/webp'&&function_exists('imagecreatefromwebp')&&function_exists('imagewebp')){$src=@imagecreatefromwebp($path);$writer='webp';}
    elseif($mime==='image/avif'&&function_exists('imagecreatefromavif')&&function_exists('imageavif')){$src=@imagecreatefromavif($path);$writer='avif';}
    if(!$src||!$writer)return ['ok'=>false,'status'=>'unsupported','team'=>$team,'width'=>$w,'height'=>$h,'message'=>'This image format cannot be resized by the installed PHP GD build.'];

    $newW=$targetWidth;$newH=max(1,(int)round($h*($newW/$w)));
    $dst=imagecreatetruecolor($newW,$newH);
    if(!$dst){imagedestroy($src);return ['ok'=>false,'status'=>'error','team'=>$team,'message'=>'Could not allocate resized image.'];}
    if(in_array($writer,['png','webp','avif'],true)){
        imagealphablending($dst,false);imagesavealpha($dst,true);
        $transparent=imagecolorallocatealpha($dst,0,0,0,127);imagefill($dst,0,0,$transparent);
    }
    imagecopyresampled($dst,$src,0,0,0,0,$newW,$newH,$w,$h);
    if($sharpen&&function_exists('imageconvolution')&&$newW>$w){
        @imageconvolution($dst,[[-1,-1,-1],[-1,16,-1],[-1,-1,-1]],8,0);
    }
    $tmp=$path.'.upscale-'.bin2hex(random_bytes(4)).'.tmp';$saved=false;
    if($writer==='png')$saved=@imagepng($dst,$tmp,6);
    elseif($writer==='jpeg')$saved=@imagejpeg($dst,$tmp,92);
    elseif($writer==='webp')$saved=@imagewebp($dst,$tmp,92);
    elseif($writer==='avif')$saved=@imageavif($dst,$tmp,82);
    imagedestroy($src);imagedestroy($dst);
    if(!$saved||!is_file($tmp)){@unlink($tmp);return ['ok'=>false,'status'=>'error','team'=>$team,'message'=>'Could not write the upscaled logo.'];}
    if(!@rename($tmp,$path)){@unlink($tmp);return ['ok'=>false,'status'=>'error','team'=>$team,'message'=>'Could not replace the cached logo.'];}
    @chmod($path,0644);
    $meta=neptune_team_logo_read_meta($org,$season,$team);
    $meta['file']=$file;$meta['upscaled_width']=$newW;$meta['upscaled_height']=$newH;$meta['upscaled_at']=gmdate('c');$meta['updated_at']=gmdate('c');
    neptune_team_logo_write_meta($org,$season,$team,$meta);
    $fresh=neptune_team_logo_info($org,$season,$team);
    return ['ok'=>true,'status'=>'upscaled','team'=>$team,'width'=>$newW,'height'=>$newH,'message'=>'Upscaled from '.$w.'×'.$h.' to '.$newW.'×'.$newH.'.','logo'=>$fresh];
}

function neptune_team_logo_remove(int $org,int $season,int $team): void {
    neptune_team_logo_clear_images($org,$season,$team);
    $meta=neptune_team_logo_meta_path($org,$season,$team);
    if(is_file($meta))@unlink($meta);

    // Also remove this team's old season-scoped cache files so a deliberately
    // removed logo cannot be re-promoted on the next page load.
    foreach(neptune_team_logo_legacy_dirs($org) as $legacyDir){
        foreach(scandir($legacyDir)?:[] as $name){
            if($name==='frc'.$team.'.meta.json'||preg_match('/^frc'.preg_quote((string)$team,'/').'\.(?:png|avif|webp|jpe?g)$/i',$name)){
                $p=$legacyDir.'/'.$name;if(is_file($p))@unlink($p);
            }
        }
    }
}
