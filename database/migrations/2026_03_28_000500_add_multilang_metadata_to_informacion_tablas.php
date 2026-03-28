<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('informacion_tablas')) {
            return;
        }

        Schema::table('informacion_tablas', function (Blueprint $table) {
            if (! Schema::hasColumn('informacion_tablas', 'contenido_ca')) {
                $table->string('contenido_ca', 1000)->nullable()->after('contenido');
            }
            if (! Schema::hasColumn('informacion_tablas', 'finalidad_ca')) {
                $table->string('finalidad_ca', 1000)->nullable()->after('finalidad');
            }
            if (! Schema::hasColumn('informacion_tablas', 'contenido_en')) {
                $table->string('contenido_en', 1000)->nullable()->after('contenido_ca');
            }
            if (! Schema::hasColumn('informacion_tablas', 'finalidad_en')) {
                $table->string('finalidad_en', 1000)->nullable()->after('finalidad_ca');
            }
        });

        $rows = DB::table('informacion_tablas')->get(['id', 'contenido', 'finalidad', 'contenido_ca', 'finalidad_ca', 'contenido_en', 'finalidad_en']);
        foreach ($rows as $row) {
            $contenidoEs = trim((string) ($row->contenido ?? ''));
            $finalidadEs = trim((string) ($row->finalidad ?? ''));
            $contenidoCa = trim((string) ($row->contenido_ca ?? ''));
            $finalidadCa = trim((string) ($row->finalidad_ca ?? ''));
            $contenidoEn = trim((string) ($row->contenido_en ?? ''));
            $finalidadEn = trim((string) ($row->finalidad_en ?? ''));

            DB::table('informacion_tablas')
                ->where('id', $row->id)
                ->update([
                    'contenido_ca' => $contenidoCa !== '' ? mb_substr($contenidoCa, 0, 1000) : ($contenidoEs !== '' ? mb_substr($contenidoEs, 0, 1000) : null),
                    'finalidad_ca' => $finalidadCa !== '' ? mb_substr($finalidadCa, 0, 1000) : ($finalidadEs !== '' ? mb_substr($finalidadEs, 0, 1000) : null),
                    'contenido_en' => $contenidoEn !== '' ? mb_substr($contenidoEn, 0, 1000) : ($contenidoEs !== '' ? mb_substr($contenidoEs, 0, 1000) : null),
                    'finalidad_en' => $finalidadEn !== '' ? mb_substr($finalidadEn, 0, 1000) : ($finalidadEs !== '' ? mb_substr($finalidadEs, 0, 1000) : null),
                ]);
        }

        $entries = [
            'audit_log_trs' => [
                'es' => ['contenido' => 'Registro de auditoría de acciones de usuarios y procesos sobre entidades del sistema.', 'finalidad' => 'Asegurar trazabilidad, revisión forense y cumplimiento normativo de cambios y operaciones críticas.'],
                'ca' => ['contenido' => 'Registre d’auditoria d’accions d’usuaris i processos sobre entitats del sistema.', 'finalidad' => 'Assegurar traçabilitat, revisió forense i compliment normatiu dels canvis i operacions crítiques.'],
                'en' => ['contenido' => 'Audit log of user and process actions on system entities.', 'finalidad' => 'Ensure traceability, forensic review, and regulatory compliance for critical changes and operations.'],
            ],
            'control_panel_access_trs' => [
                'es' => ['contenido' => 'Permisos temporales de acceso al panel de control por activación y usuario.', 'finalidad' => 'Gestionar acceso de solo lectura o compartido al panel sin comprometer la seguridad del tenant.'],
                'ca' => ['contenido' => 'Permisos temporals d’accés al panell de control per activació i usuari.', 'finalidad' => 'Gestionar accés de només lectura o compartit al panell sense comprometre la seguretat del tenant.'],
                'en' => ['contenido' => 'Temporary control-panel access permissions by activation and user.', 'finalidad' => 'Manage read-only or shared panel access without compromising tenant security.'],
            ],
            'criterios_nivel_alerta_cfg' => [
                'es' => ['contenido' => 'Configuración de criterios y umbrales que sugieren nivel de alerta por riesgo.', 'finalidad' => 'Estandarizar reglas de decisión para cambios de nivel y soporte a activación guiada.'],
                'ca' => ['contenido' => 'Configuració de criteris i llindars que suggereixen nivell d’alerta per risc.', 'finalidad' => 'Estandarditzar regles de decisió per a canvis de nivell i suport a l’activació guiada.'],
                'en' => ['contenido' => 'Configuration of criteria and thresholds that suggest alert level by risk.', 'finalidad' => 'Standardize decision rules for level changes and guided activation support.'],
            ],
            'dato_directorio_cat' => [
                'es' => ['contenido' => 'Catálogo de tipos de dato para directorio dinámico (campos contactables o descriptivos).', 'finalidad' => 'Definir estructura homogénea de datos para directorios operativos reutilizables.'],
                'ca' => ['contenido' => 'Catàleg de tipus de dada per a directori dinàmic (camps contactables o descriptius).', 'finalidad' => 'Definir una estructura homogènia de dades per a directoris operatius reutilitzables.'],
                'en' => ['contenido' => 'Catalog of data types for the dynamic directory (contact or descriptive fields).', 'finalidad' => 'Define a consistent data structure for reusable operational directories.'],
            ],
            'dato_grupo_directorio_cfg' => [
                'es' => ['contenido' => 'Relación entre grupos de directorio y tipos de dato habilitados.', 'finalidad' => 'Parametrizar qué columnas y datos aplica cada grupo de directorio por tenant.'],
                'ca' => ['contenido' => 'Relació entre grups de directori i tipus de dada habilitats.', 'finalidad' => 'Parametritzar quines columnes i dades aplica cada grup de directori per tenant.'],
                'en' => ['contenido' => 'Mapping between directory groups and enabled data types.', 'finalidad' => 'Configure which columns and data apply to each directory group per tenant.'],
            ],
            'directorio_contacto_mst' => [
                'es' => ['contenido' => 'Registros maestros de contactos del directorio dinámico (persona/entidad y sus datos).', 'finalidad' => 'Disponer de una agenda operativa consolidada para comunicación en emergencia.'],
                'ca' => ['contenido' => 'Registres mestres de contactes del directori dinàmic (persona/entitat i les seves dades).', 'finalidad' => 'Disposar d’una agenda operativa consolidada per a comunicació en emergència.'],
                'en' => ['contenido' => 'Master records for dynamic-directory contacts (person/entity and their data).', 'finalidad' => 'Provide a consolidated operational contact book for emergency communications.'],
            ],
            'directorio_grupo_cat' => [
                'es' => ['contenido' => 'Catálogo de agrupaciones del directorio dinámico.', 'finalidad' => 'Organizar contactos por dominios operativos para consulta y mantenimiento.'],
                'ca' => ['contenido' => 'Catàleg d’agrupacions del directori dinàmic.', 'finalidad' => 'Organitzar contactes per dominis operatius per a consulta i manteniment.'],
                'en' => ['contenido' => 'Catalog of dynamic-directory groups.', 'finalidad' => 'Organize contacts by operational domains for lookup and maintenance.'],
            ],
            'dominio_enum_cat' => [
                'es' => ['contenido' => 'Catálogo de valores enumerados de dominio reutilizados por múltiples tablas.', 'finalidad' => 'Centralizar listas de valores para coherencia semántica y validación de datos.'],
                'ca' => ['contenido' => 'Catàleg de valors enumerats de domini reutilitzats per múltiples taules.', 'finalidad' => 'Centralitzar llistes de valors per coherència semàntica i validació de dades.'],
                'en' => ['contenido' => 'Catalog of domain enum values reused by multiple tables.', 'finalidad' => 'Centralize value lists for semantic consistency and data validation.'],
            ],
            'ev_lugar_coordenada_mst' => [
                'es' => ['contenido' => 'Coordenadas geográficas asociadas a lugares operativos.', 'finalidad' => 'Soportar cartografía, geolocalización y análisis espacial en pantallas operativas.'],
                'ca' => ['contenido' => 'Coordenades geogràfiques associades a llocs operatius.', 'finalidad' => 'Donar suport a cartografia, geolocalització i anàlisi espacial en pantalles operatives.'],
                'en' => ['contenido' => 'Geographic coordinates associated with operational places.', 'finalidad' => 'Support mapping, geolocation, and spatial analysis in operational screens.'],
            ],
            'ev_lugar_riesgo_trs' => [
                'es' => ['contenido' => 'Vinculación entre lugares y riesgos aplicables.', 'finalidad' => 'Determinar contexto territorial del riesgo para filtros, documentación y respuesta.'],
                'ca' => ['contenido' => 'Vinculació entre llocs i riscos aplicables.', 'finalidad' => 'Determinar context territorial del risc per a filtres, documentació i resposta.'],
                'en' => ['contenido' => 'Linking between places and applicable risks.', 'finalidad' => 'Determine territorial risk context for filtering, documentation, and response.'],
            ],
            'grupos_directorio_cfg' => [
                'es' => ['contenido' => 'Configuración de grupos funcionales del directorio por tenant.', 'finalidad' => 'Permitir que cada tenant modele su estructura de directorio operativo.'],
                'ca' => ['contenido' => 'Configuració de grups funcionals del directori per tenant.', 'finalidad' => 'Permetre que cada tenant modeli la seva estructura de directori operatiu.'],
                'en' => ['contenido' => 'Configuration of functional directory groups per tenant.', 'finalidad' => 'Allow each tenant to model its own operational directory structure.'],
            ],
            'login_two_factor_tokens' => [
                'es' => ['contenido' => 'Tokens temporales para verificación de doble factor en inicio de sesión.', 'finalidad' => 'Reforzar seguridad de autenticación con un segundo factor de validación.'],
                'ca' => ['contenido' => 'Tokens temporals per a verificació de doble factor en inici de sessió.', 'finalidad' => 'Reforçar la seguretat d’autenticació amb un segon factor de validació.'],
                'en' => ['contenido' => 'Temporary tokens for two-factor login verification.', 'finalidad' => 'Strengthen authentication security with a second validation factor.'],
            ],
            'tenant_document_folders' => [
                'es' => ['contenido' => 'Carpetas de documentación por tenant para organizar archivos y enlaces.', 'finalidad' => 'Estructurar repositorio documental aislado por tenant y facilitar navegación.'],
                'ca' => ['contenido' => 'Carpetes de documentació per tenant per organitzar fitxers i enllaços.', 'finalidad' => 'Estructurar repositori documental aïllat per tenant i facilitar navegació.'],
                'en' => ['contenido' => 'Per-tenant documentation folders to organize files and links.', 'finalidad' => 'Structure a tenant-isolated document repository and simplify navigation.'],
            ],
            'tenant_document_link_riesgo_trs' => [
                'es' => ['contenido' => 'Relación entre enlaces documentales y riesgos.', 'finalidad' => 'Filtrar enlaces relevantes por riesgo activo durante la gestión operativa.'],
                'ca' => ['contenido' => 'Relació entre enllaços documentals i riscos.', 'finalidad' => 'Filtrar enllaços rellevants per risc actiu durant la gestió operativa.'],
                'en' => ['contenido' => 'Mapping between document links and risks.', 'finalidad' => 'Filter relevant links by active risk during operational management.'],
            ],
            'tenant_document_links' => [
                'es' => ['contenido' => 'Enlaces externos de documentación asociados a carpetas del tenant.', 'finalidad' => 'Complementar archivos con referencias web oficiales o repositorios externos.'],
                'ca' => ['contenido' => 'Enllaços externs de documentació associats a carpetes del tenant.', 'finalidad' => 'Complementar fitxers amb referències web oficials o repositoris externs.'],
                'en' => ['contenido' => 'External documentation links associated with tenant folders.', 'finalidad' => 'Complement files with official web references or external repositories.'],
            ],
            'tenant_document_riesgo_trs' => [
                'es' => ['contenido' => 'Relación entre documentos cargados y riesgos.', 'finalidad' => 'Servir documentación contextual al riesgo en pantallas operativas y panel de control.'],
                'ca' => ['contenido' => 'Relació entre documents carregats i riscos.', 'finalidad' => 'Servir documentació contextual al risc en pantalles operatives i panell de control.'],
                'en' => ['contenido' => 'Mapping between uploaded documents and risks.', 'finalidad' => 'Serve risk-contextual documentation in operational screens and control panel.'],
            ],
            'tenant_documents' => [
                'es' => ['contenido' => 'Archivos documentales almacenados por tenant con metadatos de carga.', 'finalidad' => 'Gestionar documentos operativos con aislamiento multi-tenant y trazabilidad.'],
                'ca' => ['contenido' => 'Fitxers documentals emmagatzemats per tenant amb metadades de càrrega.', 'finalidad' => 'Gestionar documents operatius amb aïllament multi-tenant i traçabilitat.'],
                'en' => ['contenido' => 'Document files stored per tenant with upload metadata.', 'finalidad' => 'Manage operational documents with multi-tenant isolation and traceability.'],
            ],
        ];

        foreach ($entries as $table => $texts) {
            $esContenido = mb_substr((string) ($texts['es']['contenido'] ?? ''), 0, 1000);
            $esFinalidad = mb_substr((string) ($texts['es']['finalidad'] ?? ''), 0, 1000);
            $caContenido = mb_substr((string) ($texts['ca']['contenido'] ?? $esContenido), 0, 1000);
            $caFinalidad = mb_substr((string) ($texts['ca']['finalidad'] ?? $esFinalidad), 0, 1000);
            $enContenido = mb_substr((string) ($texts['en']['contenido'] ?? $esContenido), 0, 1000);
            $enFinalidad = mb_substr((string) ($texts['en']['finalidad'] ?? $esFinalidad), 0, 1000);

            DB::table('informacion_tablas')->updateOrInsert(
                ['nombre_tabla' => $table],
                [
                    'contenido' => $esContenido,
                    'finalidad' => $esFinalidad,
                    'contenido_ca' => $caContenido,
                    'finalidad_ca' => $caFinalidad,
                    'contenido_en' => $enContenido,
                    'finalidad_en' => $enFinalidad,
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('informacion_tablas')) {
            return;
        }

        Schema::table('informacion_tablas', function (Blueprint $table) {
            if (Schema::hasColumn('informacion_tablas', 'contenido_ca')) {
                $table->dropColumn('contenido_ca');
            }
            if (Schema::hasColumn('informacion_tablas', 'finalidad_ca')) {
                $table->dropColumn('finalidad_ca');
            }
            if (Schema::hasColumn('informacion_tablas', 'contenido_en')) {
                $table->dropColumn('contenido_en');
            }
            if (Schema::hasColumn('informacion_tablas', 'finalidad_en')) {
                $table->dropColumn('finalidad_en');
            }
        });
    }
};
