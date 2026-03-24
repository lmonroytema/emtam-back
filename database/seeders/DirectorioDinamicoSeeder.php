<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DirectorioDinamicoSeeder extends Seeder
{
    public function run(): void
    {
        if (
            ! Schema::hasTable('directorio_grupo_cat')
            || ! Schema::hasTable('directorio_contacto_mst')
            || ! Schema::hasTable('grupos_directorio_cfg')
            || ! Schema::hasTable('dato_grupo_directorio_cfg')
            || ! Schema::hasTable('dato_directorio_cat')
        ) {
            return;
        }

        $groups = DB::table('directorio_grupo_cat')
            ->orderByRaw("CAST(COALESCE(`dir_gr-orden`, '999') AS UNSIGNED) ASC")
            ->get([
                'dir_gr-id',
                'dir_gr-tenant_id',
                'dir_gr-cod',
                'dir_gr-nombre',
                'dir_gr-descrip',
                'dir_gr-activo',
                'dir_gr-orden',
            ]);

        $headerTemplatesByGroupCode = [
            'COORD' => [
                ['code' => 'SERVEI', 'label' => 'Servei', 'type' => 'TEXTO', 'order' => 1],
                ['code' => 'TELEFON', 'label' => 'Telèfon', 'type' => 'TELEFONO', 'order' => 2],
            ],
            'MEDIOS' => [
                ['code' => 'EMISSORA', 'label' => 'Emissora', 'type' => 'TEXTO', 'order' => 1],
                ['code' => 'FREQUENCIA', 'label' => 'Freqüència', 'type' => 'TEXTO', 'order' => 2],
                ['code' => 'RESPONSABLE', 'label' => 'Responsable/ide localització', 'type' => 'TELEFONO', 'order' => 3],
            ],
            'MUNI' => [
                ['code' => 'MUNICIPI', 'label' => 'Nom de municipi', 'type' => 'TEXTO', 'order' => 1],
                ['code' => 'SITUACIO', 'label' => 'Situació (NSEO)', 'type' => 'TEXTO', 'order' => 2],
                ['code' => 'TEL_AJ', 'label' => 'Telèfon Ajuntament', 'type' => 'TELEFONO', 'order' => 3],
                ['code' => 'TEL_POL', 'label' => 'Telèfon Policia local', 'type' => 'TELEFONO', 'order' => 4],
                ['code' => 'SERVEI_LOCAL', 'label' => 'Servei de vigilància municipal', 'type' => 'TEXTO', 'order' => 5],
            ],
            'EV' => [
                ['code' => 'CODI', 'label' => 'Codi', 'type' => 'TEXTO', 'order' => 1],
                ['code' => 'NOM', 'label' => 'Nom', 'type' => 'TEXTO', 'order' => 2],
                ['code' => 'MITJA_CONTACTE', 'label' => "Mitjà d'avís o telèfons contacte", 'type' => 'TELEFONO', 'order' => 3],
                ['code' => 'ADRECA', 'label' => 'Adreça', 'type' => 'TEXTO', 'order' => 4],
            ],
            'ACOG' => [
                ['code' => 'CODI', 'label' => 'Codi', 'type' => 'TEXTO', 'order' => 1],
                ['code' => 'ENTITAT', 'label' => 'Entitat', 'type' => 'TEXTO', 'order' => 2],
                ['code' => 'RESPONSABLE', 'label' => 'Responsable', 'type' => 'TELEFONO', 'order' => 3],
                ['code' => 'ADRECA', 'label' => 'Adreça', 'type' => 'TEXTO', 'order' => 4],
                ['code' => 'CAPACITAT', 'label' => 'Capacitat', 'type' => 'NUMERO', 'order' => 5],
            ],
        ];

        foreach ($groups as $group) {
            $groupId = trim((string) ($group->{'dir_gr-id'} ?? ''));
            $tenantId = trim((string) ($group->{'dir_gr-tenant_id'} ?? ''));
            $groupCode = strtoupper(trim((string) ($group->{'dir_gr-cod'} ?? '')));
            if ($groupId === '' || $tenantId === '' || $groupCode === '') {
                continue;
            }

            DB::table('grupos_directorio_cfg')->updateOrInsert(
                ['gr_di-id' => $groupId],
                [
                    'gr_di-tenant_id' => $tenantId,
                    'gr_di-cod' => $groupCode,
                    'gr_di-nombre' => (string) ($group->{'dir_gr-nombre'} ?? ''),
                    'gr_di-descrip' => $group->{'dir_gr-descrip'} ?? null,
                    'gr_di-activo' => (string) ($group->{'dir_gr-activo'} ?? 'SI'),
                    'gr_di-orden' => $group->{'dir_gr-orden'} !== null ? (int) $group->{'dir_gr-orden'} : null,
                ]
            );

            $headers = $headerTemplatesByGroupCode[$groupCode] ?? [];
            $headerIdByCode = [];
            foreach ($headers as $header) {
                $headerId = 'DGD-'.strtoupper($groupCode).'-'.strtoupper((string) $header['code']);
                $headerIdByCode[(string) $header['code']] = $headerId;
                DB::table('dato_grupo_directorio_cfg')->updateOrInsert(
                    ['da_gr_di-id' => $headerId],
                    [
                        'da_gr_di-tenant_id' => $tenantId,
                        'da_gr_di-gr_di_id-fk' => $groupId,
                        'da_gr_di-cod' => (string) $header['code'],
                        'da_gr_di-cabecera' => (string) $header['label'],
                        'da_gr_di-tipo_dato' => (string) $header['type'],
                        'da_gr_di-orden' => (int) $header['order'],
                        'da_gr_di-activo' => 'SI',
                    ]
                );
            }

            $contacts = DB::table('directorio_contacto_mst')
                ->where('dir_con-tenant_id', $tenantId)
                ->where('dir_con-dir_gr_id-fk', $groupId)
                ->orderByRaw("CAST(COALESCE(`dir_con-orden`, '999') AS UNSIGNED) ASC")
                ->get([
                    'dir_con-id',
                    'dir_con-cod',
                    'dir_con-nombre',
                    'dir_con-telefono',
                    'dir_con-telefono_2',
                    'dir_con-frecuencia',
                    'dir_con-responsable',
                    'dir_con-direccion',
                    'dir_con-situacion',
                    'dir_con-capacidad',
                    'dir_con-notas',
                    'dir_con-orden',
                ]);

            foreach ($contacts as $contact) {
                $itemId = trim((string) ($contact->{'dir_con-id'} ?? ''));
                if ($itemId === '') {
                    continue;
                }

                $telefonos = array_values(array_filter([
                    trim((string) ($contact->{'dir_con-telefono'} ?? '')),
                    trim((string) ($contact->{'dir_con-telefono_2'} ?? '')),
                ], static fn ($v) => $v !== '' && $v !== '-'));
                $telefonoPrincipal = $telefonos !== [] ? implode(' / ', $telefonos) : null;

                $valuesByCode = match ($groupCode) {
                    'COORD' => [
                        'SERVEI' => trim((string) ($contact->{'dir_con-nombre'} ?? '')),
                        'TELEFON' => $telefonoPrincipal,
                    ],
                    'MEDIOS' => [
                        'EMISSORA' => trim((string) ($contact->{'dir_con-nombre'} ?? '')),
                        'FREQUENCIA' => trim((string) ($contact->{'dir_con-frecuencia'} ?? '')),
                        'RESPONSABLE' => trim((string) ($contact->{'dir_con-responsable'} ?? '')),
                    ],
                    'MUNI' => [
                        'MUNICIPI' => trim((string) ($contact->{'dir_con-nombre'} ?? '')),
                        'SITUACIO' => trim((string) ($contact->{'dir_con-situacion'} ?? '')),
                        'TEL_AJ' => trim((string) ($contact->{'dir_con-telefono'} ?? '')),
                        'TEL_POL' => trim((string) ($contact->{'dir_con-telefono_2'} ?? '')),
                        'SERVEI_LOCAL' => trim((string) ($contact->{'dir_con-notas'} ?? '')),
                    ],
                    'EV' => [
                        'CODI' => trim((string) ($contact->{'dir_con-cod'} ?? '')),
                        'NOM' => trim((string) ($contact->{'dir_con-nombre'} ?? '')),
                        'MITJA_CONTACTE' => $telefonoPrincipal,
                        'ADRECA' => trim((string) ($contact->{'dir_con-direccion'} ?? '')),
                    ],
                    'ACOG' => [
                        'CODI' => trim((string) ($contact->{'dir_con-cod'} ?? '')),
                        'ENTITAT' => trim((string) ($contact->{'dir_con-nombre'} ?? '')),
                        'RESPONSABLE' => trim((string) ($contact->{'dir_con-telefono'} ?? '')),
                        'ADRECA' => trim((string) ($contact->{'dir_con-direccion'} ?? '')),
                        'CAPACITAT' => $contact->{'dir_con-capacidad'} !== null ? (string) $contact->{'dir_con-capacidad'} : '',
                    ],
                    default => [],
                };

                foreach ($valuesByCode as $code => $rawValue) {
                    $headerId = $headerIdByCode[$code] ?? null;
                    if (! is_string($headerId) || $headerId === '') {
                        continue;
                    }
                    $value = trim((string) $rawValue);
                    DB::table('dato_directorio_cat')->updateOrInsert(
                        [
                            'da_di-id' => 'DD-'.strtoupper($itemId).'-'.strtoupper($code),
                        ],
                        [
                            'da_di-tenant_id' => $tenantId,
                            'da_di-gr_di_id-fk' => $groupId,
                            'da_di-da_gr_di_id-fk' => $headerId,
                            'da_di-item_id' => $itemId,
                            'da_di-valor' => $value !== '' ? $value : null,
                            'da_di-orden' => $contact->{'dir_con-orden'} !== null ? (int) $contact->{'dir_con-orden'} : null,
                            'da_di-activo' => 'SI',
                        ]
                    );
                }
            }
        }
    }
}
