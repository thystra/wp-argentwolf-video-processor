<?php
/**
 * File: includes/PeerTube_Staged_Upload_Guard.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Read-only local fence for a staged-upload operation.
 *
 * A future media POST must re-prove both this exact backend/destination binding
 * and the immutable staged-source commitment immediately before claiming or
 * executing a remote mutation.
 */
final class PeerTube_Staged_Upload_Guard
{
    public const READY = 'ready';
    public const RECORD_INVALID = 'record_invalid';
    public const BACKEND_CHANGED = 'backend_changed';
    public const SOURCE_CHANGED = 'source_changed';

    /** @param array<string,mixed> $record @param array<string,mixed>|null $descriptor */
    public static function evaluate(array $record, ?array $descriptor): string
    {
        if (! PeerTube_Staged_Upload_State_Machine::valid($record)) {
            return self::RECORD_INVALID;
        }

        if (! self::descriptor_matches($record, $descriptor)) {
            return self::BACKEND_CHANGED;
        }

        return PeerTube_Staged_Source_Identity::matches($record['source'])
            ? self::READY
            : self::SOURCE_CHANGED;
    }

    /** @param array<string,mixed> $record @param array<string,mixed>|null $descriptor */
    public static function descriptor_matches(array $record, ?array $descriptor): bool
    {
        if (! is_array($descriptor)) {
            return false;
        }

        return array_key_exists('id', $descriptor)
            && array_key_exists('type', $descriptor)
            && array_key_exists('state', $descriptor)
            && array_key_exists('default_destination', $descriptor)
            && array_key_exists('secret_ref', $descriptor)
            && array_key_exists('config_version', $descriptor)
            && array_key_exists('config', $descriptor)
            && is_string($descriptor['id'])
            && $descriptor['id'] === $record['backend_id']
            && Backend_Registry::PEERTUBE_TYPE === $descriptor['type']
            && 'active' === $descriptor['state']
            && is_string($descriptor['default_destination'])
            && $descriptor['default_destination'] === $record['destination_id']
            && '' !== Backend_Identity::sanitize_opaque($descriptor['secret_ref'], 191)
            && 1 === $descriptor['config_version']
            && is_array($descriptor['config'])
            && array('origin') === array_keys($descriptor['config'])
            && is_string($descriptor['config']['origin'])
            && $descriptor['config']['origin'] === $record['origin'];
    }
}

// EOF: includes/PeerTube_Staged_Upload_Guard.php
