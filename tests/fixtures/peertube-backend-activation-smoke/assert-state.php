<?php
/**
 * Authoritative postconditions for the R40 backend-activation smoke.
 */

declare(strict_types=1);

use ArgentVideo\Backend_Capabilities;
use ArgentVideo\Backend_Adapter_Factory;
use ArgentVideo\Backend_Health;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Backend_Adapter;
use ArgentVideo\PeerTube_Connection_State_Machine;

define('AWVP_ADMIN_SMOKE_EXPECTED_PHASE', PeerTube_Connection_State_Machine::PHASE_COMPLETE);
define('AWVP_ADMIN_SMOKE_EXPECTED_REVISION', 16);
define('AWVP_ADMIN_SMOKE_EXPECTED_DESTINATION', '101');
define('AWVP_ADMIN_SMOKE_EXPECT_COMPLETE', true);
define('AWVP_ADMIN_SMOKE_EXPECT_DESCRIPTOR_STATE', 'active');
define('AWVP_ADMIN_SMOKE_EXPECT_DESCRIPTOR_DESTINATION', '101');

require dirname(__DIR__) . '/peertube-admin-authorization-smoke/assert-state.php';

$descriptor = (new Backend_Registry())->get('r38-admin');
if (! is_array($descriptor)) {
    throw new RuntimeException('The R40 active PeerTube descriptor was unavailable.');
}
$secrets = new Managed_Backend_Secret_Store();
$factory = new Backend_Adapter_Factory(new PeerTube_Backend_Adapter($secrets));
$registry = new Backend_Registry();
if (! $registry->eligible('r38-admin', Backend_Capabilities::DELIVERY_EMBED, $factory)) {
    throw new RuntimeException('The R40 PeerTube descriptor was not eligible for its non-mutating capability.');
}
if ($registry->eligible('r38-admin', Backend_Capabilities::PROCESSING_VIDEO, $factory)) {
    throw new RuntimeException('R40 incorrectly exposed PeerTube processing/upload capability.');
}
$health = $factory->resolve(Backend_Registry::PEERTUBE_TYPE)?->health($descriptor);
if (
    ! $health instanceof Backend_Health
    || ! in_array($health->status(), array(Backend_Health::OK, Backend_Health::WARNING), true)
) {
    throw new RuntimeException('The activated PeerTube adapter health became blocking after R41 lifecycle support.');
}
$checks = $health->checks();
if (! in_array(
    $checks[0]['code'] ?? null,
    array('peertube.auth.operational', 'peertube.auth.refresh_required'),
    true
)) {
    throw new RuntimeException('The post-R41 PeerTube adapter health diagnostic differed.');
}

echo "PEERTUBE_BACKEND_ACTIVATION_STATE_ASSERTIONS=PASS\n";

// EOF: tests/fixtures/peertube-backend-activation-smoke/assert-state.php
