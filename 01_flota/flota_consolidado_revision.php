<?php

class CsbRevisionError extends RuntimeException {
    public array $details;

    public function __construct(string $message, int $status = 422, array $details = []) {
        parent::__construct($message, $status);
        $this->details = $details;
    }
}

function csb_revision_items($raw): array {
    if (!is_string($raw)) {
        throw new CsbRevisionError('El lote debe enviarse como JSON.');
    }
    $items = json_decode($raw, true);
    if (!is_array($items) || !csb_array_is_list_compat($items) || count($items) < 1 || count($items) > 300) {
        throw new CsbRevisionError('Envia entre 1 y 300 viajes por lote.');
    }
    return $items;
}

function csb_revision_payload(array $item, bool $approvalOnly, bool $canApprove, array $ready): array {
    $id = $item['id'] ?? null;
    if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string)$id)
        || (string)(int)$id !== (string)$id) {
        throw new CsbRevisionError('Viaje invalido para guardar.');
    }
    $data = ['id' => (int)$id];
    if (!$approvalOnly) {
        foreach (['estado', 'comentario', 'correccion', 'hojaruta'] as $field) {
            $value = $item[$field] ?? ($field === 'estado' ? 'PENDIENTE' : '');
            if (!is_string($value) || !preg_match('//u', $value)) {
                throw new CsbRevisionError('El campo debe ser texto valido.', 422, ['id' => $data['id'], 'field' => $field]);
            }
            $data[$field] = trim($value);
            if (strlen($data[$field]) > 65535) {
                throw new CsbRevisionError('El texto excede el espacio disponible para este campo.', 422, ['id' => $data['id'], 'field' => $field]);
            }
        }
        $data['estado'] = strtoupper($data['estado']);
        if (!in_array($data['estado'], ['PENDIENTE', 'VALIDADO', 'OBSERVADO', 'CORREGIDO', 'ANULADO', 'MANUAL', 'TRANSBORDADO', 'TRANSBORDO'], true)) {
            throw new CsbRevisionError('Estado de revision invalido.', 422, ['id' => $data['id'], 'field' => 'estado']);
        }
        foreach (['hojaruta_fisica_revisada', 'hojaruta_fisica_codigo'] as $field) {
            if (!array_key_exists($field, $item)) continue;
            if (empty($ready[$field])) {
                throw new CsbRevisionError('El control de hoja fisica aun no esta disponible.', 409, ['id' => $data['id'], 'field' => $field]);
            }
            if (!is_string($item[$field])) {
                throw new CsbRevisionError('Valor de hoja fisica invalido.', 422, ['id' => $data['id'], 'field' => $field]);
            }
            if ($field === 'hojaruta_fisica_codigo') {
                $data[$field] = trim($item[$field]);
                if (!preg_match('/^.{0,255}$/us', $data[$field])) {
                    throw new CsbRevisionError('El codigo de hoja fisica admite hasta 255 caracteres.', 422, ['id' => $data['id'], 'field' => $field]);
                }
            } else {
                if (!in_array($item[$field], ['0', '1'], true)) {
                    throw new CsbRevisionError('El indicador de hoja fisica debe ser 0 o 1.', 422, ['id' => $data['id'], 'field' => $field]);
                }
                $data[$field] = (int)$item[$field];
            }
        }
        $data['confirmar_importes_control'] = ($item['confirmar_importes_control'] ?? '') === '1';
    }
    if ($approvalOnly || array_key_exists('aprobado_oficial', $item)) {
        if (!$canApprove) throw new CsbRevisionError('No tienes permiso para la aprobacion oficial.', 403);
        if (empty($ready['aprobado_oficial'])) throw new CsbRevisionError('La aprobacion oficial aun no esta disponible.', 409);
        $value = $item['aprobado_oficial'] ?? null;
        if (!is_string($value) || !in_array($value, ['0', '1'], true)) {
            throw new CsbRevisionError('La aprobacion oficial debe ser 0 o 1.', 422, ['id' => $data['id'], 'field' => 'aprobado_oficial']);
        }
        $data['aprobado_oficial'] = (int)$value;
    }
    return $data;
}

function csb_code_records(mysqli $conn, bool $physicalReady): array {
    $physical = $physicalReady ? 'clm_salprog_hojaruta_fisica_codigo' : "'' AS clm_salprog_hojaruta_fisica_codigo";
    return csb_fetch_all($conn, "SELECT clm_salprog_id, clm_salprog_hojaruta, {$physical},
        clm_salprog_bus, clm_salprog_placa, clm_salprog_fecha_operativa, clm_salprog_horasalida,
        clm_salprog_origen, clm_salprog_destino FROM tb_progbuses_salida_consolidado
        WHERE COALESCE(clm_salprog_hojaruta, '') <> ''"
        . ($physicalReady ? " OR clm_salprog_hojaruta_fisica_codigo <> ''" : '')
        . ' ORDER BY clm_salprog_id DESC');
}

function csb_code_index(array $records, array $changes = []): array {
    $index = ['hojaruta' => [], 'hojaruta_fisica_codigo' => []];
    foreach ($records as $record) {
        $id = (int)$record['clm_salprog_id'];
        foreach ($index as $field => $_) {
            // Validate the final batch, including codes released or exchanged by edited rows.
            $value = $changes[$id][$field] ?? $record['clm_salprog_' . $field] ?? '';
            $key = csb_hojaruta_key($value);
            if ($key !== '') $index[$field][$key][$id] = $record;
        }
    }
    foreach ($changes as $id => $item) {
        foreach ($index as $field => $_) {
            if (!array_key_exists($field, $item)) continue;
            $key = csb_hojaruta_key($item[$field]);
            if ($key !== '' && !isset($index[$field][$key][$id])) $index[$field][$key][$id] = ['clm_salprog_id' => $id];
        }
    }
    return $index;
}

function csb_code_duplicate(array $index, string $field, string $value, int $id): ?array {
    foreach ($index[$field][csb_hojaruta_key($value)] ?? [] as $otherId => $record) {
        if ($otherId !== $id) return $record;
    }
    return null;
}

function csb_code_reference(array $record): array {
    return [
        'id' => (int)$record['clm_salprog_id'],
        'bus' => trim((string)($record['clm_salprog_bus'] ?? '')),
        'placa' => trim((string)($record['clm_salprog_placa'] ?? '')),
        'fecha' => empty($record['clm_salprog_fecha_operativa']) ? '' : csb_date_label($record['clm_salprog_fecha_operativa']),
        'hora' => empty($record['clm_salprog_horasalida']) ? '' : csb_hora_label($record['clm_salprog_horasalida']),
        'origen' => trim((string)($record['clm_salprog_origen'] ?? '')),
        'destino' => trim((string)($record['clm_salprog_destino'] ?? '')),
    ];
}

function csb_save_revisions(mysqli $conn, array $items, bool $approvalOnly, bool $canApprove, array $ready): array {
    $changes = [];
    foreach ($items as $item) {
        if (!is_array($item)) throw new CsbRevisionError('Cada viaje debe ser un objeto.');
        $data = csb_revision_payload($item, $approvalOnly, $canApprove, $ready);
        if (isset($changes[$data['id']])) throw new CsbRevisionError('No repitas un viaje en el mismo lote.');
        $changes[$data['id']] = $data;
    }
    $locked = false;
    $transaction = false;
    $statements = [];
    try {
        // Serialize individual and batch code writers, scoped to this database.
        $lock = csb_fetch_all($conn, "SELECT GET_LOCK(CONCAT('n360_csb_', LEFT(SHA2(DATABASE(), 256), 32)), 5) AS acquired");
        if ((int)($lock[0]['acquired'] ?? 0) !== 1) throw new CsbRevisionError('Hay otro guardado en curso. Intenta nuevamente.', 409);
        $locked = true;
        if (!$conn->begin_transaction()) throw new RuntimeException('No se pudo iniciar el guardado.');
        $transaction = true;
        $ids = array_keys($changes);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stored = csb_fetch_all($conn, "SELECT * FROM tb_progbuses_salida_consolidado WHERE clm_salprog_id IN ({$marks}) ORDER BY clm_salprog_id FOR UPDATE", str_repeat('i', count($ids)), $ids);
        $current = array_column($stored, null, 'clm_salprog_id');
        $index = $approvalOnly ? [] : csb_code_index(csb_code_records($conn, !empty($ready['hojaruta_fisica_codigo'])), $changes);
        foreach ($changes as $id => $item) {
            if (!isset($current[$id])) throw new CsbRevisionError('El viaje seleccionado ya no existe.', 404, ['id' => $id]);
            if ($approvalOnly) continue;
            foreach (['hojaruta', 'hojaruta_fisica_codigo'] as $field) {
                if (!isset($item[$field]) || $item[$field] === '') continue;
                $duplicate = csb_code_duplicate($index, $field, $item[$field], $id);
                if ($duplicate) {
                    $label = $field === 'hojaruta' ? 'La Hoja de Ruta' : 'El codigo de hoja fisica';
                    $ref = csb_code_reference($duplicate);
                    throw new CsbRevisionError($label . ' ya esta registrado en otro viaje (' . $ref['id'] . '). No se guardo ningun cambio.', 409,
                        ['id' => $id, 'field' => $field, 'duplicada' => true, 'duplicado_id' => $ref['id'], 'duplicado' => $ref]);
                }
            }
            if ($item['estado'] === 'ANULADO' && !empty($ready['importes_control'])) {
                $amounts = csb_control_amounts($current[$id]);
                if ($current[$id]['clm_salprog_revision_estado'] !== 'ANULADO' && csb_has_control_amounts($amounts) && !$item['confirmar_importes_control']) {
                    throw new CsbRevisionError('Este viaje tiene importes en Control de conductores. Confirma la anulacion.', 409,
                        ['id' => $id, 'requiere_confirmacion_importes' => true, 'importes_control' => $amounts]);
                }
            }
        }
        $result = [];
        foreach ($changes as $id => $item) {
            $fields = $approvalOnly ? ['aprobado_oficial'] : ['estado', 'comentario', 'correccion', 'hojaruta', 'hojaruta_fisica_revisada', 'hojaruta_fisica_codigo', 'aprobado_oficial'];
            $columns = ['estado' => 'revision_estado', 'comentario' => 'comentario_revision'];
            $sets = [];
            $params = [];
            $types = '';
            foreach ($fields as $field) {
                if (!array_key_exists($field, $item)) continue;
                $column = 'clm_salprog_' . ($columns[$field] ?? $field);
                $sets[] = $column . ($field === 'hojaruta' ? " = NULLIF(?, '')" : ' = ?');
                $params[] = $item[$field];
                $types .= is_int($item[$field]) ? 'i' : 's';
            }
            $sets[] = 'clm_salprog_usuario_revision = ?';
            $sets[] = "clm_salprog_datetime_revision = CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00')";
            $params[] = csb_uid();
            $params[] = $id;
            $types .= 'ii';
            $sql = 'UPDATE tb_progbuses_salida_consolidado SET ' . implode(', ', $sets) . ' WHERE clm_salprog_id = ? LIMIT 1';
            $stmt = $statements[$sql] ?? ($statements[$sql] = $conn->prepare($sql));
            if (!$stmt) throw new RuntimeException('No se pudo preparar el guardado.');
            csb_bind($stmt, $types, $params);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo guardar el lote.');
            $saved = ['id' => $id, 'actualizado' => date('d/m/Y H:i')];
            if (!$approvalOnly) {
                $saved += ['estado' => $item['estado'], 'clase' => csb_estado_class($item['estado']),
                    'hojaruta' => $item['hojaruta'], 'tiene_hojaruta' => $item['hojaruta'] !== '',
                    'comentario' => $item['comentario'], 'correccion' => $item['correccion'],
                    'importes_control' => $item['estado'] === 'ANULADO' && !empty($ready['importes_control']) ? csb_control_amounts($current[$id]) : null];
            }
            foreach (['hojaruta_fisica_revisada', 'hojaruta_fisica_codigo', 'aprobado_oficial'] as $field) {
                $saved[$field] = !array_key_exists($field, $item) ? null : ($field === 'hojaruta_fisica_codigo' ? $item[$field] : $item[$field] === 1);
            }
            $result[] = $saved;
        }
        if (!$conn->commit()) throw new RuntimeException('No se pudo confirmar el guardado.');
        $transaction = false;
        return $result;
    } finally {
        foreach ($statements as $stmt) if ($stmt) $stmt->close();
        if ($transaction) $conn->rollback();
        if ($locked) $conn->query("SELECT RELEASE_LOCK(CONCAT('n360_csb_', LEFT(SHA2(DATABASE(), 256), 32)))");
    }
}
