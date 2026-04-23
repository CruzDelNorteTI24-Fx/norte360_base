<?php
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    define('ACCESS_GRANTED', true);
    require_once("../.c0nn3ct/db_securebd2.php");

    $id_entrevista = intval($_POST["id_entrevista"] ?? 0);
    $estado = intval($_POST["estado"] ?? 0);
    $comentario = trim($_POST["comentario"] ?? '');

    // Validación básica
    if ($id_entrevista && in_array($estado, [2, 3, 4])) {

        // Mapeo de estado a columna
        $columna_comentario = [
            2 => "clm_comentario_entrevistapersonal",
            3 => "clm_comentario_induccion",
            4 => "clm_comentario_contratado"
        ][$estado];

        // Construcción dinámica del query seguro
        $query = "UPDATE entrevistas SET clm_estado = ?, $columna_comentario = ? WHERE id_entrevista = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("isi", $estado, $comentario, $id_entrevista);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo "✅ Evaluación actualizada.";
        } else {
            echo "⚠️ No se realizaron cambios. Puede que el comentario ya exista o el estado sea el mismo.";
        }

        $stmt->close();
    } else {
        echo "❌ Datos inválidos.";
    }

    $conn->close();
}
?>
