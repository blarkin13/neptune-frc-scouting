<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';require_login();header('Location: '.base_url('scout/tag.php?tab=team'),true,302);exit;
