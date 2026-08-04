<?php
define('N360_ENCOMIENDAS', true);
require_once __DIR__ . '/../includes/encomiendas_helpers.php';
require_once __DIR__ . '/../includes/encomiendas_queries.php';

$conn = enc_start_read_json('enc-tracking');

try {
    if (!enc_schema_has_guias_norte($conn)) {
        enc_json(false, 'La estructura unificada de Control Encomiendas aun no esta aplicada.', [], 409);
    }

    $type = strtolower(trim((string)($_GET['type'] ?? '')));

    if ($type === 'guia') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            enc_json(false, 'Control Encomienda no identificada.', [], 422);
        }

        $guia = enc_fetch_guia($conn, $id);
        if (!$guia) {
            enc_json(false, 'No se encontro la Control Encomienda solicitada.', [], 404);
        }

        enc_json(true, 'Datos listos.', [
            'user' => [
                'name' => enc_user_name(),
                'dni' => (string)($_SESSION['DNI'] ?? $_SESSION['dni'] ?? 'No registrado'),
            ],
            'guia' => $guia,
            'points' => enc_fetch_route_points($conn, $id),
            'documents' => enc_fetch_documents($conn, $id),
            'history' => enc_fetch_history($conn, $id),
        ]);
    }

    if ($type === 'tracking') {
        $filters = enc_current_filters();
        $total = enc_count_tracking($conn, $filters);
        $limit = min(3000, max(100, (int)($_GET['limit'] ?? 1500)));
        $rows = enc_fetch_tracking_report($conn, $filters, $limit);

        enc_json(true, 'Consolidado listo.', [
            'user' => [
                'name' => enc_user_name(),
                'dni' => (string)($_SESSION['DNI'] ?? $_SESSION['dni'] ?? 'No registrado'),
            ],
            'filters' => $filters,
            'kpis' => enc_fetch_kpis($conn, $filters),
            'totalRows' => $total,
            'limit' => $limit,
            'rows' => $rows,
        ]);
    }

    enc_json(false, 'Tipo de reporte no reconocido.', [], 422);
} catch (Throwable $e) {
    enc_log($e);
    enc_json(false, 'No se pudo preparar el reporte de encomiendas.', [], 500);
}
