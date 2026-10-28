<?php
// Compatibility link: public video tags no longer have a review workflow.
declare(strict_types=1);
header('Location: training-labels.php'.(isset($_GET['export'])?'?export=1':''),true,303);
exit;
