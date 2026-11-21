<#1>
<?php

/**
 * ui_uihk_seo_data: per-object-instance SEO metadata (title, description,
 * robots directive, sitemap priority/frequency, resolved object type),
 * keyed by ref_id + secondary_id + lang.
 */
$table_data = "ui_uihk_seo_data";

if (!$ilDB->tableExists($table_data)) {
    $ilDB->createTable(
        $table_data,
        [
            "ref_id" => [
                "type" => "integer",
                "length" => 8,
                "notnull" => true,
            ],
            "secondary_id" => [
                "type" => "integer",
                "length" => 8,
                "notnull" => true,
            ],
            "lang" => [
                "type" => "text",
                "length" => 5,
                "fixed" => false,
                "notnull" => true,
            ],
            "permalink" => [
                "type" => "text",
                "length" => 255,
                "fixed" => false,
                "notnull" => true,
            ],
            "title" => [
                "type" => "text",
                "length" => 255,
                "fixed" => false,
                "notnull" => true,
            ],
            "description" => [
                "type" => "text",
                "length" => 255,
                "fixed" => false,
                "notnull" => true,
            ],
            // Robots meta tag.
            "robots" => [
                "type" => "text",
                "length" => 20,
                "fixed" => false,
                "notnull" => false,
                "default" => "index, follow",
            ],
            // Page priority.
            "priority" => [
                "type" => "integer",
                "length" => 4,
                "notnull" => false,
                "default" => 5,
            ],
            // Page change frequency: Always, Hourly, Daily, Weekly, Monthly, Yearly, Never.
            "frequency" => [
                "type" => "text",
                "length" => 10,
                "fixed" => false,
                "notnull" => false,
                "default" => "monthly",
            ],
            // Resolved object type, e.g. from object_data.type.
            "type" => [
                "type" => "text",
                "length" => 5,
                "fixed" => false,
                "notnull" => false,
            ],
        ]
    );
    $ilDB->addPrimaryKey($table_data, ["ref_id", "secondary_id", "lang"]);
}

if (!$ilDB->uniqueConstraintExists($table_data, ["permalink"])) {
    $ilDB->addUniqueConstraint($table_data, ["permalink"], "i1");
}

/**
 * ui_uihk_seo_history: append-only history of every permalink a given
 * ref_id + secondary_id + lang combination has had, with a title/
 * description snapshot and the Unix timestamp it was recorded at. This
 * table has no primary key, unique constraint, or index.
 */
$table_history = "ui_uihk_seo_history";

if (!$ilDB->tableExists($table_history)) {
    $ilDB->createTable(
        $table_history,
        [
            "permalink" => [
                "type" => "text",
                "length" => 255,
                "fixed" => false,
                "notnull" => true,
            ],
            "ref_id" => [
                "type" => "integer",
                "length" => 8,
                "notnull" => true,
            ],
            "secondary_id" => [
                "type" => "integer",
                "length" => 8,
                "notnull" => true,
            ],
            "lang" => [
                "type" => "text",
                "length" => 5,
                "fixed" => false,
                "notnull" => true,
            ],
            "timestamp" => [
                "type" => "integer",
                "length" => 8,
                "notnull" => true,
            ],
            // Title snapshot at the time this permalink was recorded.
            "title" => [
                "type" => "text",
                "length" => 255,
                "fixed" => false,
                "notnull" => false,
            ],
            // Description snapshot at the time this permalink was recorded.
            "description" => [
                "type" => "text",
                "length" => 255,
                "fixed" => false,
                "notnull" => false,
            ],
        ]
    );
}

?>
