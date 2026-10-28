<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $normalizeCheck = static function (string $clause): string {
        $clause = strtolower($clause);
        $clause = preg_replace('/_[a-z0-9]+(?=\')/', '', $clause) ?? $clause;
        return str_replace(['`','(',')',' ',"\t","\r","\n"], '', $clause);
    };
    $checkDefinition = static function (string $name) use ($pdo, $normalizeCheck): ?array {
        $statement = $pdo->prepare("SELECT tc.TABLE_NAME,tc.CONSTRAINT_TYPE,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc LEFT JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.CONSTRAINT_NAME=?");
        $statement->execute([$name]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) > 1) throw new RuntimeException('DOCX Form check constraint name is not unique: ' . $name);
        if ($rows !== [] && (string)$rows[0]['CONSTRAINT_TYPE'] !== 'CHECK') throw new RuntimeException('DOCX Form constraint has an incompatible type: ' . $name);
        return $rows === [] ? null : [(string)$rows[0]['TABLE_NAME'],$normalizeCheck((string)$rows[0]['CHECK_CLAUSE'])];
    };
    $verifyCheck = static function (string $name, string $table, string $clause) use ($checkDefinition, $normalizeCheck): void {
        if ($checkDefinition($name) !== [$table,$normalizeCheck($clause)]) throw new RuntimeException('DOCX Form check constraint is incompatible: ' . $name);
    };

    foreach (['dxf_template_versions','dxf_submissions'] as $table) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$table]);
        if ((int)$statement->fetchColumn() !== 1) throw new RuntimeException('DOCX Form workflow migration requires table: ' . $table);
    }

    $column = $pdo->query("SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dxf_template_versions' AND COLUMN_NAME='workflow_mode'")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($column)) {
        $pdo->exec("ALTER TABLE `dxf_template_versions` ADD COLUMN `workflow_mode` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'operator_review' AFTER `display_description`");
        $column = $pdo->query("SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dxf_template_versions' AND COLUMN_NAME='workflow_mode'")->fetch(PDO::FETCH_ASSOC);
    }
    $default = is_array($column) && $column['COLUMN_DEFAULT'] !== null ? trim((string)$column['COLUMN_DEFAULT'], "'") : null;
    if (!is_array($column) || strtolower((string)$column['COLUMN_TYPE']) !== 'varchar(20)' || (string)$column['IS_NULLABLE'] !== 'NO'
        || $default !== 'operator_review' || (string)$column['CHARACTER_SET_NAME'] !== 'ascii' || (string)$column['COLLATION_NAME'] !== 'ascii_bin'
        || (string)$column['EXTRA'] !== '') throw new RuntimeException('DOCX Form workflow_mode column is incompatible.');

    $workflowClause = "workflow_mode in ('operator_review','immediate_download')";
    $workflowCheck = $checkDefinition('dxf_version_workflow_ck');
    if ($workflowCheck === null) $pdo->exec("ALTER TABLE `dxf_template_versions` ADD CONSTRAINT `dxf_version_workflow_ck` CHECK (`workflow_mode` IN ('operator_review','immediate_download'))");
    else $verifyCheck('dxf_version_workflow_ck', 'dxf_template_versions', $workflowClause);

    $oldStatus = "status in ('pending','approved','rejected')";
    $newStatus = "status in ('pending','approved','rejected','ready')";
    $statusCheck = $checkDefinition('dxf_submission_status_ck');
    if ($statusCheck !== null && $statusCheck !== ['dxf_submissions',$normalizeCheck($oldStatus)] && $statusCheck !== ['dxf_submissions',$normalizeCheck($newStatus)]) {
        throw new RuntimeException('DOCX Form submission status check is incompatible.');
    }
    if ($statusCheck === ['dxf_submissions',$normalizeCheck($oldStatus)]) {
        $isMariaDb = stripos((string)$pdo->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
        $pdo->exec('ALTER TABLE `dxf_submissions` ' . ($isMariaDb ? 'DROP CONSTRAINT' : 'DROP CHECK') . ' `dxf_submission_status_ck`');
        $statusCheck = null;
    }
    if ($statusCheck === null) $pdo->exec("ALTER TABLE `dxf_submissions` ADD CONSTRAINT `dxf_submission_status_ck` CHECK (`status` IN ('pending','approved','rejected','ready'))");

    $verifyCheck('dxf_version_workflow_ck', 'dxf_template_versions', $workflowClause);
    $verifyCheck('dxf_submission_status_ck', 'dxf_submissions', $newStatus);
};
