<?php
/**
 * File: includes/Backend_Capabilities.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Backend_Capabilities
{
    public const INGEST_WORDPRESS_ATTACHMENT = 'ingest.wordpress_attachment';
    public const INGEST_AWVP_STAGING = 'ingest.awvp_staging';
    public const INGEST_SERVER_PUSH = 'ingest.server_push';
    public const INGEST_DIRECT_BROWSER = 'ingest.direct_browser';
    public const PROCESSING_VIDEO = 'processing.video';
    public const LIBRARY_ACCOUNT_VIDEOS = 'library.account_videos';
    public const ASSET_SELECT_EXISTING = 'asset.select_existing';
    public const DELIVERY_EMBED = 'delivery.embed';
    public const PUBLICATION_PRIVACY = 'publication.privacy';
    public const PUBLICATION_SCHEDULE = 'publication.schedule';
    public const SOURCE_BACKEND_RETENTION = 'source.backend_retention';
    public const ASSET_REMOTE_DELETE = 'asset.remote_delete';

    /** @return array<string, bool> */
    public static function local(): array
    {
        return array(
            self::INGEST_WORDPRESS_ATTACHMENT => true,
            self::INGEST_AWVP_STAGING          => false,
            self::INGEST_SERVER_PUSH           => false,
            self::INGEST_DIRECT_BROWSER        => false,
            self::PROCESSING_VIDEO             => true,
            self::LIBRARY_ACCOUNT_VIDEOS       => false,
            self::ASSET_SELECT_EXISTING        => false,
            self::DELIVERY_EMBED               => true,
            self::PUBLICATION_PRIVACY          => false,
            self::PUBLICATION_SCHEDULE         => false,
            self::SOURCE_BACKEND_RETENTION     => false,
            self::ASSET_REMOTE_DELETE          => false,
        );
    }

    /** @param array<string, bool> $capabilities */
    public static function supports(array $capabilities, string $capability): bool
    {
        return true === ($capabilities[$capability] ?? false);
    }
}

// EOF: includes/Backend_Capabilities.php
