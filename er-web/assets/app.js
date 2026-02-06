const SCHEMA_URL = "./data/schema.json";

function byId(id) {
  return document.getElementById(id);
}

function formatInt(n) {
  if (n === null || n === undefined) return "—";
  if (typeof n !== "number") return String(n);
  return new Intl.NumberFormat("es-ES").format(n);
}

function moduleLabel(moduleKey) {
  const map = {
    cfg: "Configuración (_cfg)",
    cat: "Catálogos (_cat)",
    mst: "Maestros (_mst)",
    trs: "Transacciones (_trs)",
    system: "Sistema (Laravel)",
    otros: "Otros",
  };
  return map[moduleKey] ?? moduleKey;
}

function groupTables(tables) {
  const groups = new Map();
  for (const t of tables) {
    const key = t.module ?? "otros";
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(t);
  }
  for (const [k, list] of groups) {
    list.sort((a, b) => a.name.localeCompare(b.name));
    groups.set(k, list);
  }
  return groups;
}

function buildDiagram({ moduleKey, tables, relationships }) {
  const lines = [];
  lines.push("flowchart LR");
  lines.push("  classDef node fill:#0f172a,stroke:rgba(255,255,255,0.18),color:#e5e7eb;");
  lines.push("  classDef group fill:#111c33,stroke:rgba(255,255,255,0.14),color:#e5e7eb;");

  const moduleTables = new Set(tables.map((t) => t.name));

  const moduleOrder = ["cfg", "cat", "mst", "trs", "otros", "system"];
  const groups = groupTables(tables);
  const orderedGroups = moduleOrder.filter((k) => groups.has(k)).concat([...groups.keys()].filter((k) => !moduleOrder.includes(k)));

  for (const key of orderedGroups) {
    const list = groups.get(key) ?? [];
    if (list.length === 0) continue;
    lines.push(`  subgraph ${key.toUpperCase()}["${moduleLabel(key)}"]`);
    lines.push("    direction TB");
    for (const t of list) {
      lines.push(`    ${t.name}["${t.name}"]:::node`);
    }
    lines.push("  end");
    lines.push(`  class ${key.toUpperCase()} group;`);
  }

  for (const rel of relationships) {
    const a = rel.table;
    const b = rel.referenced_table;
    if (!moduleTables.has(a) || !moduleTables.has(b)) continue;
    const label = rel.column ? `|${rel.column}|` : "";
    lines.push(`  ${a} -->${label} ${b}`);
  }

  if (moduleKey && moduleKey !== "all") {
    const allowed = new Set(tables.map((t) => t.name));
    const rels = relationships.filter((r) => allowed.has(r.table) && allowed.has(r.referenced_table));
    if (rels.length === 0) {
      lines.push(`  ${tables[0]?.name ?? "CFG"} --- ${tables[0]?.name ?? "CFG"}`);
    }
  }

  return lines.join("\n");
}

function renderMermaid(container, mermaidText, onClickTable) {
  container.innerHTML = `<pre class="mermaid">${mermaidText}</pre>`;

  if (!window.mermaid) {
    container.innerHTML = '<div class="diagram__loading">No se pudo cargar Mermaid (sin conexión).</div>';
    return;
  }

  window.mermaid.initialize({
    startOnLoad: false,
    theme: "dark",
    securityLevel: "loose",
    flowchart: { curve: "basis" },
  });

  window.mermaid.run({ nodes: container.querySelectorAll(".mermaid") }).then(() => {
    const svg = container.querySelector("svg");
    if (!svg) return;
    svg.style.maxWidth = "100%";
    svg.querySelectorAll("g.node").forEach((node) => {
      const title = node.querySelector("title");
      const id = title ? title.textContent : null;
      if (!id) return;
      node.style.cursor = "pointer";
      node.addEventListener("click", () => onClickTable(id));
    });
  });
}

function buildTableIndex(tables) {
  const map = new Map();
  for (const t of tables) map.set(t.name, t);
  return map;
}

function openModal({ table, outgoing, incoming }) {
  const modal = byId("modal");
  const title = byId("modalTitle");
  const subtitle = byId("modalSubtitle");
  const cols = byId("modalColumns");
  const rels = byId("modalRelations");

  title.textContent = table.name;
  subtitle.textContent = `${moduleLabel(table.module ?? "otros")} · ${formatInt(table.columns_count)} columnas · ${formatInt(table.rows_count)} filas`;

  const pk = new Set(table.primary_key ?? []);
  const colLines = (table.columns ?? []).map((c) => {
    const left = pk.has(c.name) ? "PK " : "   ";
    const type = c.type ? String(c.type) : "";
    const nullable = c.nullable ? "NULL" : "NOT NULL";
    return `${left}${c.name}  ${type}  ${nullable}`;
  });
  cols.textContent = colLines.join("\n") || "—";

  const blocks = [];
  for (const r of outgoing) {
    blocks.push({
      title: `${r.table}.${r.column} → ${r.referenced_table}.${r.referenced_column}`,
      meta: r.constraint_name ? `Constraint: ${r.constraint_name}` : "",
    });
  }
  for (const r of incoming) {
    blocks.push({
      title: `${r.table}.${r.column} → ${r.referenced_table}.${r.referenced_column}`,
      meta: r.constraint_name ? `Constraint: ${r.constraint_name}` : "",
    });
  }

  rels.innerHTML = "";
  if (blocks.length === 0) {
    rels.textContent = "—";
  } else {
    for (const b of blocks) {
      const el = document.createElement("div");
      el.className = "relation";
      el.innerHTML = `<div class="relation__title">${escapeHtml(b.title)}</div><div class="relation__meta">${escapeHtml(b.meta)}</div>`;
      rels.appendChild(el);
    }
  }

  modal.setAttribute("aria-hidden", "false");
}

function closeModal() {
  const modal = byId("modal");
  modal.setAttribute("aria-hidden", "true");
}

function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function renderModules(modulesList, groups, activeKey, onSelect) {
  modulesList.innerHTML = "";

  const entries = [...groups.entries()].map(([k, list]) => ({ key: k, count: list.length }));
  entries.sort((a, b) => b.count - a.count || a.key.localeCompare(b.key));
  entries.unshift({ key: "all", count: [...groups.values()].reduce((acc, list) => acc + list.length, 0) });

  for (const e of entries) {
    const el = document.createElement("div");
    el.className = `module${e.key === activeKey ? " module--active" : ""}`;
    el.innerHTML = `<div class="module__name">${escapeHtml(e.key === "all" ? "Todos los módulos" : moduleLabel(e.key))}</div><div class="module__count">${formatInt(e.count)}</div>`;
    el.addEventListener("click", () => onSelect(e.key));
    modulesList.appendChild(el);
  }
}

function renderTablesGrid(container, tables, onClick) {
  container.innerHTML = "";
  for (const t of tables) {
    const el = document.createElement("div");
    el.className = "card";
    el.innerHTML = `
      <div class="card__title">${escapeHtml(t.name)}</div>
      <div class="card__meta">
        <span>${escapeHtml(moduleLabel(t.module ?? "otros"))}</span>
        <span>${formatInt(t.columns_count)} columnas</span>
        <span>${formatInt(t.rows_count)} filas</span>
      </div>
    `;
    el.addEventListener("click", () => onClick(t.name));
    container.appendChild(el);
  }
}

async function loadSchema() {
  const res = await fetch(SCHEMA_URL, { cache: "no-store" });
  if (!res.ok) throw new Error(`No se pudo cargar schema.json (${res.status})`);
  return res.json();
}

function buildRelationsIndex(relationships) {
  const outgoing = new Map();
  const incoming = new Map();

  for (const r of relationships) {
    if (!outgoing.has(r.table)) outgoing.set(r.table, []);
    if (!incoming.has(r.referenced_table)) incoming.set(r.referenced_table, []);
    outgoing.get(r.table).push(r);
    incoming.get(r.referenced_table).push(r);
  }

  for (const [k, list] of outgoing) list.sort((a, b) => (a.referenced_table || "").localeCompare(b.referenced_table || ""));
  for (const [k, list] of incoming) list.sort((a, b) => (a.table || "").localeCompare(b.table || ""));

  return { outgoing, incoming };
}

async function main() {
  const modulesList = byId("modulesList");
  const sectionTitle = byId("sectionTitle");
  const diagram = byId("diagram");
  const grid = byId("tablesGrid");
  const searchInput = byId("searchInput");
  const refreshBtn = byId("refreshBtn");
  const dbMeta = byId("dbMeta");

  let schema;
  try {
    schema = await loadSchema();
  } catch (e) {
    diagram.innerHTML = `<div class="diagram__loading">${escapeHtml(e.message)}</div>`;
    return;
  }

  const tables = schema.tables ?? [];
  const relationships = schema.relationships ?? [];
  const tableIndex = buildTableIndex(tables);
  const groups = groupTables(tables);
  const relIndex = buildRelationsIndex(relationships);

  dbMeta.textContent = `DB: ${schema.db ?? "—"}\nGenerado: ${schema.generated_at ?? "—"}\nTablas: ${formatInt(tables.length)}\nRelaciones: ${formatInt(relationships.length)}`;

  let activeKey = "all";
  let search = "";

  function getActiveTables() {
    let list = tables;
    if (activeKey !== "all") list = groups.get(activeKey) ?? [];
    if (search.trim() !== "") {
      const q = search.trim().toLowerCase();
      list = list.filter((t) => t.name.toLowerCase().includes(q));
    }
    return list;
  }

  function render() {
    renderModules(modulesList, groups, activeKey, (key) => {
      activeKey = key;
      sectionTitle.textContent = key === "all" ? "Vista general" : moduleLabel(key);
      render();
    });

    const activeTables = getActiveTables();
    renderTablesGrid(grid, activeTables, (name) => onClickTable(name));

    const diagramTables =
      activeKey === "all"
        ? tables
        : (groups.get(activeKey) ?? []);

    const diagramText =
      activeKey === "all"
        ? buildDiagram({ moduleKey: "all", tables: tables, relationships })
        : buildDiagram({ moduleKey: activeKey, tables: diagramTables, relationships });

    renderMermaid(diagram, diagramText, (name) => onClickTable(name));
  }

  function onClickTable(name) {
    const table = tableIndex.get(name);
    if (!table) return;
    const outgoing = relIndex.outgoing.get(name) ?? [];
    const incoming = relIndex.incoming.get(name) ?? [];
    openModal({ table, outgoing, incoming });
  }

  refreshBtn.addEventListener("click", () => window.location.reload());
  searchInput.addEventListener("input", (e) => {
    search = e.target.value ?? "";
    render();
  });

  byId("modal").addEventListener("click", (e) => {
    const target = e.target;
    if (target && target.getAttribute && target.getAttribute("data-close") === "true") {
      closeModal();
    }
  });

  window.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeModal();
  });

  render();
}

main();

