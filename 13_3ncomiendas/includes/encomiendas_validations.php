<?php
if (!defined('N360_ENCOMIENDAS')) {
    exit('Acceso no permitido.');
}

require_once __DIR__ . '/encomiendas_helpers.php';

function enc_validate_new_guia(array $data): array {
    $errors = [];

    if (enc_valid_date_required($data['fecha_guia'] ?? '') === null) {
        $errors['fecha_guia'] = 'La fecha de la Control Encomienda es obligatoria.';
    }

    $horario = enc_nullable_string($data['horario_operativo'] ?? '');
    if ($horario !== null && strlen($horario) > 120) {
        $errors['horario_operativo'] = 'El horario operativo no debe superar 120 caracteres.';
    }

    $idProgbus = (int)($data['idprogbus'] ?? 0);
    if ($idProgbus < 0) {
        $errors['horario_operativo'] = 'El horario de pizarra seleccionado no es valido.';
    }

    $horaEmbarque = trim((string)($data['hora_embarque_programada'] ?? ''));
    if ($horaEmbarque !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $horaEmbarque)) {
        $errors['hora_embarque_programada'] = 'La hora programada no tiene un formato valido.';
    }

    $origen = (int)($data['idsede_embarque'] ?? 0);
    $destino = (int)($data['idsede_desembarque'] ?? 0);
    if ($origen <= 0) {
        $errors['idsede_embarque'] = 'Selecciona la oficina de origen.';
    }
    if ($destino <= 0) {
        $errors['idsede_desembarque'] = 'Selecciona la oficina de destino final.';
    }
    if ($origen > 0 && $destino > 0 && $origen === $destino) {
        $errors['idsede_desembarque'] = 'La oficina de destino debe ser diferente al origen.';
    }

    $placa = (int)($data['idplaca_embarque'] ?? 0);
    if ($placa <= 0) {
        $errors['idplaca_embarque'] = 'Selecciona una unidad de transporte.';
    }

    $points = $data['puntos_ruta'] ?? [];
    if (!is_array($points)) {
        $points = [$points];
    }
    $seen = [$origen => true, $destino => true];
    foreach ($points as $index => $rawPoint) {
        $pointId = (int)$rawPoint;
        if ($pointId <= 0) {
            continue;
        }
        if (isset($seen[$pointId])) {
            $errors['puntos_ruta'] = 'No repitas oficinas entre origen, ruta y destino.';
            break;
        }
        $seen[$pointId] = true;
    }

    return $errors;
}

function enc_validate_estado_embarque(string $estado): bool {
    return in_array($estado, ['EMBARCADO', 'OBSERVADO'], true);
}

function enc_validate_estado_desembarque(string $estado): bool {
    return in_array($estado, ['RECIBIDO', 'INCOMPLETO', 'OBSERVADO'], true);
}

function enc_validate_doc_type(string $type): bool {
    return in_array(strtoupper($type), ['MANIFIESTO_ENCOMIENDAS', 'GUIA_TRANSPORTISTA'], true);
}

function enc_validate_doc_comprobante_type(?string $type): bool {
    $type = strtoupper(trim((string)$type));
    return $type === '' || in_array($type, ['FACTURA', 'BOLETA', 'RECIBO', 'SIN_COMPROBANTE'], true);
}
