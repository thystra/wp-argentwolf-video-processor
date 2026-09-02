<?php
/**
 * File: includes/PeerTube_Token_Lifecycle_Api.php
 */

declare(strict_types=1);

namespace ArgentVideo;

interface PeerTube_Token_Lifecycle_Api
{
    public function origin(): string;

    /** @return array{ok:bool,data:array<string,string>|null,error:array<string,mixed>|null} */
    public function local_oauth_client(): array;

    /**
     * @param array<string,string> $oauth_client
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    public function refresh_token(
        array $oauth_client,
        string $refresh_token,
        int $received_at
    ): array;

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function revoke_token(string $access_token): array;
}

// EOF: includes/PeerTube_Token_Lifecycle_Api.php
