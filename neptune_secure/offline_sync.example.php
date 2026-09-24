<?php
/**
 * Optional PHP configuration for Neptune field synchronization.
 * The preferred key source is /etc/scout/offline-sync.env.
 */
return [
    'enabled' => true,
    'key' => getenv('NEPTUNE_OFFLINE_SYNC_KEY') ?: '',
];
