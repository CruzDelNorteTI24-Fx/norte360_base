<?php
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
// php\ver_nota_salida.php:
function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_qty($n){
    $s = number_format((float)$n, 4, '.', ',');
    return rtrim(rtrim($s, '0'), '.');
}

$id_mov = intval($_GET['id'] ?? 0);

if ($conn->connect_error) {
    echo "<div class='nota360 nota-empty nota-error'><strong>Error de conexión</strong><p>No se pudo conectar a la base de datos.</p></div>";
    exit;
}

if ($id_mov <= 0) {
    echo "<div class='nota360 nota-empty nota-error'><strong>Movimiento no válido</strong><p>No se recibió un ID de movimiento correcto.</p></div>";
    exit;
}

// Buscar la nota de salida relacionada al movimiento
$sql = "
SELECT 
    ns.clm_nota_id AS nota_id,
    ns.clm_nota_serie AS nota_serie,
    ns.clm_nota_sco AS correlativo,
    ns.clm_nota_fecha AS fecha_completa,
    COALESCE(NULLIF(TRIM(u.nombre), ''), NULLIF(TRIM(ns.clm_nota_responsable), ''), 'No registrado') AS responsable,
    CASE
      WHEN p.clm_placas_id IS NOT NULL
           AND TRIM(COALESCE(p.clm_placas_BUS, '')) <> ''
           AND TRIM(COALESCE(p.clm_placas_PLACA, '')) <> ''
      THEN CONCAT(TRIM(p.clm_placas_BUS), ' (', TRIM(p.clm_placas_PLACA), ')')

      WHEN p.clm_placas_id IS NOT NULL
           AND TRIM(COALESCE(p.clm_placas_BUS, '')) <> ''
      THEN TRIM(p.clm_placas_BUS)

      WHEN p.clm_placas_id IS NOT NULL
           AND TRIM(COALESCE(p.clm_placas_PLACA, '')) <> ''
      THEN TRIM(p.clm_placas_PLACA)

      ELSE 'Sin unidad vinculada'
    END AS placa_real,
    COALESCE(NULLIF(TRIM(ns.clm_nota_modulo), ''), 'No especificado') AS area,
    COALESCE(NULLIF(TRIM(ns.clm_nota_motivo), ''), 'Sin motivo registrado') AS motivo
FROM tb_notas_salida ns
JOIN tb_alm_movimientos m ON ns.clm_nota_id = m.clm_alm_mov_idNOTA
LEFT JOIN tb_placas p ON ns.clm_nota_placa = p.clm_placas_id
LEFT JOIN tb_usuarios u ON ns.clm_nota_responsable = u.usuario
WHERE m.clm_alm_mov_id = ?
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id_mov);
$stmt->execute();
$res = $stmt->get_result();
$nota = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$nota) {
    ?>
    <style>
      .nota360{font-family:'Segoe UI',system-ui,sans-serif;color:#0f172a;}
      .nota-empty{background:#fff5f5;border:1px solid #fecaca;border-radius:18px;padding:22px;text-align:center;color:#991b1b;}
      .nota-empty strong{display:block;font-size:1.05rem;margin-bottom:6px;}
      .nota-empty p{margin:0;color:#7f1d1d;}
    </style>
    <div class="nota360 nota-empty">
        <strong>No se encontró la Nota de Almacén</strong>
        <p>Este movimiento no tiene una nota asociada o la relación ya no existe.</p>
    </div>
    <?php
    $conn->close();
    exit;
}

$nota_id = (int)$nota['nota_id'];
$nota_serie = strtoupper(trim((string)($nota['nota_serie'] ?? '')));
$nota_codigo = trim((string)($nota['correlativo'] ?? ''));
if ($nota_codigo === '') {
    $nota_codigo = $nota_serie !== '' ? $nota_serie . '-' . str_pad((string)$nota_id, 4, '0', STR_PAD_LEFT) : 'Nota #' . $nota_id;
}
$fecha_ts = !empty($nota['fecha_completa']) ? strtotime($nota['fecha_completa']) : false;
$fecha = $fecha_ts ? date('d/m/Y', $fecha_ts) : 'Sin fecha';
$hora  = $fecha_ts ? date('H:i:s', $fecha_ts) : '--:--:--';

// Productos relacionados con la nota
$productos_sql = "
SELECT 
    p.clm_alm_producto_NOMBRE AS producto,
    p.clm_alm_producto_codigo AS codigo,
    c.clm_alm_categoria_DESCRIPCION AS categoria,
    m.clm_alm_mov_cantidad AS cantidad,
    m.clm_alm_mov_OBSERVACION AS observacion
FROM tb_alm_movimientos m
JOIN tb_alm_producto p ON m.clm_alm_mov_idPRODUCTO = p.clm_alm_producto_id
LEFT JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
WHERE m.clm_alm_mov_idNOTA = ?
ORDER BY p.clm_alm_producto_NOMBRE
";

$stmt_prod = $conn->prepare($productos_sql);
$stmt_prod->bind_param('i', $nota_id);
$stmt_prod->execute();
$productos_res = $stmt_prod->get_result();

$productos = [];
$total_cantidad = 0;
while ($prod = $productos_res->fetch_assoc()) {
    $productos[] = $prod;
    $total_cantidad += (float)$prod['cantidad'];
}
$stmt_prod->close();
$conn->close();
?>

<style>
  .nota360{
    --ink:#0f172a;
    --muted:#64748b;
    --line:#dbe4ef;
    --primary:#172033;
    --primary2:#2c3e50;
    --blue:#0ea5e9;
    --soft:#f8fafc;
    font-family:'Segoe UI',system-ui,sans-serif;
    color:var(--ink);
  }
  .nota360 *{box-sizing:border-box;}
  .nota-hero{
    position:relative;
    overflow:hidden;
    border-radius:22px;
    padding:20px 22px;
    background:radial-gradient(circle at top right,rgba(14,165,233,.24),transparent 34%),linear-gradient(135deg,#172033,#2c3e50 65%,#0f172a);
    color:#fff;
    display:flex;
    justify-content:space-between;
    gap:18px;
    align-items:center;
    box-shadow:0 16px 34px rgba(15,23,42,.20);
  }
  .nota-hero::after{
    content:"";
    position:absolute;
    left:22px;
    right:22px;
    bottom:0;
    height:3px;
    border-radius:999px;
  }
  .nota-kicker{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:6px 11px;
    border-radius:999px;
    background:rgba(255,255,255,.11);
    border:1px solid rgba(255,255,255,.14);
    color:#dbeafe;
    font-size:.78rem;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.05em;
    margin-bottom:10px;
  }
  .nota-hero h2{
    margin:0;
    color:#fff;
    text-align:left;
    font-size:clamp(1.45rem,2.5vw,2.15rem);
    font-weight:900;
    letter-spacing:-.04em;
  }
  .nota-hero p{
    margin:5px 0 0;
    color:#cbd5e1;
    font-size:.92rem;
  }
  .nota-hero-right{
    min-width:180px;
    background:rgba(255,255,255,.10);
    border-radius:18px;
    padding:13px 15px;
    text-align:right;
    backdrop-filter:blur(8px);
  }
  .nota-hero-right span{
    display:block;
    color:#cbd5e1;
    font-size:.72rem;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.06em;
  }
  .nota-hero-right strong{
    display:block;
    margin-top:4px;
    color:#fff;
    font-size:1.15rem;
  }
  .nota-hero-right small{color:#bae6fd;font-weight:800;}

  .nota-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
    margin:16px 0;
  }
  .nota-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:17px;
    padding:13px 14px;
    display:flex;
    gap:11px;
    align-items:flex-start;
    box-shadow:0 8px 22px rgba(15,23,42,.07);
  }
  .nota-card .ic{
    width:38px;
    height:38px;
    border-radius:12px;
    display:grid;
    place-items:center;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1d4ed8;
    flex:0 0 auto;
    font-size:18px;
  }
  .nota-card span{
    display:block;
    color:var(--muted);
    font-size:.74rem;
    font-weight:850;
    text-transform:uppercase;
    letter-spacing:.045em;
    margin-bottom:3px;
  }
  .nota-card strong{
    display:block;
    color:var(--ink);
    font-size:.95rem;
    line-height:1.22;
    word-break:break-word;
  }
  .nota-card.wide{grid-column:span 3;}
  .nota-card.wide strong{font-weight:650;color:#334155;line-height:1.35;}

  .nota-summary{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
    margin-bottom:16px;
  }
  .nota-mini{
    background:linear-gradient(180deg,#fff,#f8fafc);
    border:1px solid var(--line);
    border-radius:17px;
    padding:13px 15px;
    box-shadow:0 8px 22px rgba(15,23,42,.06);
  }
  .nota-mini span{
    display:block;
    color:var(--muted);
    font-size:.75rem;
    font-weight:850;
    text-transform:uppercase;
    letter-spacing:.045em;
  }
  .nota-mini strong{
    display:block;
    margin-top:2px;
    font-size:1.25rem;
    font-weight:900;
    color:#172033;
  }

  .nota-section-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin:8px 0 10px;
  }
  .nota-section-title h4{
    margin:0;
    color:#172033;
    font-size:1.02rem;
    font-weight:900;
    display:flex;
    align-items:center;
    gap:8px;
  }
  .nota-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1d4ed8;
    border-radius:999px;
    padding:6px 10px;
    font-size:.78rem;
    font-weight:850;
    white-space:nowrap;
  }
  .nota-actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin:16px 0 8px;
  }
  .nota-pdf-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:42px;
    border:1px solid #1f9d55;
    border-radius:12px;
    background:#1f9d55;
    color:#fff;
    padding:9px 14px;
    font-family:'Segoe UI',system-ui,sans-serif;
    font-size:.9rem;
    font-weight:850;
    cursor:pointer;
    box-shadow:0 12px 26px rgba(31,157,85,.18);
  }
  .nota-pdf-btn:hover{background:#167a42;border-color:#167a42;}
  .nota-pdf-btn:disabled{opacity:.72;cursor:wait;}

  .nota-table-wrap{
    border:1px solid var(--line);
    border-radius:18px;
    overflow:hidden;
    background:#fff;
    box-shadow:0 10px 24px rgba(15,23,42,.07);
  }
  .nota-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    margin:0!important;
  }
  .nota-table th{
    background:#172033!important;
    color:#fff!important;
    border:0!important;
    padding:12px 13px!important;
    font-size:.76rem!important;
    letter-spacing:.045em;
    text-transform:uppercase;
    text-align:left!important;
    white-space:nowrap;
  }
  .nota-table td{
    border:0!important;
    border-bottom:1px solid #eef2f7!important;
    padding:12px 13px!important;
    vertical-align:middle;
    color:#243447;
    font-size:.9rem;
    background:#fff;
  }
  .nota-table tr:last-child td{border-bottom:0!important;}
  .nota-table tbody tr:hover td{background:#f8fbff;}
  .prod-main{display:block;font-weight:850;color:#0f172a;line-height:1.2;}
  .prod-sub{display:block;margin-top:4px;color:#64748b;font-size:.78rem;}
  .qty-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:54px;
    padding:6px 10px;
    border-radius:999px;
    background:#ecfdf5;
    border:1px solid #bbf7d0;
    color:#166534;
    font-weight:900;
  }
  .obs-note{color:#334155;line-height:1.35;}
  .nota-empty-products{
    background:#fff7ed;
    color:#9a3412;
    border:1px solid #fed7aa;
    border-radius:16px;
    padding:15px;
    font-weight:750;
  }
  @media(max-width:720px){
    .nota-hero{flex-direction:column;align-items:flex-start;}
    .nota-hero-right{text-align:left;width:100%;}
    .nota-grid{grid-template-columns:1fr;}
    .nota-card.wide{grid-column:span 1;}
    .nota-summary{grid-template-columns:1fr;}
    .nota-table-wrap{overflow:auto;}
    .nota-table{min-width:680px;}
  }
</style>

<div class="nota360">
  <div class="nota-hero">
    <div>
      <div class="nota-kicker"><i class="bi bi-receipt-cutoff"></i> Notas de Almacén</div>
      <h2><?=h($nota_codigo)?></h2>
      <p>Detalle asociado al movimiento #<?=h($id_mov)?> · trazabilidad de almacén</p>
    </div>
    <div class="nota-hero-right">
      <span>Fecha de emisión</span>
      <strong><?=h($fecha)?></strong>
      <small><?=h($hora)?> hrs</small>
    </div>
  </div>

  <div class="nota-actions">
    <button
      type="button"
      class="nota-pdf-btn"
      data-n360-note-download
      data-note-id="<?=h($nota_id)?>"
      data-note-serie="<?=h($nota_serie)?>"
    >
      <i class="bi bi-file-earmark-pdf"></i>
      Descargar PDF
    </button>
  </div>

  <div class="nota-grid">
    <div class="nota-card">
      <div class="ic"><i class="bi bi-bus-front"></i></div>
      <div>
        <span>Unidad / Placa</span>
        <strong><?=h($nota['placa_real'])?></strong>
      </div>
    </div>

    <div class="nota-card">
      <div class="ic"><i class="bi bi-person-badge"></i></div>
      <div>
        <span>Responsable</span>
        <strong><?=h($nota['responsable'])?></strong>
      </div>
    </div>

    <div class="nota-card">
      <div class="ic"><i class="bi bi-diagram-3"></i></div>
      <div>
        <span>Área / Módulo</span>
        <strong><?=h($nota['area'])?></strong>
      </div>
    </div>

    <div class="nota-card wide">
      <div class="ic"><i class="bi bi-chat-left-text"></i></div>
      <div>
        <span>Motivo</span>
        <strong><?=h($nota['motivo'])?></strong>
      </div>
    </div>
  </div>

  <div class="nota-summary">
    <div class="nota-mini">
      <span>Productos registrados</span>
      <strong><?=number_format(count($productos))?></strong>
    </div>
    <div class="nota-mini">
      <span>Cantidad total</span>
      <strong><?=h(fmt_qty($total_cantidad))?></strong>
    </div>
  </div>

  <div class="nota-section-title">
    <h4><i class="bi bi-box-seam"></i> Productos registrados</h4>
    <span class="nota-pill"><i class="bi bi-list-check"></i> <?=number_format(count($productos))?> ítem(s)</span>
  </div>

  <?php if(count($productos) > 0): ?>
    <div class="nota-table-wrap">
      <table class="nota-table">
        <thead>
          <tr>
            <th style="width:30%;">Producto</th>
            <th style="width:14%;">Cantidad</th>
            <th>Observación</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($productos as $prod): ?>
            <tr>
              <td>
                <span class="prod-main"><?=h($prod['producto'])?></span>
                <span class="prod-sub">
                  <?=h($prod['codigo'] ?: 'Sin código')?><?=!empty($prod['categoria']) ? ' · ' . h($prod['categoria']) : ''?>
                </span>
              </td>
              <td><span class="qty-badge"><?=h(fmt_qty($prod['cantidad']))?></span></td>
              <td class="obs-note"><?=h($prod['observacion'] ?: '—')?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="nota-empty-products">
      No hay productos registrados en esta nota.
    </div>
  <?php endif; ?>
</div>
