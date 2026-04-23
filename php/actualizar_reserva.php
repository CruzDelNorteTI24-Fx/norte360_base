<?php
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    define('ACCESS_GRANTED', true);
    require_once("../.c0nn3ct/db_securebd2.php");

    $id = intval($_POST["id_entrevista"] ?? 0);
    $accion = $_POST["accion"] ?? "";

    if ($id <= 0) {
        echo "⚠️ ID no recibido o inválido.";
        exit();
    }

    if ($accion === "reservar") {
        $sql = "UPDATE entrevistas SET clm_reservas = 1 WHERE id_entrevista = ?";
    } elseif ($accion === "quitar") {
        $sql = "UPDATE entrevistas SET clm_reservas = 0 WHERE id_entrevista = ?";
    } else {
        echo "❌ Acción no válida.";
        exit();
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "✅ Reserva actualizada.";
        } else {
            echo "⚠️ No se realizó ningún cambio.";
        }
    } else {
        echo "❌ Error al actualizar: " . $conn->error;
    }

    $stmt->close();
    $conn->close();
}
?>
