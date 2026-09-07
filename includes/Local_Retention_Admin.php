<?php
/** File: includes/Local_Retention_Admin.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Explicit administrator R46.9 local-retention surface. */
final class Local_Retention_Admin
{
    public const PAGE_SLUG='argent-video-local-retention';
    public const ACTION_CONFIGURE='argent_video_configure_local_retention';
    private const NONCE='argent_video_configure_local_retention';
    public function __construct(private readonly Local_Retention_Service $service){}
    public function menu():void{add_management_page(__('AWVP Local Retention','argentwolf-video-processor'),__('AWVP Local Retention','argentwolf-video-processor'),'manage_options',self::PAGE_SLUG,array($this,'page'));}
    public function configure_action():void
    {
        if(!current_user_can('manage_options'))wp_die(esc_html__('You do not have permission to configure video retention.','argentwolf-video-processor'));
        $video=isset($_POST['video_id'])?Video_Meta::sanitize_positive_id(wp_unslash($_POST['video_id'])):0;check_admin_referer(self::NONCE.':'.$video);
        if($video<1||!current_user_can('edit_post',$video))wp_die(esc_html__('You do not have permission to edit this AWVP Video.','argentwolf-video-processor'));
        $mode=isset($_POST['mode'])&&is_string($_POST['mode'])?sanitize_key(wp_unslash($_POST['mode'])):'';$master=isset($_POST['master_authority'])&&is_string($_POST['master_authority'])?sanitize_key(wp_unslash($_POST['master_authority'])):'';$grace=isset($_POST['grace_days'])?(int)wp_unslash($_POST['grace_days']):0;
        if(Local_Retention_Policy::MODE_KEEP!==$mode&&(!isset($_POST['confirm_cleanup'])||'1'!==(string)wp_unslash($_POST['confirm_cleanup'])))$result=array('status'=>Local_Retention_Service::REFUSED);
        else $result=$this->service->configure($video,$mode,$grace,$master,get_current_user_id(),time());
        wp_safe_redirect(add_query_arg(array('page'=>self::PAGE_SLUG,'awvp_retention_notice'=>(string)($result['status']??Local_Retention_Service::REFUSED),'video_id'=>(string)$video),admin_url('tools.php')));exit;
    }
    public function page():void
    {
        if(!current_user_can('manage_options'))wp_die(esc_html__('You do not have permission to view video retention.','argentwolf-video-processor'));
        $rows=get_posts(array('post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>'any','numberposts'=>200,'orderby'=>'ID','order'=>'ASC'));
        ?><div class="wrap"><h1><?php esc_html_e('AWVP Local Retention','argentwolf-video-processor'); ?></h1>
        <div class="notice notice-warning inline"><p><?php esc_html_e('KEEP is the default. Destructive cleanup is per-video, delayed, and runs only in the detached worker after verified PeerTube serving. “Delete all local video copies” removes the physical WordPress video file but preserves the attachment record and can eliminate local fallback.','argentwolf-video-processor'); ?></p></div>
        <?php if(isset($_GET['awvp_retention_notice'])):?><div class="notice notice-info"><p><?php echo esc_html('Retention request: '.sanitize_key(wp_unslash($_GET['awvp_retention_notice']))); ?></p></div><?php endif; ?>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Video','argentwolf-video-processor'); ?></th><th><?php esc_html_e('Serving / source','argentwolf-video-processor'); ?></th><th><?php esc_html_e('Policy','argentwolf-video-processor'); ?></th></tr></thead><tbody>
        <?php foreach($rows as $row):$id=(int)$row->ID;$authority=Video_Serving_Authority::sanitize(get_post_meta($id,Video_Meta::SERVING_AUTHORITY,true));$policy=Local_Retention_Policy::sanitize(get_post_meta($id,Video_Meta::LOCAL_RETENTION_POLICY,true));$execution=Local_Retention_Execution::sanitize(get_post_meta($id,Video_Meta::LOCAL_RETENTION_EXECUTION,true));$cleanup=Video_Meta::sanitize_cleanup_state(get_post_meta($id,Video_Meta::CLEANUP_STATE,true));$source=Video_Meta::sanitize_source_state(get_post_meta($id,Video_Meta::SOURCE_STATE,true));$master=Video_Meta::sanitize_master_authority(get_post_meta($id,Video_Meta::MASTER_AUTHORITY,true));$frozen='removed'===$source||('complete'===$cleanup&&array()!==$execution&&Local_Retention_Policy::MODE_DELETE_ALL===($execution['mode']??null));?>
        <tr><td>#<?php echo esc_html((string)$id); ?> — <?php echo esc_html((string)$row->post_title); ?></td><td><?php echo esc_html(array()===$authority?'local/not verified':'PeerTube verified'); ?>; source=<?php echo esc_html($source); ?>; cleanup=<?php echo esc_html($cleanup); ?></td><td>
        <?php if($frozen):?><strong><?php esc_html_e('Full local cleanup is complete; irreversible physical cleanup state is frozen.','argentwolf-video-processor'); ?></strong><?php else:?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_CONFIGURE); ?>"><input type="hidden" name="video_id" value="<?php echo esc_attr((string)$id); ?>"><?php wp_nonce_field(self::NONCE.':'.$id); ?>
        <select name="mode"><option value="keep"<?php selected($policy['mode']??'keep','keep'); ?>><?php esc_html_e('Keep all local copies','argentwolf-video-processor'); ?></option><option value="delete_managed"<?php selected($policy['mode']??'','delete_managed'); ?>><?php esc_html_e('Delete AWVP-managed copies','argentwolf-video-processor'); ?></option><option value="delete_all"<?php selected($policy['mode']??'','delete_all'); ?>><?php esc_html_e('Delete all local video copies','argentwolf-video-processor'); ?></option></select>
        <select name="master_authority"><option value="wordpress_source"<?php selected($master,'wordpress_source'); ?>><?php esc_html_e('WordPress source is master','argentwolf-video-processor'); ?></option><option value="backend_source"<?php selected($master,'backend_source'); ?>><?php esc_html_e('PeerTube/backend source is master','argentwolf-video-processor'); ?></option><option value="external_archive"<?php selected($master,'external_archive'); ?>><?php esc_html_e('External archive is master','argentwolf-video-processor'); ?></option></select>
        <label><?php esc_html_e('Grace days','argentwolf-video-processor'); ?> <input type="number" name="grace_days" min="1" max="365" value="<?php echo esc_attr((string)(((int)($policy['grace_days']??0)>0)?(int)$policy['grace_days']:7)); ?>" style="width:5em"></label>
        <label><input type="checkbox" name="confirm_cleanup" value="1"> <?php esc_html_e('I explicitly authorize the selected destructive cleanup after the grace period.','argentwolf-video-processor'); ?></label>
        <button class="button" type="submit"><?php esc_html_e('Save / re-evaluate','argentwolf-video-processor'); ?></button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php
    }
}
// EOF
