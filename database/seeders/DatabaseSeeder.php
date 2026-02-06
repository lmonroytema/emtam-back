<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantLanguage;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenantId = 'Morell';

        Tenant::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            ['name' => $tenantId, 'default_language' => 'es'],
        );

        foreach (['ca', 'es', 'en'] as $languageCode) {
            TenantLanguage::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'language_code' => $languageCode],
                ['is_active' => true],
            );
        }

        $this->call(ExcelSeeder::class);

        $directorEmail = 'director@morell.test';
        $directorPersonaId = 'PER-DIRECTOR';
        $directorRoleId = 'ROL-DIRECTOR';
        $directorRoleCode = 'ROL_DIRECTOR';
        $directorPersonaRoleId = 'PERROL-DIRECTOR';
        $directorCapabilityId = 'TCR-DIRECTOR-PLAN-ACTIVATE';
        $adminEmail = 'admin@morell.test';
        $testEmail = 'test@example.com';
        $testPersonaId = 'PER-TEST';
        $testPersonaRoleId = 'PERROL-TEST-DIRECTOR';

        User::query()->updateOrCreate(
            ['email' => $directorEmail],
            [
                'name' => 'Director Morell',
                'password' => 'password',
                'tenant_id' => $tenantId,
                'language' => 'es',
            ],
        );

        User::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Admin Morell',
                'password' => 'password',
                'tenant_id' => $tenantId,
                'language' => 'es',
            ],
        );

        DB::table('persona_mst')->updateOrInsert(
            ['per-id' => $directorPersonaId],
            [
                'per-tenant_id' => $tenantId,
                'per-nom_data_orig' => 'seed',
                'per-nombre' => 'Director',
                'per-apellido_1' => 'Morell',
                'per-apellido_2' => null,
                'per-num_doc' => null,
                'per-email' => $directorEmail,
                'per-tel_mov' => null,
                'per-activo' => 'SI',
                'per-correl' => null,
            ],
        );

        DB::table('rol_cat')->updateOrInsert(
            ['rol-id' => $directorRoleId],
            [
                'rol-tenant_id' => $tenantId,
                'rol-cod' => $directorRoleCode,
                'rol-nombre' => 'Director',
                'rol-descrip' => 'Director',
                'rol-activo' => 'SI',
                'rol-correl' => '1',
            ],
        );

        DB::table('persona_rol_cfg')->updateOrInsert(
            ['pe_ro-id' => $directorPersonaRoleId],
            [
                'pe_ro-tenant_id' => $tenantId,
                'pe_ro-per_id-fk' => $directorPersonaId,
                'pe_ro-rol_id-fk' => $directorRoleId,
                'pe_ro-activo' => 'SI',
                'pe_ro-fech_ini' => null,
                'pe_ro-fech_fin' => null,
                'pe_ro-observ' => null,
            ],
        );

        if (Schema::hasTable('tenant_capability_role_cfg')) {
            DB::table('tenant_capability_role_cfg')->updateOrInsert(
                ['tcr-id' => $directorCapabilityId],
                [
                    'tcr-tenant_id' => $tenantId,
                    'tcr-capability' => 'plan.activate',
                    'tcr-rol_cod' => $directorRoleCode,
                    'tcr-activo' => 'SI',
                    'tcr-orden' => '1',
                ],
            );
        }

        DB::table('persona_mst')->updateOrInsert(
            ['per-id' => $testPersonaId],
            [
                'per-tenant_id' => $tenantId,
                'per-nom_data_orig' => 'seed',
                'per-nombre' => 'Test',
                'per-apellido_1' => 'User',
                'per-apellido_2' => null,
                'per-num_doc' => null,
                'per-email' => $testEmail,
                'per-tel_mov' => null,
                'per-activo' => 'SI',
                'per-correl' => null,
            ],
        );

        DB::table('persona_rol_cfg')->updateOrInsert(
            ['pe_ro-id' => $testPersonaRoleId],
            [
                'pe_ro-tenant_id' => $tenantId,
                'pe_ro-per_id-fk' => $testPersonaId,
                'pe_ro-rol_id-fk' => $directorRoleId,
                'pe_ro-activo' => 'SI',
                'pe_ro-fech_ini' => null,
                'pe_ro-fech_fin' => null,
                'pe_ro-observ' => null,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => $testEmail],
            [
                'name' => 'Test User',
                'password' => 'password',
                'tenant_id' => $tenantId,
                'language' => 'es',
            ],
        );
    }
}
