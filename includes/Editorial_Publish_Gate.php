<?php
/**
 * File: includes/Editorial_Publish_Gate.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * R46.4 WordPress publication boundary for locally unresolved AWVP metadata.
 *
 * Gutenberg/REST publication receives an explicit WP_Error. The generic
 * wp_insert_post_data fallback prevents a non-public post from transitioning to
 * publish/future/private with unresolved AWVP intent, but never publishes then
 * reverts and never performs remote work.
 */
final class Editorial_Publish_Gate
{
    public const ERROR_CODE = 'argent_video_publication_review_required';

    /** @var list<string> */
    private const PROTECTED_STATUSES = array('publish', 'future', 'private');

    public function __construct(private readonly Editorial_Publish_Validator $validator)
    {
    }

    public function register(): void
    {
        add_filter('wp_insert_post_data', array($this, 'filter_insert_post_data'), 99, 4);
        add_action('rest_api_init', array($this, 'register_rest_filters'), 20);
    }

    public function register_rest_filters(): void
    {
        $types = get_post_types(array('show_in_rest' => true), 'objects');
        if (! is_array($types)) {
            return;
        }
        foreach ($types as $post_type) {
            if (! is_object($post_type) || ! is_string($post_type->name ?? null)) {
                continue;
            }
            $name = $post_type->name;
            if (Video_Post_Type::POST_TYPE === $name
                || (! ($post_type->public ?? false) && ! ($post_type->publicly_queryable ?? false))
            ) {
                continue;
            }
            add_filter('rest_pre_insert_' . $name, array($this, 'rest_pre_insert'), 10, 2);
        }
    }

    public function rest_pre_insert(mixed $prepared_post, mixed $request): mixed
    {
        if (! is_object($prepared_post)) {
            return $prepared_post;
        }
        $status = is_string($prepared_post->post_status ?? null) ? $prepared_post->post_status : '';
        if (! $this->requires_review($status)) {
            return $prepared_post;
        }

        $post_id = Video_Meta::sanitize_positive_id($prepared_post->ID ?? 0);
        if ($post_id < 1 && is_object($request) && method_exists($request, 'get_param')) {
            $post_id = Video_Meta::sanitize_positive_id($request->get_param('id'));
        }
        $content = $this->prepared_content($prepared_post, $post_id);
        if (null === $content) {
            return $prepared_post;
        }
        if ($post_id < 1) {
            if (! $this->contains_awvp_block($content)) {
                return $prepared_post;
            }
            $issues = array(array(
                'video_id' => 0,
                'code' => 'video_binding',
                'label' => __('Save this post as a draft and bind every ArgentWolf Video before publishing.', 'argentwolf-video-processor'),
            ));
            return new \WP_Error(
                self::ERROR_CODE,
                $this->message($issues),
                array('status' => 409, 'awvp_issues' => $issues)
            );
        }

        $validation = $this->validator->validate($post_id, $content);
        if (true === $validation['ready']) {
            return $prepared_post;
        }

        return new \WP_Error(
            self::ERROR_CODE,
            $this->message($validation['issues']),
            array(
                'status' => 409,
                'awvp_issues' => $validation['issues'],
            )
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $postarr
     * @param array<string,mixed> $unsanitized_postarr
     * @return array<string,mixed>
     */
    public function filter_insert_post_data(
        array $data,
        array $postarr,
        array $unsanitized_postarr,
        bool $update
    ): array {
        unset($update);
        $status = is_string($data['post_status'] ?? null) ? $data['post_status'] : '';
        if (! $this->requires_review($status)) {
            return $data;
        }

        $post_id = Video_Meta::sanitize_positive_id($postarr['ID'] ?? ($unsanitized_postarr['ID'] ?? 0));
        if ($post_id < 1) {
            // A genuinely new post cannot yet own a bound AWVP Video because
            // binding requires the saved origin post ID. Keep any attempted
            // publication non-public rather than guessing around that invariant.
            if ($this->contains_awvp_block($this->unslash_content($data['post_content'] ?? ''))) {
                $data['post_status'] = 'draft';
            }
            return $data;
        }

        $content = $this->unslash_content($data['post_content'] ?? '');
        $validation = $this->validator->validate($post_id, $content);
        if (true === $validation['ready']) {
            return $data;
        }

        $current = get_post($post_id);
        $current_status = is_object($current) && is_string($current->post_status ?? null)
            ? $current->post_status : 'draft';

        if (! $this->requires_review($current_status)) {
            // Preserve the requested content as an editable non-public draft or
            // pending item; only the publicational status transition is refused.
            $data['post_status'] = in_array($current_status, array('draft', 'pending', 'auto-draft'), true)
                ? $current_status : 'draft';
            return $data;
        }

        if ($current_status !== $status) {
            // For future/private/public status changes, retain the already-live
            // status rather than exposing unresolved new intent.
            $data['post_status'] = $current_status;
        }

        // A non-REST update to an already publicational post cannot return a
        // WP_Error from this filter. Preserve the previous content so unresolved
        // AWVP block changes are not made live silently. Gutenberg receives the
        // explicit REST error above instead.
        if (is_object($current) && is_string($current->post_content ?? null)) {
            $data['post_content'] = function_exists('wp_slash')
                ? wp_slash($current->post_content)
                : $current->post_content;
        }

        return $data;
    }

    private function requires_review(string $status): bool
    {
        return in_array($status, self::PROTECTED_STATUSES, true);
    }

    private function prepared_content(object $prepared_post, int $post_id): ?string
    {
        if (is_string($prepared_post->post_content ?? null)) {
            return $prepared_post->post_content;
        }
        $post = $post_id > 0 ? get_post($post_id) : null;
        return is_object($post) && is_string($post->post_content ?? null)
            ? $post->post_content : null;
    }

    private function unslash_content(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        return function_exists('wp_unslash') ? (string) wp_unslash($value) : $value;
    }

    private function contains_awvp_block(string $content): bool
    {
        return str_contains($content, '<!-- wp:' . Editorial_Publish_Validator::BLOCK_NAME)
            || str_contains($content, '"' . Editorial_Publish_Validator::BLOCK_NAME . '"');
    }

    /** @param list<array{video_id:int,code:string,label:string}> $issues */
    private function message(array $issues): string
    {
        $parts = array();
        foreach ($issues as $issue) {
            $label = is_string($issue['label'] ?? null) ? trim($issue['label']) : '';
            if ('' === $label) {
                continue;
            }
            $video_id = Video_Meta::sanitize_positive_id($issue['video_id'] ?? 0);
            $parts[] = $video_id > 0
                ? sprintf(
                /* translators: 1: AWVP Video post ID, 2: validation issue label. */
                __('Video #%1$d: %2$s', 'argentwolf-video-processor'),
                $video_id,
                $label
            )
                : $label;
            if (count($parts) >= 20) {
                break;
            }
        }
        $detail = implode(' ', $parts);
        if (strlen($detail) > 1800) {
            $detail = substr($detail, 0, 1797) . '...';
        }
        return '' === $detail
            ? __('AWVP PeerTube publication review is incomplete.', 'argentwolf-video-processor')
            : __('AWVP blocked WordPress publication until required video metadata is reviewed. ', 'argentwolf-video-processor') . $detail;
    }
}

// EOF: includes/Editorial_Publish_Gate.php
