<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
$u=require_role(['owner','admin']);
if((int)$u['organization_id']!==(int)($config['app']['platform_organization_id']??1))access_denied('Auto Scout is for admins of the owner organization.');

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
$message='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $action=(string)($_POST['action']??'');
        if($action==='install') {
            community_analysis_install($pdo);
            $message='Pilot database ready. The migration is recorded in Maintenance Console. Configure access below.';
        } elseif($action==='save') {
            if(!neptune_migration_is_applied($pdo,'community.analysis.pilot.v1'))throw new RuntimeException('Initialize the database first.');
            $settings=community_analysis_settings($pdo,$config);
            $contributors=$settings['contributor_user_ids']??[];
            $reviewers=$settings['reviewer_user_ids']??[];
            $lookup=$pdo->prepare('SELECT id FROM users WHERE id=? AND active=1');
            foreach(array_unique(array_merge($contributors,$reviewers)) as $id){$lookup->execute([$id]);if(!$lookup->fetchColumn())throw new InvalidArgumentException('Every ID must belong to an active Neptune account.');}
            $settings['enabled']=isset($_POST['enabled']);
            $settings['contributor_user_ids']=$contributors;
            $settings['reviewer_user_ids']=$reviewers;
            $q=$pdo->prepare('UPDATE community_settings SET settings_json=? WHERE id=1');
            $q->execute([json_encode($settings,JSON_THROW_ON_ERROR)]);
            $message='Community scouting setting saved.';
        } else throw new InvalidArgumentException('Unknown action.');
    } catch(InvalidArgumentException $e){http_response_code(422);$message=$e->getMessage();}
    catch(Throwable $e){http_response_code(503);error_log('Community pilot setup: '.$e->getMessage());$message='Setup could not finish. Check the server error log, then retry. No migration is recorded until all schema steps succeed.';}
}
$ready=neptune_migration_is_applied($pdo,'community.analysis.pilot.v1');
$settings=$ready?community_analysis_settings($pdo,$config):[];
require_once dirname(__DIR__).'/community/layout.php';community_page_start('Community Scouting Settings','Turn public robot tagging and scoring estimates on or off.','admin','PLATFORM OWNER · COMMUNITY SCOUTING');
?>
<?php if($message):?><section role="status"><?=e($message)?></section><?php endif ?>
<section><h2>Public tag scouting</h2><p>Anyone can watch a linked match, tag robot actions and add scoring estimates. Tags save immediately; no account or review queue is required.</p>
<?php if($ready):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><label><input type="checkbox" name="enabled" <?=!empty($settings['enabled'])?'checked':''?>> Enable community tag scouting</label><button>Save setting</button></form><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button name="action" value="install">Prepare community database</button></form><?php endif ?></section>
<section><h2>Open scouting or training</h2><p><a class="community-button" href="../community/?view=catalog">Watch &amp; tag</a><a class="community-button" href="../community/?view=results">Saved tags &amp; scores</a><a class="community-button" href="community-learning.php">Train &amp; test</a></p></section><?php community_page_end(); ?>
