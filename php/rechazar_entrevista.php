<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id_entrevista'] ?? 0);
    $comentario = trim($_POST['comentario'] ?? '');

    if ($id > 0 && $comentario !== '') {
        define('ACCESS_GRANTED', true);
        require_once("../.c0nn3ct/db_securebd2.php");
        $stmt = $conn->prepare("UPDATE entrevistas SET clm_yesorno = 2, clm_comentario_rechazado = ? WHERE id_entrevista = ?");
        $stmt->bind_param("si", $comentario, $id);

        if ($stmt->execute()) {
            echo "OK";
        } else {
            echo "Error al actualizar";
        }

        $stmt->close();
        $conn->close();
    } else {
        echo "ID o comentario inválido";
    }
}
?>
