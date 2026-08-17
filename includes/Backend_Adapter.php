<?php
/**
 * File: includes/Backend_Adapter.php
 */

declare(strict_types=1);

namespace ArgentVideo;

interface Backend_Adapter
{
    public function type(): string;

    /** @return array<string, bool> */
    public function capabilities(): array;

    public function health(): Backend_Health;
}

// EOF: includes/Backend_Adapter.php
