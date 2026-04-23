<?php
session_start();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso Denegado | Norte 360°</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/norte360.png">
    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
        }

        h1 {
            color: #e74c3c;
            margin-bottom: 20px;
        }

        p {
            color: #333;
            font-size: 16px;
            margin-bottom: 30px;
        }

        a.button {
            text-decoration: none;
            background: #2980b9;
            color: white;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: bold;
            transition: background 0.3s ease;
        }

        a.button:hover {
            background: #1f6391;
        }
    </style>
</head>
<body>

    <div class="card">
        <h1>Acceso Denegado</h1>
        <p>No tienes permisos para acceder a esta sección.</p>
    </div>
</body>
</html>
