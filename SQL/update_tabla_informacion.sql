START TRANSACTION;

UPDATE informacion_tablas
SET
  contenido_ca = LEFT(
    CASE nombre_tabla
      WHEN 'audit_log_trs' THEN 'Registre d’auditoria d’accions d’usuaris i processos sobre entitats del sistema.'
      WHEN 'control_panel_access_trs' THEN 'Permisos temporals d’accés al panell de control per activació i usuari.'
      WHEN 'criterios_nivel_alerta_cfg' THEN 'Configuració de criteris i llindars que suggereixen nivell d’alerta per risc.'
      WHEN 'dato_directorio_cat' THEN 'Catàleg de tipus de dada per a directori dinàmic (camps contactables o descriptius).'
      WHEN 'dato_grupo_directorio_cfg' THEN 'Relació entre grups de directori i tipus de dada habilitats.'
      WHEN 'directorio_contacto_mst' THEN 'Registres mestres de contactes del directori dinàmic (persona/entitat i les seves dades).'
      WHEN 'directorio_grupo_cat' THEN 'Catàleg d’agrupacions del directori dinàmic.'
      WHEN 'dominio_enum_cat' THEN 'Catàleg de valors enumerats de domini reutilitzats per múltiples taules.'
      WHEN 'ev_lugar_coordenada_mst' THEN 'Coordenades geogràfiques associades a llocs operatius.'
      WHEN 'ev_lugar_riesgo_trs' THEN 'Vinculació entre llocs i riscos aplicables.'
      WHEN 'grupos_directorio_cfg' THEN 'Configuració de grups funcionals del directori per tenant.'
      WHEN 'login_two_factor_tokens' THEN 'Tokens temporals per a verificació de doble factor en inici de sessió.'
      WHEN 'tenant_document_folders' THEN 'Carpetes de documentació per tenant per organitzar fitxers i enllaços.'
      WHEN 'tenant_document_link_riesgo_trs' THEN 'Relació entre enllaços documentals i riscos.'
      WHEN 'tenant_document_links' THEN 'Enllaços externs de documentació associats a carpetes del tenant.'
      WHEN 'tenant_document_riesgo_trs' THEN 'Relació entre documents carregats i riscos.'
      WHEN 'tenant_documents' THEN 'Fitxers documentals emmagatzemats per tenant amb metadades de càrrega.'
      ELSE COALESCE(NULLIF(TRIM(contenido_ca), ''), NULLIF(TRIM(contenido), ''), contenido_ca)
    END
  , 1000),

  finalidad_ca = LEFT(
    CASE nombre_tabla
      WHEN 'audit_log_trs' THEN 'Assegurar traçabilitat, revisió forense i compliment normatiu dels canvis i operacions crítiques.'
      WHEN 'control_panel_access_trs' THEN 'Gestionar accés de només lectura o compartit al panell sense comprometre la seguretat del tenant.'
      WHEN 'criterios_nivel_alerta_cfg' THEN 'Estandarditzar regles de decisió per a canvis de nivell i suport a l’activació guiada.'
      WHEN 'dato_directorio_cat' THEN 'Definir una estructura homogènia de dades per a directoris operatius reutilitzables.'
      WHEN 'dato_grupo_directorio_cfg' THEN 'Parametritzar quines columnes i dades aplica cada grup de directori per tenant.'
      WHEN 'directorio_contacto_mst' THEN 'Disposar d’una agenda operativa consolidada per a comunicació en emergència.'
      WHEN 'directorio_grupo_cat' THEN 'Organitzar contactes per dominis operatius per a consulta i manteniment.'
      WHEN 'dominio_enum_cat' THEN 'Centralitzar llistes de valors per coherència semàntica i validació de dades.'
      WHEN 'ev_lugar_coordenada_mst' THEN 'Donar suport a cartografia, geolocalització i anàlisi espacial en pantalles operatives.'
      WHEN 'ev_lugar_riesgo_trs' THEN 'Determinar context territorial del risc per a filtres, documentació i resposta.'
      WHEN 'grupos_directorio_cfg' THEN 'Permetre que cada tenant modeli la seva estructura de directori operatiu.'
      WHEN 'login_two_factor_tokens' THEN 'Reforçar la seguretat d’autenticació amb un segon factor de validació.'
      WHEN 'tenant_document_folders' THEN 'Estructurar repositori documental aïllat per tenant i facilitar navegació.'
      WHEN 'tenant_document_link_riesgo_trs' THEN 'Filtrar enllaços rellevants per risc actiu durant la gestió operativa.'
      WHEN 'tenant_document_links' THEN 'Complementar fitxers amb referències web oficials o repositoris externs.'
      WHEN 'tenant_document_riesgo_trs' THEN 'Servir documentació contextual al risc en pantalles operatives i panell de control.'
      WHEN 'tenant_documents' THEN 'Gestionar documents operatius amb aïllament multi-tenant i traçabilitat.'
      ELSE COALESCE(NULLIF(TRIM(finalidad_ca), ''), NULLIF(TRIM(finalidad), ''), finalidad_ca)
    END
  , 1000),

  contenido_en = LEFT(
    CASE nombre_tabla
      WHEN 'audit_log_trs' THEN 'Audit log of user and process actions on system entities.'
      WHEN 'control_panel_access_trs' THEN 'Temporary control-panel access permissions by activation and user.'
      WHEN 'criterios_nivel_alerta_cfg' THEN 'Configuration of criteria and thresholds that suggest alert level by risk.'
      WHEN 'dato_directorio_cat' THEN 'Catalog of data types for the dynamic directory (contact or descriptive fields).'
      WHEN 'dato_grupo_directorio_cfg' THEN 'Mapping between directory groups and enabled data types.'
      WHEN 'directorio_contacto_mst' THEN 'Master records for dynamic-directory contacts (person/entity and their data).'
      WHEN 'directorio_grupo_cat' THEN 'Catalog of dynamic-directory groups.'
      WHEN 'dominio_enum_cat' THEN 'Catalog of domain enum values reused by multiple tables.'
      WHEN 'ev_lugar_coordenada_mst' THEN 'Geographic coordinates associated with operational places.'
      WHEN 'ev_lugar_riesgo_trs' THEN 'Linking between places and applicable risks.'
      WHEN 'grupos_directorio_cfg' THEN 'Configuration of functional directory groups per tenant.'
      WHEN 'login_two_factor_tokens' THEN 'Temporary tokens for two-factor login verification.'
      WHEN 'tenant_document_folders' THEN 'Per-tenant documentation folders to organize files and links.'
      WHEN 'tenant_document_link_riesgo_trs' THEN 'Mapping between document links and risks.'
      WHEN 'tenant_document_links' THEN 'External documentation links associated with tenant folders.'
      WHEN 'tenant_document_riesgo_trs' THEN 'Mapping between uploaded documents and risks.'
      WHEN 'tenant_documents' THEN 'Document files stored per tenant with upload metadata.'
      ELSE COALESCE(NULLIF(TRIM(contenido_en), ''), NULLIF(TRIM(contenido), ''), contenido_en)
    END
  , 1000),

  finalidad_en = LEFT(
    CASE nombre_tabla
      WHEN 'audit_log_trs' THEN 'Ensure traceability, forensic review, and regulatory compliance for critical changes and operations.'
      WHEN 'control_panel_access_trs' THEN 'Manage read-only or shared panel access without compromising tenant security.'
      WHEN 'criterios_nivel_alerta_cfg' THEN 'Standardize decision rules for level changes and guided activation support.'
      WHEN 'dato_directorio_cat' THEN 'Define a consistent data structure for reusable operational directories.'
      WHEN 'dato_grupo_directorio_cfg' THEN 'Configure which columns and data apply to each directory group per tenant.'
      WHEN 'directorio_contacto_mst' THEN 'Provide a consolidated operational contact book for emergency communications.'
      WHEN 'directorio_grupo_cat' THEN 'Organize contacts by operational domains for lookup and maintenance.'
      WHEN 'dominio_enum_cat' THEN 'Centralize value lists for semantic consistency and data validation.'
      WHEN 'ev_lugar_coordenada_mst' THEN 'Support mapping, geolocation, and spatial analysis in operational screens.'
      WHEN 'ev_lugar_riesgo_trs' THEN 'Determine territorial risk context for filtering, documentation, and response.'
      WHEN 'grupos_directorio_cfg' THEN 'Allow each tenant to model its own operational directory structure.'
      WHEN 'login_two_factor_tokens' THEN 'Strengthen authentication security with a second validation factor.'
      WHEN 'tenant_document_folders' THEN 'Structure a tenant-isolated document repository and simplify navigation.'
      WHEN 'tenant_document_link_riesgo_trs' THEN 'Filter relevant links by active risk during operational management.'
      WHEN 'tenant_document_links' THEN 'Complement files with official web references or external repositories.'
      WHEN 'tenant_document_riesgo_trs' THEN 'Serve risk-contextual documentation in operational screens and control panel.'
      WHEN 'tenant_documents' THEN 'Manage operational documents with multi-tenant isolation and traceability.'
      ELSE COALESCE(NULLIF(TRIM(finalidad_en), ''), NULLIF(TRIM(finalidad), ''), finalidad_en)
    END
  , 1000);

COMMIT;