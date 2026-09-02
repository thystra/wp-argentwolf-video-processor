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

    public static function peertube_operational(int $generation): self
    {
        return new self(
            self::OK,
            array(array(
                'code' => 'peertube.auth.operational',
                'status' => self::OK,
                'message' => 'PeerTube credentials are locally available and the access token is within its usable lifetime.',
                'data' => array('secret_generation' => max($generation, 0)),
            ))
        );
    }

    public static function peertube_refresh_required(int $generation): self
    {
        return new self(
            self::WARNING,
            array(array(
                'code' => 'peertube.auth.refresh_required',
                'status' => self::WARNING,
                'message' => 'The PeerTube access token requires an explicit administrator refresh; the stored refresh credential remains locally usable.',
                'data' => array('secret_generation' => max($generation, 0)),
            ))
        );
    }

    public static function peertube_blocking(string $reason): self
    {
        $detail = match ($reason) {
            'descriptor_invalid' => array(
                'code' => 'peertube.descriptor.invalid',
                'message' => 'The active PeerTube descriptor is not structurally usable.',
            ),
            'refresh_token_unusable' => array(
                'code' => 'peertube.auth.reauthentication_required',
                'message' => 'The stored PeerTube refresh credential is expired or unavailable; administrator reauthentication is required.',
            ),
            default => array(
                'code' => 'peertube.auth.secret_unavailable',
                'message' => 'The managed PeerTube credential is unavailable or unreadable.',
            ),
        };

        return new self(
            self::BLOCKING,
            array(array(
                'code' => $detail['code'],
                'status' => self::BLOCKING,
                'message' => $detail['message'],
            ))
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
