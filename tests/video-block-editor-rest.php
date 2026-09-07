<?php
/** Focused dependency-free tests for the R46.3b editor REST boundary. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Video_Meta
    {
        public static function sanitize_positive_id(mixed $value): int
        {
            if (is_int($value)) return $value > 0 ? $value : 0;
            return is_string($value) && 1 === preg_match('/^[1-9][0-9]*$/D', $value) ? (int) $value : 0;
        }
    }
    final class Backend_Identity
    {
        public static function sanitize(mixed $value): string
        {
            return is_string($value) && 1 === preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $value) ? $value : '';
        }
    }
    final class Video_Block_Editor_Service
    {
        public const APPLIED='applied'; public const PRESENT='present'; public const REFUSED='refused'; public const BUSY='busy'; public const INDETERMINATE='indeterminate';
        public array $bind_result = array('status'=>self::APPLIED,'video_id'=>101);
        public array $destination_result = array('status'=>self::APPLIED);
        public ?array $state = array(
            'video'=>array('id'=>101,'attachment_id'=>20,'attachment_url'=>'https://example.test/a.mp4','attachment_title'=>'A','destination_valid'=>true,'destination'=>array('backend_id'=>'local','label'=>'Local')),
            'site_default'=>array('backend_id'=>'local','label'=>'Local'),
            'destinations'=>array(array('backend_id'=>'local','type'=>'local','label'=>'Local')),
        );
        public array $bind_calls = array(); public array $destination_calls = array(); public array $state_calls = array();
        public function bind_local_attachment(int $attachment_id,int $origin_post_id,int $user_id): array { $this->bind_calls[]=func_get_args(); return $this->bind_result; }
        public function editor_state(int $video_id): ?array { $this->state_calls[]=$video_id; return $this->state; }
        public function set_destination(int $video_id,string $mode,string $backend_id=''): array { $this->destination_calls[]=func_get_args(); return $this->destination_result; }
    }
}

namespace {
    $GLOBALS['awvp_rest_routes'] = array();
    $GLOBALS['awvp_caps'] = array('upload_files'=>true,'edit_post'=>true);
    $GLOBALS['awvp_cap_calls'] = array();

    class WP_REST_Request
    {
        public function __construct(private array $params = array()) {}
        public function get_param(string $name): mixed { return $this->params[$name] ?? null; }
    }
    class WP_REST_Response
    {
        public function __construct(public mixed $data) {}
    }
    class WP_Error
    {
        public function __construct(public string $code, public string $message, public array $data = array()) {}
    }
    function register_rest_route(string $namespace,string $route,array $args): bool { $GLOBALS['awvp_rest_routes'][]=array($namespace,$route,$args); return true; }
    function current_user_can(string $capability, mixed ...$args): bool { $GLOBALS['awvp_cap_calls'][]=array($capability,$args); return (bool) ($GLOBALS['awvp_caps'][$capability] ?? false); }
    function get_current_user_id(): int { return 7; }
    function rest_ensure_response(mixed $data): WP_REST_Response { return new WP_REST_Response($data); }
    function __(string $text,string $domain=''): string { unset($domain); return $text; }

    require_once dirname(__DIR__) . '/includes/Video_Block_Editor_Rest.php';

    $assert = static function (bool $ok,string $message): void { if (!$ok) { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); } };
    $service = new \ArgentVideo\Video_Block_Editor_Service();
    $rest = new \ArgentVideo\Video_Block_Editor_Rest($service);
    $rest->register();
    $assert(3 === count($GLOBALS['awvp_rest_routes']), 'Editor REST surface must register exactly three reviewed routes.');
    foreach ($GLOBALS['awvp_rest_routes'] as [$namespace,$route,$args]) {
        $assert(\ArgentVideo\Video_Block_Editor_Rest::NAMESPACE === $namespace, 'Editor REST namespace drifted.');
        $assert(isset($args['permission_callback']) && is_callable($args['permission_callback']), 'Editor REST route omitted permission_callback.');
        $assert(in_array($args['methods'] ?? '', array('GET','POST'), true), 'Editor REST route acquired an unreviewed HTTP method.');
    }

    $bind_request = new WP_REST_Request(array('attachment_id'=>'20','origin_post_id'=>'10'));
    $assert(true === $rest->can_bind($bind_request), 'Authorized attachment bind was refused.');
    $GLOBALS['awvp_caps']['upload_files'] = false;
    $assert(false === $rest->can_bind($bind_request), 'Attachment bind did not require upload_files.');
    $GLOBALS['awvp_caps']['upload_files'] = true;
    $assert(false === $rest->can_bind(new WP_REST_Request(array('attachment_id'=>'020','origin_post_id'=>'10'))), 'Non-canonical attachment ID was accepted.');

    $response = $rest->bind($bind_request);
    $assert($response instanceof WP_REST_Response && 101 === ($response->data['video']['id'] ?? 0), 'Successful bind did not return bounded editor state.');
    $assert(array(20,10,7) === ($service->bind_calls[0] ?? null), 'REST bind did not pass canonical IDs/current user to application service.');

    $service->bind_result = array('status'=>\ArgentVideo\Video_Block_Editor_Service::BUSY,'video_id'=>0);
    $busy = $rest->bind($bind_request);
    $assert($busy instanceof WP_Error && 409 === ($busy->data['status'] ?? 0), 'Busy bind did not return bounded HTTP 409.');

    $read_request = new WP_REST_Request(array('video_id'=>'101'));
    $assert(true === $rest->can_edit_video($read_request), 'Authorized AWVP Video edit was refused.');
    $read = $rest->read($read_request);
    $assert($read instanceof WP_REST_Response, 'Editor-state GET did not return REST response.');

    $bad_destination = $rest->destination(new WP_REST_Request(array('video_id'=>'101','mode'=>'backend','backend_id'=>'../bad')));
    $assert($bad_destination instanceof WP_Error && 400 === ($bad_destination->data['status'] ?? 0), 'Injected backend ID was not rejected before service call.');
    $good_destination = $rest->destination(new WP_REST_Request(array('video_id'=>'101','mode'=>'backend','backend_id'=>'pt-primary')));
    $assert($good_destination instanceof WP_REST_Response, 'Valid destination selection failed REST boundary.');
    $assert(array(101,'backend','pt-primary') === ($service->destination_calls[0] ?? null), 'Destination REST call was not canonicalized.');

    $source = (string) file_get_contents(dirname(__DIR__) . '/includes/Video_Block_Editor_Rest.php');
    foreach (array('wp_remote_','PeerTube_Api_Client','PeerTube_Task','wp_insert_post','update_post_meta','wp_publish_post','transition_post_status') as $forbidden) {
        $assert(! str_contains($source, $forbidden), 'REST controller acquired forbidden direct mutation/remote authority: ' . $forbidden);
    }

    fwrite(STDOUT, "R46 block editor REST boundary tests passed.\n");
}
