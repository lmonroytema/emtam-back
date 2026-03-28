START TRANSACTION;

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'informacion_tablas' AND column_name = 'contenido_ca') = 0,
  'ALTER TABLE informacion_tablas ADD COLUMN contenido_ca VARCHAR(1000) NULL AFTER contenido',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'informacion_tablas' AND column_name = 'finalidad_ca') = 0,
  'ALTER TABLE informacion_tablas ADD COLUMN finalidad_ca VARCHAR(1000) NULL AFTER finalidad',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'informacion_tablas' AND column_name = 'contenido_en') = 0,
  'ALTER TABLE informacion_tablas ADD COLUMN contenido_en VARCHAR(1000) NULL AFTER contenido_ca',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'informacion_tablas' AND column_name = 'finalidad_en') = 0,
  'ALTER TABLE informacion_tablas ADD COLUMN finalidad_en VARCHAR(1000) NULL AFTER finalidad_ca',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE informacion_tablas
SET
  contenido_ca = LEFT(COALESCE(NULLIF(TRIM(contenido_ca), ''), NULLIF(TRIM(contenido), '')), 1000),
  finalidad_ca = LEFT(COALESCE(NULLIF(TRIM(finalidad_ca), ''), NULLIF(TRIM(finalidad), '')), 1000),
  contenido_en = LEFT(COALESCE(NULLIF(TRIM(contenido_en), ''), NULLIF(TRIM(contenido), '')), 1000),
  finalidad_en = LEFT(COALESCE(NULLIF(TRIM(finalidad_en), ''), NULLIF(TRIM(finalidad), '')), 1000);

INSERT INTO informacion_tablas (
  nombre_tabla,
  contenido, finalidad,
  contenido_ca, finalidad_ca,
  contenido_en, finalidad_en
) VALUES
(
  'audit_log_trs',
  'Registro de auditoría de acciones de usuarios y procesos sobre entidades del sistema.',
  'Asegurar trazabilidad, revisión forense y cumplimiento normativo de cambios y operaciones críticas.',
  'Registre d’auditoria d’accions d’usuaris i processos sobre entitats del sistema.',
  'Assegurar traçabilitat, revisió forense i compliment normatiu dels canvis i operacions crítiques.',
  'Audit log of user and process actions on system entities.',
  'Ensure traceability, forensic review, and regulatory compliance for critical changes and operations.'
),
(
  'control_panel_access_trs',
  'Permisos temporales de acceso al panel de control por activación y usuario.',
  'Gestionar acceso de solo lectura o compartido al panel sin comprometer la seguridad del tenant.',
  'Permisos temporals d’accés al panell de control per activació i usuari.',
  'Gestionar accés de només lectura o compartit al panell sense comprometre la seguretat del tenant.',
  'Temporary control-panel access permissions by activation and user.',
  'Manage read-only or shared panel access without compromising tenant security.'
),
(
  'criterios_nivel_alerta_cfg',
  'Configuración de criterios y umbrales que sugieren nivel de alerta por riesgo.',
  'Estandarizar reglas de decisión para cambios de nivel y soporte a activación guiada.',
  'Configuració de criteris i llindars que suggereixen nivell d’alerta per risc.',
  'Estandarditzar regles de decisió per a canvis de nivell i suport a l’activació guiada.',
  'Configuration of criteria and thresholds that suggest alert level by risk.',
  'Standardize decision rules for level changes and guided activation support.'
),
(
  'dato_directorio_cat',
  'Catálogo de tipos de dato para directorio dinámico (campos contactables o descriptivos).',
  'Definir estructura homogénea de datos para directorios operativos reutilizables.',
  'Catàleg de tipus de dada per a directori dinàmic (camps contactables o descriptius).',
  'Definir una estructura homogènia de dades per a directoris operatius reutilitzables.',
  'Catalog of data types for the dynamic directory (contact or descriptive fields).',
  'Define a consistent data structure for reusable operational directories.'
),
(
  'dato_grupo_directorio_cfg',
  'Relación entre grupos de directorio y tipos de dato habilitados.',
  'Parametrizar qué columnas y datos aplica cada grupo de directorio por tenant.',
  'Relació entre grups de directori i tipus de dada habilitats.',
  'Parametritzar quines columnes i dades aplica cada grup de directori per tenant.',
  'Mapping between directory groups and enabled data types.',
  'Configure which columns and data apply to each directory group per tenant.'
),
(
  'directorio_contacto_mst',
  'Registros maestros de contactos del directorio dinámico (persona/entidad y sus datos).',
  'Disponer de una agenda operativa consolidada para comunicación en emergencia.',
  'Registres mestres de contactes del directori dinàmic (persona/entitat i les seves dades).',
  'Disposar d’una agenda operativa consolidada per a comunicació en emergència.',
  'Master records for dynamic-directory contacts (person/entity and their data).',
  'Provide a consolidated operational contact book for emergency communications.'
),
(
  'directorio_grupo_cat',
  'Catálogo de agrupaciones del directorio dinámico.',
  'Organizar contactos por dominios operativos para consulta y mantenimiento.',
  'Catàleg d’agrupacions del directori dinàmic.',
  'Organitzar contactes per dominis operatius per a consulta i manteniment.',
  'Catalog of dynamic-directory groups.',
  'Organize contacts by operational domains for lookup and maintenance.'
),
(
  'dominio_enum_cat',
  'Catálogo de valores enumerados de dominio reutilizados por múltiples tablas.',
  'Centralizar listas de valores para coherencia semántica y validación de datos.',
  'Catàleg de valors enumerats de domini reutilitzats per múltiples taules.',
  'Centralitzar llistes de valors per coherència semàntica i validació de dades.',
  'Catalog of domain enum values reused by multiple tables.',
  'Centralize value lists for semantic consistency and data validation.'
),
(
  'ev_lugar_coordenada_mst',
  'Coordenadas geográficas asociadas a lugares operativos.',
  'Soportar cartografía, geolocalización y análisis espacial en pantallas operativas.',
  'Coordenades geogràfiques associades a llocs operatius.',
  'Donar suport a cartografia, geolocalització i anàlisi espacial en pantalles operatives.',
  'Geographic coordinates associated with operational places.',
  'Support mapping, geolocation, and spatial analysis in operational screens.'
),
(
  'ev_lugar_riesgo_trs',
  'Vinculación entre lugares y riesgos aplicables.',
  'Determinar contexto territorial del riesgo para filtros, documentación y respuesta.',
  'Vinculació entre llocs i riscos aplicables.',
  'Determinar context territorial del risc per a filtres, documentació i resposta.',
  'Linking between places and applicable risks.',
  'Determine territorial risk context for filtering, documentation, and response.'
),
(
  'grupos_directorio_cfg',
  'Configuración de grupos funcionales del directorio por tenant.',
  'Permitir que cada tenant modele su estructura de directorio operativo.',
  'Configuració de grups funcionals del directori per tenant.',
  'Permetre que cada tenant modeli la seva estructura de directori operatiu.',
  'Configuration of functional directory groups per tenant.',
  'Allow each tenant to model its own operational directory structure.'
),
(
  'login_two_factor_tokens',
  'Tokens temporales para verificación de doble factor en inicio de sesión.',
  'Reforzar seguridad de autenticación con un segundo factor de validación.',
  'Tokens temporals per a verificació de doble factor en inici de sessió.',
  'Reforçar la seguretat d’autenticació amb un segon factor de validació.',
  'Temporary tokens for two-factor login verification.',
  'Strengthen authentication security with a second validation factor.'
),
(
  'tenant_document_folders',
  'Carpetas de documentación por tenant para organizar archivos y enlaces.',
  'Estructurar repositorio documental aislado por tenant y facilitar navegación.',
  'Carpetes de documentació per tenant per organitzar fitxers i enllaços.',
  'Estructurar repositori documental aïllat per tenant i facilitar navegació.',
  'Per-tenant documentation folders to organize files and links.',
  'Structure a tenant-isolated document repository and simplify navigation.'
),
(
  'tenant_document_link_riesgo_trs',
  'Relación entre enlaces documentales y riesgos.',
  'Filtrar enlaces relevantes por riesgo activo durante la gestión operativa.',
  'Relació entre enllaços documentals i riscos.',
  'Filtrar enllaços rellevants per risc actiu durant la gestió operativa.',
  'Mapping between document links and risks.',
  'Filter relevant links by active risk during operational management.'
),
(
  'tenant_document_links',
  'Enlaces externos de documentación asociados a carpetas del tenant.',
  'Complementar archivos con referencias web oficiales o repositorios externos.',
  'Enllaços externs de documentació associats a carpetes del tenant.',
  'Complementar fitxers amb referències web oficials o repositoris externs.',
  'External documentation links associated with tenant folders.',
  'Complement files with official web references or external repositories.'
),
(
  'tenant_document_riesgo_trs',
  'Relación entre documentos cargados y riesgos.',
  'Servir documentación contextual al riesgo en pantallas operativas y panel de control.',
  'Relació entre documents carregats i riscos.',
  'Servir documentació contextual al risc en pantalles operatives i panell de control.',
  'Mapping between uploaded documents and risks.',
  'Serve risk-contextual documentation in operational screens and control panel.'
),
(
  'tenant_documents',
  'Archivos documentales almacenados por tenant con metadatos de carga.',
  'Gestionar documentos operativos con aislamiento multi-tenant y trazabilidad.',
  'Fitxers documentals emmagatzemats per tenant amb metadades de càrrega.',
  'Gestionar documents operatius amb aïllament multi-tenant i traçabilitat.',
  'Document files stored per tenant with upload metadata.',
  'Manage operational documents with multi-tenant isolation and traceability.'
)
ON DUPLICATE KEY UPDATE
  contenido = VALUES(contenido),
  finalidad = VALUES(finalidad),
  contenido_ca = VALUES(contenido_ca),
  finalidad_ca = VALUES(finalidad_ca),
  contenido_en = VALUES(contenido_en),
  finalidad_en = VALUES(finalidad_en);

COMMIT;