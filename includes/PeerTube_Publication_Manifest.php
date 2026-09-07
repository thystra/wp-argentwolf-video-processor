<?php
/**
 * File: includes/PeerTube_Publication_Manifest.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Immutable, non-secret execution projection of a reviewed PeerTube plan.
 *
 * R46.5b freezes provider-backed choices before remote mutation. The manifest
 * contains no credential, task identity, URL, or serving-cutover authority.
 */
final class PeerTube_Publication_Manifest
{
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public static function build(
        array $plan,
        array $catalog,
        ?array $publishing_defaults = null
    ): array {
        $plan = PeerTube_Publication_Plan::sanitize($plan);
        $catalog = PeerTube_Publication_Catalog::sanitize($catalog);
        if (
            array() === $plan || ! PeerTube_Publication_Plan::ready_for_dispatch($plan)
            || array() === $catalog || true === $catalog['stale']
            || $plan['backend_id'] !== $catalog['backend_id']
            || ! self::channel_owned((string) $plan['channel_id'], $catalog['channels'])
            || ! isset($catalog['privacies'][(string) $plan['final_privacy_id']])
            || '5' === (string) $plan['final_privacy_id']
            || true === ($plan['embed']['restricted'] ?? false)
        ) {
            return array();
        }

        foreach (array('licence_id'=>'licences','category_id'=>'categories') as $field => $vocabulary) {
            $id = (string) $plan[$field];
            if ('' !== $id && ! isset($catalog[$vocabulary][$id])) {
                return array();
            }
        }
        if ('' !== $plan['language'] && ! isset($catalog['languages'][$plan['language']])) {
            return array();
        }
        if (true === $plan['moderation']['sensitive'] && false === $catalog['capabilities']['sensitive_content']) {
            return array();
        }
        if (($plan['moderation']['violent'] || $plan['moderation']['sexually_explicit'])
            && true !== $catalog['capabilities']['sensitive_flags']) {
            return array();
        }

        $support = self::resolved_support($plan['support'], $publishing_defaults);
        if (null === $support) {
            return array();
        }

        $thumbnail_sha256 = '';
        $thumbnail_bytes = 0;
        $thumbnail_mime = '';
        if ((int) $plan['thumbnail_attachment_id'] > 0) {
            $thumbnail = PeerTube_Publication_Thumbnail::capture((int) $plan['thumbnail_attachment_id']);
            if (null === $thumbnail) {
                return array();
            }
            $thumbnail_sha256 = $thumbnail['sha256'];
            $thumbnail_bytes = $thumbnail['bytes'];
            $thumbnail_mime = $thumbnail['mime'];
        }

        $manifest = array(
            'version'                 => self::VERSION,
            'backend_id'              => (string) $plan['backend_id'],
            'channel_id'              => (string) $plan['channel_id'],
            'anchor_post_id'          => (int) $plan['anchor_post_id'],
            'plan_sha256'             => PeerTube_Publication_Lifecycle::plan_sha256($plan),
            'title'                   => (string) $plan['title'],
            'description_markdown'    => (string) $plan['description_markdown'],
            'tags'                    => $plan['tags'],
            'support_markdown'        => $support,
            'final_privacy_id'        => (string) $plan['final_privacy_id'],
            'licence_id'              => (string) $plan['licence_id'],
            'category_id'             => (string) $plan['category_id'],
            'language'                => (string) $plan['language'],
            'thumbnail_attachment_id' => (int) $plan['thumbnail_attachment_id'],
            'thumbnail_sha256'        => $thumbnail_sha256,
            'thumbnail_bytes'         => $thumbnail_bytes,
            'thumbnail_mime'          => $thumbnail_mime,
            'download_enabled'        => (bool) $plan['download_enabled'],
            'originally_published_at' => (string) $plan['originally_published_at'],
            'comments_policy'         => (string) $plan['comments_policy'],
            'moderation'              => $plan['moderation'],
        );

        return self::sanitize($manifest);
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        $keys = array(
            'version','backend_id','channel_id','anchor_post_id','plan_sha256','title',
            'description_markdown','tags','support_markdown','final_privacy_id','licence_id',
            'category_id','language','thumbnail_attachment_id','thumbnail_sha256','thumbnail_bytes',
            'thumbnail_mime','download_enabled','originally_published_at','comments_policy','moderation',
        );
        if (! is_array($value) || $keys !== array_keys($value) || self::VERSION !== ($value['version'] ?? null)) {
            return array();
        }

        $plan = array(
            'version'                 => PeerTube_Publication_Plan::VERSION,
            'backend_id'              => $value['backend_id'],
            'channel_id'              => $value['channel_id'],
            'title'                   => $value['title'],
            'description_markdown'    => $value['description_markdown'],
            'tags'                    => $value['tags'],
            'support'                 => '' === ($value['support_markdown'] ?? '')
                ? array('mode'=>'none','preset_id'=>'','markdown'=>'')
                : array('mode'=>'custom','preset_id'=>'','markdown'=>$value['support_markdown']),
            'final_privacy_id'        => $value['final_privacy_id'],
            'licence_id'              => $value['licence_id'],
            'category_id'             => $value['category_id'],
            'language'                => $value['language'],
            'thumbnail_attachment_id' => $value['thumbnail_attachment_id'],
            'download_enabled'        => $value['download_enabled'],
            'originally_published_at' => $value['originally_published_at'],
            'comments_policy'         => $value['comments_policy'],
            'moderation'              => $value['moderation'],
            'embed'                   => array('restricted'=>false,'domains'=>array()),
            'review'                  => array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true),
            'dispatch_policy'         => PeerTube_Publication_Plan::DISPATCH_SEND_NOW,
            'release_policy'          => PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,
            'anchor_post_id'          => $value['anchor_post_id'],
        );
        $normalized = PeerTube_Publication_Plan::sanitize($plan);
        if (array() === $normalized || '5' === (string) $value['final_privacy_id']) {
            return array();
        }
        $sha = is_string($value['plan_sha256'] ?? null) ? $value['plan_sha256'] : '';
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            return array();
        }
        $support = is_string($value['support_markdown'] ?? null) ? $value['support_markdown'] : null;
        $thumb_sha = is_string($value['thumbnail_sha256'] ?? null) ? $value['thumbnail_sha256'] : '';
        $thumb_bytes = is_int($value['thumbnail_bytes'] ?? null) ? $value['thumbnail_bytes'] : -1;
        $thumb_mime = is_string($value['thumbnail_mime'] ?? null) ? $value['thumbnail_mime'] : '';
        if (null === $support || strlen($support) > PeerTube_Publication_Plan::MAX_MARKDOWN_BYTES || 1 !== preg_match('//u', $support)) {
            return array();
        }
        if ((int) $normalized['thumbnail_attachment_id'] < 1) {
            if ('' !== $thumb_sha || 0 !== $thumb_bytes || '' !== $thumb_mime) {
                return array();
            }
        } elseif (1 !== preg_match('/^[a-f0-9]{64}$/D', $thumb_sha)
            || $thumb_bytes < 1 || $thumb_bytes > PeerTube_Publication_Thumbnail::MAX_BYTES
            || ! in_array($thumb_mime, array('image/jpeg','image/png','image/webp'), true)) {
            return array();
        }

        return array(
            'version'=>self::VERSION,
            'backend_id'=>(string)$normalized['backend_id'],
            'channel_id'=>(string)$normalized['channel_id'],
            'anchor_post_id'=>(int)$normalized['anchor_post_id'],
            'plan_sha256'=>$sha,
            'title'=>(string)$normalized['title'],
            'description_markdown'=>(string)$normalized['description_markdown'],
            'tags'=>$normalized['tags'],
            'support_markdown'=>$support,
            'final_privacy_id'=>(string)$normalized['final_privacy_id'],
            'licence_id'=>(string)$normalized['licence_id'],
            'category_id'=>(string)$normalized['category_id'],
            'language'=>(string)$normalized['language'],
            'thumbnail_attachment_id'=>(int)$normalized['thumbnail_attachment_id'],
            'thumbnail_sha256'=>$thumb_sha,
            'thumbnail_bytes'=>$thumb_bytes,
            'thumbnail_mime'=>$thumb_mime,
            'download_enabled'=>(bool)$normalized['download_enabled'],
            'originally_published_at'=>(string)$normalized['originally_published_at'],
            'comments_policy'=>(string)$normalized['comments_policy'],
            'moderation'=>$normalized['moderation'],
        );
    }

    /** @param array<string,mixed> $manifest */
    public static function sha256(array $manifest): string
    {
        $manifest = self::sanitize($manifest);
        if (array() === $manifest) {
            return '';
        }
        try {
            return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\JsonException) {
            return '';
        }
    }

    /** @param list<array<string,mixed>> $channels */
    private static function channel_owned(string $id, array $channels): bool
    {
        foreach ($channels as $channel) {
            if ($id === ($channel['id'] ?? null) && 'owned' === ($channel['authority'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $support */
    private static function resolved_support(array $support, ?array $settings): ?string
    {
        if ('none' === ($support['mode'] ?? null)) {
            return '';
        }
        if ('custom' === ($support['mode'] ?? null)) {
            return is_string($support['markdown'] ?? null) ? $support['markdown'] : null;
        }
        if ('preset' !== ($support['mode'] ?? null) || ! is_array($settings)) {
            return null;
        }
        $settings = Video_Publishing_Defaults::sanitize($settings);
        $id = is_string($support['preset_id'] ?? null) ? $support['preset_id'] : '';
        $preset = array() === $settings ? null : ($settings['support_presets'][$id] ?? null);
        return is_array($preset) && is_string($preset['markdown'] ?? null) ? $preset['markdown'] : null;
    }
}

// EOF: includes/PeerTube_Publication_Manifest.php
