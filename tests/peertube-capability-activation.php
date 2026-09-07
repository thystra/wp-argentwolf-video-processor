<?php
/** R45.6 / RC capability truth-map regression. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Backend_Capabilities.php';

use ArgentVideo\Backend_Capabilities;

$actual = Backend_Capabilities::peertube_activation();
$expected = array(
    Backend_Capabilities::INGEST_WORDPRESS_ATTACHMENT => false,
    Backend_Capabilities::INGEST_AWVP_STAGING          => true,
    Backend_Capabilities::INGEST_SERVER_PUSH           => true,
    Backend_Capabilities::INGEST_DIRECT_BROWSER        => false,
    Backend_Capabilities::PROCESSING_VIDEO             => true,
    Backend_Capabilities::LIBRARY_ACCOUNT_VIDEOS       => false,
    Backend_Capabilities::ASSET_SELECT_EXISTING        => false,
    Backend_Capabilities::DELIVERY_EMBED               => true,
    Backend_Capabilities::PUBLICATION_PRIVACY          => true,
    Backend_Capabilities::PUBLICATION_SCHEDULE         => false,
    Backend_Capabilities::SOURCE_BACKEND_RETENTION     => false,
    Backend_Capabilities::ASSET_REMOTE_DELETE          => false,
);

if ($expected !== $actual) {
    fwrite(STDERR, "FAIL: PeerTube RC capability map drifted from the reviewed exact grant set.\n");
    var_export($actual);
    exit(1);
}

echo "PeerTube R45.6/RC capability activation tests passed.\n";

// EOF: tests/peertube-capability-activation.php
