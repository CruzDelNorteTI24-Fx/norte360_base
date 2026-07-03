<?php
define('ACCESS_GRANTED', true);
require_once("../.c0nn3ct/db_securebd2.php");
// php\ver_movimientos_producto.php:
if ($conn->connect_error) {
    http_response_code(500);
    die("Conexión fallida: " . $conn->connect_error);
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_cantidad($n) {
    if ($n === null || $n === '') return '0';
    $v = number_format((float)$n, 4, '.', '');
    $v = rtrim(rtrim($v, '0'), '.');
    return $v === '' ? '0' : $v;
}

function fmt_fecha($fecha) {
    if (empty($fecha)) return ['-', '-'];
    $ts = strtotime($fecha);
    if (!$ts) return [h($fecha), '-'];
    return [date('d/m/Y', $ts), date('H:i', $ts)];
}

$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    echo "<div class='movprod360 movprod360-empty'><strong>Producto inválido.</strong><span>No se recibió un ID de producto correcto.</span></div>";
    $conn->close();
    exit;
}

// Obtener información del producto
$info_sql = "
SELECT 
    p.clm_alm_producto_id AS producto_id,
    p.clm_alm_producto_NOMBRE AS producto,
    p.clm_alm_producto_codigo AS cod_producto,
    c.clm_alm_categoria_NOMBRE AS categoria,
    COALESCE(NULLIF(TRIM(c.clm_alm_categoria_DESCRIPCION), ''), c.clm_alm_categoria_NOMBRE) AS descategoria,
    cod.clm_alm_codigo_NOMBRE AS codigo,
    COALESCE(NULLIF(TRIM(cod.clm_alm_codigo_DESCRIPCION), ''), cod.clm_alm_codigo_NOMBRE) AS descodigo
FROM tb_alm_producto p
JOIN tb_alm_categoria c ON p.clm_alm_producto_idCATEGORIA = c.clm_alm_categoria_id
JOIN tb_alm_codigo cod ON c.clm_alm_categoria_idCODIGO = cod.clm_alm_codigo_id
WHERE p.clm_alm_producto_id = ?
LIMIT 1
";

$stmt = $conn->prepare($info_sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$info = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$info) {
    echo "<div class='movprod360 movprod360-empty'><strong>Producto no encontrado.</strong><span>No existe información para el producto solicitado.</span></div>";
    $conn->close();
    exit;
}

// Obtener movimientos del producto
$mov_sql = "
SELECT 
    m.clm_alm_mov_id AS id_movimiento,
    m.clm_alm_mov_fecha_registro AS fecha_registro_MOV, 
    m.clm_alm_mov_TIPO AS tipo_MOV, 
    m.clm_alm_mov_cantidad AS cantidad_MOV,
    m.clm_alm_mov_idNOTA AS id_nota,
    COALESCE(CAST(ns.clm_nota_sco AS CHAR), CAST(m.clm_alm_mov_idNOTA AS CHAR)) AS nota_label,
    m.clm_alm_mov_observacion AS observacion_MOV
FROM tb_alm_movimientos m
LEFT JOIN tb_notas_salida ns ON ns.clm_nota_id = m.clm_alm_mov_idNOTA
WHERE m.clm_alm_mov_idPRODUCTO = ?
ORDER BY m.clm_alm_mov_fecha_registro DESC, m.clm_alm_mov_id DESC
";

$stmt = $conn->prepare($mov_sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$movimientos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_movs = count($movimientos);
$cant_entradas = 0.0;
$cant_salidas = 0.0;
$cant_inventariado = 0.0;

foreach ($movimientos as $m) {
    $tipo = strtoupper(trim($m['tipo_MOV'] ?? ''));
    $cantidad = (float)($m['cantidad_MOV'] ?? 0);
    if ($tipo === 'ENTRADA') {
        $cant_entradas += $cantidad;
    } elseif ($tipo === 'SALIDA') {
        $cant_salidas += $cantidad;
    } elseif ($tipo === 'INVENTARIADO') {
        $cant_inventariado += $cantidad;
    }
}

$saldo_estimado = $cant_inventariado + $cant_entradas - $cant_salidas;
?>

<style>
  #modal-movimientos .modal-content{
    max-width:1120px!important;
    padding:0!important;
    overflow:hidden;
    border-radius:20px!important;
    border:1px solid #dbe4ef;
  }

  .movprod360,
  .movprod360 *{
    box-sizing:border-box;
  }

  .movprod360{
    font-family:'Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,sans-serif;
    color:#0f172a;
    background:#f3f7fb;
  }

  .mp-hero{
    position:relative;
    padding:24px 26px 22px;
    color:#fff;
    background:
      radial-gradient(circle at top right, rgba(14,165,233,.27), transparent 36%),
      linear-gradient(135deg,#121a2b 0%, #26384d 58%, #0f172a 100%);
    overflow:hidden;
  }

  .mp-hero:after{
    content:"";
    position:absolute;
    left:26px;
    right:26px;
    bottom:0;
    height:3px;
    border-radius:999px;
    background:linear-gradient(90deg,#0ea5e9,#facc15,#0ea5e9);
    opacity:.9;
  }

  .mp-topline{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:14px;
    flex-wrap:wrap;
  }

  .mp-eyebrow{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:7px 11px;
    border-radius:999px;
    background:rgba(255,255,255,.10);
    color:#dbeafe;
    font-size:.78rem;
    font-weight:800;
    letter-spacing:.03em;
    text-transform:uppercase;
  }

  .mp-correlativo{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-radius:14px;
    background:rgba(255,255,255,.11);
    border:1px solid rgba(255,255,255,.16);
    color:#e0f2fe;
    font-weight:850;
    white-space:nowrap;
  }

  .mp-hero h2{
    color:#fff;
    text-align:left;
    margin:12px 0 8px;
    font-size:clamp(1.35rem,2.2vw,2.1rem);
    font-weight:900;
    letter-spacing:-.035em;
    line-height:1.12;
  }

  .mp-hero p{
    margin:0;
    color:#cbd5e1;
    max-width:820px;
    line-height:1.45;
  }

  .mp-body{
    padding:18px;
  }

  .mp-kpis{
    display:grid;
    grid-template-columns:repeat(5,minmax(130px,1fr));
    gap:10px;
    margin-bottom:14px;
  }

  .mp-kpi{
    background:#fff;
    border:1px solid #dbe4ef;
    border-radius:16px;
    padding:13px 14px;
    box-shadow:0 10px 24px rgba(15,23,42,.06);
    position:relative;
    overflow:hidden;
  }

  .mp-kpi:before{
    content:"";
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:5px;
    background:#334155;
  }

  .mp-kpi.in:before{ background:#16a34a; }
  .mp-kpi.out:before{ background:#dc2626; }
  .mp-kpi.inv:before{ background:#f59e0b; }
  .mp-kpi.net:before{ background:#06b6d4; }

  .mp-kpi span{
    display:block;
    color:#64748b;
    font-size:.72rem;
    font-weight:850;
    text-transform:uppercase;
    letter-spacing:.04em;
  }

  .mp-kpi strong{
    display:block;
    margin-top:4px;
    color:#0f172a;
    font-size:1.25rem;
    line-height:1.1;
  }

  .mp-info-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
    margin-bottom:14px;
  }

  .mp-info-card{
    background:#fff;
    border:1px solid #dbe4ef;
    border-radius:16px;
    padding:13px 14px;
    box-shadow:0 10px 24px rgba(15,23,42,.05);
  }

  .mp-info-card .label{
    display:flex;
    align-items:center;
    gap:8px;
    color:#64748b;
    font-size:.75rem;
    text-transform:uppercase;
    letter-spacing:.04em;
    font-weight:850;
    margin-bottom:5px;
  }

  .mp-info-card .value{
    color:#0f172a;
    font-weight:800;
    line-height:1.28;
  }

  .mp-code-pill{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
    border-radius:999px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1d4ed8;
    font-weight:900;
    font-size:.82rem;
  }

  .mp-table-card{
    background:#fff;
    border:1px solid #dbe4ef;
    border-radius:18px;
    overflow:hidden;
    box-shadow:0 14px 30px rgba(15,23,42,.07);
  }

  .mp-table-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding:14px 16px;
    background:linear-gradient(180deg,#fff,#f8fafc);
    border-bottom:1px solid #e8eef6;
  }

  .mp-table-head h3{
    margin:0;
    color:#172033;
    font-size:1rem;
    font-weight:900;
    display:flex;
    align-items:center;
    gap:8px;
  }

  .mp-table-head p{
    margin:3px 0 0;
    color:#64748b;
    font-size:.84rem;
  }

  .mp-count-pill{
    background:#eff6ff;
    color:#1d4ed8;
    border-radius:999px;
    padding:7px 11px;
    font-size:.82rem;
    font-weight:900;
    white-space:nowrap;
  }

  .mp-table-wrap{
    overflow:auto;
    max-height:430px;
  }

  .mp-table{
    width:100%;
    min-width:760px;
    border-collapse:separate;
    border-spacing:0;
  }

  .mp-table th{
    position:sticky;
    top:0;
    z-index:2;
    background:#172033;
    color:#fff;
    padding:12px 13px;
    font-size:.76rem;
    text-transform:uppercase;
    letter-spacing:.04em;
    text-align:left;
    white-space:nowrap;
  }

  .mp-table td{
    padding:12px 13px;
    border-bottom:1px solid #eef2f7;
    vertical-align:middle;
    color:#243447;
    font-size:.89rem;
    background:#fff;
  }

  .mp-table tbody tr:hover td{
    background:#f8fbff;
  }

  .mp-date strong{
    display:block;
    color:#0f172a;
    font-weight:850;
  }

  .mp-date small{
    display:block;
    margin-top:2px;
    color:#64748b;
    font-weight:700;
  }

  .mp-type{
    border:0;
    width:auto!important;
    min-width:108px;
    border-radius:999px;
    padding:7px 10px;
    display:inline-flex;
    justify-content:center;
    align-items:center;
    gap:7px;
    font-size:.78rem;
    font-weight:900;
    letter-spacing:.02em;
  }

  .mp-type.type-in{
    background:#dcfce7;
    color:#166534;
  }

  .mp-type.type-out{
    background:#fee2e2;
    color:#991b1b;
  }

  .mp-type.type-inv{
    background:#ffedd5;
    color:#9a3412;
  }

  .mp-type.type-other{
    background:#eef2f7;
    color:#334155;
  }

  .mp-type.clickable{
    cursor:pointer;
    transition:transform .18s ease, filter .18s ease;
  }

  .mp-type.clickable:hover{
    transform:translateY(-1px);
    filter:brightness(.98);
  }

  .mp-note-mini{
    display:block;
    margin-top:4px;
    font-size:.68rem;
    font-weight:800;
    opacity:.82;
  }

  .mp-cantidad{
    text-align:right;
    font-weight:900;
    color:#0f172a!important;
    white-space:nowrap;
  }

  .mp-obs{
    max-width:460px;
    color:#475569!important;
    line-height:1.35;
  }

  .mp-obs-text{
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
  }

  .movprod360-empty,
  .mp-empty{
    margin:0;
    padding:26px;
    min-height:160px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    color:#64748b;
    background:#f8fafc;
    border-radius:16px;
  }

  .movprod360-empty strong,
  .mp-empty strong{
    color:#0f172a;
    font-size:1.05rem;
    margin-bottom:5px;
  }

  @media(max-width:900px){
    .mp-kpis{grid-template-columns:repeat(2,1fr);}
    .mp-info-grid{grid-template-columns:1fr;}
  }

  @media(max-width:560px){
    .mp-body{padding:12px;}
    .mp-hero{padding:20px 18px;}
    .mp-kpis{grid-template-columns:1fr;}
    .mp-table-head{align-items:flex-start;flex-direction:column;}
  }
</style>

<div class="movprod360">
  <section class="mp-hero">
    <div class="mp-topline">
      <div class="mp-eyebrow"><i class="bi bi-clock-history"></i> Historial de producto</div>
      <div class="mp-correlativo"><i class="bi bi-box-seam"></i> ID Producto #<?= h($info['producto_id']) ?></div>
    </div>
    <h2><?= h($info['producto']) ?></h2>
    <p>Consulta consolidada de entradas, salidas e inventariados relacionados a este producto.</p>
  </section>

  <div class="mp-body">
    <div class="mp-kpis">
      <div class="mp-kpi">
        <span>Movimientos</span>
        <strong><?= number_format($total_movs) ?></strong>
      </div>
      <div class="mp-kpi in">
        <span>Entradas</span>
        <strong><?= h(fmt_cantidad($cant_entradas)) ?></strong>
      </div>
      <div class="mp-kpi out">
        <span>Salidas</span>
        <strong><?= h(fmt_cantidad($cant_salidas)) ?></strong>
      </div>
      <div class="mp-kpi inv">
        <span>Inventariado</span>
        <strong><?= h(fmt_cantidad($cant_inventariado)) ?></strong>
      </div>
      <div class="mp-kpi net">
        <span>Saldo estimado</span>
        <strong><?= h(fmt_cantidad($saldo_estimado)) ?></strong>
      </div>
    </div>

    <div class="mp-info-grid">
      <div class="mp-info-card">
        <div class="label"><i class="bi bi-upc-scan"></i> Código de producto</div>
        <div class="value"><span class="mp-code-pill"><?= h($info['cod_producto'] ?: ($info['codigo'] . $info['producto_id'])) ?></span></div>
      </div>
      <div class="mp-info-card">
        <div class="label"><i class="bi bi-diagram-3"></i> Grupo</div>
        <div class="value"><?= h($info['descodigo'] ?: '-') ?></div>
      </div>
      <div class="mp-info-card">
        <div class="label"><i class="bi bi-collection"></i> Categoría</div>
        <div class="value"><?= h($info['descategoria'] ?: $info['categoria']) ?></div>
      </div>
    </div>

    <div class="mp-table-card">
      <div class="mp-table-head">
        <div>
          <h3><i class="bi bi-list-check"></i> Detalle de movimientos</h3>
          <p>Las salidas se pueden abrir para visualizar su nota asociada cuando corresponda.</p>
        </div>
        <span class="mp-count-pill"><?= number_format($total_movs) ?> registros</span>
      </div>

      <?php if ($total_movs > 0): ?>
        <div class="mp-table-wrap">
          <table class="mp-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th style="text-align:right;">Cantidad</th>
                <th>Observación</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($movimientos as $row): ?>
                <?php
                  $tipo = strtoupper(trim($row['tipo_MOV'] ?? ''));
                  [$fecha, $hora] = fmt_fecha($row['fecha_registro_MOV'] ?? '');
                  $obs = trim((string)($row['observacion_MOV'] ?? ''));
                  $id_mov = (int)($row['id_movimiento'] ?? 0);
                  $nota = trim((string)($row['nota_label'] ?? ''));

                  $typeClass = 'type-other';
                  if ($tipo === 'ENTRADA') $typeClass = 'type-in';
                  elseif ($tipo === 'SALIDA') $typeClass = 'type-out';
                  elseif ($tipo === 'INVENTARIADO') $typeClass = 'type-inv';
                ?>
                <tr>
                  <td class="mp-date">
                    <strong><?= h($fecha) ?></strong>
                    <small><i class="bi bi-clock"></i> <?= h($hora) ?></small>
                  </td>
                  <td>
                    <?php if ($tipo === 'SALIDA' && $id_mov > 0): ?>
                      <button type="button" class="mp-type <?= h($typeClass) ?> clickable" onclick="verNotaSalida(<?= $id_mov ?>)">
                        <i class="bi bi-box-arrow-up"></i> <?= h($tipo) ?>
                        <?php if ($nota !== ''): ?><span class="mp-note-mini">Nota <?= h($nota) ?></span><?php endif; ?>
                      </button>
                    <?php else: ?>
                      <span class="mp-type <?= h($typeClass) ?>">
                        <?php if ($tipo === 'ENTRADA'): ?><i class="bi bi-box-arrow-in-down"></i><?php endif; ?>
                        <?php if ($tipo === 'INVENTARIADO'): ?><i class="bi bi-clipboard-check"></i><?php endif; ?>
                        <?= h($tipo ?: '-') ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="mp-cantidad"><?= h(fmt_cantidad($row['cantidad_MOV'] ?? 0)) ?></td>
                  <td class="mp-obs" title="<?= h($obs) ?>">
                    <div class="mp-obs-text"><?= h($obs !== '' ? $obs : 'Sin observación') ?></div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="mp-empty">
          <strong>Este producto no tiene movimientos registrados.</strong>
          <span>Cuando se registre una entrada, salida o inventariado, aparecerá en esta sección.</span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php $conn->close(); ?>
