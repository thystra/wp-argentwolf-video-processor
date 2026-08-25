<?php
/**
 * Plugin Name: ArgentWolf Video Processor
 * Plugin URI: https://github.com/thystra/wp-argentwolf-video-processor
 * Description: Queues WordPress videos and creates adaptive and progressive streaming derivatives with a detached FFmpeg worker while preserving the original attachment.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Alan Johnson
 * Author URI: https://github.com/thystra
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: argentwolf-video-processor
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('ARGENT_VIDEO_VERSION', '1.0.0');
define('ARGENT_VIDEO_FILE', __FILE__);
define('ARGENT_VIDEO_DIR', plugin_dir_path(__FILE__));
define('ARGENT_VIDEO_URL', plugin_dir_url(__FILE__));

require_once ARGENT_VIDEO_DIR . 'includes/Settings.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Identity.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Origin.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Snapshot.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Result.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Mutation_Plan.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Plan_Result.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_State_Machine.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_Operation_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Api_Error.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Http_Client.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Api_Client.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Secret_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Secret_Crypto.php';
require_once ARGENT_VIDEO_DIR . 'includes/Managed_Backend_Secret_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Capabilities.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Health.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Adapter.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Registry.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_Coordinator.php';
require_once ARGENT_VIDEO_DIR . 'includes/Model_Activator.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Post_Type.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Meta.php';
require_once ARGENT_VIDEO_DIR . 'includes/Activator.php';
require_once ARGENT_VIDEO_DIR . 'includes/Job_Repository.php';
require_once ARGENT_VIDEO_DIR . 'includes/Worker_Log_Repository.php';
require_once ARGENT_VIDEO_DIR . 'includes/Storage.php';
require_once ARGENT_VIDEO_DIR . 'includes/Output_Namer.php';
require_once ARGENT_VIDEO_DIR . 'includes/Command_Builder.php';
require_once ARGENT_VIDEO_DIR . 'includes/Shell_Probe.php';
require_once ARGENT_VIDEO_DIR . 'includes/FFmpeg_Security.php';
require_once ARGENT_VIDEO_DIR . 'includes/Process_Runner.php';
require_once ARGENT_VIDEO_DIR . 'includes/Probe.php';
require_once ARGENT_VIDEO_DIR . 'includes/Adaptive_HLS.php';
require_once ARGENT_VIDEO_DIR . 'includes/Transcoder.php';
require_once ARGENT_VIDEO_DIR . 'includes/Queue.php';
require_once ARGENT_VIDEO_DIR . 'includes/Bulk_Queue.php';
require_once ARGENT_VIDEO_DIR . 'includes/Worker.php';
require_once ARGENT_VIDEO_DIR . 'includes/Worker_Launcher.php';
require_once ARGENT_VIDEO_DIR . 'includes/Player.php';
require_once ARGENT_VIDEO_DIR . 'includes/Renderer.php';
require_once ARGENT_VIDEO_DIR . 'includes/Diagnostics.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Adapter_Factory.php';
require_once ARGENT_VIDEO_DIR . 'includes/Local_Backend_Adapter.php';
require_once ARGENT_VIDEO_DIR . 'includes/Admin.php';
require_once ARGENT_VIDEO_DIR . 'includes/CLI_Command.php';
require_once ARGENT_VIDEO_DIR . 'includes/Plugin.php';

register_activation_hook(
    ARGENT_VIDEO_FILE,
    array(ArgentVideo\Activator::class, 'activate')
);
register_deactivation_hook(
    ARGENT_VIDEO_FILE,
    array(ArgentVideo\Activator::class, 'deactivate')
);

ArgentVideo\Plugin::instance()->boot();

// EOF: argentwolf-video-processor.php
