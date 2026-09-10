<?php
/** File: includes/Serving_Health_Adapter.php */
declare(strict_types=1);
namespace ArgentVideo;
interface Serving_Health_Adapter
{
    public function type(): string;
    /** @param array<string,mixed> $descriptor @param array<string,mixed> $asset */
    public function probe_publication(array $descriptor, array $asset): Serving_Viability;
}
// EOF
