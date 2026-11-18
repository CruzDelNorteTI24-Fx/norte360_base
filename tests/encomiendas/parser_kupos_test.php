<?php
// php tests/encomiendas/parser_kupos_test.php [reporte.csv opcional]
// Solo lectura; no utiliza conexiones ni configura las aplicaciones.
if (PHP_SAPI !== 'cli') { http_response_code(403);exit; }
define('N360_ENCOMIENDAS',true);
require __DIR__.'/../../13_3ncomiendas/includes/encomiendas_kupos_csv.php';
$checks=0;
function kupos_test_assert(bool $condition,string $message): void {
    global $checks;$checks++;
    if (!$condition) throw new RuntimeException($message);
}
function kupos_test_csv(array $rows,?array $headers=null): array {
    $stream=fopen('php://temp','w+');
    fputcsv($stream,['Sucursal: Oficina de prueba']);
    fputcsv($stream,$headers ?? array_values(enc_kupos_columns()));
    foreach ($rows as $row) fputcsv($stream,array_values($row));
    rewind($stream);
    try { return enc_kupos_parse($stream); } finally { fclose($stream); }
}
foreach (['S/10'=>'10.0000','S/1.069'=>'1069.0000','S/1.234,50'=>'1234.5000',
    'S/10.25'=>'10.2500','S/0'=>'0.0000','S/-10,25'=>'-10.2500'] as $input=>$value) {
    kupos_test_assert(enc_kupos_total($input)===$value,'Total exacto: '.$input);
}
kupos_test_assert(enc_inventory_decimal('99999999999999.9999')==='99999999999999.9999','DECIMAL exacto sin float');
kupos_test_assert(enc_inventory_decimal('-0.0000')==='0.0000','Cero normalizado');
$row=array_combine(array_keys(enc_kupos_columns()),['B001-00001','Boleta','-','CE-12345678-A','11/11/2026',
    'Remitente de prueba','Destinatario de prueba','2','Activo','Recibido','Cobro a Destino',
    'Por pagar','00001234','Ciudad A','Ciudad B','Oficina B','Operador externo','-','S/12.50']);
$parsed=kupos_test_csv([$row]);$data=$parsed['rows'][0]['data'];
kupos_test_assert(!$parsed['errors'],'CSV válido');
kupos_test_assert($data['codigo_envio']===$row['codigo_envio'],'Código completo conservado');
kupos_test_assert($data['numero_operacion']==='00001234','Ceros iniciales');
kupos_test_assert($data['fecha']==='2026-11-11','Fecha de calendario');
kupos_test_assert($data['articulos']===2,'Una fila, varios artículos');
kupos_test_assert($data['total']==='12.5000','Total incluido');
kupos_test_assert($data['nota_credito']===null,'Ausencia de nota de crédito');
$missing=$row;$missing['documento_fiscal']='';$missing['doc_fiscal']='-';
kupos_test_assert(!kupos_test_csv([$missing])['errors'],'Vacíos fiscales legítimos');
$same=kupos_test_csv([$row,$row]);
kupos_test_assert(count($same['rows'])===1&&!$same['errors']&&count($same['warnings'])===1,'Duplicado idéntico consolidado');
$change=$row;$change['total']='S/12.51';
kupos_test_assert(count(kupos_test_csv([$row,$change])['errors'])===1,'Duplicado contradictorio bloqueado');
kupos_test_assert(kupos_test_csv([$row])['hash']!==kupos_test_csv([$change])['hash'],'Total participa en cambios');
$other=$row;$other['codigo_envio']='CP-87654321-B';
kupos_test_assert(kupos_test_csv([$row,$other])['hash']===kupos_test_csv([$other,$row])['hash'],'Orden de filas no duplica lotes');
$invalid=$row;$invalid['fecha']='31/11/2026';
kupos_test_assert(count(kupos_test_csv([$invalid])['errors'])===1,'Fecha imposible');
$invalid=$row;$invalid['articulos']='0';
kupos_test_assert(count(kupos_test_csv([$invalid])['errors'])===1,'Cantidad inválida');
$invalid=$row;$invalid['total']='S/12.34567';
kupos_test_assert(count(kupos_test_csv([$invalid])['errors'])===1,'No truncar decimales');
$invalid=$row;$invalid['codigo_envio']='';
kupos_test_assert(count(kupos_test_csv([$invalid])['errors'])===1,'Código obligatorio');
$failed=false;
try {kupos_test_csv([array_slice($row,0,-1)],array_slice(array_values(enc_kupos_columns()),0,-1));}
catch(InvalidArgumentException $e){$failed=true;}
kupos_test_assert($failed,'Encabezado Total obligatorio');
$realCount=null;
if (isset($argv[1])) {
    $stream=fopen($argv[1],'rb');
    if (!$stream) throw new RuntimeException('No se pudo abrir el CSV indicado.');
    try {$real=enc_kupos_parse($stream);}finally{fclose($stream);}
    kupos_test_assert(!$real['errors'],'Reporte adicional sin errores');
    $realCount=count($real['rows']);
}
echo json_encode(['checks'=>$checks,'reporte_adicional_envios'=>$realCount,'sin_conexiones'=>true],JSON_UNESCAPED_UNICODE).PHP_EOL;
