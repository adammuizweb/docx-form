<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $verifyFoundation = require __DIR__ . '/0003-verify-schema.php';
    $verifyFoundation($pdo);

    $expected = [
        'namespace_id' => ['varchar(100)', 'NO', null, 'ascii', 'ascii_bin', ''],
        'definition_id' => ['varchar(100)', 'NO', null, 'ascii', 'ascii_bin', ''],
        'release_id' => ['varchar(100)', 'NO', null, 'ascii', 'ascii_bin', ''],
        'template_id' => ['bigint unsigned', 'NO', null, null, null, ''],
        'template_version_id' => ['bigint unsigned', 'NO', null, null, null, ''],
        'projection_sha256' => ['char(64)', 'NO', null, 'ascii', 'ascii_bin', ''],
        'created_by' => ['bigint unsigned', 'NO', null, null, null, ''],
        'updated_by' => ['bigint unsigned', 'NO', null, null, null, ''],
        'created_at_utc' => ['datetime', 'NO', null, null, null, ''],
        'updated_at_utc' => ['datetime', 'NO', null, null, null, ''],
    ];
    $statement = $pdo->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dxf_import_ledger' ORDER BY ORDINAL_POSITION");
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (array_column($rows, 'COLUMN_NAME') !== array_keys($expected)) throw new RuntimeException('DOCX Form import-ledger columns are incompatible.');
    foreach ($rows as $row) {
        $name = (string)$row['COLUMN_NAME'];
        $actualType = strtolower((string)$row['COLUMN_TYPE']);
        $actualType = preg_replace('/\A(bigint)\(20\)(.*)\z/', '$1$2', $actualType) ?? $actualType;
        $actual = [$actualType,(string)$row['IS_NULLABLE'],$row['COLUMN_DEFAULT'],$row['CHARACTER_SET_NAME'],$row['COLLATION_NAME'],strtolower((string)$row['EXTRA'])];
        if ($actual !== $expected[$name]) throw new RuntimeException('DOCX Form import-ledger column is incompatible: ' . $name);
    }
    $table = $pdo->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dxf_import_ledger'")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($table) || (string)$table['ENGINE'] !== 'InnoDB' || !str_starts_with((string)$table['TABLE_COLLATION'], 'utf8mb4_')) throw new RuntimeException('DOCX Form import ledger must use InnoDB and utf8mb4.');
    foreach ([
        ['PRIMARY','namespace_id,definition_id',0],
        ['dxf_import_template_uq','template_id',0],
        ['dxf_import_version','template_version_id',1],
    ] as [$index,$columns,$nonUnique]) {
        $query = $pdo->prepare("SELECT NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') columns_csv FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dxf_import_ledger' AND INDEX_NAME=? GROUP BY NON_UNIQUE");
        $query->execute([$index]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['NON_UNIQUE'] !== $nonUnique || (string)$row['columns_csv'] !== $columns) throw new RuntimeException('DOCX Form import-ledger index is incompatible: ' . $index);
    }
    $foreignKeys = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='dxf_import_ledger' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn();
    if ((int)$foreignKeys !== 0) throw new RuntimeException('DOCX Form import ledger must not add unmanaged foreign keys.');
};
