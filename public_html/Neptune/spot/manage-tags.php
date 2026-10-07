<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';require_login();header('Location: '.base_url('scout/tag-manage-tags.php'),true,302);exit;
