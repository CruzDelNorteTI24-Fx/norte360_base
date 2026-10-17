<?php

function n360_flota_driver_order_token(array $row): string {
    // Include history so returning to the same order cannot revive an old form.
    return hash('sha256', serialize([
        (string)($row['clm_salprog_conductores_texto'] ?? ''),
        (string)($row['clm_salprog_conductores_json'] ?? ''),
    ]));
}

function n360_flota_lock_driver_order(mysqli $conn, int $id, string $expected): array {
    if (!preg_match('/^[a-f0-9]{64}$/D', $expected)) {
        throw new RuntimeException('Actualiza la pagina antes de guardar los conductores o sus pagos.', 409);
    }
    $stmt = $conn->prepare('SELECT * FROM tb_progbuses_salida_consolidado WHERE clm_salprog_id = ? LIMIT 1 FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('No se pudo comprobar el orden de conductores.', 500);
    }
    try {
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            throw new RuntimeException('No se pudo comprobar el orden de conductores.', 500);
        }
        $row = $stmt->get_result()->fetch_assoc();
    } finally {
        $stmt->close();
    }
    if (!$row) {
        throw new RuntimeException('No se encontro el viaje seleccionado.', 404);
    }
    if (!hash_equals(n360_flota_driver_order_token($row), $expected)) {
        throw new RuntimeException('Los conductores de este viaje cambiaron. Actualiza la pagina antes de guardar para no asignar pagos a otra persona.', 409);
    }
    return $row;
}
