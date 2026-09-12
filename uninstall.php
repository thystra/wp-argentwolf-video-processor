<?php
/**
 * File: uninstall.php
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Preserve all local plugin data and media by default. Destructive local
// database/state cleanup requires an explicit operator-defined constant.
if (! defined('ARGENT_VIDEO_REMOVE_DATA_ON_UNINSTALL') || true !== ARGENT_VIDEO_REMOVE_DATA_ON_UNINSTALL) {
    return;
}

require_once __DIR__ . '/includes/Uninstall_Data.php';

\ArgentVideo\Uninstall_Data::remove();

// Destructive uninstall is local-only. It deliberately makes no PeerTube API
// calls and never removes ordinary WordPress source attachments, external
// archives, or managed derivative/staging files from disk.

// EOF: uninstall.php
