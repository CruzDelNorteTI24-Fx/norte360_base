<?php

function comb_apuntes_validar(string $accion, int $usuario, $csrf, array $post): array
{
    if ($usuario < 1) {
        comb_hist_json(['ok' => false, 'message' => 'Vuelve a iniciar sesión para gestionar apuntes.'], 401);
    }
    $token = $post['csrf'] ?? null;
    if (!is_string($csrf) || $csrf === '' || !is_string($token) || !hash_equals($csrf, $token)) {
        comb_hist_json(['ok' => false, 'message' => 'La validación de seguridad venció. Actualiza el historial.'], 403);
    }

    $enteroPositivo = ['options' => ['min_range' => 1]];
    $referencia = $post['id_movimiento'] ?? '';
    $movimiento = $referencia === '' ? null : filter_var($referencia, FILTER_VALIDATE_INT, $enteroPositivo);
    $id = filter_var($post['id_apunte'] ?? null, FILTER_VALIDATE_INT, $enteroPositivo);
    $version = filter_var($post['version'] ?? null, FILTER_VALIDATE_INT, $enteroPositivo);
    if ($movimiento === false) {
        comb_hist_json(['ok' => false, 'message' => 'Selecciona un movimiento válido o deja el apunte sin movimiento.'], 422);
    }
    if ($accion !== 'crear_apunte' && ($id === false || $version === false)) {
        comb_hist_json(['ok' => false, 'message' => 'Selecciona un apunte válido.'], 422);
    }

    $nota = '';
    if ($accion !== 'archivar_apunte') {
        $texto = $post['nota'] ?? '';
        $nota = is_string($texto) ? trim(str_replace(["\r\n", "\r"], "\n", $texto)) : '';
        if (preg_match('/\A.{1,2000}\z/us', $nota) !== 1 || preg_match('/\S/u', $nota) !== 1) {
            comb_hist_json(['ok' => false, 'message' => 'Escribe un apunte de hasta 2000 caracteres.'], 422);
        }
    }

    $clave = $post['clave_solicitud'] ?? '';
    if ($accion === 'crear_apunte' && (!is_string($clave) || preg_match('/\A[a-f0-9]{32}\z/', $clave) !== 1)) {
        comb_hist_json(['ok' => false, 'message' => 'Vuelve a abrir el formulario del apunte.'], 422);
    }

    return ['id_movimiento' => $movimiento, 'id_apunte' => $id, 'version' => $version, 'nota' => $nota, 'clave' => $clave];
}

function comb_apuntes_consultar(mysqli $conn, string $condicion, string $types, array $params, int $usuario, bool $admin): array
{
    $rows = comb_fetch_all($conn, "SELECT a.id_apunte, a.id_movimiento, a.nota, a.version, a.creado_por,
            a.creado_en, a.editado_en,
            COALESCE(NULLIF(TRIM(u.nombre), ''), CONCAT('Usuario #', a.creado_por)) AS autor
        FROM tb_comb_apuntes_movimiento a
        LEFT JOIN tb_usuarios u ON u.id_usuario = a.creado_por
        WHERE a.archivado_en IS NULL AND ($condicion)
        ORDER BY a.id_apunte DESC", $types, $params);

    return array_map(static function (array $row) use ($usuario, $admin): array {
        return [
            'id_apunte' => (int)$row['id_apunte'],
            'id_movimiento' => $row['id_movimiento'] === null ? null : (int)$row['id_movimiento'],
            'nota' => $row['nota'],
            'autor' => $row['autor'],
            'creado_en' => comb_fecha_display($row['creado_en']),
            'editado_en' => $row['editado_en'] ? comb_fecha_display($row['editado_en']) : '',
            'version' => (int)$row['version'],
            'editable' => $usuario > 0 && ($admin || $usuario === (int)$row['creado_por']),
        ];
    }, $rows);
}

function comb_apuntes_listar(mysqli $conn, array $movimientos, int $usuario, bool $admin): array
{
    $ids = array_values(array_unique(array_map('intval', array_column($movimientos, 'id_mov'))));
    $condicion = 'a.id_movimiento IS NULL';
    if ($ids) {
        $lugares = implode(',', array_fill(0, count($ids), '?'));
        $condicion .= " OR a.id_movimiento IN ($lugares)";
    }
    return comb_apuntes_consultar($conn, $condicion, str_repeat('i', count($ids)), $ids, $usuario, $admin);
}

function comb_apuntes_escribir(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la operación.', $conn->errno);
    }
    try {
        comb_bind_params($stmt, $types, $params);
        if (!$stmt->execute()) {
            throw new RuntimeException('No se pudo ejecutar la operación.', $stmt->errno);
        }
        return [$stmt->affected_rows, (int)$stmt->insert_id];
    } finally {
        $stmt->close();
    }
}

function comb_apuntes_guardar(mysqli $conn, string $accion, array $datos, int $usuario, bool $admin): ?array
{
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('No se pudo iniciar la operación.', $conn->errno);
    }

    $confirmada = false;
    try {
        $fecha = date('Y-m-d H:i:s');
        $movimiento = $datos['id_movimiento'];
        $id = $datos['id_apunte'];
        if ($accion === 'crear_apunte') {
            [, $id] = comb_apuntes_escribir($conn, "INSERT INTO tb_comb_apuntes_movimiento
                    (id_movimiento, nota, creado_por, creado_en, clave_solicitud)
                SELECT ?, ?, ?, ?, ? FROM DUAL
                WHERE ? IS NULL OR EXISTS (
                    SELECT 1 FROM tb_alm_movimientos m
                    INNER JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
                    WHERE m.clm_alm_mov_id = ? AND p.clm_alm_producto_idCATEGORIA = 11
                )
                ON DUPLICATE KEY UPDATE id_apunte = LAST_INSERT_ID(id_apunte)",
                'isissii', [$movimiento, $datos['nota'], $usuario, $fecha, $datos['clave'], $movimiento, $movimiento]);
            if ($id < 1) {
                return null;
            }
        } else {
            $archivar = $accion === 'archivar_apunte';
            $cambios = $archivar ? 'a.archivado_por = ?, a.archivado_en = ?' : 'a.nota = ?, a.editado_por = ?, a.editado_en = ?';
            $params = $archivar ? [$usuario, $fecha] : [$datos['nota'], $usuario, $fecha];
            array_push($params, $id, $movimiento, $datos['version'], (int)$admin, $usuario);
            [$afectadas] = comb_apuntes_escribir($conn, "UPDATE tb_comb_apuntes_movimiento a
                LEFT JOIN tb_alm_movimientos m ON m.clm_alm_mov_id = a.id_movimiento
                LEFT JOIN tb_alm_producto p ON p.clm_alm_producto_id = m.clm_alm_mov_idPRODUCTO
                SET $cambios, a.version = a.version + 1
                WHERE a.id_apunte = ? AND a.id_movimiento <=> ? AND a.version = ?
                  AND a.archivado_en IS NULL
                  AND (a.id_movimiento IS NULL OR p.clm_alm_producto_idCATEGORIA = 11)
                  AND (? = 1 OR a.creado_por = ?)", $archivar ? 'isiiiii' : 'sisiiiii', $params);
            if ($afectadas !== 1) {
                return null;
            }
        }

        if ($accion === 'archivar_apunte') {
            $resultado = ['id_archivado' => $id];
        } else {
            $apuntes = comb_apuntes_consultar($conn, 'a.id_apunte = ? AND a.id_movimiento <=> ?', 'ii', [$id, $movimiento], $usuario, $admin);
            if (!$apuntes) {
                return null;
            }
            $resultado = ['apunte' => $apuntes[0]];
        }
        if (!$conn->commit()) {
            throw new RuntimeException('No se pudo finalizar la operación.', $conn->errno);
        }
        $confirmada = true;
        return $resultado;
    } finally {
        if (!$confirmada) {
            $conn->rollback();
        }
    }
}
