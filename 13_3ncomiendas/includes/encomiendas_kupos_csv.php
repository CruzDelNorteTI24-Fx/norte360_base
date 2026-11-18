<?php
if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.');

const ENC_KUPOS_MAX_BYTES = 10485760;
const ENC_KUPOS_MAX_ROWS = 5000;

function enc_kupos_columns(): array {
    return [
        'doc_fiscal' => 'Doc. Fiscal', 'documento_fiscal' => 'Documento Fiscal',
        'nota_credito' => 'Nota de Crédito', 'codigo_envio' => 'Código de envío',
        'fecha' => 'Fecha', 'remitente' => 'Remitente', 'destinatario' => 'Destinatario',
        'articulos' => 'Artículos', 'estatus' => 'Estatus', 'seguimiento' => 'Seguimiento',
        'estado_pago' => 'Estado de Pago', 'tipo_pago' => 'Tipo de Pago',
        'numero_operacion' => 'N° de Operación', 'origen' => 'Origen', 'destino' => 'Destino',
        'oficina_destino' => 'Oficina de Destino', 'creado_por_externo' => 'Creado Por',
        'cobrado_por_externo' => 'Cobrado Por', 'total' => 'Total',
    ];
}

function enc_kupos_text(string $text): string {
    if (!preg_match('//u', $text)) throw new InvalidArgumentException('El CSV debe estar codificado en UTF-8.');
    if (class_exists('Normalizer')) $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
    return trim($text);
}

function enc_kupos_key(string $text): string {
    $text = preg_replace('/\s+/u', ' ', enc_kupos_text($text)) ?? '';
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function enc_kupos_length(string $text): int {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

// Decimal exacto: nunca convertir importes a float para comparar o guardar.
function enc_inventory_decimal($value): ?string {
    if ($value === null || $value === '') return null;
    $value = trim((string)$value);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,4}))?$/D', $value, $m)) {
        throw new InvalidArgumentException('Importe decimal no válido.');
    }
    $integer = ltrim($m[2], '0');
    $integer = $integer === '' ? '0' : $integer;
    if (strlen($integer) > 14) throw new InvalidArgumentException('El importe excede el máximo permitido.');
    $fraction = str_pad($m[3] ?? '', 4, '0');
    return (($m[1] === '-' && ($integer !== '0' || $fraction !== '0000')) ? '-' : '') . $integer . '.' . $fraction;
}

function enc_kupos_total(string $text): string {
    $text = enc_kupos_text($text);
    $text = preg_replace('/^S\s*\/\s*/iu', '', $text) ?? $text;
    $text = preg_replace('/[\s\x{00a0}]/u', '', $text) ?? $text;
    // Kupos exporta soles con punto de miles: S/1.069 = 1069 soles.
    if (strpos($text, ',') !== false) {
        if (!preg_match('/^-?(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,4}$/D', $text)) {
            throw new InvalidArgumentException('Formato de Total no válido.');
        }
        $text = str_replace(',', '.', str_replace('.', '', $text));
    } elseif (preg_match('/^-?\d{1,3}(?:\.\d{3})+$/D', $text)) {
        $text = str_replace('.', '', $text);
    }
    $value = enc_inventory_decimal($text);
    if ($value === null) throw new InvalidArgumentException('Total es obligatorio.');
    return $value;
}

function enc_kupos_row(array $raw, ?string $branch): array {
    $limits = ['doc_fiscal'=>100,'documento_fiscal'=>40,'nota_credito'=>100,'codigo_envio'=>100,
        'remitente'=>255,'destinatario'=>255,'estatus'=>50,'seguimiento'=>80,'estado_pago'=>50,
        'tipo_pago'=>80,'numero_operacion'=>100,'origen'=>160,'destino'=>160,'oficina_destino'=>160,
        'creado_por_externo'=>160,'cobrado_por_externo'=>160];
    $row = [];
    foreach (enc_kupos_columns() as $key => $label) {
        $value = enc_kupos_text((string)($raw[$key] ?? ''));
        if (isset($limits[$key]) && enc_kupos_length($value) > $limits[$key]) {
            throw new InvalidArgumentException($label . ' excede la longitud permitida.');
        }
        $row[$key] = ($value === '' || $value === '-') ? null : $value;
    }
    if (!$row['codigo_envio'] || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/D', $row['codigo_envio'])) {
        throw new InvalidArgumentException('Código de envío vacío o no válido.');
    }
    $date = DateTimeImmutable::createFromFormat('!d/m/Y', (string)$row['fecha']);
    if (!$date || $date->format('d/m/Y') !== $row['fecha']) {
        throw new InvalidArgumentException('Fecha debe tener el formato DD/MM/AAAA y ser válida.');
    }
    $row['fecha'] = $date->format('Y-m-d');
    if (!preg_match('/^[1-9]\d{0,6}$/D', (string)$row['articulos']) || (int)$row['articulos'] > 1000000) {
        throw new InvalidArgumentException('Artículos debe ser una cantidad entera positiva.');
    }
    $row['articulos'] = (int)$row['articulos'];
    $row['total'] = enc_kupos_total((string)($raw['total'] ?? ''));
    $row['sucursal'] = $branch !== null && $branch !== '' ? enc_kupos_text($branch) : null;
    if ($row['sucursal'] !== null && enc_kupos_length($row['sucursal']) > 160) {
        throw new InvalidArgumentException('Nombre de sucursal demasiado largo.');
    }
    return $row;
}

function enc_kupos_json($value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function enc_kupos_hash(array $data): string {
    return hash('sha256', enc_kupos_json($data));
}

function enc_kupos_parse($stream): array {
    $columns = enc_kupos_columns();
    $expected = [];
    foreach ($columns as $key => $label) $expected[enc_kupos_key($label)] = $key;
    $summaryLabels = ['resumen','total envíos','bruto','cantidad de cancelaciones','monto de cancelaciones',
        'neto (vigente)','cobrado en origen','pendiente por cobrar en destino','convenio','cortesía'];
    $rows = []; $errors = []; $warnings = []; $map = null; $branch = null; $line = 0;
    $headers = 0; $skipped = 0; $branches = []; $duplicates = 0;
    while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $line++;
        if ($line > ENC_KUPOS_MAX_ROWS * 3) throw new InvalidArgumentException('El archivo tiene demasiadas filas.');
        if (isset($cells[0])) $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
        $nonempty = array_filter($cells, static function ($v) { return trim((string)$v) !== ''; });
        if (!$nonempty) { $skipped++; continue; }
        $first = enc_kupos_text((string)($cells[0] ?? ''));
        if (count($cells) === 1 && preg_match('/^Sucursal:\s*(.+)$/u', $first, $m)) {
            $branch = enc_kupos_text($m[1]); $branches[$branch] = 0; $map = null; $skipped++; continue;
        }
        if (enc_kupos_key($first) === enc_kupos_key('Doc. Fiscal')) {
            $map = []; $seen = [];
            foreach ($cells as $position => $label) {
                $normalized = enc_kupos_key((string)$label);
                if (!isset($expected[$normalized]) || isset($seen[$normalized])) {
                    throw new InvalidArgumentException('Encabezado desconocido o repetido en la fila ' . $line . '.');
                }
                $seen[$normalized] = true; $map[$expected[$normalized]] = $position;
            }
            if (count($map) !== count($columns)) throw new InvalidArgumentException('El encabezado debe incluir las 19 columnas de Kupos, incluido Total.');
            $headers++; continue;
        }
        if (count($cells) <= 2 && in_array(enc_kupos_key($first), $summaryLabels, true)) { $skipped++; continue; }
        if ($map === null || count($cells) !== count($columns)) {
            if (count($errors) < 50) $errors[] = ['fila'=>$line,'mensaje'=>'Fila fuera de un encabezado válido o con cantidad de columnas incorrecta.'];
            continue;
        }
        try {
            $raw = [];
            foreach ($map as $key => $position) $raw[$key] = $cells[$position];
            $data = enc_kupos_row($raw, $branch);
            $code = $data['codigo_envio'];
            if (isset($rows[$code])) {
                if ($rows[$code]['data'] !== $data) throw new InvalidArgumentException('Código de envío repetido con datos contradictorios: ' . $code . '.');
                $duplicates++; continue;
            }
            $rows[$code] = ['fila'=>$line,'data'=>$data];
            if ($branch !== null) $branches[$branch] = ($branches[$branch] ?? 0) + 1;
            if (count($rows) > ENC_KUPOS_MAX_ROWS) throw new LengthException('El archivo supera los 5.000 envíos permitidos por carga. Exporta un periodo menor.');
        } catch (InvalidArgumentException $e) {
            if (count($errors) < 50) $errors[] = ['fila'=>$line,'mensaje'=>$e->getMessage()];
        }
    }
    if (!$headers) throw new InvalidArgumentException('No se encontró el encabezado de envíos de Kupos.');
    if (!$rows && !$errors) throw new InvalidArgumentException('El archivo no contiene envíos.');
    if ($duplicates) $warnings[] = 'Se consolidaron ' . $duplicates . ' filas idénticas repetidas.';
    ksort($rows, SORT_STRING);
    $canonical = array_map(static function ($r) { return $r['data']; }, array_values($rows));
    $dates = array_column($canonical, 'fecha'); sort($dates, SORT_STRING);
    return ['rows'=>array_values($rows),'errors'=>$errors,'warnings'=>$warnings,'branches'=>$branches,
        'headers'=>$headers,'skipped'=>$skipped,'hash'=>enc_kupos_hash($canonical),
        'fecha_desde'=>$dates[0] ?? null,'fecha_hasta'=>$dates ? $dates[count($dates)-1] : null];
}
