<?php
require_once __DIR__ . '/../layout/security_n360.php';
n360_send_security_headers();
n360_start_secure_session();

$csrfToken = n360_csrf_token('login');

$errorMessages = [
    '1' => 'Usuario o contraseña incorrectos.',
    'csrf' => 'La sesión expiró. Intenta ingresar nuevamente.',
    'blocked' => 'Demasiados intentos. Espera unos minutos antes de volver a intentar.',
];

$errorCode = isset($_GET['error']) ? (string) $_GET['error'] : '';
$errorMessage = $errorMessages[$errorCode] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#101820">
    <title>Acceso | Norte 360</title>
    <link rel="icon" href="../img/norte360.png">

    <style>
        :root {
            --n360-dark: #101820;
            --n360-dark-2: #173449;
            --n360-blue: #248ccc;
            --n360-blue-deep: #176b9f;
            --n360-yellow: #f4c316;
            --n360-text: #142536;
            --n360-muted: #6f7f8d;
            --n360-line: #dce3e8;
            --n360-soft: #eef2f5;
            --n360-white: #ffffff;
            --n360-danger: #b42318;
            --n360-danger-bg: #fff1f0;
            --shell-radius: 34px;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            background: var(--n360-soft);
        }

        body {
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            padding: clamp(18px, 3vw, 42px);
            display: grid;
            place-items: center;
            overflow-x: hidden;
            background:
                radial-gradient(circle at 14% 18%, rgba(36, 140, 204, 0.10), transparent 30%),
                radial-gradient(circle at 88% 82%, rgba(244, 195, 22, 0.09), transparent 24%),
                #eef2f5;
            color: var(--n360-text);
            font-family: "Segoe UI", Inter, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        button,
        input {
            font: inherit;
        }

        .login-shell {
            width: min(1160px, 100%);
            min-height: min(710px, calc(100dvh - clamp(36px, 6vw, 84px)));
            display: grid;
            grid-template-columns: minmax(410px, 1.05fr) minmax(390px, 0.95fr);
            overflow: hidden;
            border-radius: var(--shell-radius);
            background: linear-gradient(145deg, rgba(9, 15, 20, 0.96), rgba(18, 45, 63, 0.96)),
                var(--n360-dark);
            box-shadow:
                0 28px 70px rgba(16, 24, 32, 0.16),
                0 6px 20px rgba(16, 24, 32, 0.08);
        }

        /* Lado institucional */
        .brand-panel {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            padding: clamp(32px, 5vw, 64px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #fff;
            background:
                linear-gradient(145deg, rgba(9, 15, 20, 0.96), rgba(18, 45, 63, 0.96)),
                var(--n360-dark);
        }

        .brand-panel::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: -2;
            opacity: 0.92;

        }

        .brand-panel::after {
            content: "";
            position: absolute;
            right: -140px;
            bottom: -150px;
            z-index: -1;
            width: 390px;
            aspect-ratio: 1;
            border-radius: 50%;
        }

        .brand-top {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .brand-mark {
            width: 58px;
            height: 58px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.22);
        }

        .brand-mark img {
            width: 42px;
            height: 42px;
            object-fit: contain;
        }

        .brand-name strong {
            display: block;
            font-size: 17px;
            letter-spacing: 0.01em;
        }

        .brand-name span {
            display: block;
            margin-top: 3px;
            color: rgba(255, 255, 255, 0.66);
            font-size: 12px;
            font-weight: 600;
        }

        .brand-content {
            max-width: 540px;
            padding: 44px 0;
        }

        .brand-kicker {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin: 0 0 18px;
            color: #9ddaff;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .brand-kicker::before {
            content: "";
            width: 28px;
            height: 3px;
            border-radius: 999px;
            background: var(--n360-yellow);
        }

        .brand-content h1 {
            margin: 0;
            max-width: 510px;
            font-size: clamp(36px, 4.4vw, 58px);
            line-height: 1.02;
            letter-spacing: -0.045em;
        }

        .brand-content p {
            max-width: 490px;
            margin: 22px 0 0;
            color: rgba(255, 255, 255, 0.70);
            font-size: 16px;
            line-height: 1.65;
        }

        .brand-bottom {
            display: flex;
            flex-wrap: wrap;
            gap: 18px 28px;
            color: rgba(255, 255, 255, 0.68);
            font-size: 12px;
            font-weight: 600;
        }

        .brand-bottom span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .brand-bottom span::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--n360-yellow);
            box-shadow: 0 0 0 4px rgba(244, 195, 22, 0.12);
        }

        /* Zona del formulario */
        .access-panel {
            position: relative;
            display: grid;
            place-items: center;
            padding: clamp(42px, 6vw, 82px);
            background: #fff;
            border-radius: 72px 0 0 72px;
            margin-left: -42px;
            z-index: 2;
        }

        .form-wrap {
            width: min(405px, 100%);
        }

        .mobile-logo {
            display: none;
        }

        .form-heading {
            margin-bottom: 34px;
        }

        .form-heading .small-title {
            margin: 0 0 8px;
            color: var(--n360-blue-deep);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }

        .form-heading h2 {
            margin: 0;
            color: var(--n360-dark);
            font-size: clamp(34px, 3.3vw, 46px);
            line-height: 1.06;
            letter-spacing: -0.04em;
        }

        .form-heading p {
            margin: 12px 0 0;
            color: var(--n360-muted);
            font-size: 15px;
            line-height: 1.55;
        }

        .field {
            position: relative;
            margin-bottom: 22px;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            color: #253746;
            font-size: 13px;
            font-weight: 700;
        }

        .field-control {
            position: relative;
        }

        .field-icon {
            position: absolute;
            left: 0;
            top: 50%;
            width: 20px;
            height: 20px;
            transform: translateY(-50%);
            color: #7a8a96;
            pointer-events: none;
        }

        .field input {
            width: 100%;
            height: 50px;
            padding: 8px 42px 8px 32px;
            border: 0;
            border-bottom: 1px solid var(--n360-line);
            border-radius: 0;
            outline: none;
            background: transparent;
            color: var(--n360-text);
            font-size: 15px;
            transition: border-color 160ms ease, box-shadow 160ms ease;
        }

        .field input::placeholder {
            color: #9aa7b1;
        }

        .field input:focus {
            border-color: var(--n360-blue);
            box-shadow: 0 1px 0 var(--n360-blue);
        }

        .field:focus-within label {
            color: var(--n360-blue-deep);
        }

        .field:focus-within .field-icon {
            color: var(--n360-blue);
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 0;
            transform: translateY(-50%);
            padding: 7px 0 7px 10px;
            border: 0;
            background: transparent;
            color: var(--n360-blue-deep);
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
        }

        .password-toggle:hover {
            color: var(--n360-dark);
        }

        .login-button {
            width: 100%;
            min-height: 52px;
            margin-top: 10px;
            padding: 0 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: 0;
            border-radius: 10px;
            background: var(--n360-dark);
            color: #fff;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.01em;
            cursor: pointer;
            box-shadow: inset 0 -3px 0 rgba(244, 195, 22, 0.92);
            transition: transform 160ms ease, background 160ms ease, box-shadow 160ms ease;
        }

        .login-button:hover {
            background: #172b3c;
            transform: translateY(-1px);
            box-shadow: inset 0 -4px 0 var(--n360-yellow), 0 10px 24px rgba(16, 24, 32, 0.13);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .login-button:disabled {
            cursor: wait;
            opacity: 0.76;
            transform: none;
        }

        .button-spinner {
            display: none;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.36);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 700ms linear infinite;
        }

        .login-button.is-loading .button-spinner {
            display: inline-block;
        }

        .error-message {
            margin: 18px 0 0;
            padding: 12px 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border-left: 3px solid var(--n360-danger);
            background: var(--n360-danger-bg);
            color: var(--n360-danger);
            font-size: 13px;
            line-height: 1.45;
        }

        .error-message svg {
            flex: 0 0 auto;
            margin-top: 1px;
        }

        .support-note {
            margin: 24px 0 0;
            color: #87949f;
            font-size: 12px;
            line-height: 1.5;
            text-align: center;
        }

        .support-note strong {
            color: #526573;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 960px) {
            body {
                padding: 18px;
            }

            .login-shell {
                grid-template-columns: 1fr;
                min-height: auto;
                max-width: 720px;
            }

            .brand-panel {
                min-height: 290px;
                padding: 28px 30px 56px;
            }

            .brand-content {
                padding: 30px 0 0;
            }

            .brand-content h1 {
                max-width: 620px;
                font-size: clamp(34px, 7vw, 50px);
            }

            .brand-content p,
            .brand-bottom {
                display: none;
            }

            .access-panel {
                margin: -34px 0 0;
                padding: 50px 34px 46px;
                border-radius: 54px 0 0 0;
            }
        }

        @media (max-width: 560px) {
            body {
                display: block;
                min-height: 100dvh;
                padding: 0;
                background: #fff;
            }

            .login-shell {
                width: 100%;
                min-height: 100dvh;
                border-radius: 0;
                box-shadow: none;
            }

            .brand-panel {
                min-height: 250px;
                padding: max(22px, env(safe-area-inset-top)) 22px 58px;
            }

            .brand-mark {
                width: 52px;
                height: 52px;
                border-radius: 14px;
            }

            .brand-mark img {
                width: 38px;
                height: 38px;
            }

            .brand-name strong {
                font-size: 15px;
            }

            .brand-name span {
                font-size: 11px;
            }

            .brand-content {
                padding: 30px 0 0;
            }

            .brand-kicker {
                margin-bottom: 12px;
                font-size: 10px;
            }

            .brand-content h1 {
                max-width: 330px;
                font-size: clamp(31px, 11vw, 41px);
            }

            .access-panel {
                min-height: calc(100dvh - 216px);
                align-items: start;
                margin-top: -36px;
                padding: 54px 24px max(32px, env(safe-area-inset-bottom));
                border-radius: 48px 0 0 0;
            }

            .form-wrap {
                width: 100%;
            }

            .form-heading {
                margin-bottom: 30px;
            }

            .form-heading h2 {
                font-size: 34px;
            }

            .support-note {
                margin-top: 22px;
            }
        }

        @media (max-height: 720px) and (min-width: 961px) {
            .login-shell {
                min-height: 620px;
            }

            .brand-content {
                padding: 24px 0;
            }

            .brand-content h1 {
                font-size: 45px;
            }

            .access-panel {
                padding-top: 44px;
                padding-bottom: 44px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>

<main class="login-shell">
    <section class="brand-panel" aria-label="Presentación de Norte 360">
        <div class="brand-top">
            <div class="brand-mark" aria-hidden="true">
                <img src="../img/norte360_black.png" alt="">
            </div>
            <div class="brand-name">
                <strong>Norte 360</strong>
                <span>ERP Operativo de Transporte</span>
            </div>
        </div>

        <div class="brand-content">

        </div>

        <div class="brand-bottom" aria-label="Características del acceso">

        </div>
    </section>

    <section class="access-panel" aria-labelledby="loginTitle">
        <div class="form-wrap">
            <header class="form-heading">
                <h2 id="loginTitle">Iniciar sesión</h2>
                <p>Ingresa tus credenciales para acceder al sistema.</p>
            </header>

            <form id="loginForm" action="validar_login.php" method="POST" autocomplete="on">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
                >

                <div class="field">
                    <label for="usuario">Usuario</label>
                    <div class="field-control">
                        <svg class="field-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M4.5 20c.8-3.8 3.3-5.7 7.5-5.7s6.7 1.9 7.5 5.7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                        <input
                            id="usuario"
                            type="text"
                            name="usuario"
                            placeholder="Escribe tu usuario"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="clave">Contraseña</label>
                    <div class="field-control">
                        <svg class="field-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M8.5 10V7.8A3.5 3.5 0 0 1 12 4.3a3.5 3.5 0 0 1 3.5 3.5V10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <path d="M12 14v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                        <input
                            id="clave"
                            type="password"
                            name="clave"
                            placeholder="Escribe tu contraseña"
                            autocomplete="current-password"
                            required
                        >
                        <button
                            type="button"
                            class="password-toggle"
                            id="togglePassword"
                            aria-controls="clave"
                            aria-label="Mostrar contraseña"
                        >
                            Mostrar
                        </button>
                    </div>
                </div>

                <button class="login-button" id="loginButton" type="submit">
                    <span class="button-spinner" aria-hidden="true"></span>
                    <span class="button-text">Ingresar</span>
                </button>
            </form>

            <?php if ($errorMessage !== ''): ?>
                <div class="error-message" role="alert" aria-live="polite">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M12 7.8v5.4M12 16.7h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                    <span><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <p class="support-note">
                ¿Problemas para ingresar? <strong>Contacta al administrador del sistema.</strong>
            </p>
        </div>
    </section>
</main>

<script>
    (() => {
        const form = document.getElementById('loginForm');
        const passwordInput = document.getElementById('clave');
        const togglePassword = document.getElementById('togglePassword');
        const loginButton = document.getElementById('loginButton');
        const buttonText = loginButton?.querySelector('.button-text');

        togglePassword?.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            togglePassword.textContent = isHidden ? 'Ocultar' : 'Mostrar';
            togglePassword.setAttribute(
                'aria-label',
                isHidden ? 'Ocultar contraseña' : 'Mostrar contraseña'
            );
            passwordInput.focus();
        });

        form?.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }

            loginButton.disabled = true;
            loginButton.classList.add('is-loading');
            loginButton.setAttribute('aria-busy', 'true');
            if (buttonText) buttonText.textContent = 'Validando...';
        });
    })();
</script>

</body>
</html>