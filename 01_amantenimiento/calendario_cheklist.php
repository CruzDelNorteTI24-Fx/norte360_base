<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login/login.php');
    exit();
}

define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';

define('N360_LAYOUT', true);
define('N360_BASE_URL', '../');
require_once __DIR__ . '/../layout/sidebar_n360.php';
require_once __DIR__ . '/../layout/header_n360.php';
require_once __DIR__ . '/../layout/footer_n360.php';
require_once __DIR__ . '/../layout/content_n360.php';

function calchk_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function calchk_month_name(int $month): string {
    $names = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
    return $names[$month] ?? 'Mes';
}

$selectedMonth = trim((string)($_POST['mes_mostrar'] ?? $_GET['mes'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

[$yearRaw, $monthRaw] = explode('-', $selectedMonth);
$year = (int)$yearRaw;
$month = (int)$monthRaw;
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayWeek = (int)date('w', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$today = date('Y-m-d');

$stmt = $conn->prepare("
    SELECT DAY(clm_checklist_fecha) AS dia, COUNT(*) AS cantidad
    FROM tb_checklist_limpieza
    WHERE YEAR(clm_checklist_fecha) = ?
      AND MONTH(clm_checklist_fecha) = ?
    GROUP BY DAY(clm_checklist_fecha)
    ORDER BY dia ASC
");
if (!$stmt) {
    die('No se pudo preparar el calendario.');
}
$stmt->bind_param('ii', $year, $month);
$stmt->execute();
$result = $stmt->get_result();

$checksByDay = [];
$totalChecks = 0;
$maxDay = 0;
$maxCount = 0;
while ($row = $result->fetch_assoc()) {
    $day = (int)$row['dia'];
    $count = (int)$row['cantidad'];
    $checksByDay[$day] = $count;
    $totalChecks += $count;
    if ($count > $maxCount) {
        $maxCount = $count;
        $maxDay = $day;
    }
}
$stmt->close();

$daysWithChecks = count(array_filter($checksByDay, fn($count) => (int)$count > 0));
$monthLabel = calchk_month_name($month) . ' ' . $year;
$peakLabel = $maxDay > 0 ? sprintf('%02d/%02d/%04d', $maxDay, $month, $year) : 'Sin registros';
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Calendario checklist | Norte360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= n360_asset('img/norte360.png') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/sidebar_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/header_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/main_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/footer_n360.css') ?>">
    <link rel="stylesheet" href="<?= n360_asset('assets/css/content_n360.css') ?>">
    <style>
        .calchk-shell {
            width: min(100%, 1480px);
            margin: 0 auto;
        }

        .calchk-hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 22px;
            background: linear-gradient(135deg, #163851 0%, #1f5574 100%);
            color: #fff;
            border-radius: 8px;
            padding: 28px 32px;
            box-shadow: 0 16px 32px rgba(18, 42, 64, .16);
        }

        .calchk-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #cfe8ff;
            font-size: .78rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        .calchk-hero h1 {
            margin: 8px 0;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1;
            letter-spacing: 0;
        }

        .calchk-hero p {
            margin: 0;
            max-width: 760px;
            color: #e8f3fb;
            line-height: 1.45;
        }

        .calchk-toolbar {
            display: flex;
            align-items: end;
            gap: 12px;
            flex-wrap: wrap;
            background: #fff;
            border: 1px solid #d7e2ec;
            border-radius: 8px;
            padding: 14px;
            box-shadow: 0 12px 28px rgba(18, 42, 64, .08);
        }

        .calchk-field {
            display: grid;
            gap: 6px;
        }

        .calchk-field span {
            color: #5f7287;
            font-size: .76rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        .calchk-field input {
            min-height: 42px;
            border: 1px solid #c9d7e5;
            border-radius: 8px;
            padding: 9px 12px;
            color: #102a43;
            font: inherit;
        }

        .calchk-btn {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1px solid #2389c9;
            border-radius: 8px;
            background: #2389c9;
            color: #fff;
            padding: 10px 15px;
            font-weight: 900;
            cursor: pointer;
            text-decoration: none;
        }

        .calchk-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin: 18px 0;
        }

        .calchk-metric,
        .calchk-calendar {
            background: #fff;
            border: 1px solid #d7e2ec;
            border-radius: 8px;
            box-shadow: 0 12px 28px rgba(18, 42, 64, .08);
        }

        .calchk-metric {
            padding: 14px 16px;
        }

        .calchk-metric span {
            display: block;
            color: #5f7287;
            font-size: .76rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        .calchk-metric strong {
            display: block;
            margin-top: 4px;
            color: #102a43;
            font-size: 1.8rem;
            line-height: 1;
        }

        .calchk-calendar {
            overflow: hidden;
        }

        .calchk-calendar__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid #e3edf6;
        }

        .calchk-calendar__head h2 {
            margin: 0;
            color: #102a43;
            font-size: 1.2rem;
        }

        .calchk-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .calchk-weekday {
            padding: 12px 8px;
            background: #122a40;
            color: #fff;
            font-size: .76rem;
            font-weight: 900;
            text-align: center;
            text-transform: uppercase;
        }

        .calchk-empty {
            min-height: 112px;
            background: #f6f9fc;
            border-right: 1px solid #e4edf5;
            border-bottom: 1px solid #e4edf5;
        }

        .calchk-day {
            min-height: 112px;
            border: 0;
            border-right: 1px solid #e4edf5;
            border-bottom: 1px solid #e4edf5;
            background: #fff;
            color: #102a43;
            padding: 12px;
            text-align: left;
            cursor: pointer;
            transition: background .15s ease, transform .15s ease;
        }

        .calchk-day:hover,
        .calchk-day:focus {
            background: #f3f9fe;
            outline: none;
            transform: translateY(-1px);
        }

        .calchk-day__num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #eef5fb;
            color: #183b56;
            font-weight: 900;
        }

        .calchk-day.is-today .calchk-day__num {
            background: #183b56;
            color: #fff;
        }

        .calchk-day__count {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 16px;
            border-radius: 999px;
            padding: 5px 9px;
            background: #edf2f7;
            color: #66788a;
            font-size: .78rem;
            font-weight: 900;
        }

        .calchk-day.has-checks .calchk-day__count {
            background: #e9f9ee;
            color: #17643a;
        }

        .calchk-modal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 35, 52, .58);
            padding: 18px;
        }

        .calchk-modal.is-open {
            display: flex;
        }

        .calchk-modal__dialog {
            width: min(100%, 980px);
            max-height: min(760px, 90vh);
            overflow: hidden;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 22px 60px rgba(15, 35, 52, .28);
            display: grid;
            grid-template-rows: auto minmax(0, 1fr);
        }

        .calchk-modal__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: #183b56;
            color: #fff;
            padding: 16px 18px;
        }

        .calchk-modal__head h3 {
            margin: 0;
            font-size: 1.1rem;
        }

        .calchk-modal__close {
            width: 38px;
            height: 38px;
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 8px;
            background: rgba(255,255,255,.12);
            color: #fff;
            cursor: pointer;
        }

        .calchk-modal__body {
            overflow: auto;
            padding: 18px;
        }

        .calchk-loading {
            min-height: 180px;
            display: grid;
            place-items: center;
            color: #66788a;
            font-weight: 800;
        }

        @media (max-width: 900px) {
            .calchk-hero {
                align-items: flex-start;
                flex-direction: column;
            }

            .calchk-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 680px) {
            .calchk-summary,
            .calchk-toolbar {
                display: grid;
                grid-template-columns: 1fr;
            }

            .calchk-calendar {
                overflow-x: auto;
            }

            .calchk-grid {
                min-width: 720px;
            }
        }
    </style>
</head>
<body>
<?php n360_render_header(['title' => 'Calendario checklist', 'subtitle' => 'Calidad']); ?>
<?php n360_render_sidebar(); ?>

<main class="main-content n360-main n360-main--module" role="main">
    <div class="n360-main__inner calchk-shell">
        <?php n360_render_content_separator('top'); ?>

        <section class="calchk-hero">
            <div>
                <span class="calchk-kicker"><i class="bi bi-calendar-check-fill" aria-hidden="true"></i> Calidad</span>
                <h1>Calendario checklist</h1>
                <p>Revisa por mes la actividad de checklists generados y abre el detalle diario sin salir de la vista.</p>
            </div>
            <form class="calchk-toolbar" method="POST">
                <label class="calchk-field" for="mes_mostrar">
                    <span>Mes operativo</span>
                    <input type="month" name="mes_mostrar" id="mes_mostrar" value="<?= calchk_h($selectedMonth) ?>">
                </label>
                <button type="submit" class="calchk-btn"><i class="bi bi-search"></i> Ver mes</button>
            </form>
        </section>

        <section class="calchk-summary" aria-label="Resumen del mes">
            <div class="calchk-metric"><span>Checklists</span><strong><?= calchk_h($totalChecks) ?></strong></div>
            <div class="calchk-metric"><span>Dias con registro</span><strong><?= calchk_h($daysWithChecks) ?></strong></div>
            <div class="calchk-metric"><span>Dia pico</span><strong><?= calchk_h($peakLabel) ?></strong></div>
            <div class="calchk-metric"><span>Mayor carga</span><strong><?= calchk_h($maxCount) ?></strong></div>
        </section>

        <section class="calchk-calendar">
            <div class="calchk-calendar__head">
                <h2><?= calchk_h($monthLabel) ?></h2>
                <span class="calchk-kicker"><i class="bi bi-info-circle"></i> Clic en un dia para ver detalle</span>
            </div>
            <div class="calchk-grid" role="grid" aria-label="Calendario de checklists">
                <?php foreach (['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'] as $weekday): ?>
                    <div class="calchk-weekday"><?= calchk_h($weekday) ?></div>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < $firstDayWeek; $i++): ?>
                    <div class="calchk-empty" aria-hidden="true"></div>
                <?php endfor; ?>

                <?php for ($day = 1; $day <= $daysInMonth; $day++):
                    $count = (int)($checksByDay[$day] ?? 0);
                    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $classes = 'calchk-day';
                    if ($count > 0) $classes .= ' has-checks';
                    if ($date === $today) $classes .= ' is-today';
                ?>
                    <button type="button" class="<?= calchk_h($classes) ?>" data-date="<?= calchk_h($date) ?>">
                        <span class="calchk-day__num"><?= calchk_h($day) ?></span>
                        <span class="calchk-day__count"><i class="bi bi-ui-checks-grid"></i><?= calchk_h($count) ?> checklist<?= $count === 1 ? '' : 's' ?></span>
                    </button>
                <?php endfor; ?>
            </div>
        </section>

        <?php n360_render_content_separator('bottom'); ?>
    </div>
</main>

<div id="modalChecklistDetalle" class="calchk-modal" aria-hidden="true">
    <div class="calchk-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="calchkModalTitle">
        <div class="calchk-modal__head">
            <div>
                <span class="calchk-kicker"><i class="bi bi-card-checklist"></i> Detalle diario</span>
                <h3 id="calchkModalTitle">Checklists del <span id="fechaModal"></span></h3>
            </div>
            <button type="button" class="calchk-modal__close" id="btnCerrarDetalle" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="calchk-modal__body" id="contenidoDetalleChecklist">
            <div class="calchk-loading">Cargando detalle...</div>
        </div>
    </div>
</div>

<?php n360_render_footer(); ?>
<script src="<?= n360_asset('assets/js/header_n360.js') ?>"></script>
<script src="<?= n360_asset('assets/js/sidebar_n360.js') ?>"></script>
<script>
(function () {
    const modal = document.getElementById('modalChecklistDetalle');
    const modalDate = document.getElementById('fechaModal');
    const modalBody = document.getElementById('contenidoDetalleChecklist');
    const closeBtn = document.getElementById('btnCerrarDetalle');

    function openDetail(date) {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        modalDate.textContent = date;
        modalBody.innerHTML = '<div class="calchk-loading">Cargando detalle...</div>';

        fetch('ajax_detalle_checklist.php?fecha=' + encodeURIComponent(date), {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
            .then(response => response.text())
            .then(html => {
                modalBody.innerHTML = html || '<div class="calchk-loading">Sin detalle para esta fecha.</div>';
            })
            .catch(() => {
                modalBody.innerHTML = '<div class="calchk-loading">No se pudo cargar el detalle.</div>';
            });
    }

    function closeDetail() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-date]').forEach(button => {
        button.addEventListener('click', () => openDetail(button.dataset.date));
    });
    closeBtn.addEventListener('click', closeDetail);
    modal.addEventListener('click', event => {
        if (event.target === modal) closeDetail();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) closeDetail();
    });
})();
</script>
</body>
</html>
