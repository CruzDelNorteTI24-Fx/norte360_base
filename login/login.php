<?php
require_once __DIR__ . '/../layout/security_n360.php';
n360_send_security_headers();
n360_start_secure_session();

$csrfToken = n360_csrf_token('login');
$errorMessages = [
    '1' => 'Usuario o contrasena incorrectos.',
    'csrf' => 'La sesion expiro. Intenta ingresar nuevamente.',
    'blocked' => 'Demasiados intentos. Espera unos minutos antes de volver a intentar.',
];
$errorCode = isset($_GET['error']) ? (string)$_GET['error'] : '';
$errorMessage = $errorMessages[$errorCode] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login | Norte 360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">

    <style>
        :root {
            --n360-blue: #173449;
            --n360-accent: #268ed3;
            --n360-text: #0b2239;
            --n360-muted: #607084;
            --n360-border: rgba(39, 98, 138, 0.18);
        }

        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }

        body {
            margin: 0;
            padding: 24px;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(38, 142, 211, 0.18), transparent 34rem),
                linear-gradient(135deg, #ecf0f1, #d9e2ec);
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--n360-text);
        }

        .container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 52px;
            width: min(880px, 100%);
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(10px);
            border: 1px solid var(--n360-border);
            padding: 46px 40px;
            border-radius: 18px;
            box-shadow: 0 22px 52px rgba(21, 52, 73, 0.14), 0 8px 18px rgba(21, 52, 73, 0.08);
        }

        .logo {
            max-width: 230px;
            width: 36%;
            min-width: 160px;
        }

        .login-card {
            max-width: 360px;
            width: 100%;
        }

        .login-card h2 {
            color: var(--n360-blue);
            margin: 0 0 8px;
            font-size: 30px;
            letter-spacing: 0;
        }

        .login-card p.subtitle {
            color: var(--n360-muted);
            margin: 0 0 26px;
            line-height: 1.45;
        }

        .input-group {
            position: relative;
            margin-bottom: 16px;
        }

        .input-group img.icon {
            position: absolute;
            top: 13px;
            left: 15px;
            width: 20px;
            height: 20px;
            opacity: 0.6;
        }

        .input-group input {
            width: 100%;
            padding: 13px 15px 13px 45px;
            border: 1px solid #cbd8e4;
            border-radius: 8px;
            font-size: 15px;
            color: var(--n360-text);
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .input-group input:focus {
            border-color: var(--n360-accent);
            box-shadow: 0 0 0 4px rgba(38, 142, 211, 0.14);
        }

        button {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #1d83c4, #2f9ee0);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            box-shadow: 0 10px 20px rgba(38, 142, 211, 0.22);
        }

        button:hover {
            background: linear-gradient(135deg, #166da6, #238dd0);
            transform: translateY(-1px);
        }

        .error {
            color: #9c1c1c;
            background: #fff0f0;
            border: 1px solid #ffd0d0;
            border-radius: 8px;
            padding: 10px 12px;
            margin-top: 14px;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                gap: 26px;
                padding: 30px 20px;
            }

            .logo {
                max-width: 150px;
                width: 58%;
            }

            .login-card h2,
            .login-card p.subtitle {
                text-align: center;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <img src="../img/norte360_black.png" alt="Logo NORTE360" class="logo">

    <div class="login-card">
        <h2>Iniciar sesion</h2>
        <p class="subtitle">Accede al panel operativo Norte 360.</p>
        <form action="validar_login.php" method="POST" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="input-group">
                <img src="../img/icons/user_login.png" class="icon" alt="Usuario">
                <input type="text" name="usuario" placeholder="Usuario" autocomplete="username" required>
            </div>
            <div class="input-group">
                <img src="../img/icons/contrasena.png" class="icon" alt="Contrasena">
                <input type="password" name="clave" placeholder="Contrasena" autocomplete="current-password" required>
            </div>
            <button type="submit">Ingresar</button>
        </form>
        <?php if ($errorMessage !== ''): ?>
            <p class="error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
