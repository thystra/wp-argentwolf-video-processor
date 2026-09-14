<?php
/**
 * File: includes/Video_Embed_Resolver.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Provider-neutral URL recognizer for external AWVP Video sources. */
final class Video_Embed_Resolver
{
    /** @var list<Video_Embed_Provider> */
    private array $providers;

    /** @param list<Video_Embed_Provider>|null $providers */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? array(
            new YouTube_Embed_Provider(),
            new Vimeo_Embed_Provider(),
            new PeerTube_Embed_Provider(),
        );
    }

    /** @return array<string,mixed>|null */
    public function recognize(string $url): ?array
    {
        if ('' === trim($url) || strlen($url) > 2048) {
            return null;
        }

        foreach ($this->providers as $provider) {
            $identity = $provider->recognize($url);
            if (is_array($identity)) {
                return $identity;
            }
        }

        return null;
    }
}

// EOF: includes/Video_Embed_Resolver.php
