<?php
/** Static administrator/consequential boundary checks for R46.9. */
declare(strict_types=1);
$root=dirname(__DIR__);$admin=file_get_contents($root.'/includes/Local_Retention_Admin.php');$service=file_get_contents($root.'/includes/Local_Retention_Service.php');$plugin=file_get_contents($root.'/includes/Plugin.php');$worker=file_get_contents($root.'/includes/PeerTube_Task_Worker.php');$launcher=file_get_contents($root.'/includes/PeerTube_Task_Worker_Launcher.php');$queue=file_get_contents($root.'/includes/Queue.php');
$f=0;$a=function(bool $v,string $m)use(&$f){if(!$v){fwrite(STDERR,"FAIL: $m\n");$f++;}};
$a(is_string($admin)&&str_contains($admin,"current_user_can('manage_options')")&&str_contains($admin,'check_admin_referer'),'Retention admin must require capability and nonce.');
foreach(array('wp_delete_file','Storage::remove_tree','unlink(','wp_delete_attachment','wp_delete_post') as $needle)$a(!str_contains((string)$admin,$needle),"Admin request acquired inline destructive authority: $needle");
$a(str_contains((string)$service,"public const TASK_TYPE='peertube_local_retention_cleanup'")&&str_contains((string)$service,'$this->tasks->enqueue'),'Cleanup must be a durable queued task.');
$a(str_contains((string)$admin,'Local_Retention_Policy::MODE_DELETE_ALL===')&&!str_contains((string)$admin,"if('removed'===\$source||'complete'===\$cleanup)"),'Completed managed-only cleanup must remain administratively reconfigurable; only irreversible full cleanup is frozen.');
$a(str_contains((string)$worker,"'peertube_local_retention_cleanup'")&&str_contains((string)$launcher,"'peertube_local_retention_cleanup'"),'Detached worker/launcher do not own retention cleanup.');
$onceStart=strpos((string)$worker,'private const ONCE_TASK_TYPES');$drainStart=strpos((string)$worker,'private const DRAIN_TASK_TYPES');$a(false!==$onceStart&&false!==$drainStart&&!str_contains(substr((string)$worker,$onceStart,$drainStart-$onceStart),'peertube_local_retention_cleanup'),'Retention cleanup must not broaden qualified --once diagnostics.');
$a(str_contains((string)$queue,'attachment_local_processing_blocked($attachment_id)'),'Normal local queue is not fenced against running/completed destructive retention.');
$a(str_contains((string)$plugin,"admin_post_' . Local_Retention_Admin::ACTION_CONFIGURE")&&str_contains((string)$plugin,"array(\$local_retention_service, 'advance_claimed')"),'Production retention wiring incomplete.');
if($f>0)exit(1);echo "R46.9 retention administrator/boundary tests passed.\n";
