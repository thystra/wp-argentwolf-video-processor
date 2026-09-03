<?php
/** R44 retains R43's browser prerequisite and exposes no reconciliation action. */
declare(strict_types=1);
require dirname(__DIR__) . '/peertube-staged-upload-smoke/assert-browser.php';
$complete_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert(
    ! str_contains($complete_page->body, 'remote_asset_reconciliation')
        && ! str_contains($complete_page->body, 'remote-reconciliation'),
    'R44 prematurely exposed a browser-triggerable remote reconciliation action.'
);
echo "PEERTUBE_REMOTE_ASSET_RECONCILIATION_BROWSER_PREREQUISITE=PASS\n";
