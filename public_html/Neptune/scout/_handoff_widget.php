<?php
/** Hand-off QR button at the bottom of a scouting page. Expects $pdo and the signed-in $u. */
require_once __DIR__ . '/_handoff.php';
$nhoConfig = null;
try { $nhoConfig = nho_widget_config($pdo, $u); } catch (Throwable $e) { error_log('[Neptune hand-off widget] ' . $e->getMessage()); }
if ($nhoConfig):
  $nhoJs = static fn(string $rel) => e(base_url($rel)) . '?v=' . (@filemtime(dirname(__DIR__) . '/' . $rel) ?: 1);
?>
<style>
.nho-wrap{margin:18px auto 8px;max-width:1100px;padding:0 12px}
.nho-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:10px 12px;border:1px dashed var(--line);border-radius:12px;background:var(--panel)}
.nho-bar .nho-open{display:inline-flex;align-items:center;gap:7px}
.nho-hint{color:var(--muted);font-size:.72rem}
.nho-modal{position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:12px}
.nho-sheet{background:var(--panel);color:var(--text);border:1px solid var(--line);border-radius:14px;width:min(760px,100%);max-height:100%;overflow:auto;padding:14px 16px;box-shadow:0 24px 70px rgba(0,0,0,.5)}
.nho-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px}.nho-head small{display:block;color:var(--muted);font-size:.74rem;margin-top:2px}
.nho-qr{display:flex;justify-content:center;background:#fff;border-radius:10px;padding:6px}.nho-qr canvas{image-rendering:pixelated;max-width:100%;height:auto}
.nho-part{text-align:center;font-weight:900;margin:8px 0 4px}
.nho-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:8px}
.nho-confirm-form{display:grid;gap:8px;margin-top:12px;padding:10px;border:1px solid var(--line);border-radius:10px;background:var(--panel2)}
.nho-confirm-form input{font-size:1.3rem;letter-spacing:.2em;text-transform:uppercase;text-align:center;max-width:220px}
.nho-msg{color:var(--muted)}
.nho-note{color:var(--muted);font-size:.74rem;margin:12px 0 0}
</style>
<section class="nho-wrap" id="neptuneHandoff" aria-label="Hand-off QR"></section>
<script>window.NeptuneHandoffConfig=<?=json_encode($nhoConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)?>;</script>
<script defer src="<?=$nhoJs('assets/js/neptune-qr-full.js')?>"></script>
<script defer src="<?=$nhoJs('assets/js/neptune-handoff.js')?>"></script>
<?php endif; ?>
