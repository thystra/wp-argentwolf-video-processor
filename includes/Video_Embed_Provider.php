<?php
/**
 * File: includes/Video_Embed_Provider.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Provider-neutral URL recognition contract for external video embeds. */
interface Video_Embed_Provider
{
    public function provider_id(): string;

    /**
     * Recognize one provider URL and return its canonical external-video identity.
     *
     * @return array<string,mixed>|null
     */
    public function recognize(string $url): ?array;
}

// EOF: includes/Video_Embed_Provider.php
