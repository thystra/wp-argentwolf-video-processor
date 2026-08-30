<?php
/**
 * File: includes/PeerTube_Password_Grant_Api.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Narrow ephemeral API boundary used by the password-grant service.
 *
 * Implementations persist nothing. OAuth-client credentials, bootstrap
 * credentials, and returned tokens remain request-local caller-owned values.
 */
interface PeerTube_Password_Grant_Api
{
    public function origin(): string;

    /**
     * @return array{ok:bool,data:array<string,string>|null,error:array<string,mixed>|null}
     */
    public function local_oauth_client(): array;

    /**
     * @param array<string, mixed> $oauth_client
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    public function password_token(
        array $oauth_client,
        string $username,
        string $password,
        string $otp,
        int $received_at
    ): array;
}

// EOF: includes/PeerTube_Password_Grant_Api.php
