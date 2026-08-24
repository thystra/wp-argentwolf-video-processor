<?php
/**
 * File: includes/Backend_Registry.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use stdClass;

final class Backend_Registry
{
    public const OPTION = 'argent_video_processor_backends';
    public const VERSION = 1;
    public const LOCAL_ID = 'local';

    private const PEERTUBE_TYPE = 'peertube';
    private const PEERTUBE_CONFIG_VERSION = 1;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->read()['backends'];
    }

    /** @return array<string, mixed>|null */
    public function get(string $backend_id): ?array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id) {
            return null;
        }

        return $this->all()[$backend_id] ?? null;
    }

    /** @return list<string> */
    public function diagnostics(): array
    {
        return $this->read()['diagnostics'];
    }

    public function eligible(
        string $backend_id,
        string $capability,
        Backend_Adapter_Factory $factory
    ): bool {
        $descriptor = $this->get($backend_id);
        if (null === $descriptor || 'active' !== ($descriptor['state'] ?? '')) {
            return false;
        }

        $adapter = $factory->resolve((string) ($descriptor['type'] ?? ''));
        if (null === $adapter) {
            return false;
        }

        if (! Backend_Capabilities::supports($adapter->capabilities(), $capability)) {
            return false;
        }

        return in_array($adapter->health()->status(), array(Backend_Health::OK, Backend_Health::WARNING), true);
    }

    /**
     * Validate a write payload.
     *
     * The whole-registry writer remains limited to the built-in local
     * descriptor. PeerTube creation uses the dedicated create-only atomic
     * append below so it can preserve unknown/future state without
     * reconstructing that state.
     *
     * @return array{version:int,backends:array<string,array<string,mixed>>}|null
     */
    public function sanitize(mixed $input): ?array
    {
        if (! is_array($input) || self::VERSION !== self::positive_int($input['version'] ?? null)) {
            return null;
        }

        if ($this->stored_contains_unwritable_configuration()) {
            return null;
        }

        $input_backends = $input['backends'] ?? null;
        if (! is_array($input_backends) || self::contains_secret_material($input)) {
            return null;
        }

        $current = $this->read()['backends'];
        $backends = array();

        foreach ($input_backends as $key => $descriptor) {
            if (! is_string($key) || ! is_array($descriptor)) {
                return null;
            }

            $id = Backend_Identity::sanitize($descriptor['id'] ?? null);
            $type = Backend_Identity::sanitize($descriptor['type'] ?? null);
            if ('' === $id || $key !== $id || '' === $type) {
                return null;
            }

            if (self::LOCAL_ID !== $id || self::LOCAL_ID !== $type) {
                return null;
            }

            $local = $this->sanitize_local_descriptor($descriptor);
            if (null === $local) {
                return null;
            }

            $backends[self::LOCAL_ID] = $local;
        }

        if (! isset($backends[self::LOCAL_ID])) {
            $backends[self::LOCAL_ID] = $current[self::LOCAL_ID] ?? self::local_descriptor('disabled');
        }

        return array(
            'version'  => self::VERSION,
            'backends' => $backends,
        );
    }

    public function save(mixed $input): bool
    {
        $value = $this->sanitize($input);
        if (null === $value) {
            return false;
        }

        $sentinel = new stdClass();
        $existing = get_option(self::OPTION, $sentinel);

        if ($sentinel === $existing) {
            if (! add_option(self::OPTION, $value, '', false)) {
                update_option(self::OPTION, $value, false);
            }
        } else {
            update_option(self::OPTION, $value, false);
        }

        if (! function_exists('wp_set_option_autoload') || ! function_exists('wp_load_alloptions')) {
            return false;
        }

        wp_set_option_autoload(self::OPTION, false);

        $autoloaded = wp_load_alloptions(true);
        if (array_key_exists(self::OPTION, $autoloaded)) {
            return false;
        }

        return $value === get_option(self::OPTION, null);
    }

    /**
     * Read-only preflight for a prospective PeerTube append.
     *
     * Unlike the generic local writer, this path deliberately retains every
     * existing registry field and descriptor verbatim. It models one known
     * PeerTube v1 append to a structurally valid v1 registry entirely in
     * memory; it never persists the modeled result.
     */
    public function can_add_peertube(mixed $descriptor): bool
    {
        return null !== $this->prepare_peertube_append($descriptor);
    }

    /**
     * Create one disabled PeerTube descriptor without replacing registry state.
     *
     * The before value comes exclusively from Atomic_Option_Store's raw,
     * non-cached snapshot. The prospective value is validated once and the
     * exact snapshot is attempted once; a stale snapshot is reported as a
     * conflict and is never retried here.
     */
    public function create_disabled_peertube(mixed $descriptor): Atomic_Option_Result
    {
        $store = new Atomic_Option_Store(self::OPTION);
        $snapshot = $store->snapshot();

        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_VALIDATION
            );
        }

        if (Atomic_Option_Snapshot::REFUSED === $snapshot->state()) {
            return Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_VALIDATION);
        }

        $exists = $snapshot->is_present();
        $stored = $exists ? $snapshot->value() : null;
        $prepared = $this->prepare_peertube_append_from_state($descriptor, $exists, $stored);

        if (null === $prepared) {
            return Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_VALIDATION);
        }

        return $store->compare_exchange($snapshot, $prepared['value']);
    }

    /**
     * @return array{
     *   backends:array<string,array<string,mixed>>,
     *   diagnostics:list<string>
     * }
     */
    private function read(): array
    {
        $sentinel = new stdClass();
        $stored = get_option(self::OPTION, $sentinel);

        if ($sentinel === $stored) {
            return array(
                'backends'    => array(self::LOCAL_ID => self::local_descriptor('active')),
                'diagnostics' => array(),
            );
        }

        if (! is_array($stored)
            || self::VERSION !== self::positive_int($stored['version'] ?? null)
            || ! is_array($stored['backends'] ?? null)
        ) {
            return array(
                'backends'    => array(self::LOCAL_ID => self::local_descriptor('disabled')),
                'diagnostics' => array('registry_malformed'),
            );
        }

        $backends = array();
        $diagnostics = array();

        foreach ($stored['backends'] as $key => $descriptor) {
            $normalized = $this->normalize_stored_descriptor($key, $descriptor);
            if (null === $normalized) {
                $diagnostics[] = 'registry_descriptor_malformed';
                continue;
            }

            $backends[$normalized['id']] = $normalized;
        }

        if (! isset($backends[self::LOCAL_ID])
            || self::LOCAL_ID !== ($backends[self::LOCAL_ID]['type'] ?? '')
        ) {
            $backends[self::LOCAL_ID] = self::local_descriptor('disabled');
            $diagnostics[] = 'registry_missing_local';
        }

        return array(
            'backends'    => $backends,
            'diagnostics' => array_values(array_unique($diagnostics)),
        );
    }

    /** @return array<string, mixed>|null */
    private function normalize_stored_descriptor(mixed $key, mixed $descriptor): ?array
    {
        if (! is_string($key) || ! is_array($descriptor)) {
            return null;
        }

        if (self::contains_secret_material($descriptor)) {
            return null;
        }

        $id = Backend_Identity::sanitize($descriptor['id'] ?? null);
        $type = Backend_Identity::sanitize($descriptor['type'] ?? null);
        if ('' === $id || $key !== $id || '' === $type) {
            return null;
        }

        $state = self::state($descriptor['state'] ?? null);
        $label = self::strict_text($descriptor['label'] ?? '', 120);
        $config_version = self::positive_int($descriptor['config_version'] ?? null);
        if ('' === $state || '' === $label || $config_version < 1) {
            return null;
        }

        $default_destination = self::optional_opaque($descriptor['default_destination'] ?? null, 191);
        $secret_ref = self::optional_opaque($descriptor['secret_ref'] ?? null, 191);
        if (null === $default_destination || null === $secret_ref) {
            return null;
        }

        $config = self::strict_structure($descriptor['config'] ?? array());
        if (! is_array($config)) {
            return null;
        }

        if (self::LOCAL_ID === $id) {
            if (self::LOCAL_ID !== $type || '' !== $default_destination || '' !== $secret_ref || [] !== $config) {
                return null;
            }
        }

        if (self::PEERTUBE_TYPE === $type) {
            if (self::PEERTUBE_CONFIG_VERSION !== $config_version) {
                return null;
            }

            $peertube_config = self::sanitize_peertube_config($config);
            if (self::LOCAL_ID === $id || '' === $secret_ref || null === $peertube_config) {
                return null;
            }

            $config = $peertube_config;
        }

        return array(
            'id'                  => $id,
            'type'                => $type,
            'label'               => $label,
            'state'               => $state,
            'default_destination' => $default_destination,
            'secret_ref'          => $secret_ref,
            'config_version'      => $config_version,
            'config'              => $config,
        );
    }

    /** @return array<string, mixed>|null */
    private function sanitize_local_descriptor(array $descriptor): ?array
    {
        $state = self::state($descriptor['state'] ?? null);
        $label = self::strict_text($descriptor['label'] ?? '', 120);
        $config_version = self::positive_int($descriptor['config_version'] ?? null);
        $default_destination = self::optional_opaque($descriptor['default_destination'] ?? null, 191);
        $secret_ref = self::optional_opaque($descriptor['secret_ref'] ?? null, 191);
        $config = $descriptor['config'] ?? array();

        if ('' === $state
            || '' === $label
            || $config_version < 1
            || null === $default_destination
            || null === $secret_ref
            || '' !== $default_destination
            || '' !== $secret_ref
            || ! is_array($config)
            || [] !== $config
        ) {
            return null;
        }

        return array(
            'id'                  => self::LOCAL_ID,
            'type'                => self::LOCAL_ID,
            'label'               => $label,
            'state'               => $state,
            'default_destination' => '',
            'secret_ref'          => '',
            'config_version'      => $config_version,
            'config'              => array(),
        );
    }

    /** @return array<string, mixed>|null */
    private function sanitize_peertube_descriptor(mixed $descriptor): ?array
    {
        if (! is_array($descriptor)
            || ! self::has_exact_keys(
                $descriptor,
                array(
                    'id',
                    'type',
                    'label',
                    'state',
                    'default_destination',
                    'secret_ref',
                    'config_version',
                    'config',
                )
            )
            || self::contains_secret_material($descriptor)
        ) {
            return null;
        }

        $id = Backend_Identity::sanitize($descriptor['id']);
        $type = Backend_Identity::sanitize($descriptor['type']);
        $label = self::strict_text($descriptor['label'], 120);
        $state = self::state($descriptor['state']);
        $default_destination = self::optional_opaque($descriptor['default_destination'], 191);
        $secret_ref = self::optional_opaque($descriptor['secret_ref'], 191);
        $config_version = self::positive_int($descriptor['config_version']);
        $config = self::sanitize_peertube_config($descriptor['config']);

        if ('' === $id
            || self::LOCAL_ID === $id
            || self::PEERTUBE_TYPE !== $type
            || '' === $label
            || 'disabled' !== $state
            || null === $default_destination
            || null === $secret_ref
            || '' === $secret_ref
            || self::PEERTUBE_CONFIG_VERSION !== $config_version
            || null === $config
        ) {
            return null;
        }

        return array(
            'id'                  => $id,
            'type'                => self::PEERTUBE_TYPE,
            'label'               => $label,
            'state'               => $state,
            'default_destination' => $default_destination,
            'secret_ref'          => $secret_ref,
            'config_version'      => self::PEERTUBE_CONFIG_VERSION,
            'config'              => $config,
        );
    }

    /** @return array{origin:string}|null */
    private static function sanitize_peertube_config(mixed $config): ?array
    {
        if (! is_array($config) || ! self::has_exact_keys($config, array('origin'))) {
            return null;
        }

        $origin = PeerTube_Origin::sanitize($config['origin']);
        if ('' === $origin || $origin !== $config['origin']) {
            return null;
        }

        return array('origin' => $origin);
    }

    /**
     * @return array{
     *   exists:bool,
     *   before:array<string,mixed>|null,
     *   value:array<string,mixed>
     * }|null
     */
    private function prepare_peertube_append(mixed $descriptor): ?array
    {
        $sentinel = new stdClass();
        $stored = get_option(self::OPTION, $sentinel);
        $exists = $sentinel !== $stored;

        return $this->prepare_peertube_append_from_state(
            $descriptor,
            $exists,
            $exists ? $stored : null
        );
    }

    /**
     * Strictly model one disabled, create-only PeerTube append from a supplied
     * state. Atomic callers must supply that state from their raw snapshot.
     *
     * @return array{
     *   exists:bool,
     *   before:array<string,mixed>|null,
     *   value:array<string,mixed>
     * }|null
     */
    private function prepare_peertube_append_from_state(
        mixed $descriptor,
        bool $exists,
        mixed $stored
    ): ?array {
        $descriptor = $this->sanitize_peertube_descriptor($descriptor);
        if (null === $descriptor) {
            return null;
        }

        if (! $exists) {
            $before = null;
            $value = array(
                'version'  => self::VERSION,
                'backends' => array(self::LOCAL_ID => self::local_descriptor('active')),
            );
        } else {
            if (! is_array($stored)
                || self::VERSION !== self::positive_int($stored['version'] ?? null)
                || ! is_array($stored['backends'] ?? null)
                || self::contains_secret_material($stored)
            ) {
                return null;
            }

            $strict = self::strict_structure($stored);
            if (! is_array($strict) || $strict !== $stored) {
                return null;
            }

            // An existing v1 registry is expected to own its local identity.
            // Only a genuinely absent option receives the compatibility
            // default; never repair malformed stored state as a side effect of
            // adding a remote backend.
            if (! array_key_exists(self::LOCAL_ID, $stored['backends'])) {
                return null;
            }

            foreach ($stored['backends'] as $key => $stored_descriptor) {
                if (! $this->stored_descriptor_can_be_preserved($key, $stored_descriptor)) {
                    return null;
                }
            }

            $before = $stored;
            $value = $stored;
        }

        if (array_key_exists($descriptor['id'], $value['backends'])) {
            return null;
        }

        $value['backends'][$descriptor['id']] = $descriptor;

        return array(
            'exists' => $exists,
            'before' => $before,
            'value'  => $value,
        );
    }

    /**
     * Validate the known core envelope without reconstructing future state.
     *
     * A future type-specific descriptor can safely survive an append even
     * though this version cannot normalize it for use. Current local v1 and
     * PeerTube v1 descriptors still receive their exact known validation.
     */
    private function stored_descriptor_can_be_preserved(mixed $key, mixed $descriptor): bool
    {
        if (! is_string($key) || ! is_array($descriptor) || self::contains_secret_material($descriptor)) {
            return false;
        }

        $id = Backend_Identity::sanitize($descriptor['id'] ?? null);
        $type = Backend_Identity::sanitize($descriptor['type'] ?? null);
        $state = self::state($descriptor['state'] ?? null);
        $label = self::strict_text($descriptor['label'] ?? '', 120);
        $config_version = self::positive_int($descriptor['config_version'] ?? null);
        $default_destination = self::optional_opaque($descriptor['default_destination'] ?? null, 191);
        $secret_ref = self::optional_opaque($descriptor['secret_ref'] ?? null, 191);
        $config = self::strict_structure($descriptor['config'] ?? array());

        if ('' === $id
            || $key !== $id
            || '' === $type
            || '' === $state
            || '' === $label
            || $config_version < 1
            || null === $default_destination
            || null === $secret_ref
            || ! is_array($config)
        ) {
            return false;
        }

        if (self::LOCAL_ID === $id) {
            if (self::LOCAL_ID !== $type) {
                return false;
            }

            if (1 === $config_version
                && ('' !== $default_destination || '' !== $secret_ref || [] !== $config)
            ) {
                return false;
            }
        }

        if (self::PEERTUBE_TYPE === $type && self::PEERTUBE_CONFIG_VERSION === $config_version) {
            return self::LOCAL_ID !== $id
                && '' !== $secret_ref
                && null !== self::sanitize_peertube_config($config);
        }

        return true;
    }

    /** @return array<string, mixed> */
    private static function local_descriptor(string $state): array
    {
        return array(
            'id'                  => self::LOCAL_ID,
            'type'                => self::LOCAL_ID,
            'label'               => 'Local AWVP',
            'state'               => $state,
            'default_destination' => '',
            'secret_ref'          => '',
            'config_version'      => 1,
            'config'              => array(),
        );
    }

    /** @param list<string> $expected */
    private static function has_exact_keys(array $value, array $expected): bool
    {
        if (count($value) !== count($expected)) {
            return false;
        }

        foreach ($expected as $key) {
            if (! array_key_exists($key, $value)) {
                return false;
            }
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $expected, true)) {
                return false;
            }
        }

        return true;
    }

    private static function state(mixed $value): string
    {
        return is_string($value) && in_array($value, array('active', 'disabled', 'retired'), true)
            ? $value
            : '';
    }

    private static function positive_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return 0;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return false !== $integer ? (int) $integer : 0;
    }

    private static function strict_text(mixed $value, int $maximum): string
    {
        if (! is_string($value) || '' === $value) {
            return '';
        }

        $sanitized = sanitize_text_field($value);
        if ($sanitized !== $value) {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        return $length <= $maximum ? $value : '';
    }

    private static function optional_opaque(mixed $value, int $maximum): ?string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        $sanitized = Backend_Identity::sanitize_opaque($value, $maximum);
        return '' !== $sanitized ? $sanitized : null;
    }

    private function stored_contains_unwritable_configuration(): bool
    {
        $sentinel = new stdClass();
        $stored = get_option(self::OPTION, $sentinel);

        if ($sentinel === $stored || ! is_array($stored)) {
            return false;
        }

        $known_top_level = array('version' => true, 'backends' => true);
        foreach (array_keys($stored) as $key) {
            if (! is_string($key) || ! isset($known_top_level[$key])) {
                return true;
            }
        }

        $stored_version = self::positive_int($stored['version'] ?? null);
        if ($stored_version > 0 && self::VERSION !== $stored_version) {
            return true;
        }

        $stored_backends = $stored['backends'] ?? null;
        if (! is_array($stored_backends)) {
            return false;
        }

        $known_descriptor_fields = array(
            'id'                  => true,
            'type'                => true,
            'label'               => true,
            'state'               => true,
            'default_destination' => true,
            'secret_ref'          => true,
            'config_version'      => true,
            'config'              => true,
        );

        foreach ($stored_backends as $key => $descriptor) {
            if (! is_string($key) || self::LOCAL_ID !== $key) {
                return true;
            }

            if (! is_array($descriptor)) {
                continue;
            }

            foreach (array_keys($descriptor) as $field) {
                if (! is_string($field) || ! isset($known_descriptor_fields[$field])) {
                    return true;
                }
            }

            $config_version = self::positive_int($descriptor['config_version'] ?? null);
            if ($config_version > 1) {
                return true;
            }

            $config = $descriptor['config'] ?? array();
            if (is_array($config) && [] !== $config) {
                return true;
            }

            if ('' !== ($descriptor['default_destination'] ?? '')
                || '' !== ($descriptor['secret_ref'] ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function contains_secret_material(mixed $value, int $depth = 0): bool
    {
        if ($depth > 8 || ! is_array($value)) {
            return false;
        }

        $secret_keys = array(
            'password',
            'passwd',
            'token',
            'access_token',
            'refresh_token',
            'client_secret',
            'api_key',
            'apikey',
            'secret',
        );

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $secret_keys, true)) {
                return true;
            }

            if (is_array($item) && self::contains_secret_material($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private static function strict_structure(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
            return $value;
        }

        if (is_string($value)) {
            $sanitized = sanitize_text_field($value);
            return $sanitized === $value ? $value : null;
        }

        if (! is_array($value) || count($value) > 100) {
            return null;
        }

        $output = array();
        foreach ($value as $key => $item) {
            if (! is_int($key) && ! is_string($key)) {
                return null;
            }

            if (is_string($key)) {
                if (strlen($key) > 100 || 1 !== preg_match('/^[A-Za-z0-9_.-]+$/D', $key)) {
                    return null;
                }
            }

            $sanitized = self::strict_structure($item, $depth + 1);
            if (null === $sanitized && null !== $item) {
                return null;
            }

            $output[$key] = $sanitized;
        }

        return $output;
    }
}

// EOF: includes/Backend_Registry.php
