<?php
define('ACCESS_GRANTED', true);
require_once __DIR__ . '/../.c0nn3ct/db_securebd2.php';
// Limpiar cualquier sesión previa antes de iniciar
session_start();
session_unset();
session_destroy();
session_start();

function n360_login_photo_data_uri($blob): string {
    if (!is_string($blob) || $blob === '') {
        return '';
    }

    $mime = 'image/jpeg';
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($blob);
        if (is_array($info) && !empty($info['mime'])) {
            $mime = (string)$info['mime'];
        }
    }

    return 'data:' . $mime . ';base64,' . base64_encode($blob);
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usuario = trim($_POST['usuario']);
    $clave = trim($_POST['clave']);

    // Aplicar hash SHA-256 a la contraseña ingresada
    $clave_hash = hash('sha256', $clave);

    $stmt = $conn->prepare("
        SELECT
            u.id_usuario,
            u.usuario,
            u.contrasena,
            u.nombre,
            u.DNI,
            u.clm_usuarios_sede,
            u.web_rol,
            s.clm_sedes_name AS sede_nombre,
            u.clm_tra_imagen AS foto_usuario
        FROM tb_usuarios u
        LEFT JOIN tb_sedes s ON s.clm_sedes_id = u.clm_usuarios_sede
        LEFT JOIN tb_trabajador t ON t.clm_tra_id = (
            SELECT t2.clm_tra_id
            FROM tb_trabajador t2
            WHERE t2.clm_tra_dni = u.DNI
              AND t2.clm_tra_imagen IS NOT NULL
              AND t2.clm_tra_imagen <> ''
            ORDER BY t2.clm_tra_id DESC
            LIMIT 1
        )
        WHERE u.usuario = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $usuario);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows === 1) {
        $fila = $resultado->fetch_assoc();
        $clave_bd = $fila['contrasena'];  // Asegúrate que este sea el campo del hash en la tabla


        if ($clave_hash === $clave_bd) {
            $_SESSION['id_usuario'] = $fila['id_usuario'];
            $_SESSION['usuario'] = $fila['usuario'];
            $_SESSION['web_rol'] = $fila['web_rol'];
            $_SESSION['nombre'] = $fila['nombre'];
            $_SESSION['DNI'] = $fila['DNI'];
            $_SESSION['clm_usuarios_sede'] = $fila['clm_usuarios_sede'];
            $_SESSION['clm_usuarios_sede_nombre'] = trim((string)($fila['sede_nombre'] ?? '')) !== ''
                ? $fila['sede_nombre']
                : $fila['clm_usuarios_sede'];

            $_SESSION['n360_user_photo_checked'] = true;
            $fotoPerfil = n360_login_photo_data_uri($fila['foto_usuario'] ?? null);
            if ($fotoPerfil !== '') {
                $_SESSION['n360_user_photo'] = $fotoPerfil;
            } else {
                unset($_SESSION['n360_user_photo']);
            }

            // Si es Admin, redirecciona a index.php
            if ($fila['web_rol'] === 'Admin') {
                $_SESSION['permisos'] = 'all'; // Admin tiene acceso total
                $_SESSION['vista_redirect'] = 'index.php';
                header("Location: ../index.php");
                exit();
            } else {
                // Si es Usuario, buscar todos sus módulos permitidos
                $id_usuario = $fila['id_usuario'];
                $stmt_permiso = $conn->prepare("SELECT id_modulo, vista_redirect FROM tb_permisos WHERE id_usuario = ?");
                $stmt_permiso->bind_param("i", $id_usuario);
                $stmt_permiso->execute();
                $res_permiso = $stmt_permiso->get_result();

                if ($res_permiso->num_rows > 0) {
                    $permisos = [];
                    $vistas = [];

                    while ($permiso = $res_permiso->fetch_assoc()) {
                        $permisos[] = $permiso['id_modulo'];
                        $vistas[] = $permiso['vista_redirect'];
                    }

                    // Guardar en sesión
                    $_SESSION['permisos'] = $permisos;
                    $_SESSION['vistas'] = $vistas;

                    // Redireccionar según rol, permiso y vista
                    foreach ($permisos as $index => $modulo) {
                        $vista = $vistas[$index];

                        if ($modulo == 1) {
                            if ($vista == "checklist-limpieza") {
                                header("Location: ../checklistlimpieza.php");
                                exit();
                            } elseif ($vista == "checklist-carro") {
                                header("Location: ../checklistcarro.php");
                                exit();
                            }
                        } elseif ($modulo == 6) {
                            if ($vista == "r-gen") {
                                header("Location: ../01_contratos/nregrcdn_h.php");
                                exit();
                            } elseif ($vista == "e-gen") {
                                header("Location: ../01_entrevistas/reentrev.php");
                                exit();
                            }
                        } elseif ($modulo == 5) {
                            if ($vista == "c-limp") {
                                header("Location: ../index.php");
                                exit();
                            } elseif ($vista == "c-sab") {
                                header("Location: ../01_amantenimiento/lista_cheklist.php");
                                exit();
                            } elseif ($vista == "c-lalu") {
                                header("Location: ../01_amantenimiento/lista_cheklist.php");
                                exit();
                            }
                        } elseif ($modulo == 10) {
                            if ($vista == "f-flotayoperaciones") {
                                header("Location: ../index.php");
                                exit();
                            } elseif ($vista == "f-placas") {
                                header("Location: ../01_flota/gest_plac.php");
                                exit();
                            } elseif ($vista == "f-progcond") {
                                header("Location: ../01_flota/programacion_condt.php");
                                exit();
                            } elseif ($vista == "f-proghor") {
                                header("Location: ../01_flota/programacion_horarios.php");
                                exit();
                            }
                        }
                        // Añade aquí más elseif según tu estructura de módulos y vistas
                    }

                    // Si no coincidió ningún permiso y vista específica
                    header("Location: none_permisos.php");
                    exit();
                } else {
                    // Sin permisos asignados
                    $_SESSION['permisos'] = [];
                    $_SESSION['vistas'] = [];
                    header("Location: none_permisos.php");
                    exit();
                }
            }
        }
    }

    header("Location: login.php?error=1");
    exit();
}
?>