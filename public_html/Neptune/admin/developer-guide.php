<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u = require_role(['owner','admin','strategy']);
$platformOrgId = max(1, (int)($config['app']['platform_organization_id'] ?? 1));
$isPlatformOwner = ($u['role'] ?? '') === 'owner' && (int)($u['organization_id'] ?? 0) === $platformOrgId;
$githubUrl = 'https://github.com/blarkin13/neptune-frc-scouting';

$topics = require __DIR__.'/developer-guide-data.php';
$pageTitle = 'Developer Guide';
$moduleName = 'MERCURY';
$pageStyles = ['assets/css/developer-guide.css'];
include dirname(__DIR__).'/partials_header.php';

$requestedTopic = trim((string)($_GET['topic'] ?? ''));
$validIds = array_column($topics, 'id');
if ($requestedTopic !== '' && !in_array($requestedTopic, $validIds, true)) $requestedTopic = '';
?>
<section class="module-page dg" data-initial-topic="<?=e($requestedTopic)?>">
  <header class="dg-hero">
    <div>
      <span class="dg-kicker">Strategy+ code reference</span>
      <h1>Neptune Developer Guide</h1>
      <p>Use this guide when reviewing or changing Neptune, including when preparing code suggestions for GitHub. Every topic points to the real files in this build, gives search strings that work well in GitHub search, File Manager, VS Code or <code>rg</code>/<code>grep</code>, and ends with the places most likely to need regression testing.</p>
    </div>
    <div class="dg-hero-actions">
      <a class="btn secondary" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a>
      <a class="btn secondary" href="<?=e($githubUrl)?>" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> GitHub</a>
      <?php if($isPlatformOwner):?><a class="btn secondary" href="<?=e(base_url('admin/file-manager.php'))?>"><i class="fa-solid fa-folder-tree"></i> File Manager</a><?php endif;?>
    </div>
  </header>

  <div class="dg-toolbar card">
    <div class="dg-search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input id="dgSearch" type="search" placeholder="Search guides, tags, files or code terms…" autocomplete="off" aria-label="Search developer guide">
    </div>
    <div class="dg-toolbar-meta"><span id="dgResultCount"><?=count($topics)?> topics</span><button class="secondary compact" type="button" id="dgClear"><i class="fa-solid fa-xmark"></i> Clear</button></div>
  </div>

  <div class="dg-layout">
    <aside class="dg-sidebar">
      <div class="dg-side-card card">
        <b>Topics</b>
        <nav id="dgNav">
          <?php foreach($topics as $topic):?>
            <a href="#<?=e($topic['id'])?>" data-topic-link="<?=e($topic['id'])?>"><i class="fa-solid <?=e($topic['icon'])?>"></i><span><?=e($topic['title'])?></span></a>
          <?php endforeach;?>
        </nav>
      </div>
      <div class="dg-side-card card">
        <b>Useful shell search</b>
        <pre><code>cd /var/www/neptune
rg -n "SEARCH_TERM" \
  public_html/Neptune \
  neptune_secure scripts</code></pre>
        <small>Use <code>grep -Rni</code> if ripgrep (<code>rg</code>) is not installed.</small>
      </div>
    </aside>

    <main class="dg-main" id="dgMain">
      <?php foreach($topics as $topic):
        $searchBlob = strtolower(implode(' ', array_merge(
            [$topic['id'],$topic['title'],$topic['summary']],
            $topic['tags'],$topic['files'],$topic['search'],
            array_map(static fn($section)=>strip_tags((string)$section['html']).' '.($section['title']??''), $topic['sections'])
        )));
      ?>
      <article class="dg-topic card" id="<?=e($topic['id'])?>" data-topic="<?=e($topic['id'])?>" data-search="<?=e($searchBlob)?>">
        <header class="dg-topic-head">
          <span class="dg-topic-icon"><i class="fa-solid <?=e($topic['icon'])?>"></i></span>
          <div><h2><?=e($topic['title'])?></h2><p><?=e($topic['summary'])?></p></div>
          <a class="dg-permalink" href="?topic=<?=e($topic['id'])?>#<?=e($topic['id'])?>" title="Link directly to this topic" aria-label="Link directly to <?=e($topic['title'])?>"><i class="fa-solid fa-link"></i></a>
        </header>

        <div class="dg-tags" aria-label="Search tags">
          <span class="dg-label">Search tags</span>
          <?php foreach($topic['tags'] as $tag):?><button type="button" class="dg-tag" data-search-tag="<?=e($tag)?>"><?=e($tag)?></button><?php endforeach;?>
        </div>

        <details class="dg-locate" open>
          <summary><i class="fa-solid fa-location-crosshairs"></i> Find it in Neptune</summary>
          <div class="dg-locate-grid">
            <div><h3>Files</h3><ul><?php foreach($topic['files'] as $file):?><li><code><?=e($file)?></code></li><?php endforeach;?></ul></div>
            <div><h3>Search for</h3><div class="dg-code-tags"><?php foreach($topic['search'] as $term):?><button type="button" data-copy="<?=e($term)?>" title="Copy search term"><code><?=e($term)?></code><i class="fa-regular fa-copy"></i></button><?php endforeach;?></div></div>
          </div>
        </details>

        <?php foreach($topic['sections'] as $section):?>
          <section class="dg-section">
            <h3><?=e($section['title'])?></h3>
            <?=$section['html']?>
          </section>
        <?php endforeach;?>
      </article>
      <?php endforeach;?>

      <div class="dg-empty card" id="dgEmpty" hidden>
        <i class="fa-solid fa-magnifying-glass"></i>
        <h2>No developer topics matched</h2>
        <p>Try a filename, subsystem, function name or one of the tags shown in the guide.</p>
      </div>
    </main>
  </div>
</section>
<script src="<?=e(base_url('assets/js/developer-guide.js'))?>?v=<?=e((string)(@filemtime(dirname(__DIR__).'/assets/js/developer-guide.js')?:1))?>"></script>
<?php include dirname(__DIR__).'/partials_footer.php';
