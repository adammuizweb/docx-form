<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $required = [
        'dxf_templates' => ['id','title','slug','description','status','identity_mode','current_version_id','version','created_by','updated_by','created_at_utc','updated_at_utc'],
        'dxf_template_versions' => ['id','template_id','revision','display_title','display_description','descriptor_json','descriptor_sha256','docx_storage_name','docx_sha256','docx_size','source_pdf_storage_name','source_pdf_sha256','source_pdf_size','source_pdf_inline_validated','allow_source_view','allow_source_download','allow_docx_download','allow_print','allow_pdf','created_by','created_at_utc'],
        'dxf_submissions' => ['id','public_id','template_id','template_version_id','identity_mode','identity_json','identity_sha256','values_json','values_sha256','idempotency_hash','receipt_token_hash','receipt_expires_at_utc','receipt_revoked_at_utc','status','review_reason','reviewed_by','reviewed_at_utc','output_docx_storage_name','output_docx_sha256','output_pdf_storage_name','output_pdf_sha256','output_generated_at_utc','version','created_at_utc','updated_at_utc'],
        'dxf_otp_challenges' => ['id','public_id','template_id','template_version_id','token_hash','email','code_hash','attempt_count','max_attempts','expires_at_utc','verified_at_utc','used_at_utc','invalidated_at_utc','created_at_utc'],
        'dxf_submission_events' => ['id','submission_id','template_id','template_version_id','challenge_public_id','event_key','actor_user_id','ip_hash','user_agent_hash','metadata_json','dedupe_key','created_at_utc'],
        'dxf_rate_limits' => ['subject_hash','action_key','bucket_index','request_count','expires_at_utc'],
    ];
    $notNull = [
        'dxf_templates' => ['id','title','slug','status','identity_mode','version','created_by','updated_by','created_at_utc','updated_at_utc'],
        'dxf_template_versions' => ['id','template_id','revision','display_title','descriptor_json','descriptor_sha256','docx_storage_name','docx_sha256','docx_size','source_pdf_inline_validated','allow_source_view','allow_source_download','allow_docx_download','allow_print','allow_pdf','created_by','created_at_utc'],
        'dxf_submissions' => ['id','public_id','template_id','template_version_id','identity_mode','identity_json','identity_sha256','values_json','values_sha256','idempotency_hash','receipt_token_hash','receipt_expires_at_utc','status','version','created_at_utc','updated_at_utc'],
        'dxf_otp_challenges' => ['id','public_id','template_id','template_version_id','token_hash','email','code_hash','attempt_count','max_attempts','expires_at_utc','created_at_utc'],
        'dxf_submission_events' => ['id','template_id','template_version_id','event_key','ip_hash','user_agent_hash','metadata_json','created_at_utc'],
        'dxf_rate_limits' => ['subject_hash','action_key','bucket_index','request_count','expires_at_utc'],
    ];
    $ascii = [
        'dxf_templates' => ['slug','status','identity_mode'],
        'dxf_template_versions' => ['descriptor_sha256','docx_storage_name','docx_sha256','source_pdf_storage_name','source_pdf_sha256'],
        'dxf_submissions' => ['public_id','identity_mode','identity_sha256','values_sha256','idempotency_hash','receipt_token_hash','status','output_docx_storage_name','output_docx_sha256','output_pdf_storage_name','output_pdf_sha256'],
        'dxf_otp_challenges' => ['public_id','token_hash','code_hash'],
        'dxf_submission_events' => ['challenge_public_id','event_key','ip_hash','user_agent_hash','dedupe_key'],
        'dxf_rate_limits' => ['subject_hash','action_key'],
    ];
    $types = [
        'dxf_templates'=>['id'=>'bigint unsigned','title'=>'varchar(191)','slug'=>'varchar(100)','description'=>'text','status'=>'varchar(20)','identity_mode'=>'varchar(20)','current_version_id'=>'bigint unsigned','version'=>'int unsigned','created_by'=>'bigint unsigned','updated_by'=>'bigint unsigned','created_at_utc'=>'datetime','updated_at_utc'=>'datetime'],
        'dxf_template_versions'=>['id'=>'bigint unsigned','template_id'=>'bigint unsigned','revision'=>'int unsigned','display_title'=>'varchar(191)','display_description'=>'text','descriptor_json'=>'json','descriptor_sha256'=>'char(64)','docx_storage_name'=>'char(53)','docx_sha256'=>'char(64)','docx_size'=>'bigint unsigned','source_pdf_storage_name'=>'char(52)','source_pdf_sha256'=>'char(64)','source_pdf_size'=>'bigint unsigned','source_pdf_inline_validated'=>'tinyint(1)','allow_source_view'=>'tinyint(1)','allow_source_download'=>'tinyint(1)','allow_docx_download'=>'tinyint(1)','allow_print'=>'tinyint(1)','allow_pdf'=>'tinyint(1)','created_by'=>'bigint unsigned','created_at_utc'=>'datetime'],
        'dxf_submissions'=>['id'=>'bigint unsigned','public_id'=>'char(36)','template_id'=>'bigint unsigned','template_version_id'=>'bigint unsigned','identity_mode'=>'varchar(20)','identity_json'=>'json','identity_sha256'=>'char(64)','values_json'=>'json','values_sha256'=>'char(64)','idempotency_hash'=>'char(64)','receipt_token_hash'=>'char(64)','receipt_expires_at_utc'=>'datetime','receipt_revoked_at_utc'=>'datetime','status'=>'varchar(20)','review_reason'=>'varchar(1000)','reviewed_by'=>'bigint unsigned','reviewed_at_utc'=>'datetime','output_docx_storage_name'=>'char(53)','output_docx_sha256'=>'char(64)','output_pdf_storage_name'=>'char(52)','output_pdf_sha256'=>'char(64)','output_generated_at_utc'=>'datetime','version'=>'int unsigned','created_at_utc'=>'datetime','updated_at_utc'=>'datetime'],
        'dxf_otp_challenges'=>['id'=>'bigint unsigned','public_id'=>'char(36)','template_id'=>'bigint unsigned','template_version_id'=>'bigint unsigned','token_hash'=>'char(64)','email'=>'varchar(254)','code_hash'=>'varchar(255)','attempt_count'=>'tinyint unsigned','max_attempts'=>'tinyint unsigned','expires_at_utc'=>'datetime','verified_at_utc'=>'datetime','used_at_utc'=>'datetime','invalidated_at_utc'=>'datetime','created_at_utc'=>'datetime'],
        'dxf_submission_events'=>['id'=>'bigint unsigned','submission_id'=>'bigint unsigned','template_id'=>'bigint unsigned','template_version_id'=>'bigint unsigned','challenge_public_id'=>'char(36)','event_key'=>'varchar(80)','actor_user_id'=>'bigint unsigned','ip_hash'=>'char(64)','user_agent_hash'=>'char(64)','metadata_json'=>'json','dedupe_key'=>'char(64)','created_at_utc'=>'datetime'],
        'dxf_rate_limits'=>['subject_hash'=>'char(64)','action_key'=>'varchar(80)','bucket_index'=>'bigint unsigned','request_count'=>'int unsigned','expires_at_utc'=>'datetime'],
    ];
    $nullable = [
        'dxf_templates'=>['description','current_version_id'],
        'dxf_template_versions'=>['display_description','source_pdf_storage_name','source_pdf_sha256','source_pdf_size'],
        'dxf_submissions'=>['receipt_revoked_at_utc','review_reason','reviewed_by','reviewed_at_utc','output_docx_storage_name','output_docx_sha256','output_pdf_storage_name','output_pdf_sha256','output_generated_at_utc'],
        'dxf_otp_challenges'=>['verified_at_utc','used_at_utc','invalidated_at_utc'],
        'dxf_submission_events'=>['submission_id','challenge_public_id','actor_user_id','dedupe_key'],
        'dxf_rate_limits'=>[],
    ];
    $defaults = [
        'dxf_templates'=>['status'=>'draft','version'=>'1'],
        'dxf_template_versions'=>['source_pdf_inline_validated'=>'0','allow_source_view'=>'0','allow_source_download'=>'0','allow_docx_download'=>'1','allow_print'=>'1','allow_pdf'=>'0'],
        'dxf_submissions'=>['status'=>'pending','version'=>'1'],
        'dxf_otp_challenges'=>['attempt_count'=>'0'],
        'dxf_submission_events'=>[],
        'dxf_rate_limits'=>['request_count'=>'1'],
    ];
    $observedTypes = [];
    $column = $pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    foreach ($required as $table => $columns) {
        $column->execute([$table]);
        $available = [];
        foreach ($column->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $available[(string)$row['COLUMN_NAME']] = $row;
        if (array_keys($available) !== $columns) throw new RuntimeException('DOCX Form schema columns are incompatible: ' . $table);
        foreach ($available as $name => $definition) {
            $observedTypes[$table][$name] = strtolower((string)$definition['DATA_TYPE']);
            if (str_ends_with($name, '_utc') && strtolower((string)$definition['DATA_TYPE']) !== 'datetime') throw new RuntimeException('DOCX Form UTC column must use DATETIME: ' . $table . '.' . $name);
            $actualType = strtolower((string)$definition['COLUMN_TYPE']);
            $actualType = preg_replace('/\A(bigint|int|tinyint)\((?:3|10|20)\)(.*)\z/', '$1$2', $actualType) ?? $actualType;
            $expectedType = $types[$table][$name];
            if ($expectedType === 'json') {
                if (!in_array(strtolower((string)$definition['DATA_TYPE']), ['json','longtext'], true)) throw new RuntimeException('DOCX Form JSON column is incompatible: ' . $table . '.' . $name);
            } elseif ($actualType !== $expectedType) throw new RuntimeException('DOCX Form column type is incompatible: ' . $table . '.' . $name . ' (expected ' . $expectedType . ', got ' . $actualType . ')');
            if ((($definition['IS_NULLABLE'] ?? '') === 'YES') !== in_array($name, $nullable[$table], true)) throw new RuntimeException('DOCX Form column nullability is incompatible: ' . $table . '.' . $name);
            $expectedDefault = $defaults[$table][$name] ?? null;
            $actualDefault = $definition['COLUMN_DEFAULT'];
            if ($actualDefault !== null) $actualDefault = trim((string)$actualDefault, "'");
            if ($actualDefault === 'NULL' && in_array($name, $nullable[$table], true)) $actualDefault = null;
            if ($actualDefault !== $expectedDefault) throw new RuntimeException('DOCX Form column default is incompatible: ' . $table . '.' . $name);
            if (strtolower((string)$definition['EXTRA']) !== ($name === 'id' ? 'auto_increment' : '')) throw new RuntimeException('DOCX Form identity increment is incompatible: ' . $table . '.' . $name);
            if ($expectedType !== 'json' && in_array(strtolower((string)$definition['DATA_TYPE']), ['char','varchar','text','longtext'], true) && !in_array($name, $ascii[$table], true)
                && (($definition['CHARACTER_SET_NAME'] ?? '') !== 'utf8mb4' || ($definition['COLLATION_NAME'] ?? '') !== 'utf8mb4_unicode_ci')) throw new RuntimeException('DOCX Form text collation is incompatible: ' . $table . '.' . $name);
        }
        if (isset($available['id'])) {
            $id = $available['id'];
            if (!str_starts_with(strtolower((string)$id['COLUMN_TYPE']), 'bigint') || !str_contains(strtolower((string)$id['COLUMN_TYPE']), 'unsigned') || !str_contains(strtolower((string)$id['EXTRA']), 'auto_increment')) throw new RuntimeException('DOCX Form identity column is incompatible: ' . $table . '.id');
        }
        foreach ($notNull[$table] as $name) if (($available[$name]['IS_NULLABLE'] ?? '') !== 'NO') throw new RuntimeException('DOCX Form required column is nullable: ' . $table . '.' . $name);
        foreach ($ascii[$table] as $name) if (($available[$name]['CHARACTER_SET_NAME'] ?? '') !== 'ascii' || ($available[$name]['COLLATION_NAME'] ?? '') !== 'ascii_bin') throw new RuntimeException('DOCX Form identifier column must use ascii_bin: ' . $table . '.' . $name);
    }
    $marks = implode(',', array_fill(0, count($required), '?'));
    $tables = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $marks . ") AND ENGINE='InnoDB' AND TABLE_COLLATION LIKE 'utf8mb4%'");
    $tables->execute(array_keys($required));
    if ((int)$tables->fetchColumn() !== count($required)) throw new RuntimeException('DOCX Form tables must use InnoDB and utf8mb4.');
    foreach ([
        ['dxf_templates','PRIMARY','id',0],['dxf_templates','dxf_template_slug_uq','slug',0],['dxf_templates','dxf_template_public','status,slug',1],['dxf_templates','dxf_template_current_version','current_version_id',1],
        ['dxf_template_versions','PRIMARY','id',0],['dxf_template_versions','dxf_version_revision_uq','template_id,revision',0],
        ['dxf_submissions','PRIMARY','id',0],['dxf_submissions','dxf_submission_public_uq','public_id',0],['dxf_submissions','dxf_submission_receipt_uq','receipt_token_hash',0],['dxf_submissions','dxf_submission_idempotency_uq','template_id,idempotency_hash',0],['dxf_submissions','dxf_submission_queue','status,created_at_utc,id',1],['dxf_submissions','dxf_submission_receipt_expiry','receipt_expires_at_utc,receipt_revoked_at_utc,id',1],['dxf_submissions','dxf_submission_output_retention','output_generated_at_utc,id',1],['dxf_submissions','dxf_submission_version','template_version_id',1],
        ['dxf_otp_challenges','PRIMARY','id',0],['dxf_otp_challenges','dxf_otp_public_uq','public_id',0],['dxf_otp_challenges','dxf_otp_token_uq','token_hash',0],['dxf_otp_challenges','dxf_otp_expiry','expires_at_utc',1],['dxf_otp_challenges','dxf_otp_resend','template_id,email,created_at_utc,id',1],['dxf_otp_challenges','dxf_otp_version','template_version_id',1],
        ['dxf_submission_events','PRIMARY','id',0],['dxf_submission_events','dxf_event_submission','submission_id,id',1],['dxf_submission_events','dxf_event_template','template_id,created_at_utc,id',1],['dxf_submission_events','dxf_event_dedupe_uq','dedupe_key',0],['dxf_submission_events','dxf_event_version','template_version_id',1],
        ['dxf_rate_limits','PRIMARY','subject_hash,action_key,bucket_index',0],['dxf_rate_limits','dxf_rate_expiry','expires_at_utc',1],
    ] as [$table,$index,$expected,$nonUnique]) {
        $statement = $pdo->prepare("SELECT NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') columns_csv FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? GROUP BY NON_UNIQUE LIMIT 1");
        $statement->execute([$table,$index]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['NON_UNIQUE'] !== $nonUnique || (string)$row['columns_csv'] !== $expected) throw new RuntimeException('DOCX Form index is incompatible: ' . $table . '.' . $index);
    }
    $foreignKeys = [
        'dxf_template_current_version_fk'=>['dxf_templates','current_version_id','dxf_template_versions','id'],
        'dxf_version_template_fk'=>['dxf_template_versions','template_id','dxf_templates','id'],
        'dxf_submission_template_fk'=>['dxf_submissions','template_id','dxf_templates','id'],
        'dxf_submission_version_fk'=>['dxf_submissions','template_version_id','dxf_template_versions','id'],
        'dxf_otp_template_fk'=>['dxf_otp_challenges','template_id','dxf_templates','id'],
        'dxf_otp_version_fk'=>['dxf_otp_challenges','template_version_id','dxf_template_versions','id'],
        'dxf_event_submission_fk'=>['dxf_submission_events','submission_id','dxf_submissions','id'],
        'dxf_event_template_fk'=>['dxf_submission_events','template_id','dxf_templates','id'],
        'dxf_event_version_fk'=>['dxf_submission_events','template_version_id','dxf_template_versions','id'],
    ];
    $foreignStatement = $pdo->prepare('SELECT k.CONSTRAINT_NAME,k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND (k.TABLE_NAME IN (' . $marks . ') OR k.REFERENCED_TABLE_NAME IN (' . $marks . ')) ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION');
    $foreignStatement->execute([...array_keys($required),...array_keys($required)]);
    $actualForeignKeys = [];
    foreach ($foreignStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $name = (string)$row['CONSTRAINT_NAME'];
        if (isset($actualForeignKeys[$name])) throw new RuntimeException('DOCX Form does not support composite or duplicate owned foreign keys: ' . $name);
        $actualForeignKeys[$name] = [(string)$row['TABLE_NAME'],(string)$row['COLUMN_NAME'],(string)$row['REFERENCED_TABLE_NAME'],(string)$row['REFERENCED_COLUMN_NAME']];
        if ((string)$row['DELETE_RULE'] !== 'RESTRICT' || (string)$row['UPDATE_RULE'] !== 'RESTRICT') throw new RuntimeException('DOCX Form foreign-key rules are incompatible: ' . $name);
    }
    ksort($actualForeignKeys, SORT_STRING); ksort($foreignKeys, SORT_STRING);
    if ($actualForeignKeys !== $foreignKeys) throw new RuntimeException('DOCX Form foreign-key ownership or definitions are incompatible.');

    $normalizeCheck = static function (string $clause): string {
        $clause = strtolower($clause);
        $clause = preg_replace('/_[a-z0-9]+(?=\')/', '', $clause) ?? $clause;
        return str_replace(['`','(',')',' ',"\t","\r","\n"], '', $clause);
    };
    $expectedChecks = [
        'dxf_template_status_ck'=>['dxf_templates',"status in ('draft','published','archived')"],
        'dxf_template_identity_ck'=>['dxf_templates',"identity_mode in ('email_otp','self_declared')"],
        'dxf_version_toggles_ck'=>['dxf_template_versions','source_pdf_inline_validated in (0,1) and allow_source_view in (0,1) and allow_source_download in (0,1) and allow_docx_download in (0,1) and allow_print in (0,1) and allow_pdf in (0,1)'],
        'dxf_submission_status_ck'=>['dxf_submissions',"status in ('pending','approved','rejected')"],
    ];
    $checkSupport = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='information_schema' AND TABLE_NAME='CHECK_CONSTRAINTS'");
    if ((int)$checkSupport->fetchColumn() > 0) {
        $checkStatement = $pdo->prepare('SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME IN (' . $marks . ") AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.TABLE_NAME,tc.CONSTRAINT_NAME");
        $checkStatement->execute(array_keys($required));
        $actualChecks = [];
        foreach ($checkStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $actualChecks[(string)$row['CONSTRAINT_NAME']] = [(string)$row['TABLE_NAME'],$normalizeCheck((string)$row['CHECK_CLAUSE'])];
        $allowedChecks = [];
        foreach ($expectedChecks as $name => [$table,$clause]) {
            if (($actualChecks[$name] ?? null) !== [$table,$normalizeCheck($clause)]) throw new RuntimeException('DOCX Form check constraint is semantically incompatible: ' . $name);
            $allowedChecks[$name] = true;
        }
        foreach (['dxf_template_versions'=>['descriptor_json'],'dxf_submissions'=>['identity_json','values_json'],'dxf_submission_events'=>['metadata_json']] as $table => $columns) {
            foreach ($columns as $name) {
                if (($observedTypes[$table][$name] ?? '') !== 'longtext') continue;
                $expected = $normalizeCheck('json_valid(' . $name . ')');
                $matched = false;
                foreach ($actualChecks as $constraint => [$checkTable,$clause]) if ($checkTable === $table && $clause === $expected) { $allowedChecks[$constraint] = true; $matched = true; }
                if (!$matched) throw new RuntimeException('DOCX Form MariaDB JSON_VALID constraint is missing or incompatible: ' . $table . '.' . $name);
            }
        }
        foreach ($actualChecks as $name => $_definition) if (!isset($allowedChecks[$name])) throw new RuntimeException('DOCX Form contains an unexpected owned check constraint: ' . $name);
    } else {
        $statement = $pdo->prepare("SELECT TABLE_NAME,COUNT(*) count_rows FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK' AND CONSTRAINT_NAME=? GROUP BY TABLE_NAME");
        foreach ($expectedChecks as $name => [$table]) { $statement->execute([$name]); $row=$statement->fetch(PDO::FETCH_ASSOC); if (!is_array($row) || $row['TABLE_NAME'] !== $table || (int)$row['count_rows'] !== 1) throw new RuntimeException('DOCX Form check constraint is missing: ' . $name); }
    }
};
