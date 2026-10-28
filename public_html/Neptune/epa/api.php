<?php
require_once __DIR__.'/_common.php';
ns_head('Developer API');
$base='https://neptunefrc.com';
$epa=$base.'/api/augur-ratings.php';
$depa=$base.'/api/augur-depa.php';
$examples=[
    ['label'=>'EPA: list events in a season','url'=>$epa.'?events=1&year=2026','description'=>'Returns public archive events and rating coverage for a season.'],
    ['label'=>'EPA: season team ratings','url'=>$epa.'?year=2026&limit=100','description'=>'Returns an array of season ratings, ranked by public EPA (up to 1,000 per request).'],
    ['label'=>'EPA: one team in a season','url'=>$epa.'?year=2026&team=6369','description'=>'Returns that team’s public season EPA, phase ratings and context, when archived.'],
    ['label'=>'EPA: event ratings','url'=>$epa.'?event=EVENT_KEY','description'=>'Replace EVENT_KEY with the TBA event key from the event directory endpoint.'],
    ['label'=>'EPA: team event history','url'=>$epa.'?history=1&year=2026&team=6369','description'=>'Lists a team’s archived rated events for the selected season.'],
    ['label'=>'D-EPA: season team ratings','url'=>$depa.'?year=2026&team=6369','description'=>'Returns public D-EPA and, when supported by observations, SV-Points Denied.'],
    ['label'=>'D-EPA: event ratings','url'=>$depa.'?event=EVENT_KEY','description'=>'Replace EVENT_KEY with a TBA event key.'],
    ['label'=>'D-EPA: event directory','url'=>$depa.'?events=1&year=2026','description'=>'Lists D-EPA archive event coverage.'],
];
?>
<section class="page-intro"><span class="eyebrow">NEPTUNE · OPEN DATA</span><h1>Developer <em>API.</em></h1><p>Use Neptune's public FIRST Robotics Competition EPA and D-EPA archives in your scouting application, dashboard, or analysis—without scraping a leaderboard.</p></section>
<section class="panel">
  <span class="eyebrow">01 · QUICK START</span><h2>Public, read-only JSON</h2>
  <p>No Neptune login or API key is required by these public endpoints. Use HTTPS <code>GET</code> requests. EPA supports <code>OPTIONS</code> and the API sets a permissive CORS origin for cross-site reads. EPA responses are cached for up to five minutes. Availability depends on which seasons and events have been archived.</p>
  <div class="api-links"><a href="<?=ns_h($epa.'?year=2026&limit=5')?>" target="_blank" rel="noopener">Try EPA JSON ↗</a><a href="<?=ns_h($depa.'?year=2026&team=6369')?>" target="_blank" rel="noopener">Try D-EPA JSON ↗</a><a href="/epa/rankings.php">Browse EPA rankings →</a></div>
  <p class="api-tip">These are Neptune's own response formats, not a drop-in replacement for Statbotics API schemas. A third-party app should parse Neptune's JSON fields directly.</p>
</section>
<section class="panel"><span class="eyebrow">02 · ENDPOINTS</span><h2>EPA and defense data</h2>
  <div class="api-endpoints">
  <?php foreach($examples as $ex):?>
    <div class="api-endpoint"><strong><span class="api-method">GET</span><?=ns_h($ex['label'])?></strong><code><?=ns_h($ex['url'])?></code><p class="api-tip"><?=ns_h($ex['description'])?></p>
    <?php if(strpos($ex['url'],'EVENT_KEY')===false):?><a href="<?=ns_h($ex['url'])?>" target="_blank" rel="noopener">Open JSON ↗</a><?php endif;?></div>
  <?php endforeach;?>
  </div>
  <p class="api-tip"><b>EPA filters:</b> <code>year</code>, <code>team</code>, <code>event</code>, <code>events=1</code>, <code>history=1</code>, <code>limit</code> (1–1000). For the event key, use a key returned by <code>?events=1&amp;year=...</code>. Team IDs are FRC team numbers; season years are four digits.</p>
  <p class="api-tip"><b>D-EPA filters:</b> <code>year</code>, <code>team</code>, <code>event</code>, <code>events=1</code>. The D-EPA API returns a different JSON envelope than the EPA API.</p>
</section>
<section class="panel"><span class="eyebrow">03 · INTEGRATION</span><h2>Use it from JavaScript or Python</h2>
  <h3>JavaScript fetch</h3><pre class="api-code"><code>const url = 'https://neptunefrc.com/api/augur-ratings.php?year=2026&amp;team=6369';
const response = await fetch(url);
if (!response.ok) throw new Error('Neptune API: ' + response.status);
const data = await response.json();
if (data.ok) {
  for (const team of data.ratings ?? []) {
    console.log(team.team, team.epa.total, team.epa.auto);
  }
}</code></pre>
  <h3>Python requests</h3><pre class="api-code"><code>import requests
url = 'https://neptunefrc.com/api/augur-ratings.php'
r = requests.get(url, params={'year': 2026, 'team': 6369}, timeout=15)
r.raise_for_status()
for row in r.json().get('ratings', []):
    print(row['team'], row['epa']['total'])</code></pre>
  <p class="api-tip">The EPA <code>ratings</code> array exposes <code>team</code>, <code>epa.total</code>, <code>epa.auto</code>, <code>epa.teleop</code>, <code>epa.endgame</code>, <code>trend</code>, <code>sigma</code>, <code>confidence</code>, <code>matches</code>, and the win-loss-tie <code>record</code>. Some phase ratings may be <code>null</code> if the archive does not support them.</p>
</section>
<section class="panel"><span class="eyebrow">04 · DATA & PRIVACY</span><h2>Which EPA is available publicly?</h2>
  <p><b>Public EPA:</b> match-derived ratings from Neptune's TBA-backed public archive. This is the statistical baseline, not a robot's private scouting-adjusted SA-EPA.</p>
  <p><b>Public D-EPA:</b> match-derived opponent suppression estimate. <b>SV-Points Denied:</b> verified defensive match estimates where sufficient evidence exists. See the defense API's <code>meta</code> explanation for its public network scope and definitions.</p>
  <p><b>Private scouting:</b> organization-specific match observations, scout identities, notes, and internal AUGUR scouting-adjusted prediction inputs are not exposed through these public routes. Data freshness depends on archive updates; clients should cache responses responsibly.</p>
  <p><a href="/epa/intelligence.php">Learn how SA-EPA and AUGUR work →</a></p>
</section>
<?php ns_foot(); ?>
