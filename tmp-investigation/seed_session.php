<?php
// Seed a phpMyAdmin-like session file with a bloated payload that mimics what
// might be cached after a large CSV import (per-column metadata, full row
// snapshots for "edit", a navigation tree, etc.).
//
// We deliberately make the unserialize cost asymmetric to the on-disk size:
// plain PHP-serialized arrays expand into many zvals + HashTable buckets at
// unserialize time, so a 5–10 MB session file balloons to >>26 MB heap.

$session_dir = __DIR__ . '/sessions';
$session_id  = 'oomrepro1234';

session_save_path($session_dir);
session_id($session_id);
session_name('PHPSESSID');

// Build the session payload without going through session_start() so we don't
// allocate the huge structure in this seeder process.
$rows = 50000;
$cols = 83;

$column_meta = [];
for ($c = 0; $c < $cols; $c++) {
    $column_meta["col_{$c}"] = [
        'type'        => 'varchar',
        'length'      => 255,
        'nullable'    => true,
        'default'     => null,
        'collation'   => 'utf8mb4_unicode_ci',
        'comment'     => "auto-imported column #{$c}",
        'extra'       => '',
    ];
}

// "Recent edits cache" — phpMyAdmin really does cache rows in $_SESSION for
// the inline-edit feature ($cfg['RowActionLinks']). Simulate that with a
// fraction of the imported rows.
$recent_rows = [];
for ($r = 0; $r < 4000; $r++) {
    $row = [];
    for ($c = 0; $c < $cols; $c++) {
        $row["col_{$c}"] = "row{$r}_col{$c}_value_padding_padding";
    }
    $recent_rows[] = $row;
}

$payload = [
    'pma_db'           => 'imported_db',
    'pma_table'        => 'imported_table',
    'pma_columns'      => $column_meta,
    'pma_recent_rows'  => $recent_rows,
    'pma_navi_tree'    => array_fill(0, 1000, ['db' => 'imported_db', 'tables' => array_fill(0, 50, 'imported_table')]),
    'pma_token'        => bin2hex(random_bytes(16)),
    'tableStructure'   => [
        'rows_total'   => $rows,
        'columns'      => $cols,
        'imported_at'  => date(DATE_ATOM),
    ],
];

// PHP's default session serializer is "php" — key|serialized\nkey|... — but
// we'll just use the "php_serialize" format which is plain serialize() output
// since it makes the session file directly inspectable.
ini_set('session.serialize_handler', 'php_serialize');
$blob = serialize($payload);

file_put_contents("{$session_dir}/sess_{$session_id}", $blob);
printf("seeded %s : %.1f MiB on disk\n",
    "{$session_dir}/sess_{$session_id}",
    strlen($blob) / 1048576
);
