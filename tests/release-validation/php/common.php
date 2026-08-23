<?php
declare(strict_types=1);

function awvp_release_fail(string $message): never
{
    fwrite(STDERR, "AWVP_RELEASE_TEST_FAIL: {$message}\n");
    exit(1);
}

function awvp_release_assert(bool $condition, string $message): void
{
    if (! $condition) {
        awvp_release_fail($message);
    }
}

function awvp_release_env(string $name): string
{
    $value = getenv($name);
    if (false === $value || '' === $value) {
        awvp_release_fail("Required test environment variable {$name} is missing.");
    }
    return (string) $value;
}

function awvp_release_env_int(string $name): int
{
    return (int) awvp_release_env($name);
}

function awvp_release_table_exists(string $table): bool
{
    global $wpdb;
    $found = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    );
    return $table === (string) $found;
}

/** @return array<string, array{unique:bool,columns:list<string>}> */
function awvp_release_indexes(string $table): array
{
    global $wpdb;

    $rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
    awvp_release_assert(is_array($rows), "SHOW INDEX failed for {$table}");

    $grouped = array();
    foreach ($rows as $row) {
        $name = (string) ($row['Key_name'] ?? '');
        if ('' === $name) {
            continue;
        }
        $seq = (int) ($row['Seq_in_index'] ?? 0);
        $grouped[$name]['unique'] = 0 === (int) ($row['Non_unique'] ?? 1);
        $grouped[$name]['columns'][$seq] = (string) ($row['Column_name'] ?? '');
    }

    $indexes = array();
    foreach ($grouped as $name => $definition) {
        ksort($definition['columns']);
        $indexes[$name] = array(
            'unique'  => (bool) $definition['unique'],
            'columns' => array_values($definition['columns']),
        );
    }

    return $indexes;
}

/** @return array<string, array<string, mixed>> */
function awvp_release_columns(string $table): array
{
    global $wpdb;

    $rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
    awvp_release_assert(is_array($rows), "SHOW COLUMNS failed for {$table}");

    $columns = array();
    foreach ($rows as $row) {
        $field = (string) ($row['Field'] ?? '');
        if ('' !== $field) {
            $columns[$field] = $row;
        }
    }
    return $columns;
}

function awvp_release_assert_index(
    array $indexes,
    string $name,
    bool $unique,
    array $columns
): void {
    awvp_release_assert(isset($indexes[$name]), "Missing index {$name}");
    awvp_release_assert(
        $unique === (bool) $indexes[$name]['unique'],
        "Index {$name} uniqueness mismatch"
    );
    awvp_release_assert(
        $columns === $indexes[$name]['columns'],
        "Index {$name} columns mismatch"
    );
}
