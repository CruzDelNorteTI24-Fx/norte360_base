<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/norte360.png">

    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }

        body {
            margin: 0;
            padding: 0;
            height: 100vh;
            background: linear-gradient(-45deg, #d9e2ec, #eef2f3, #d9e2ec, #ecf0f1);
    background-size: 400% 400%;
    animation: gradientBG 10s ease infinite;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 60px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            padding: 50px 40px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1), 0 10px 15px rgba(0,0,0,0.05);
        }

        .logo {
            max-width: 200px;
        }

        .login-card {
            max-width: 350px;
            width: 100%;
        }

        .login-card h2 {
            text-align: center;
            color: #34495e;
            margin-bottom: 25px;
        }

        .input-group {
            position: relative;
            margin-bottom: 20px;
        }

        .input-group img.icon {
            position: absolute;
            top: 12px;
            left: 15px;
            width: 20px;
            height: 20px;
            opacity: 0.6;
        }

        .input-group input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 15px;
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: #007bff;
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: bold;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background-color: #0056b3;
        }

        .error {
            color: red;
            text-align: center;
            margin-top: 10px;
        }
        
        @keyframes gradientBG {
            0% {background-position: 0% 50%;}
            50% {background-position: 100% 50%;}
            100% {background-position: 0% 50%;}
        }
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                gap: 30px;
                padding: 30px 20px;
            }
            .logo {
                max-width: 140px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <img src="../img/norte360_black.png" alt="Logo NORTE360" class="logo">

    <div class="login-card">
        <h2>Iniciar Sesión</h2>
        <form action="validar_login.php" method="POST">
            <div class="input-group">
                <img src="../img/icons/user_login.png" class="icon" alt="Usuario">
                <input type="text" name="usuario" placeholder="Usuario" required>
            </div>
            <div class="input-group">
                <img src="../img/icons/contrasena.png" class="icon" alt="Contraseña">
                <input type="password" name="clave" placeholder="Contraseña" required>
            </div>
            <button type="submit">Ingresar</button>
        </form>
        <?php if (isset($_GET['error'])): ?>
            <p class="error">⚠ Usuario o contraseña incorrectos</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
