<?php
// 01_contratos/planillas/ver_planillas.php
session_start();
if (!isset($_SESSION['usuario'])) { header("Location: ../../login/login.php"); exit(); }
$permisos = ($_SESSION['permisos'] == 'all') ? [] : ($_SESSION['permisos'] ?? []);
if ($_SESSION['web_rol'] !== 'Admin') {
    $modulo_actual = 6; // RH
    if (!in_array($modulo_actual, $_SESSION['permisos'])) {
        header("Location: ../../login/none_permisos.php"); exit();
    }
}
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php"); // por si necesitas datos del usuario en header
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Historial de Planillas | Norte 360°</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../../img/norte360.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
  body { background:#f0f2f5; font-family:'Segoe UI',sans-serif; }
  .page-wrap { max-width:1200px; margin: 30px auto; }
  .card { border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,.08); }
  .badge-estado { border-radius:999px; padding:.35rem .65rem; font-weight:700; font-size:.78rem; }
  .estado-activo { background:#e8f5e9; color:#27ae60; border:2px solid #2ecc71; }
  .estado-cesado { background:#fdecea; color:#c0392b; border:2px solid #e74c3c; }
  .estado-otro { background:#f4f6f8; color:#7f8c8d; border:2px solid #95a5a6; }
  .table thead th { background:#2c3e50; color:#fff; }
  .filters .form-select, .filters .form-control { border-radius:10px; }
  .sticky-actions { position:sticky; top:0; z-index:2; background:#fff; padding:10px 0; }
  .truncate { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:280px; display:inline-block; }
</style>
</head>
<body>
<div class="page-wrap">
  <div class="card p-3 mb-3">
    <div class="d-flex align-items-center gap-2">
      <img src="../../img/norte360_black.png" style="height:40px">
      <h3 class="m-0">Historial de Planillas</h3>
      <div class="ms-auto">
        <a id="btnExport" class="btn btn-success btn-sm"><i class="bi bi-filetype-csv"></i> Exportar CSV</a>
      </div>
    </div>
    <hr>

    <!-- Filtros -->
    <div class="filters row g-2">
      <div class="col-12 col-lg-3">
        <label class="form-label mb-1">Buscar (Nombre o DNI)</label>
        <input id="f_search" type="text" class="form-control" placeholder="Ej: Juan / 12345678">
      </div>
      <div class="col-12 col-md-3 col-lg-3">
        <label class="form-label mb-1">Trabajador</label>
        <select id="f_trab" class="form-select">
          <option value="">Todos</option>
        </select>
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label mb-1">Estado</label>
        <select id="f_estado" class="form-select">
          <option value="">Todos</option>
          <option>ACTIVO</option>
          <option>VIGENTE</option>
          <option>EN PLANILLA</option>
          <option>INACTIVO</option>
          <option>CESADO</option>
          <option>BAJA</option>
        </select>
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label mb-1">Fecha por</label>
        <select id="f_ftipo" class="form-select">
          <option value="fechregistro">Registro</option>
          <option value="fechaingrespl">Ingreso Planilla</option>
          <option value="fechasalida">Salida</option>
        </select>
      </div>
      <div class="col-6 col-md-3 col-lg-1">
        <label class="form-label mb-1">Desde</label>
        <input id="f_desde" type="date" class="form-control">
      </div>
      <div class="col-6 col-md-3 col-lg-1">
        <label class="form-label mb-1">Hasta</label>
        <input id="f_hasta" type="date" class="form-control">
      </div>
      <div class="col-12 d-flex gap-2 mt-1">
        <button id="btnFiltrar" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
        <button id="btnLimpiar" class="btn btn-outline-secondary">Limpiar</button>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card p-0">
    <div class="table-responsive">
      <table class="table table-hover m-0">
        <thead>
          <tr>
            <th style="width:70px;">ID</th>
            <th>Trabajador</th>
            <th style="width:120px;">DNI</th>
            <th>Cargo</th>
            <th style="width:120px;">Estado</th>
            <th style="width:140px;">F. Registro</th>
            <th style="width:160px;">F. Ingreso Planilla</th>
            <th style="width:140px;">F. Salida</th>
            <th>Doc</th>
            <th>Comentario</th>
            <th style="width:90px;">Acciones</th>
          </tr>
        </thead>
        <tbody id="tbodyPlanillas">
          <tr><td colspan="11" class="text-center py-4">Cargando...</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <div class="p-2 d-flex align-items-center justify-content-between">
      <div><small id="lblResumen" class="text-muted"></small></div>
      <div class="d-flex gap-1" id="paginador"></div>
    </div>
  </div>
</div>

<!-- Modal Detalle -->
<div class="modal fade" id="mdlDetalle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Detalle de Planilla</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-0" id="dlDetalle"></dl>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const API = 'planillas_api.php';
    let state = { page:1, limit:50, sort:'t.clm_pl_fechregistro', dir:'DESC' };

    function qs(id){ return document.getElementById(id); }
    function esc(s){ return (s??'').toString().replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

    function badgeEstado(est) {
    const e = (est||'').toUpperCase();
    if (['ACTIVO','VIGENTE','EN PLANILLA'].includes(e)) return `<span class="badge-estado estado-activo">`+esc(e)+`</span>`;
    if (['CESADO','BAJA'].includes(e)) return `<span class="badge-estado estado-cesado">`+esc(e)+`</span>`;
    return `<span class="badge-estado estado-otro">`+esc(e||'—')+`</span>`;
    }

    async function cargarTrabajadores() {
    const res = await fetch(`${API}?action=trabajadores`);
    const j = await res.json();
    if (j.ok) {
        const sel = qs('f_trab');
        j.options.forEach(o=>{
        const opt = document.createElement('option');
        opt.value = o.clm_tra_id;
        opt.textContent = `${o.clm_tra_nombres} (${o.clm_tra_dni})`;
        sel.appendChild(opt);
        });
    }
    }

    function buildParams(extra={}) {
    const p = new URLSearchParams({
        action:'list',
        page: state.page, limit: state.limit, sort: state.sort, dir: state.dir,
        search: qs('f_search').value.trim(),
        trab_id: qs('f_trab').value,
        estado: qs('f_estado').value,
        fecha_tipo: qs('f_ftipo').value,
        desde: qs('f_desde').value,
        hasta: qs('f_hasta').value
    });
    Object.entries(extra).forEach(([k,v])=> p.set(k,v));
    return p.toString();
    }

    async function cargarTabla() {
    const res = await fetch(`${API}?${buildParams()}`);
    const j = await res.json();
    const tb = qs('tbodyPlanillas');
    tb.innerHTML = '';
    if (!j.ok) {
        tb.innerHTML = `<tr><td colspan="11" class="text-danger text-center py-4">${esc(j.error||'Error')}</td></tr>`;
        return;
    }
    if (!j.rows.length) {
        tb.innerHTML = `<tr><td colspan="11" class="text-center py-4">Sin resultados</td></tr>`;
    } else {
        j.rows.forEach(r=>{
        const linkDoc = r.clm_pl_doc ? `<a href="${esc(r.clm_pl_doc)}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip"></i></a>` : '<span class="text-muted">—</span>';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${esc(r.clm_pl_id)}</td>
            <td>${esc(r.clm_tra_nombres)}</td>
            <td>${esc(r.clm_tra_dni)}</td>
            <td>${esc(r.clm_tra_cargo||'')}</td>
            <td>${badgeEstado(r.clm_pl_tra_estado)}</td>
            <td>${esc(r.clm_pl_fechregistro||'')}</td>
            <td>${esc(r.clm_pl_fechaingrespl||'')}</td>
            <td>${esc(r.clm_pl_fechasalida||'')}</td>
            <td>${linkDoc}</td>
            <td><span class="truncate" title="${esc(r.clm_pl_com||'')}">${esc(r.clm_pl_com||'')}</span></td>
            <td>
            <button class="btn btn-sm btn-primary" onclick="verDetalle(${r.clm_pl_id})"><i class="bi bi-eye"></i></button>
            </td>
        `;
        tb.appendChild(tr);
        });
    }
    // Resumen y paginación
    const desde = (j.rows.length ? ((state.page-1)*state.limit+1) : 0);
    const hasta = (j.rows.length ? (desde + j.rows.length - 1) : 0);
    qs('lblResumen').textContent = `Mostrando ${desde}–${hasta} de ${j.total}`;

    const pag = qs('paginador'); pag.innerHTML = '';
    const totalPages = Math.max(1, Math.ceil(j.total/state.limit));
    function addBtn(label, page, active=false, disabled=false) {
        const btn = document.createElement('button');
        btn.className = `btn btn-sm ${active?'btn-primary':'btn-outline-primary'}`;
        btn.textContent = label;
        btn.disabled = disabled;
        btn.onclick = ()=>{ state.page = page; cargarTabla(); };
        pag.appendChild(btn);
    }
    addBtn('«', 1, false, state.page===1);
    addBtn('‹', Math.max(1,state.page-1), false, state.page===1);
    const windowSize = 5;
    const start = Math.max(1, state.page - Math.floor(windowSize/2));
    const end   = Math.min(totalPages, start + windowSize - 1);
    for (let p=start; p<=end; p++) addBtn(String(p), p, p===state.page, false);
    addBtn('›', Math.min(totalPages,state.page+1), false, state.page===totalPages);
    addBtn('»', totalPages, false, state.page===totalPages);
    }

    async function verDetalle(id) {
    const res = await fetch(`${API}?action=detalle&id=${id}`);
    const j = await res.json();
    const dl = qs('dlDetalle');
    dl.innerHTML = '';
    if (!j.ok || !j.row) {
        dl.innerHTML = `<div class="text-danger">No se encontró el registro.</div>`;
    } else {
        const r = j.row;
        const rows = [
        ['ID', r.clm_pl_id],
        ['Trabajador', r.clm_tra_nombres],
        ['DNI', r.clm_tra_dni],
        ['Cargo', r.clm_tra_cargo||''],
        ['Estado', r.clm_pl_tra_estado||''],
        ['Fecha Registro', r.clm_pl_fechregistro||''],
        ['Ingreso Planilla', r.clm_pl_fechaingrespl||''],
        ['Salida', r.clm_pl_fechasalida||''],
        ['Documento', r.clm_pl_doc ? `<a href="${esc(r.clm_pl_doc)}" target="_blank">Abrir</a>` : '—'],
        ['Comentario', esc(r.clm_pl_com||'')]
        ];
        rows.forEach(([k,v])=>{
        dl.insertAdjacentHTML('beforeend', `
            <dt class="col-sm-4">${k}</dt>
            <dd class="col-sm-8">${v}</dd>
        `);
        });
        new bootstrap.Modal(document.getElementById('mdlDetalle')).show();
    }
    }

    qs('btnFiltrar').addEventListener('click', ()=>{
    state.page = 1; cargarTabla();
    });
    qs('btnLimpiar').addEventListener('click', ()=>{
    ['f_search','f_trab','f_estado','f_ftipo','f_desde','f_hasta'].forEach(id=>{
        const el = qs(id);
        if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
    });
    state.page=1; cargarTabla();
    });
    qs('btnExport').addEventListener('click', ()=>{
    const url = `${API}?${buildParams({action:'export_csv'})}`;
    window.open(url, '_blank');
    });

    // Orden por click en cabecera (simple)
    document.querySelectorAll('th').forEach(th=>{
    th.style.cursor = 'pointer';
    th.addEventListener('click', ()=>{
        const map = {
        'ID':'t.clm_pl_id',
        'Trabajador':'tr.clm_tra_nombres',
        'DNI':'tr.clm_tra_dni',
        'Cargo':'tr.clm_tra_cargo',
        'Estado':'t.clm_pl_tra_estado',
        'F. Registro':'t.clm_pl_fechregistro',
        'F. Ingreso Planilla':'t.clm_pl_fechaingrespl',
        'F. Salida':'t.clm_pl_fechasalida'
        };
        const key = th.innerText.trim();
        if (!map[key]) return;
        if (state.sort === map[key]) { state.dir = (state.dir==='ASC'?'DESC':'ASC'); }
        else { state.sort = map[key]; state.dir = 'ASC'; }
        state.page = 1;
        cargarTabla();
    });
    });

    // Init
    (async function init(){
    await cargarTrabajadores();
    await cargarTabla();
    })();
</script>
</body>
</html>
