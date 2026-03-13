<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Users:\n";
$users = DB::table('users')->get();
foreach ($users as $u) {
    echo "  ID: {$u->id}, Name: {$u->name}, Email: {$u->email}, Tenant: {$u->tenant_id}\n";
}

echo "\nPersonas (Morell):\n";
$personas = DB::table('persona_mst')->where('per-tenant_id', 'Morell')->get();
foreach ($personas as $p) {
    echo "  ID: {$p->{'per-id'}}, Name: {$p->{'per-nombre'}}, Email: {$p->{'per-email'}}\n";
}

echo "\nRoles por persona (Morell):\n";
$roles = DB::table('persona_rol_cfg')
    ->select('pe_ro-per_id-fk', 'pe_ro-rol_id-fk', 'pe_ro-tenant_id', 'pe_ro-activo')
    ->orderBy('pe_ro-per_id-fk')
    ->get();
foreach ($roles as $r) {
    echo "  Persona: {$r->{'pe_ro-per_id-fk'}}, Rol: {$r->{'pe_ro-rol_id-fk'}}, Tenant: {$r->{'pe_ro-tenant_id'}}, Activo: {$r->{'pe_ro-activo'}}\n";
}
