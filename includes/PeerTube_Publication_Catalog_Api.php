<?php
/**
 * File: includes/PeerTube_Publication_Catalog_Api.php
 */

declare(strict_types=1);

namespace ArgentVideo;

interface PeerTube_Publication_Catalog_Api
{
    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function publication_catalog(string $access_token): array;
}

// EOF: includes/PeerTube_Publication_Catalog_Api.php
