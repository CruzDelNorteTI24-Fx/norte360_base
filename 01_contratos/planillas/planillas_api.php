<?php
// 01_contratos/planillas/planillas_api.php
session_start();
if (!isset($_SESSION['usuario'])) { http_response_code(401); exit; }
define('ACCESS_GRANTED', true);
require_once("../../.c0nn3ct/db_securebd2.php");
require_once(__DIR__."/planillas_service.php");

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'list') {
        $filters = [
            'search'     => trim($_GET['search'] ?? ''),
            'trab_id'    => isset($_GET['trab_id']) && $_GET['trab_id'] !== '' ? (int)$_GET['trab_id'] : null,
            'estado'     => trim($_GET['estado'] ?? ''),
            'fecha_tipo' => trim($_GET['fecha_tipo'] ?? 'fechregistro'),
            'desde'      => trim($_GET['desde'] ?? ''),
            'hasta'      => trim($_GET['hasta'] ?? ''),
        ];
        if ($filters['estado'] === '') $filters['estado'] = null;
        if ($filters['desde'] === '')  $filters['desde']  = null;
        if ($filters['hasta'] === '')  $filters['hasta']  = null;

        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $sort  = $_GET['sort'] ?? 't.clm_pl_fechregistro';
        $dir   = $_GET['dir'] ?? 'DESC';

        $data = planillas_list($conn, $filters, $page, $limit, $sort, $dir);
        echo json_encode([
            'ok'    => true,
            'rows'  => $data['rows'],
            'total' => $data['total'],
            'page'  => $page,
            'limit' => $limit
        ]);
        exit;
    }

    if ($action === 'detalle') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) throw new Exception('ID inválido');
        $row = planillas_get($conn, $id);
        echo json_encode(['ok'=>true, 'row'=>$row]);
        exit;
    }

    if ($action === 'trabajadores') {
        $ops = planillas_trabajadores_options($conn);
        echo json_encode(['ok'=>true, 'options'=>$ops]);
        exit;
    }

    if ($action === 'export_csv') {
        // Mismo filtro que 'list', pero sin paginación (o podrías limitar a 10k)
        $filters = [
            'search'     => trim($_GET['search'] ?? ''),
            'trab_id'    => isset($_GET['trab_id']) && $_GET['trab_id'] !== '' ? (int)$_GET['trab_id'] : null,
            'estado'     => trim($_GET['estado'] ?? ''),
            'fecha_tipo' => trim($_GET['fecha_tipo'] ?? 'fechregistro'),
            'desde'      => trim($_GET['desde'] ?? ''),
            'hasta'      => trim($_GET['hasta'] ?? ''),
        ];
        if ($filters['estado'] === '') $filters['estado'] = null;
        if ($filters['desde'] === '')  $filters['desde']  = null;
        if ($filters['hasta'] === '')  $filters['hasta']  = null;

        // Exporta hasta 20000 filas
        $data = planillas_list($conn, $filters, 1, 20000, $_GET['sort'] ?? 't.clm_pl_fechregistro', $_GET['dir'] ?? 'DESC');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="planillas_export.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Trabajador','DNI','Cargo','Estado','Fec.Registro','Fec.Ingreso Planilla','Fec.Salida','Documento','Comentario']);
        foreach ($data['rows'] as $r) {
            fputcsv($out, [
                $r['clm_pl_id'],
                $r['clm_tra_nombres'],
                $r['clm_tra_dni'],
                $r['clm_tra_cargo'],
                $r['clm_pl_tra_estado'],
                $r['clm_pl_fechregistro'],
                $r['clm_pl_fechaingrespl'],
                $r['clm_pl_fechasalida'],
                $r['clm_pl_doc'],
                $r['clm_pl_com'],
            ]);
        }
        fclose($out);
        exit;
    }

    echo json_encode(['ok'=>false, 'error'=>'Acción no soportada']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
