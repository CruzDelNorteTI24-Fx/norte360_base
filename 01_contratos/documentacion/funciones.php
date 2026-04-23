<?php
if (!defined('ACCESS_GRANTED')) {
    die("Acceso denegado.");
}

function obtenerDocumentacion($conn) {
    $sql = "SELECT d.clm_doc_iddocumento, d.clm_doc_fecharegistro, d.clm_doc_observaciones, 
                   t.nombre_tipo, 
                   tr.clm_tra_nombres, tr.clm_tra_dni 
            FROM tb_documento_trabajador d
            INNER JOIN tb_tipo_documento t ON d.clm_doc_idtipo_documento = t.id_tipo_documento
            INNER JOIN tb_trabajador tr ON d.clm_doc_idtrabajador = tr.clm_tra_id
            ORDER BY d.clm_doc_fecharegistro DESC";

    $resultado = $conn->query($sql);
    $documentos = [];

    if ($resultado && $resultado->num_rows > 0) {
        while ($fila = $resultado->fetch_assoc()) {
            $documentos[] = $fila;
        }
    }

    return $documentos;
}
?>
