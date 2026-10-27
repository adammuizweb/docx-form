<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $statement = $pdo->prepare('SELECT k.CONSTRAINT_NAME,k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.COLUMN_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME');
    $statement->execute(['dxf_templates','current_version_id']);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $expected = ['dxf_template_current_version_fk','dxf_templates','current_version_id','dxf_template_versions','id','RESTRICT','RESTRICT'];
    if ($rows !== []) {
        if (count($rows) !== 1) throw new RuntimeException('DOCX Form current-version foreign key ownership is ambiguous.');
        $row = $rows[0];
        $actual = [(string)$row['CONSTRAINT_NAME'],(string)$row['TABLE_NAME'],(string)$row['COLUMN_NAME'],(string)$row['REFERENCED_TABLE_NAME'],(string)$row['REFERENCED_COLUMN_NAME'],(string)$row['DELETE_RULE'],(string)$row['UPDATE_RULE']];
        if ($actual !== $expected) throw new RuntimeException('DOCX Form current-version foreign key is incompatible.');
        return;
    }
    $named = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?');
    $named->execute(['dxf_template_current_version_fk']);
    if ((int)$named->fetchColumn() !== 0) throw new RuntimeException('DOCX Form current-version foreign key name is already owned by another constraint.');
    $index = $pdo->prepare("SELECT NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') columns_csv FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dxf_templates' AND INDEX_NAME='dxf_template_current_version' GROUP BY NON_UNIQUE");
    $index->execute();
    $indexRow = $index->fetch(PDO::FETCH_ASSOC);
    if (!is_array($indexRow) || (int)$indexRow['NON_UNIQUE'] !== 1 || (string)$indexRow['columns_csv'] !== 'current_version_id') throw new RuntimeException('DOCX Form current-version index is unavailable or incompatible.');
    $pdo->exec('ALTER TABLE `dxf_templates` ADD CONSTRAINT `dxf_template_current_version_fk` FOREIGN KEY (`current_version_id`) REFERENCES `dxf_template_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT');
    $statement->execute(['dxf_templates','current_version_id']);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || [(string)$row['CONSTRAINT_NAME'],(string)$row['TABLE_NAME'],(string)$row['COLUMN_NAME'],(string)$row['REFERENCED_TABLE_NAME'],(string)$row['REFERENCED_COLUMN_NAME'],(string)$row['DELETE_RULE'],(string)$row['UPDATE_RULE']] !== $expected) throw new RuntimeException('DOCX Form current-version foreign key could not be verified after creation.');
};
