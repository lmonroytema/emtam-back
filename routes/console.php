<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('activaciones:auto-delegar {--tenant=} {--dry-run}', function () {
    if (! Schema::hasTable('tenants')
        || ! Schema::hasTable('activacion_del_plan_trs')
        || ! Schema::hasTable('ejecucion_accion_trs')
        || ! Schema::hasTable('asignacion_en_funciones_trs')
        || ! Schema::hasTable('accion_set_detalle_cfg')
        || ! Schema::hasTable('persona_rol_grupo_cfg')
        || ! Schema::hasTable('notificacion_envio_trs')
        || ! Schema::hasTable('notificacion_confirmacion_trs')
    ) {
        $this->warn('Tablas requeridas no disponibles para autodelegación.');

        return 0;
    }

    $tenantOption = trim((string) $this->option('tenant'));
    $dryRun = (bool) $this->option('dry-run');
    $auditLogger = app(\App\Services\AuditLogger::class);
    $resolveTimezone = static function (?string $timezone): string {
        $candidate = trim((string) $timezone);
        if ($candidate !== '' && in_array($candidate, timezone_identifiers_list(), true)) {
            return $candidate;
        }

        return 'Europe/Madrid';
    };

    $tenantsQuery = DB::table('tenants')->select(['tenant_id', 'conformacion_tiempo_limite', 'timezone']);
    if ($tenantOption !== '') {
        $tenantsQuery->where('tenant_id', $tenantOption);
    }
    $tenants = $tenantsQuery->get();

    $processedActivations = 0;
    $delegatedActions = 0;

    foreach ($tenants as $tenant) {
        $tenantId = trim((string) ($tenant->tenant_id ?? ''));
        $limitMinutes = (int) ($tenant->conformacion_tiempo_limite ?? 0);
        $timezone = $resolveTimezone((string) ($tenant->timezone ?? ''));
        $now = \Carbon\Carbon::now($timezone);
        $offset = $now->format('P');
        DB::statement("SET time_zone = '{$offset}'");
        if ($tenantId === '' || $limitMinutes <= 0) {
            continue;
        }

        $activations = DB::table('activacion_del_plan_trs')
            ->when(
                Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-tenant_id'),
                static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
            )
            ->when(
                Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-activo'),
                static fn ($q) => $q->whereRaw("UPPER(COALESCE(`ac_de_pl-activo`, 'SI')) <> 'NO'"),
            )
            ->when(
                Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-estado'),
                static fn ($q) => $q->whereRaw("UPPER(COALESCE(`ac_de_pl-estado`, '')) NOT IN ('FINALIZADA','FINALIZADO')"),
            )
            ->get([
                'ac_de_pl-id',
                'ac_de_pl-fecha_activac',
                'ac_de_pl-hora_activac',
            ]);

        foreach ($activations as $activation) {
            $activationId = trim((string) ($activation->{'ac_de_pl-id'} ?? ''));
            $date = trim((string) ($activation->{'ac_de_pl-fecha_activac'} ?? ''));
            $time = trim((string) ($activation->{'ac_de_pl-hora_activac'} ?? ''));
            if ($activationId === '' || $date === '' || $time === '') {
                continue;
            }

            $activationAt = \Carbon\Carbon::parse($date.' '.$time, $timezone);
            $deadline = $activationAt->copy()->addMinutes($limitMinutes);
            if ($deadline->greaterThan($now)) {
                continue;
            }
            $processedActivations++;

            $details = DB::table('accion_set_detalle_cfg')
                ->when(
                    Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id'),
                    static fn ($q) => $q->where('ac_se_de-tenant_id', $tenantId),
                )
                ->when(
                    Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-activo'),
                    static fn ($q) => $q->whereRaw("UPPER(COALESCE(`ac_se_de-activo`, 'SI')) <> 'NO'"),
                )
                ->get(['ac_se_de-id', 'ac_se_de-rol_id-fk']);
            $roleByDetailId = [];
            foreach ($details as $d) {
                $detailId = trim((string) ($d->{'ac_se_de-id'} ?? ''));
                $roleId = trim((string) ($d->{'ac_se_de-rol_id-fk'} ?? ''));
                if ($detailId !== '' && $roleId !== '') {
                    $roleByDetailId[$detailId] = $roleId;
                }
            }

            $sentRows = DB::table('notificacion_envio_trs')
                ->when(
                    Schema::hasColumn('notificacion_envio_trs', 'no_en-tenant_id'),
                    static fn ($q) => $q->where('no_en-tenant_id', $tenantId),
                )
                ->where('no_en-ac_de_pl_id-fk', $activationId)
                ->whereNotNull('no_en-per_id-fk')
                ->whereNotNull('no_en-ts')
                ->orderBy('no_en-ts')
                ->get(['no_en-id', 'no_en-per_id-fk', 'no_en-ts']);
            $notifIdsByPerson = [];
            foreach ($sentRows as $s) {
                $perId = trim((string) ($s->{'no_en-per_id-fk'} ?? ''));
                $sentTs = trim((string) ($s->{'no_en-ts'} ?? ''));
                if ($perId === '' || $sentTs === '') {
                    continue;
                }
                if (\Carbon\Carbon::parse($sentTs, $timezone)->greaterThan($deadline)) {
                    continue;
                }
                $noEnId = trim((string) ($s->{'no_en-id'} ?? ''));
                if ($noEnId !== '') {
                    $notifIdsByPerson[$perId][] = $noEnId;
                }
            }

            $confirmedByPerson = [];
            if (! empty($notifIdsByPerson)) {
                $allNotifIds = array_values(array_unique(array_merge(...array_values($notifIdsByPerson))));
                $confirmRows = DB::table('notificacion_confirmacion_trs')
                    ->when(
                        Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-tenant_id'),
                        static fn ($q) => $q->where('no_co-tenant_id', $tenantId),
                    )
                    ->whereIn('no_co-no_en_id-fk', $allNotifIds)
                    ->whereRaw("UPPER(COALESCE(`no_co-confirmado`, 'NO')) IN ('SI','S','1','TRUE')")
                    ->get(['no_co-no_en_id-fk', 'no_co-ts']);
                $confirmedNotifIds = [];
                foreach ($confirmRows as $c) {
                    $noEnId = trim((string) ($c->{'no_co-no_en_id-fk'} ?? ''));
                    $ts = trim((string) ($c->{'no_co-ts'} ?? ''));
                    if ($noEnId === '' || $ts === '') {
                        continue;
                    }
                    if (\Carbon\Carbon::parse($ts, $timezone)->greaterThan($deadline)) {
                        continue;
                    }
                    $confirmedNotifIds[$noEnId] = true;
                }
                foreach ($notifIdsByPerson as $perId => $ids) {
                    foreach ($ids as $id) {
                        if (isset($confirmedNotifIds[$id])) {
                            $confirmedByPerson[$perId] = true;
                            break;
                        }
                    }
                }
            }

            $prgRows = DB::table('persona_rol_grupo_cfg')
                ->when(
                    Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                    static fn ($q) => $q->where('pe_ro_gr-tenant_id', $tenantId),
                )
                ->when(
                    Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-activo'),
                    static fn ($q) => $q->whereRaw("UPPER(COALESCE(`pe_ro_gr-activo`, 'SI')) <> 'NO'"),
                )
                ->when(
                    Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-fech_fin'),
                    static function ($q): void {
                        $q->where(function ($sub): void {
                            $sub->whereNull('pe_ro_gr-fech_fin')->orWhere('pe_ro_gr-fech_fin', '');
                        });
                    },
                )
                ->get([
                    'pe_ro_gr-id',
                    'pe_ro_gr-per_id-fk',
                    'pe_ro_gr-gr_op_id-fk',
                    'pe_ro_gr-rol_id-fk',
                    'pe_ro_gr-tipo_asignacion',
                    'pe_ro_gr-orden_sust',
                    'pe_ro_gr-fech_ini',
                ]);
            $titularesByRoleGroup = [];
            $suplentesByRoleGroup = [];
            foreach ($prgRows as $r) {
                $perId = trim((string) ($r->{'pe_ro_gr-per_id-fk'} ?? ''));
                $gid = trim((string) ($r->{'pe_ro_gr-gr_op_id-fk'} ?? ''));
                $rolId = trim((string) ($r->{'pe_ro_gr-rol_id-fk'} ?? ''));
                if ($perId === '' || $gid === '' || $rolId === '') {
                    continue;
                }
                $tipo = strtoupper(trim((string) ($r->{'pe_ro_gr-tipo_asignacion'} ?? 'SUPLENTE')));
                if ($tipo === 'LIDER') {
                    $tipo = 'TITULAR';
                }
                $entry = [
                    'per_id' => $perId,
                    'orden' => (int) ($r->{'pe_ro_gr-orden_sust'} ?? 9999),
                    'ini' => trim((string) ($r->{'pe_ro_gr-fech_ini'} ?? '')),
                    'id' => trim((string) ($r->{'pe_ro_gr-id'} ?? '')),
                ];
                $key = $rolId.'|'.$gid;
                if ($tipo === 'TITULAR') {
                    $titularesByRoleGroup[$key][] = $entry;
                } elseif ($tipo === 'SUPLENTE') {
                    $suplentesByRoleGroup[$key][] = $entry;
                }
            }
            $sortRows = static function (array &$rows): void {
                usort($rows, static function (array $a, array $b): int {
                    $ao = (int) ($a['orden'] ?? 9999);
                    $bo = (int) ($b['orden'] ?? 9999);
                    if ($ao !== $bo) {
                        return $ao <=> $bo;
                    }
                    $ai = (string) ($a['ini'] ?? '');
                    $bi = (string) ($b['ini'] ?? '');
                    if ($ai !== $bi) {
                        return strcmp($ai, $bi);
                    }
                    return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
                });
            };
            foreach ($titularesByRoleGroup as &$rows) {
                $sortRows($rows);
            }
            unset($rows);
            foreach ($suplentesByRoleGroup as &$rows) {
                $sortRows($rows);
            }
            unset($rows);

            $execRows = DB::table('ejecucion_accion_trs')
                ->when(
                    Schema::hasColumn('ejecucion_accion_trs', 'ej_ac-tenant_id'),
                    static fn ($q) => $q->where('ej_ac-tenant_id', $tenantId),
                )
                ->where('ej_ac-ac_de_pl_id-fk', $activationId)
                ->orderByDesc('ej_ac-ts_ini')
                ->orderByDesc('ej_ac-id')
                ->get([
                    'ej_ac-id',
                    'ej_ac-gr_op_id-fk',
                    'ej_ac-ac_se_de_id-fk',
                    'ej_ac-as_en_fu_id-fk',
                    'ej_ac-estado',
                ]);
            $latestExecByKey = [];
            foreach ($execRows as $ej) {
                $gid = trim((string) ($ej->{'ej_ac-gr_op_id-fk'} ?? ''));
                $detailId = trim((string) ($ej->{'ej_ac-ac_se_de_id-fk'} ?? ''));
                if ($gid === '' || $detailId === '') {
                    continue;
                }
                $key = $gid.'|'.$detailId;
                if (! isset($latestExecByKey[$key])) {
                    $latestExecByKey[$key] = $ej;
                }
            }
            $detailIdsInPlan = [];
            foreach (array_keys($latestExecByKey) as $key) {
                [, $detailId] = explode('|', $key, 2);
                $detailId = trim((string) $detailId);
                if ($detailId !== '') {
                    $detailIdsInPlan[$detailId] = true;
                }
            }
            if (! empty($detailIdsInPlan)) {
                $roleByDetailId = [];
                $detailRows = DB::table('accion_set_detalle_cfg')
                    ->when(
                        Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id'),
                        static fn ($q) => $q->where('ac_se_de-tenant_id', $tenantId),
                    )
                    ->whereIn('ac_se_de-id', array_keys($detailIdsInPlan))
                    ->when(
                        Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-activo'),
                        static fn ($q) => $q->whereRaw("UPPER(COALESCE(`ac_se_de-activo`, 'SI')) <> 'NO'"),
                    )
                    ->get(['ac_se_de-id', 'ac_se_de-rol_id-fk']);
                foreach ($detailRows as $d) {
                    $detailId = trim((string) ($d->{'ac_se_de-id'} ?? ''));
                    $roleId = trim((string) ($d->{'ac_se_de-rol_id-fk'} ?? ''));
                    if ($detailId !== '' && $roleId !== '') {
                        $roleByDetailId[$detailId] = $roleId;
                    }
                }
            }

            $assignmentRows = DB::table('asignacion_en_funciones_trs')
                ->when(
                    Schema::hasColumn('asignacion_en_funciones_trs', 'as_en_fu-tenant_id'),
                    static fn ($q) => $q->where('as_en_fu-tenant_id', $tenantId),
                )
                ->where('as_en_fu-ac_de_pl_id-fk', $activationId)
                ->when(
                    Schema::hasColumn('asignacion_en_funciones_trs', 'as_en_fu-estado'),
                    static fn ($q) => $q->whereRaw("UPPER(COALESCE(`as_en_fu-estado`, 'ACTIVA')) NOT IN ('CERRADA','CERRADO')"),
                )
                ->when(
                    Schema::hasColumn('asignacion_en_funciones_trs', 'as_en_fu-ts_fin'),
                    static function ($q): void {
                        $q->where(function ($sub): void {
                            $sub->whereNull('as_en_fu-ts_fin')->orWhere('as_en_fu-ts_fin', '');
                        });
                    },
                )
                ->get([
                    'as_en_fu-id',
                    'as_en_fu-gr_op_id-fk',
                    'as_en_fu-per_id-fk',
                    'as_en_fu-tipo_asignacion',
                ]);
            $assignmentById = [];
            $assignmentByKey = [];
            foreach ($assignmentRows as $a) {
                $id = trim((string) ($a->{'as_en_fu-id'} ?? ''));
                $gid = trim((string) ($a->{'as_en_fu-gr_op_id-fk'} ?? ''));
                $perId = trim((string) ($a->{'as_en_fu-per_id-fk'} ?? ''));
                $tipo = strtoupper(trim((string) ($a->{'as_en_fu-tipo_asignacion'} ?? '')));
                if ($id === '' || $gid === '' || $perId === '' || $tipo === '') {
                    continue;
                }
                $assignmentById[$id] = $a;
                $assignmentByKey[$gid.'|'.$perId.'|'.$tipo] = $id;
            }

            foreach ($latestExecByKey as $key => $ej) {
                $status = strtoupper(trim((string) ($ej->{'ej_ac-estado'} ?? '')));
                if (in_array($status, ['CONFIRMADO', 'COMPLETADA', 'COMPLETADO', 'FINALIZADA', 'FINALIZADO'], true)) {
                    continue;
                }

                [$gid, $detailId] = explode('|', $key, 2);
                $roleId = $roleByDetailId[$detailId] ?? '';
                if ($gid === '' || $detailId === '' || $roleId === '') {
                    continue;
                }

                $rgKey = $roleId.'|'.$gid;
                $titularRows = $titularesByRoleGroup[$rgKey] ?? [];
                $titularId = trim((string) ($titularRows[0]['per_id'] ?? ''));
                if ($titularId === '') {
                    continue;
                }
                if (($confirmedByPerson[$titularId] ?? false) === true) {
                    continue;
                }

                $candidateRows = $suplentesByRoleGroup[$rgKey] ?? [];
                $suplenteId = '';
                foreach ($candidateRows as $c) {
                    $pid = trim((string) ($c['per_id'] ?? ''));
                    if ($pid !== '' && (($confirmedByPerson[$pid] ?? false) === true)) {
                        $suplenteId = $pid;
                        break;
                    }
                }
                if ($suplenteId === '') {
                    continue;
                }

                $currentAsignId = trim((string) ($ej->{'ej_ac-as_en_fu_id-fk'} ?? ''));
                $currentAsign = $currentAsignId !== '' ? ($assignmentById[$currentAsignId] ?? null) : null;
                $currentPerId = trim((string) ($currentAsign?->{'as_en_fu-per_id-fk'} ?? ''));
                if ($currentPerId === $suplenteId) {
                    continue;
                }

                $targetAsigKey = $gid.'|'.$suplenteId.'|SUPLENTE';
                $targetAsigId = $assignmentByKey[$targetAsigKey] ?? '';
                if ($targetAsigId === '') {
                    $targetAsigId = 'ASEF-'.Str::uuid()->toString();
                    if (! $dryRun) {
                        DB::table('asignacion_en_funciones_trs')->insert([
                            'as_en_fu-id' => $targetAsigId,
                            'as_en_fu-tenant_id' => $tenantId,
                            'as_en_fu-ac_de_pl_id-fk' => $activationId,
                            'as_en_fu-gr_op_id-fk' => $gid,
                            'as_en_fu-per_id-fk' => $suplenteId,
                            'as_en_fu-per_id-fk-delegador' => $titularId,
                            'as_en_fu-tipo_asignacion' => 'SUPLENTE',
                            'as_en_fu-motivo' => 'AUTO_DELEGACION_VENCIMIENTO_CONFIRMACION',
                            'as_en_fu-ts_ini' => $now->toDateTimeString(),
                            'as_en_fu-ts_fin' => null,
                            'as_en_fu-estado' => 'ACTIVA',
                        ]);
                    }
                    $assignmentByKey[$targetAsigKey] = $targetAsigId;
                }

                if (! $dryRun) {
                    DB::table('ejecucion_accion_trs')
                        ->where('ej_ac-id', $ej->{'ej_ac-id'})
                        ->update([
                            'ej_ac-as_en_fu_id-fk' => $targetAsigId,
                            'ej_ac-ts_ini' => $now->toDateTimeString(),
                            'ej_ac-ts_fin' => null,
                        ]);

                    $auditLogger->log([
                        'tenant_id' => $tenantId,
                        'plan_id' => $activationId,
                        'event_type' => 'delegation_auto',
                        'module' => 'delegations',
                        'entity_id' => $targetAsigId,
                        'entity_type' => 'asignacion_en_funciones_trs',
                        'previous_value' => [
                            'accion_detalle_id' => $detailId,
                            'grupo_id' => $gid,
                            'titular_prev_per_id' => $titularId,
                            'asignacion_prev_id' => $currentAsignId !== '' ? $currentAsignId : null,
                        ],
                        'new_value' => [
                            'suplente_new_per_id' => $suplenteId,
                            'asignacion_id' => $targetAsigId,
                            'tipo_delegacion' => 'AUTO',
                            'motivo' => 'Vencimiento tiempo conformación',
                        ],
                        'justification' => 'Autodelegación automática backend por vencimiento de confirmación',
                    ]);
                }

                $delegatedActions++;
            }
        }
    }

    $this->info('Activaciones evaluadas: '.$processedActivations);
    $this->info('Acciones autodelegadas: '.$delegatedActions.($dryRun ? ' (dry-run)' : ''));

    return 0;
})->purpose('Autodelega acciones vencidas al primer suplente confirmado sin depender de una pantalla');

Schedule::command('activaciones:auto-delegar')->everyMinute();

Artisan::command('activaciones:reset {--force}', function () {
    $tables = [
        'notificacion_confirmacion_trs',
        'notificacion_envio_trs',
        'ejecucion_accion_trs',
        'asignacion_en_funciones_trs',
        'notas_operativas_trs',
        'cronologia_emergencia_trs',
        'activacion_nivel_hist_trs',
        'activacion_del_plan_trs',
    ];

    $existing = [];
    $missing = [];

    foreach ($tables as $table) {
        if (Schema::hasTable($table)) {
            $count = DB::table($table)->count();
            $existing[] = ['name' => $table, 'count' => $count];
        } else {
            $missing[] = $table;
        }
    }

    if (empty($existing)) {
        $this->error('No hay tablas para reiniciar.');

        return 1;
    }

    $this->info('Tablas a reiniciar:');
    foreach ($existing as $row) {
        $this->line('- '.$row['name'].' (filas: '.$row['count'].')');
    }

    if (! empty($missing)) {
        $this->warn('Tablas no encontradas:');
        foreach ($missing as $table) {
            $this->line('- '.$table);
        }
    }

    if (! $this->option('force')) {
        if (! $this->confirm('¿Deseas continuar y eliminar estos registros?')) {
            $this->line('Cancelado.');

            return 0;
        }
    }

    DB::transaction(function () use ($existing): void {
        foreach ($existing as $row) {
            DB::table($row['name'])->delete();
        }
    });

    $this->info('Reinicio completado.');

    return 0;
})->purpose('Reinicia las tablas de planes activados');

Artisan::command('csv:validate {--dir=} {--limit=0} {--json} {--all} {--mode=} {--strict}', function () {
    $limit = (int) $this->option('limit');
    $asJson = (bool) $this->option('json');
    $mode = strtolower(trim((string) $this->option('mode')));
    $showAll = $this->option('all') || $mode === 'all' || $mode === 'todas';
    $onlyDiff = ! $showAll;
    $dirsOption = trim((string) $this->option('dir'));
    $strict = (bool) $this->option('strict');

    $isAbsolute = static function (string $path): bool {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, '/');
    };

    $addCsvFile = static function (string $path, array &$files): void {
        if (! is_file($path)) {
            return;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            return;
        }
        $files[] = $path;
    };
    $detectExcel = static function (string $path): bool {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $sig = fread($fh, 4);
        fclose($fh);

        return $sig === "PK\x03\x04";
    };
    $readExcelHeaders = static function (string $path): array {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);
        $highestCol = $sheet->getHighestColumn();
        $headerRow = $sheet->rangeToArray('A1:'.$highestCol.'1', null, true, false)[0] ?? [];

        return [$headerRow, $sheet, $highestCol];
    };

    $directories = [];
    $csvFiles = [];

    if ($dirsOption !== '') {
        foreach (array_filter(array_map('trim', explode(',', $dirsOption))) as $raw) {
            $candidatePaths = [];
            if ($isAbsolute($raw)) {
                $candidatePaths[] = $raw;
            } else {
                $candidatePaths[] = base_path($raw);
                $candidatePaths[] = base_path('..'.DIRECTORY_SEPARATOR.$raw);
            }
            foreach ($candidatePaths as $path) {
                if (is_dir($path)) {
                    $directories[] = $path;
                } elseif (is_file($path)) {
                    $addCsvFile($path, $csvFiles);
                }
            }
        }
    } else {
        foreach ([base_path('CSV'), base_path('..'.DIRECTORY_SEPARATOR.'CSV')] as $dir) {
            if (is_dir($dir)) {
                $directories[] = $dir;
            }
        }
        foreach ([base_path('Indice.csv'), base_path('..'.DIRECTORY_SEPARATOR.'Indice.csv')] as $file) {
            if (is_file($file)) {
                $addCsvFile($file, $csvFiles);
            }
        }
    }

    foreach ($directories as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $addCsvFile($path, $csvFiles);
        }
    }

    $csvFiles = array_values(array_unique($csvFiles));
    sort($csvFiles);

    if (empty($csvFiles)) {
        $this->error('No se encontraron CSVs.');

        return 1;
    }

    $driver = DB::getDriverName();
    $dbName = DB::getDatabaseName();
    $tables = [];

    if ($driver === 'mysql') {
        $tables = DB::table('information_schema.tables')
            ->select('table_name')
            ->where('table_schema', $dbName)
            ->pluck('table_name')
            ->all();
    } elseif ($driver === 'sqlite') {
        $tables = array_map(
            static fn ($r) => $r->name,
            DB::select("SELECT name FROM sqlite_master WHERE type='table'")
        );
    } elseif ($driver === 'pgsql') {
        $tables = array_map(
            static fn ($r) => $r->tablename,
            DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public'")
        );
    }

    $tablesLookup = array_fill_keys($tables, true);
    $systemTables = array_fill_keys([
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'personal_access_tokens',
        'sessions',
        'tenants',
        'tenant_languages',
        'users',
    ], true);
    $suffixes = ['_cat', '_cfg', '_mst', '_trs'];
    $explicitMap = [
        'criterios_alerta' => 'criterios_nivel_alerta_cfg',
        'indice' => 'informacion_tablas',
        'informacion_tablas' => 'informacion_tablas',
        'riesgo_nivel_accion_set' => 'riesgo_nivel_accion_set_cfg',
        'tipo_riesgo_nivel_accion_set' => 'tipo_riesgo_nivel_accion_set_cfg',
    ];

    $normalizeBase = static function (string $name): string {
        $base = strtolower(trim($name));
        $base = preg_replace('/\.csv$/', '', $base) ?? $base;
        $base = preg_replace('/\s+/', '_', $base) ?? $base;
        $base = preg_replace('/[^a-z0-9_]+/', '_', $base) ?? $base;
        $base = preg_replace('/^\\d+_+/', '', $base) ?? $base;
        $base = preg_replace('/_+/', '_', $base) ?? $base;
        $base = trim($base, '_');
        $tokens = array_filter(explode('_', $base), static fn ($t) => $t !== '');
        $filtered = [];
        foreach ($tokens as $t) {
            if (in_array($t, ['apptabs', 'app', 'tabs', 'dataset', 'data', 'notebooklm', 'emta', 'main', 'df', 'general', 'resum'], true)) {
                continue;
            }
            $filtered[] = $t;
        }
        $base = implode('_', $filtered);
        $base = preg_replace('/_+/', '_', $base) ?? $base;

        return trim($base, '_');
    };

    $normalizeCol = static function (string $col): string {
        $col = preg_replace('/^\xEF\xBB\xBF/', '', $col) ?? $col;
        $col = trim($col);
        $col = trim($col, " \t\n\r\0\x0B\"'");
        $col = strtolower($col);
        $col = preg_replace('/\s+/', ' ', $col) ?? $col;

        return $col;
    };

    $isIgnoredColumn = static function (string $col): bool {
        return preg_match('/(^|[-_])id$/', $col) === 1;
    };

    $resolveTable = static function (string $base) use ($tables, $tablesLookup, $suffixes, $explicitMap): array {
        if (isset($explicitMap[$base])) {
            $table = $explicitMap[$base];

            return ['table' => $table, 'ambiguous' => []];
        }
        if (isset($tablesLookup[$base])) {
            return ['table' => $base, 'ambiguous' => []];
        }
        foreach ($suffixes as $suffix) {
            $candidate = $base.$suffix;
            if (isset($tablesLookup[$candidate])) {
                return ['table' => $candidate, 'ambiguous' => []];
            }
        }
        $matches = [];
        foreach ($tables as $table) {
            $candidate = preg_replace('/_(cat|cfg|mst|trs)$/', '', $table) ?? $table;
            if ($candidate === $base) {
                $matches[] = $table;
            }
        }
        if (count($matches) === 1) {
            return ['table' => $matches[0], 'ambiguous' => []];
        }

        return ['table' => null, 'ambiguous' => $matches];
    };

    $report = [];
    $resolvedCsvTables = [];
    $unresolvedCsv = [];
    $ambiguousCsv = [];

    foreach ($csvFiles as $file) {
        $headers = [];
        $delimiter = ';';
        if ($detectExcel($file)) {
            [$headers] = $readExcelHeaders($file);
            $delimiter = 'xlsx';
        } else {
            $headerLine = null;
            $fh = fopen($file, 'rb');
            if ($fh === false) {
                $report[] = ['file' => $file, 'error' => 'No se pudo abrir el archivo.'];

                continue;
            }
            while (($line = fgets($fh)) !== false) {
                if (trim($line) !== '') {
                    $headerLine = $line;
                    break;
                }
            }
            if ($headerLine === null) {
                fclose($fh);
                $report[] = ['file' => $file, 'error' => 'Archivo vacío.'];

                continue;
            }

            $delimiterScores = [
                ';' => substr_count($headerLine, ';'),
                ',' => substr_count($headerLine, ','),
                "\t" => substr_count($headerLine, "\t"),
            ];
            arsort($delimiterScores);
            $delimiter = array_key_first($delimiterScores) ?: ';';
            $headers = str_getcsv($headerLine, $delimiter);
            fclose($fh);
        }

        $csvNormalized = [];
        $csvOriginal = [];
        $duplicates = [];
        $emptyHeaders = 0;

        foreach ($headers as $h) {
            if (! is_string($h)) {
                $emptyHeaders++;

                continue;
            }
            $normalized = $normalizeCol($h);
            if ($normalized === '') {
                $emptyHeaders++;

                continue;
            }
            if (array_key_exists($normalized, $csvOriginal)) {
                $duplicates[$normalized] = true;
            }
            $csvOriginal[$normalized] = trim($h);
            $csvNormalized[$normalized] = true;
        }

        $rowCount = 0;
        if ($limit > 0 && $delimiter !== 'xlsx') {
            $fh = fopen($file, 'rb');
            if ($fh !== false) {
                $skippedHeader = false;
                while (($line = fgets($fh)) !== false && $rowCount < $limit) {
                    if (! $skippedHeader && trim($line) !== '') {
                        $skippedHeader = true;
                        continue;
                    }
                    if (trim($line) !== '') {
                        $rowCount++;
                    }
                }
                fclose($fh);
            }
        }

        $base = $normalizeBase(pathinfo($file, PATHINFO_FILENAME));
        $resolved = $resolveTable($base);
        $table = $resolved['table'];
        $ambiguous = $resolved['ambiguous'];

        $missingInDb = [];
        $missingInCsv = [];
        $dbCols = [];

        if ($table && Schema::hasTable($table)) {
            $dbCols = Schema::getColumnListing($table);
            $dbNormalized = array_values(array_filter(array_map($normalizeCol, $dbCols), static function (string $col) use ($isIgnoredColumn): bool {
                return ! $isIgnoredColumn($col);
            }));
            $dbSet = array_fill_keys($dbNormalized, true);
            $csvSet = array_filter($csvNormalized, static function (bool $value, string $key) use ($isIgnoredColumn): bool {
                return ! $isIgnoredColumn($key);
            }, ARRAY_FILTER_USE_BOTH);
            $missingInDb = array_values(array_diff(array_keys($csvSet), array_keys($dbSet)));
            $missingInCsv = array_values(array_diff(array_keys($dbSet), array_keys($csvSet)));
        } elseif ($table) {
            $missingInDb = array_values(array_filter(array_keys($csvNormalized), static function (string $col) use ($isIgnoredColumn): bool {
                return ! $isIgnoredColumn($col);
            }));
        } else {
            $missingInDb = array_values(array_filter(array_keys($csvNormalized), static function (string $col) use ($isIgnoredColumn): bool {
                return ! $isIgnoredColumn($col);
            }));
        }

        $report[] = [
            'file' => $file,
            'table' => $table,
            'ambiguous' => $ambiguous,
            'delimiter' => $delimiter,
            'csv_columns' => array_values(array_map(static fn ($c) => $c, $csvOriginal)),
            'db_columns' => $dbCols,
            'missing_in_db' => $missingInDb,
            'missing_in_csv' => $missingInCsv,
            'duplicate_headers' => array_keys($duplicates),
            'empty_headers' => $emptyHeaders,
            'rows_sampled' => $rowCount,
        ];

        if ($table) {
            $resolvedCsvTables[$table] = true;
        } else {
            $unresolvedCsv[] = $base;
        }
        if (! empty($ambiguous)) {
            $ambiguousCsv[$base] = $ambiguous;
        }
    }

    $resolvedCsvTablesList = array_keys($resolvedCsvTables);
    sort($resolvedCsvTablesList);
    $dbTablesForCompare = array_values(array_filter($tables, static function (string $table) use ($systemTables): bool {
        return ! isset($systemTables[$table]);
    }));
    sort($dbTablesForCompare);
    $tablesWithoutCsv = array_values(array_diff($dbTablesForCompare, $resolvedCsvTablesList));
    $newCsvTables = array_values(array_unique($unresolvedCsv));
    sort($newCsvTables);
    $transactionTables = array_values(array_filter($tables, static fn (string $t): bool => str_ends_with($t, '_trs')));
    sort($transactionTables);

    $foreignKeys = [];
    if ($driver === 'mysql') {
        $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select('TABLE_NAME', 'REFERENCED_TABLE_NAME')
            ->where('TABLE_SCHEMA', $dbName)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->get();
    } elseif ($driver === 'sqlite') {
        foreach ($dbTablesForCompare as $table) {
            $rows = DB::select("PRAGMA foreign_key_list('{$table}')");
            foreach ($rows as $r) {
                if (! empty($r->table)) {
                    $foreignKeys[] = (object) ['TABLE_NAME' => $table, 'REFERENCED_TABLE_NAME' => $r->table];
                }
            }
        }
    } elseif ($driver === 'pgsql') {
        $fkRows = DB::select("
            SELECT tc.table_name, ccu.table_name AS referenced_table_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.constraint_column_usage AS ccu
              ON ccu.constraint_name = tc.constraint_name
             AND ccu.constraint_schema = tc.constraint_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = 'public'
        ");
        foreach ($fkRows as $r) {
            $foreignKeys[] = (object) ['TABLE_NAME' => $r->table_name, 'REFERENCED_TABLE_NAME' => $r->referenced_table_name];
        }
    }

    $dependencyGraph = [];
    foreach ($resolvedCsvTablesList as $t) {
        $dependencyGraph[$t] = [];
    }
    foreach ($foreignKeys as $fk) {
        $child = (string) ($fk->TABLE_NAME ?? '');
        $parent = (string) ($fk->REFERENCED_TABLE_NAME ?? '');
        if ($child === '' || $parent === '') {
            continue;
        }
        if (! isset($dependencyGraph[$child]) || ! isset($dependencyGraph[$parent])) {
            continue;
        }
        if (! in_array($parent, $dependencyGraph[$child], true)) {
            $dependencyGraph[$child][] = $parent;
        }
    }

    $importPlan = [];
    $graphCopy = $dependencyGraph;
    while (! empty($graphCopy)) {
        $ready = array_keys(array_filter($graphCopy, static fn (array $deps): bool => empty($deps)));
        sort($ready);
        if (empty($ready)) {
            break;
        }
        foreach ($ready as $node) {
            $importPlan[] = $node;
            unset($graphCopy[$node]);
        }
        foreach ($graphCopy as $node => $deps) {
            $graphCopy[$node] = array_values(array_diff($deps, $ready));
        }
    }
    $cycleTables = array_keys($graphCopy);
    sort($cycleTables);

    $truncatePlan = array_values(array_unique(array_merge($transactionTables, $importPlan)));
    $truncatePlan = array_values(array_filter($truncatePlan, static fn (string $t): bool => $t !== ''));
    $truncatePlan = array_reverse($truncatePlan);
    $filteredReport = $report;
    if ($onlyDiff) {
        $filteredReport = array_values(array_filter($report, static function (array $entry): bool {
            if (isset($entry['error'])) {
                return true;
            }
            if (empty($entry['table'])) {
                return true;
            }

            return ! empty($entry['ambiguous'])
                || ! empty($entry['missing_in_db'])
                || ! empty($entry['missing_in_csv'])
                || ! empty($entry['duplicate_headers'])
                || (($entry['empty_headers'] ?? 0) > 0);
        }));
    }

    if ($asJson) {
        $this->line(json_encode([
            'summary' => [
                'csv_files' => count($csvFiles),
                'resolved_tables' => $resolvedCsvTablesList,
                'tables_without_csv' => $tablesWithoutCsv,
                'new_csv_tables' => $newCsvTables,
                'ambiguous_csv_tables' => $ambiguousCsv,
                'transaction_tables' => $transactionTables,
            ],
            'import_plan' => $importPlan,
            'truncate_plan' => $truncatePlan,
            'cycle_tables' => $cycleTables,
            'report' => $filteredReport,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ($strict && (! empty($newCsvTables) || ! empty($tablesWithoutCsv) || count($filteredReport) > 0)) ? 2 : 0;
    }

    $this->line($onlyDiff ? 'Archivos con diferencias: '.count($filteredReport) : 'Archivos verificados: '.count($filteredReport));
    $this->line('Tablas CSV sin resolver: '.count($newCsvTables));
    foreach ($newCsvTables as $t) {
        $this->warn('  - '.$t);
    }
    if (! empty($ambiguousCsv)) {
        $this->line('Tablas CSV con coincidencias ambiguas: '.count($ambiguousCsv));
        foreach ($ambiguousCsv as $base => $matches) {
            $this->warn('  - '.$base.': '.implode(', ', $matches));
        }
    }
    $this->line('Tablas en BD sin CSV: '.count($tablesWithoutCsv));
    foreach ($tablesWithoutCsv as $t) {
        $this->warn('  - '.$t);
    }
    $this->line('Tablas transaccionales (_trs) detectadas: '.count($transactionTables));
    foreach ($transactionTables as $t) {
        $this->line('  - '.$t);
    }
    $this->line('Plan de importación (por dependencias FK): '.count($importPlan));
    foreach ($importPlan as $t) {
        $this->line('  - '.$t);
    }
    if (! empty($cycleTables)) {
        $this->warn('Tablas con dependencias cíclicas: '.count($cycleTables));
        foreach ($cycleTables as $t) {
            $this->warn('  - '.$t);
        }
    }
    $this->line('Plan de truncado sugerido: '.count($truncatePlan));
    foreach ($truncatePlan as $t) {
        $this->line('  - '.$t);
    }

    foreach ($filteredReport as $entry) {
        $this->line('');
        $this->line($entry['file']);
        if (isset($entry['error'])) {
            $this->error('  '.$entry['error']);

            continue;
        }
        $table = $entry['table'] ?? null;
        $this->line('  Tabla: '.($table ?: 'sin resolver'));
        if (! empty($entry['ambiguous'])) {
            $this->warn('  Coincidencias posibles: '.implode(', ', $entry['ambiguous']));
        }
        if (! empty($entry['missing_in_db'])) {
            $this->warn('  Columnas en CSV no presentes en BD: '.implode(', ', $entry['missing_in_db']));
        }
        if (! empty($entry['missing_in_csv'])) {
            $this->warn('  Columnas en BD no presentes en CSV: '.implode(', ', $entry['missing_in_csv']));
        }
        if (! empty($entry['duplicate_headers'])) {
            $this->warn('  Encabezados duplicados: '.implode(', ', $entry['duplicate_headers']));
        }
        if (($entry['empty_headers'] ?? 0) > 0) {
            $this->warn('  Encabezados vacíos: '.$entry['empty_headers']);
        }
    }

    if ($strict && (! empty($newCsvTables) || ! empty($tablesWithoutCsv) || count($filteredReport) > 0)) {
        return 2;
    }

    return 0;
})->purpose('Validar CSVs contra el esquema de la base de datos');

Artisan::command('csv:migrate {--dir=} {--dry-run} {--yes} {--limit=0}', function () {
    $dirsOption = trim((string) $this->option('dir'));
    $dryRun = (bool) $this->option('dry-run');
    $autoYes = (bool) $this->option('yes');
    $limit = (int) $this->option('limit');

    $isAbsolute = static function (string $path): bool {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, '/');
    };

    $addCsvFile = static function (string $path, array &$files): void {
        if (! is_file($path)) {
            return;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            return;
        }
        $files[] = $path;
    };
    $detectExcel = static function (string $path): bool {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $sig = fread($fh, 4);
        fclose($fh);

        return $sig === "PK\x03\x04";
    };
    $readExcelSheet = static function (string $path): array {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);
        $highestCol = $sheet->getHighestColumn();
        $highestRow = $sheet->getHighestRow();
        $headerRow = $sheet->rangeToArray('A1:'.$highestCol.'1', null, true, false)[0] ?? [];

        return [$sheet, $highestCol, $highestRow, $headerRow];
    };

    $directories = [];
    $csvFiles = [];

    if ($dirsOption !== '') {
        foreach (array_filter(array_map('trim', explode(',', $dirsOption))) as $raw) {
            $candidatePaths = [];
            if ($isAbsolute($raw)) {
                $candidatePaths[] = $raw;
            } else {
                $candidatePaths[] = base_path($raw);
                $candidatePaths[] = base_path('..'.DIRECTORY_SEPARATOR.$raw);
            }
            foreach ($candidatePaths as $path) {
                if (is_dir($path)) {
                    $directories[] = $path;
                } elseif (is_file($path)) {
                    $addCsvFile($path, $csvFiles);
                }
            }
        }
    } else {
        foreach ([base_path('CSV'), base_path('..'.DIRECTORY_SEPARATOR.'CSV')] as $dir) {
            if (is_dir($dir)) {
                $directories[] = $dir;
            }
        }
        foreach ([base_path('Indice.csv'), base_path('..'.DIRECTORY_SEPARATOR.'Indice.csv')] as $file) {
            if (is_file($file)) {
                $addCsvFile($file, $csvFiles);
            }
        }
    }

    foreach ($directories as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $addCsvFile($path, $csvFiles);
        }
    }

    $csvFiles = array_values(array_unique($csvFiles));
    sort($csvFiles);

    if (empty($csvFiles)) {
        $this->error('No se encontraron CSVs.');

        return 1;
    }

    $driver = DB::getDriverName();
    $dbName = DB::getDatabaseName();
    $tables = [];

    if ($driver === 'mysql') {
        $tables = DB::table('information_schema.tables')
            ->select('table_name')
            ->where('table_schema', $dbName)
            ->pluck('table_name')
            ->all();
    } elseif ($driver === 'sqlite') {
        $tables = array_map(
            static fn ($r) => $r->name,
            DB::select("SELECT name FROM sqlite_master WHERE type='table'")
        );
    } elseif ($driver === 'pgsql') {
        $tables = array_map(
            static fn ($r) => $r->tablename,
            DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public'")
        );
    }

    $tablesLookup = array_fill_keys($tables, true);
    $suffixes = ['_cat', '_cfg', '_mst', '_trs'];
    $explicitMap = [
        'criterios_alerta' => 'criterios_nivel_alerta_cfg',
        'indice' => 'informacion_tablas',
        'informacion_tablas' => 'informacion_tablas',
        'riesgo_nivel_accion_set' => 'riesgo_nivel_accion_set_cfg',
        'tipo_riesgo_nivel_accion_set' => 'tipo_riesgo_nivel_accion_set_cfg',
    ];

    $normalizeBase = static function (string $name): string {
        $base = strtolower(trim($name));
        $base = preg_replace('/\.csv$/', '', $base) ?? $base;
        $base = preg_replace('/\s+/', '_', $base) ?? $base;
        $base = preg_replace('/[^a-z0-9_]+/', '_', $base) ?? $base;
        $base = preg_replace('/^\\d+_+/', '', $base) ?? $base;
        $base = preg_replace('/_+/', '_', $base) ?? $base;
        $base = trim($base, '_');
        $tokens = array_filter(explode('_', $base), static fn ($t) => $t !== '');
        $filtered = [];
        foreach ($tokens as $t) {
            if (in_array($t, ['apptabs', 'app', 'tabs', 'dataset', 'data', 'notebooklm', 'emta', 'main', 'df', 'general', 'resum'], true)) {
                continue;
            }
            $filtered[] = $t;
        }
        $base = implode('_', $filtered);
        $base = preg_replace('/_+/', '_', $base) ?? $base;

        return trim($base, '_');
    };

    $normalizeCol = static function (string $col): string {
        $col = preg_replace('/^\xEF\xBB\xBF/', '', $col) ?? $col;
        $col = trim($col);
        $col = trim($col, " \t\n\r\0\x0B\"'");
        $col = strtolower($col);
        $col = preg_replace('/\s+/', ' ', $col) ?? $col;

        return $col;
    };

    $resolveTable = static function (string $base) use ($tables, $tablesLookup, $suffixes, $explicitMap): array {
        if (isset($explicitMap[$base])) {
            $table = $explicitMap[$base];

            return ['table' => $table, 'ambiguous' => []];
        }
        if (isset($tablesLookup[$base])) {
            return ['table' => $base, 'ambiguous' => []];
        }
        foreach ($suffixes as $suffix) {
            $candidate = $base.$suffix;
            if (isset($tablesLookup[$candidate])) {
                return ['table' => $candidate, 'ambiguous' => []];
            }
        }
        $matches = [];
        foreach ($tables as $table) {
            $candidate = preg_replace('/_(cat|cfg|mst|trs)$/', '', $table) ?? $table;
            if ($candidate === $base) {
                $matches[] = $table;
            }
        }
        if (count($matches) === 1) {
            return ['table' => $matches[0], 'ambiguous' => []];
        }

        return ['table' => null, 'ambiguous' => $matches];
    };

    $total = count($csvFiles);
    $index = 0;

    foreach ($csvFiles as $file) {
        $index++;
        $this->line('');
        $this->line("CSV {$index}/{$total}: {$file}");

        $headers = [];
        $delimiter = ';';
        $sheet = null;
        $highestCol = '';
        $highestRow = 0;
        if ($detectExcel($file)) {
            [$sheet, $highestCol, $highestRow, $headers] = $readExcelSheet($file);
            $delimiter = 'xlsx';
        } else {
            $fh = fopen($file, 'rb');
            if ($fh === false) {
                $this->error('  No se pudo abrir el archivo.');
                continue;
            }

            $headerLine = null;
            while (($line = fgets($fh)) !== false) {
                if (trim($line) !== '') {
                    $headerLine = $line;
                    break;
                }
            }
            if ($headerLine === null) {
                fclose($fh);
                $this->error('  Archivo vacío.');
                continue;
            }

            $delimiterScores = [
                ';' => substr_count($headerLine, ';'),
                ',' => substr_count($headerLine, ','),
                "\t" => substr_count($headerLine, "\t"),
            ];
            arsort($delimiterScores);
            $delimiter = array_key_first($delimiterScores) ?: ';';
            $headers = str_getcsv($headerLine, $delimiter);
            fclose($fh);
        }

        $csvNormalized = [];
        $csvOriginal = [];
        $duplicates = [];
        $emptyHeaders = 0;

        foreach ($headers as $h) {
            if (! is_string($h)) {
                $emptyHeaders++;
                continue;
            }
            $normalized = $normalizeCol($h);
            if ($normalized === '') {
                $emptyHeaders++;
                continue;
            }
            if (array_key_exists($normalized, $csvOriginal)) {
                $duplicates[$normalized] = true;
            }
            $csvOriginal[$normalized] = trim($h);
            $csvNormalized[$normalized] = true;
        }

        $base = $normalizeBase(pathinfo($file, PATHINFO_FILENAME));
        $resolved = $resolveTable($base);
        $table = $resolved['table'];
        $ambiguous = $resolved['ambiguous'];

        if (! $table) {
            $this->warn('  Tabla: sin resolver');
            if (! empty($ambiguous)) {
                $this->warn('  Coincidencias posibles: '.implode(', ', $ambiguous));
            }
            continue;
        }

        $this->line('  Tabla: '.$table);
        if (! Schema::hasTable($table)) {
            $this->error('  La tabla no existe en la BD.');
            continue;
        }
        if (str_ends_with($table, '_trs')) {
            $this->warn('  Tabla transaccional (_trs): no se migra.');
            continue;
        }

        $dbCols = Schema::getColumnListing($table);
        $dbNormalized = [];
        $dbMap = [];
        foreach ($dbCols as $c) {
            $n = $normalizeCol($c);
            if ($n === '') {
                continue;
            }
            $dbNormalized[] = $n;
            if (! array_key_exists($n, $dbMap)) {
                $dbMap[$n] = $c;
            }
        }
        $dbSet = array_fill_keys($dbNormalized, true);
        $missingInDb = array_values(array_diff(array_keys($csvNormalized), array_keys($dbSet)));
        $missingInCsv = array_values(array_diff(array_keys($dbSet), array_keys($csvNormalized)));

        $requiredColumns = [];
        if ($driver === 'mysql') {
            $requiredColumns = array_values(array_map(
                static fn ($r) => $r->column_name,
                DB::table('information_schema.columns')
                    ->select('column_name', 'is_nullable', 'column_default', 'extra')
                    ->where('table_schema', $dbName)
                    ->where('table_name', $table)
                    ->where('is_nullable', 'NO')
                    ->get()
                    ->filter(static fn ($r) => $r->column_default === null && ! str_contains((string) ($r->extra ?? ''), 'auto_increment'))
                    ->all()
            ));
        } elseif ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA table_info('{$table}')");
            foreach ($rows as $r) {
                if (! empty($r->notnull) && ($r->dflt_value ?? null) === null) {
                    $requiredColumns[] = $r->name;
                }
            }
        } elseif ($driver === 'pgsql') {
            $rows = DB::select(
                "SELECT column_name, is_nullable, column_default FROM information_schema.columns WHERE table_name = ? AND table_schema = 'public'",
                [$table]
            );
            foreach ($rows as $r) {
                if ($r->is_nullable === 'NO' && $r->column_default === null) {
                    $requiredColumns[] = $r->column_name;
                }
            }
        }
        $requiredColumns = array_values(array_unique(array_filter($requiredColumns)));
        $requiredNormalized = array_values(array_unique(array_map($normalizeCol, $requiredColumns)));
        $requiredMissingHeaders = array_values(array_diff($requiredNormalized, array_keys($csvNormalized)));

        if (! empty($missingInDb)) {
            $this->warn('  Columnas en CSV no presentes en BD: '.implode(', ', $missingInDb));
        }
        if (! empty($missingInCsv)) {
            $this->warn('  Columnas en BD no presentes en CSV: '.implode(', ', $missingInCsv));
        }
        if (! empty($duplicates)) {
            $this->warn('  Encabezados duplicados: '.implode(', ', array_keys($duplicates)));
        }
        if ($emptyHeaders > 0) {
            $this->warn('  Encabezados vacíos: '.$emptyHeaders);
        }
        if (! empty($requiredMissingHeaders)) {
            $this->error('  Faltan columnas obligatorias en el CSV: '.implode(', ', $requiredMissingHeaders));
            continue;
        }

        $shouldMigrate = $autoYes ? true : $this->confirm('  ¿Migrar este CSV?', false);
        if (! $shouldMigrate) {
            $this->line('  Saltado.');
            continue;
        }

        if ($dryRun) {
            $this->line('  Modo dry-run: no se importaron datos.');
            continue;
        }

        $rowCount = 0;
        $imported = 0;
        $skipped = 0;
        $batch = [];
        $isTransactionTable = false;

        $importFn = function () use (
            $table,
            $isTransactionTable,
            $file,
            $delimiter,
            $sheet,
            $highestCol,
            $highestRow,
            $normalizeCol,
            $dbMap,
            $requiredColumns,
            $headers,
            $limit,
            &$rowCount,
            &$imported,
            &$skipped,
            &$batch
        ): void {
            if ($isTransactionTable) {
                DB::table($table)->delete();
            } else {
                DB::table($table)->truncate();
            }

            $headerIndex = [];
            foreach ($headers as $i => $h) {
                if (! is_string($h)) {
                    continue;
                }
                $normalized = $normalizeCol($h);
                if ($normalized === '' || ! isset($dbMap[$normalized])) {
                    continue;
                }
                $headerIndex[$i] = $dbMap[$normalized];
            }

            if ($delimiter === 'xlsx' && $sheet !== null) {
                for ($r = 2; $r <= $highestRow; $r++) {
                    if ($limit > 0 && $rowCount >= $limit) {
                        break;
                    }
                    $row = $sheet->rangeToArray('A'.$r.':'.$highestCol.$r, null, true, false)[0] ?? [];
                    $rowCount++;
                    $data = [];
                    foreach ($headerIndex as $idx => $col) {
                        $data[$col] = $row[$idx] ?? null;
                    }
                    $missingRequired = false;
                    foreach ($requiredColumns as $req) {
                        $value = $data[$req] ?? null;
                        if ($value === null || trim((string) $value) === '') {
                            $missingRequired = true;
                            break;
                        }
                    }
                    if ($missingRequired) {
                        $skipped++;
                        continue;
                    }
                    $hasData = false;
                    foreach ($data as $v) {
                        if ($v !== null && trim((string) $v) !== '') {
                            $hasData = true;
                            break;
                        }
                    }
                    if (! $hasData) {
                        continue;
                    }
                    $batch[] = $data;
                    if (count($batch) >= 500) {
                        DB::table($table)->insert($batch);
                        $imported += count($batch);
                        $batch = [];
                    }
                }
            } else {
                $fh = fopen($file, 'rb');
                if ($fh === false) {
                    return;
                }
                $headerSkipped = false;
                while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                    if (! $headerSkipped) {
                        $headerSkipped = true;
                        continue;
                    }
                    if ($limit > 0 && $rowCount >= $limit) {
                        break;
                    }
                    $rowCount++;
                    $data = [];
                    foreach ($headerIndex as $idx => $col) {
                        $data[$col] = $row[$idx] ?? null;
                    }
                    $missingRequired = false;
                    foreach ($requiredColumns as $req) {
                        $value = $data[$req] ?? null;
                        if ($value === null || trim((string) $value) === '') {
                            $missingRequired = true;
                            break;
                        }
                    }
                    if ($missingRequired) {
                        $skipped++;
                        continue;
                    }
                    $hasData = false;
                    foreach ($data as $v) {
                        if ($v !== null && trim((string) $v) !== '') {
                            $hasData = true;
                            break;
                        }
                    }
                    if (! $hasData) {
                        continue;
                    }
                    $batch[] = $data;
                    if (count($batch) >= 500) {
                        DB::table($table)->insert($batch);
                        $imported += count($batch);
                        $batch = [];
                    }
                }
                fclose($fh);
            }

            if (! empty($batch)) {
                DB::table($table)->insert($batch);
                $imported += count($batch);
                $batch = [];
            }
        };

        if ($isTransactionTable) {
            DB::transaction($importFn);
        } else {
            $importFn();
        }

        $this->info('  Importadas filas: '.$imported.' (leídas: '.$rowCount.', saltadas: '.$skipped.').');
    }

    return 0;
})->purpose('Migrar CSVs con revisión individual por tabla');

Artisan::command('inconsistencias:acciones {--out=}', function () {
    $outPath = trim((string) $this->option('out'));
    if ($outPath === '') {
        $outPath = storage_path('app/inconsistencias_acciones.csv');
    }
    $dir = dirname($outPath);
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pickColumn = static function (array $columns, array $patterns): ?string {
        foreach ($patterns as $pattern) {
            foreach ($columns as $c) {
                if (preg_match($pattern, $c)) {
                    return $c;
                }
            }
        }
        return null;
    };

    $accionCols = Schema::getColumnListing('accion_operativa_cfg');
    $detalleCols = Schema::getColumnListing('accion_set_detalle_cfg');
    $detalleCanalCols = Schema::getColumnListing('accion_set_detalle_canal_cfg');

    $accionPkCol = $pickColumn($accionCols, ['/-id$/i', '/ac_op.*id/i']);
    $accionCodCol = $pickColumn($accionCols, ['/-cod$/i', '/cod/i']);
    $accionDescCol = $pickColumn($accionCols, ['/descrip/i', '/descripcion/i', '/nombre/i']);
    $accionTenantCol = $pickColumn($accionCols, ['/tenant_id/i']);

    $detallePkCol = $pickColumn($detalleCols, ['/-id$/i', '/ac_se_de.*id/i']);
    $detalleAccionCol = $pickColumn($detalleCols, ['/-ac_op_id-fk$/i', '/ac_op/i']);
    $detalleRolCol = $pickColumn($detalleCols, ['/-rol_id-fk$/i', '/rol/i']);
    $detalleDescCol = $pickColumn($detalleCols, ['/detalle/i', '/descrip/i', '/descripcion/i', '/nombre/i']);
    $detalleTenantCol = $pickColumn($detalleCols, ['/tenant_id/i']);

    $detalleCanalDetalleCol = $pickColumn($detalleCanalCols, ['/-ac_se_de_id-fk$/i', '/ac_se_de/i']);
    $detalleCanalCanalCol = $pickColumn($detalleCanalCols, ['/-ca_co_id-fk$/i', '/ca_co/i', '/canal/i']);

    $accionRows = DB::table('accion_operativa_cfg')->get();
    $detalleRows = DB::table('accion_set_detalle_cfg')->get();
    $detalleCanalRows = DB::table('accion_set_detalle_canal_cfg')->get();

    $canalCountByDetalleId = [];
    if ($detalleCanalDetalleCol && $detalleCanalCanalCol) {
        foreach ($detalleCanalRows as $r) {
            $detId = trim((string) ($r->{$detalleCanalDetalleCol} ?? ''));
            $caId = trim((string) ($r->{$detalleCanalCanalCol} ?? ''));
            if ($detId === '' || $caId === '') {
                continue;
            }
            $canalCountByDetalleId[$detId] = ($canalCountByDetalleId[$detId] ?? 0) + 1;
        }
    }

    $detallesByAccion = [];
    foreach ($detalleRows as $d) {
        if (! $detalleAccionCol) {
            continue;
        }
        $accionId = trim((string) ($d->{$detalleAccionCol} ?? ''));
        if ($accionId === '') {
            continue;
        }
        $detallesByAccion[$accionId][] = $d;
    }

    $fh = fopen($outPath, 'wb');
    if ($fh === false) {
        $this->error('No se pudo abrir el archivo de salida: '.$outPath);
        return 1;
    }

    fputcsv($fh, [
        'accion_id',
        'accion_codigo',
        'accion_descripcion',
        'tenant_id',
        'detalle_id',
        'detalle_descripcion',
        'problemas',
    ]);

    $totalIssues = 0;

    foreach ($accionRows as $a) {
        $accionId = $accionPkCol ? trim((string) ($a->{$accionPkCol} ?? '')) : '';
        if ($accionId === '') {
            continue;
        }
        $accionCod = $accionCodCol ? trim((string) ($a->{$accionCodCol} ?? '')) : '';
        $accionDesc = $accionDescCol ? trim((string) ($a->{$accionDescCol} ?? '')) : '';
        $tenantId = $accionTenantCol ? trim((string) ($a->{$accionTenantCol} ?? '')) : '';

        $detalles = $detallesByAccion[$accionId] ?? [];
        if (empty($detalles)) {
            fputcsv($fh, [$accionId, $accionCod, $accionDesc, $tenantId, '', '', 'SIN_DETALLE']);
            $totalIssues++;
            continue;
        }

        foreach ($detalles as $d) {
            $issues = [];
            $detalleId = $detallePkCol ? trim((string) ($d->{$detallePkCol} ?? '')) : '';
            $detalleDesc = $detalleDescCol ? trim((string) ($d->{$detalleDescCol} ?? '')) : '';
            $rolId = $detalleRolCol ? trim((string) ($d->{$detalleRolCol} ?? '')) : '';

            if ($rolId === '') {
                $issues[] = 'ROL_VACIO';
            }
            if ($detalleDesc === '') {
                $issues[] = 'DETALLE_VACIO';
            }
            if ($detalleId !== '' && ($canalCountByDetalleId[$detalleId] ?? 0) === 0) {
                $issues[] = 'SIN_CANALES';
            }

            if (! empty($issues)) {
                fputcsv($fh, [
                    $accionId,
                    $accionCod,
                    $accionDesc,
                    $tenantId ?: ($detalleTenantCol ? trim((string) ($d->{$detalleTenantCol} ?? '')) : ''),
                    $detalleId,
                    $detalleDesc,
                    implode('|', $issues),
                ]);
                $totalIssues++;
            }
        }
    }

    fclose($fh);
    $this->info('CSV generado: '.$outPath);
    $this->info('Inconsistencias detectadas: '.$totalIssues);

    return 0;
})->purpose('Exporta inconsistencias de acciones operativas a CSV');
