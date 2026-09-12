<?php
/**
 * Plugin Name: ArgentWolf Video Processor
 * Plugin URI: https://github.com/thystra/wp-argentwolf-video-processor
 * Description: Processes WordPress video locally or publishes selected videos to configured PeerTube servers.
 * Version: 2.0.0-rc13.2
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

define('ARGENT_VIDEO_VERSION', '2.0.0-rc13.2');
define('ARGENT_VIDEO_FILE', __FILE__);
define('ARGENT_VIDEO_DIR', plugin_dir_path(__FILE__));
define('ARGENT_VIDEO_URL', plugin_dir_url(__FILE__));

require_once ARGENT_VIDEO_DIR . 'includes/Settings.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Identity.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Origin.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_Input.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Snapshot.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Result.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Mutation_Plan.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Plan_Result.php';
require_once ARGENT_VIDEO_DIR . 'includes/Atomic_Option_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_State_Machine.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_Operation_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Api_Error.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Http_Client.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Password_Grant_Api.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Identity_Destination_Api.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Token_Lifecycle_Api.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Staged_Upload_Api.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Remote_Reconciliation_Api.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Catalog_Api.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Mutation_Api.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Catalog.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Thumbnail.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Api_Client.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Secret_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Secret_Crypto.php';
require_once ARGENT_VIDEO_DIR . 'includes/Managed_Backend_Secret_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Capabilities.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Health.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Adapter.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Registry.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Serving_Priority_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Processing_Estimator.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Maintenance_Status_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Backend_Health_Incident_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Health_Notification_Policy_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Health_Notification_State_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Serving_Viability.php';
require_once ARGENT_VIDEO_DIR . 'includes/Serving_Health_Adapter.php';
require_once ARGENT_VIDEO_DIR . 'includes/Serving_Health_Adapter_Factory.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Backend_Adapter.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_Coordinator.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Password_Grant_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Identity_Destination_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Backend_Activation_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Token_Lifecycle_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Token_Lifecycle_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Staged_Source_Identity.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Upload_Slice.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Upload_Runtime_Budget.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Upload_Policy.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Upload_Policy_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Staged_Upload_State_Machine.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Staged_Upload_Guard.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Staged_Upload_Operation_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Staged_Upload_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Remote_Asset_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Asset_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Asset_Repository.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Publication_Health_Repository.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Health_Probe.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Publication_Health_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Health_Operator_Check.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Health_Operator_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Health_Notification_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Daily_Maintenance_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Remote_Asset_Reconciliation_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_Admin_Actions.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_Admin_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Connection_Admin.php';
require_once ARGENT_VIDEO_DIR . 'includes/Model_Activator.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Event_Repository.php';
require_once ARGENT_VIDEO_DIR . 'includes/Publication_History_Admin.php';
require_once ARGENT_VIDEO_DIR . 'includes/Task_Repository.php';
require_once ARGENT_VIDEO_DIR . 'includes/Local_Retention_Policy.php';
require_once ARGENT_VIDEO_DIR . 'includes/Local_Delivery_Evidence.php';
require_once ARGENT_VIDEO_DIR . 'includes/Local_Retention_Default_Policy_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Archive_Of_Record_Policy_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Local_Retention_Execution.php';
require_once ARGENT_VIDEO_DIR . 'includes/WordPress_Source_File.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Republish_Request.php';
require_once ARGENT_VIDEO_DIR . 'includes/Remote_Republish_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/Local_Delivery_Rebuild_Request.php';
require_once ARGENT_VIDEO_DIR . 'includes/Local_Delivery_Rebuild_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/Local_Retention_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/Local_Retention_Admin.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Upload_Failure_Notification.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Upload_Task_Coordinator.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Task_Worker.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Task_Worker_Launcher.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Post_Type.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Destination.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Plan.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Migration_Plan.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Migration_Execution.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Lifecycle.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Manifest.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Execution.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Serving_Authority.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Serving_Resolver.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Serving_Cutover_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Serving_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/Legacy_Video_Serving_Bridge.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Derivative_Cleanup_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Staging_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Publishing_Defaults.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Publishing_Defaults_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Block_Editor_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Block_Editor_Rest.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Editor_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Editor_Rest.php';
require_once ARGENT_VIDEO_DIR . 'includes/Editorial_Publish_Validator.php';
require_once ARGENT_VIDEO_DIR . 'includes/Editorial_Publish_Gate.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Synchronizer.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Task_Coordinator.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Finalizer_Recovery.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Incomplete_Work_Reconciler.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Block.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Catalog_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Catalog_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Publication_Authority_Repair.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Publishing_Admin.php';
require_once ARGENT_VIDEO_DIR . 'includes/Legacy_Video_Adoption_Service.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Migration_Planner.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Migration_Executor.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Migration_Admin.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Reference_Index.php';
require_once ARGENT_VIDEO_DIR . 'includes/Video_Routing_Admin.php';
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
require_once ARGENT_VIDEO_DIR . 'includes/Overview_Disposition_Store.php';
require_once ARGENT_VIDEO_DIR . 'includes/PeerTube_Overview_Admin.php';
require_once ARGENT_VIDEO_DIR . 'includes/Settings_Hub.php';
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
