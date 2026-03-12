<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sourceDb = $argv[1] ?? 'emta_backup';
$targetDb = $argv[2] ?? 'emta_db';

$rows = DB::select("
    SELECT
        b.`ac_se_de_ca-id` AS src_id,
        b.`ac_se_de_ca-tenant_id` AS tenant_id,
        b.`ac_se_de_ca-ac_se_de_id-fk` AS ac_se_de_id,
        b.`ac_se_de_ca-ca_co_id-fk` AS ca_co_id,
        b.`ac_se_de_ca-especif_por_fuente` AS especif_por_fuente,
        b.`ac_se_de_ca-activo` AS activo,
        t.`ac_se_de_ca-id` AS tgt_id,
        t.`ac_se_de_ca-especif_por_fuente` AS tgt_especif_por_fuente,
        t.`ac_se_de_ca-activo` AS tgt_activo
    FROM {$sourceDb}.`accion_set_detalle_canal_cfg` b
    INNER JOIN {$targetDb}.`accion_set_detalle_cfg` d
        ON d.`ac_se_de-id` = b.`ac_se_de_ca-ac_se_de_id-fk`
       AND (d.`ac_se_de-tenant_id` <=> b.`ac_se_de_ca-tenant_id`)
    LEFT JOIN {$targetDb}.`accion_set_detalle_canal_cfg` t
        ON t.`ac_se_de_ca-ac_se_de_id-fk` = b.`ac_se_de_ca-ac_se_de_id-fk`
       AND (t.`ac_se_de_ca-tenant_id` <=> b.`ac_se_de_ca-tenant_id`)
       AND t.`ac_se_de_ca-ca_co_id-fk` = b.`ac_se_de_ca-ca_co_id-fk`
");

$escape = static function ($value): string {
    if ($value === null) {
        return 'NULL';
    }
    $v = (string) $value;
    $v = str_replace("'", "''", $v);

    return "'".$v."'";
};

$inserts = [];
$updates = [];

foreach ($rows as $r) {
    $needsInsert = empty($r->tgt_id);
    $needsUpdate = ! $needsInsert
        && ((string) $r->tgt_especif_por_fuente !== (string) $r->especif_por_fuente
            || (string) $r->tgt_activo !== (string) $r->activo);

    if ($needsInsert) {
        $id = $r->src_id ?: uniqid('ASDC');
        $inserts[] = "INSERT INTO `accion_set_detalle_canal_cfg` (`ac_se_de_ca-id`, `ac_se_de_ca-tenant_id`, `ac_se_de_ca-ac_se_de_id-fk`, `ac_se_de_ca-ca_co_id-fk`, `ac_se_de_ca-especif_por_fuente`, `ac_se_de_ca-activo`) VALUES ("
            .$escape($id).', '.$escape($r->tenant_id).', '.$escape($r->ac_se_de_id).', '.$escape($r->ca_co_id).', '.$escape($r->especif_por_fuente).', '.$escape($r->activo).");";
    } elseif ($needsUpdate) {
        $updates[] = "UPDATE `accion_set_detalle_canal_cfg` SET `ac_se_de_ca-especif_por_fuente` = ".$escape($r->especif_por_fuente).", `ac_se_de_ca-activo` = ".$escape($r->activo)
            ." WHERE `ac_se_de_ca-id` = ".$escape($r->tgt_id).";";
    }
}

foreach ($inserts as $sql) {
    echo $sql.PHP_EOL;
}
foreach ($updates as $sql) {
    echo $sql.PHP_EOL;
}
