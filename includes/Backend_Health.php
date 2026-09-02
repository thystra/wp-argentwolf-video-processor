<?php
/**
 * File: includes/Backend_Health.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Backend_Health
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const BLOCKING = 'blocking';
    public const UNKNOWN = 'unknown';

    /**
     * @param list<array{code:string,status:string,message:string,data?:array<string,mixed>}> $checks
     */
    private function __construct(
        private readonly string $status,
        private readonly array $checks
    ) {
    }

    /**
     * @param list<array{check?:mixed,status?:mixed,detail?:mixed}> $diagnostics
     */
    public static function from_local_diagnostics(array $diagnostics): self
    {
        $safe = array();
        $errors = 0;
        $warnings = 0;

        foreach (array_slice($diagnostics, 0, 32) as $diagnostic) {
            if (! is_array($diagnostic)) {
                continue;
            }

            $status = (string) ($diagnostic['status'] ?? '');
            if ('error' === $status) {
                ++$errors;
            } elseif ('warning' === $status) {
                ++$warnings;
            }

            $safe[] = array(
                'check'  => self::bounded((string) ($diagnostic['check'] ?? ''), 120),
                'status' => self::bounded($status, 24),
                'detail' => self::bounded((string) ($diagnostic['detail'] ?? ''), 500),
            );
        }

        if ($errors > 0) {
            $overall = self::BLOCKING;
        } elseif ($warnings > 0) {
            $overall = self::WARNING;
        } elseif ([] === $safe) {
            $overall = self::UNKNOWN;
        } else {
            $overall = self::OK;
        }

        return new self(
            $overall,
            array(
                array(
                    'code'    => 'local.diagnostics.summary',
                    'status'  => $overall,
                    'message' => sprintf(
                        'Local diagnostics: %d blocking, %d warning, %d total checks.',
                        $errors,
                        $warnings,
                        count($safe)
                    ),
                    'data'    => array('checks' => $safe),
                ),
            )
        );
    }

    public static function peertube_activation_ready(int $generation): self
    {
        if ($generation < 1) {
            return self::peertube_blocking('secret_unavailable');
        }

        return new self(
            self::WARNING,
            array(
                array(
                    'code'    => 'peertube.lifecycle.refresh_pending',
                    'status'  => self::WARNING,
                    'message' => 'PeerTube activation is locally verified; token refresh and live operational health are not implemented in this checkpoint.',
                    'data'    => array('secret_generation' => $generation),
                ),
            )
        );
    }

    public static function peertube_blocking(string $reason): self
    {
        $detail = match ($reason) {
            'descriptor_invalid' => array(
                'code' => 'peertube.descriptor.invalid',
                'message' => 'The active PeerTube descriptor is not structurally usable.',
            ),
            'access_token_unusable' => array(
                'code' => 'peertube.auth.access_token_unusable',
                'message' => 'The stored PeerTube access token is expired or too close to expiry; refresh is not implemented in this checkpoint.',
            ),
            default => array(
                'code' => 'peertube.auth.secret_unavailable',
                'message' => 'The managed PeerTube credential is unavailable or unreadable.',
            ),
        };

        return new self(
            self::BLOCKING,
            array(
                array(
                    'code'    => $detail['code'],
                    'status'  => self::BLOCKING,
                    'message' => $detail['message'],
                ),
            )
        );
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<array{code:string,status:string,message:string,data?:array<string,mixed>}> */
    public function checks(): array
    {
        return $this->checks;
    }

    /** @return array{status:string,checks:list<array{code:string,status:string,message:string,data?:array<string,mixed>}>} */
    public function to_array(): array
    {
        return array(
            'status' => $this->status,
            'checks' => $this->checks,
        );
    }

    private static function bounded(string $value, int $maximum): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maximum);
        }

        return substr($value, 0, $maximum);
    }
}

// EOF: includes/Backend_Health.php
