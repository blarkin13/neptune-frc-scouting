<?php
declare(strict_types=1);

// Alternate host/control view for testing Robot Recall's pixel-art presentation.
// It intentionally reuses the existing host implementation and database/API.
$rrPixelTarget = __DIR__ . '/robot-recall.php';
$rrPixelMode = 'host';

ob_start();
require $rrPixelTarget;
$html = (string)ob_get_clean();

$inject = "\n<link rel=\"stylesheet\" href=\"../assets/css/robot-recall-pixel.css?v=31\">\n"
    . '<script>document.documentElement.dataset.rrPixelMode="host";</script>' . "\n"
    . "<script defer src=\"../assets/js/robot-recall-pixel.js?v=31\"></script>\n"
    . <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const rewrite=()=>{
    document.querySelectorAll('a[href]').forEach(a=>{
      try{
        const u=new URL(a.href,location.href);
        if(/\/play\/screen\.php$/i.test(u.pathname)) u.pathname=u.pathname.replace(/screen\.php$/i,'pixel-screen.php');
        else if(/\/play\/?$/i.test(u.pathname) || /\/play\/index\.php$/i.test(u.pathname)) u.pathname=u.pathname.replace(/(?:index\.php)?$/i,'pixel.php');
        a.href=u.toString();
      }catch(e){}
    });
  };
  rewrite();
  new MutationObserver(rewrite).observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['href']});
});
</script>
HTML;

if (preg_match('/<\/head>/i', $html)) {
    $html = preg_replace('/<\/head>/i', $inject . '</head>', $html, 1) ?? $html;
} else {
    $html = $inject . $html;
}

echo $html;
