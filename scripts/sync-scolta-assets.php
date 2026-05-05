<?php
// Prefer the monorepo source (development) over the installed vendor copy.
$monorepo = __DIR__ . '/../../../packages/scolta-php/assets/js/scolta.js';
$vendor   = __DIR__ . '/../vendor/tag1/scolta-php/assets/js/scolta.js';
$src = file_exists($monorepo) ? $monorepo : (file_exists($vendor) ? $vendor : null);

if ($src === null) {
    fwrite(STDERR, "sync-scolta-assets: scolta.js not found\n");
    exit(1);
}

foreach (glob(__DIR__ . '/../web/modules/contrib/scolta*/js') as $dir) {
    copy($src, $dir . '/scolta.js');
}
