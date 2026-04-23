<?php
session_start();
define('ACCESS_GRANTED', true);
if (!isset($_SESSION['usuario'])) {
    header("Location: ../../login/login.php");
    exit();
}
require_once("../../.c0nn3ct/db_securebd2.php");


$id = intval($_GET['id']);
$sql = "SELECT * FROM tb_trabajador WHERE clm_tra_id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if (!$row = $result->fetch_assoc()) {
    echo "Trabajador no encontrado.";
    exit;
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Editar Trabajador</title>
  <link rel="stylesheet" href="estilos.css"> <!-- Puedes incluir el mismo CSS que ya tienes -->
</head>
<body>
  <div class="card" style="max-width:700px; margin:auto; margin-top:40px;">
    <h2>Editar Datos del Trabajador</h2>
    <form method="post">
      <div class="campo-form">
        <label>Nombres:</label>
        <input type="text" name="nombres" value="<?= htmlspecialchars($trabajador['clm_tra_nombres']) ?>" required>
      </div>
      <div class="campo-form">
        <label>Celular:</label>
        <input type="text" name="celular" value="<?= htmlspecialchars($trabajador['clm_tra_celular']) ?>">
      </div>
      <div class="campo-form">
        <label>Correo:</label>
        <input type="text" name="correo" value="<?= htmlspecialchars($trabajador['clm_tra_correo']) ?>">
      </div>
      <div class="campo-form">
        <label>Domicilio:</label>
        <input type="text" name="domicilio" value="<?= htmlspecialchars($trabajador['clm_tra_domicilio']) ?>">
      </div>
      <div class="campo-form">
        <label>Cargo:</label>
        <input type="text" name="cargo" value="<?= htmlspecialchars($trabajador['clm_tra_cargo']) ?>">
      </div>
      <div class="campo-form">
        <label>Tipo de Trabajador:</label>
        <input type="text" name="tipo" value="<?= htmlspecialchars($trabajador['clm_tra_tipo_trabajador']) ?>">
      </div>
      <br>
      <button type="submit">💾 Guardar Cambios</button>
      <br><br>
      <a href="detalle_trabajador.php?id=<?= $id ?>" class="btn-ver">⬅ Volver</a>
    </form>
  </div>
</body>
</html>
