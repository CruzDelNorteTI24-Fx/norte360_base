<?php
if (!defined('N360_LAYOUT')) {
    exit('Acceso no permitido.');
}

require_once __DIR__ . '/assets_n360.php';

if (!function_exists('n360_base_url')) {
    function n360_base_url(string $path = ''): string {
        $base = defined('N360_BASE_URL') ? N360_BASE_URL : './';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

function n360_footer_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function n360_footer_version(): string {
    return n360_version();
}

function n360_render_footer(array $options = []): void {
    global $h2bd_img, $h2bd_name;

    $year = date('Y');
    $version = $options['version'] ?? n360_footer_version();
    $logoUrl = $options['logo_url'] ?? n360_base_url('img/norte360.png');
    $homeUrl = $options['home_url'] ?? n360_base_url('index.php');
    $supportUrl = $options['support_url'] ?? 'https://wa.me/51944532822?text=Hola%2C%20quisiera%20hacer%20una%20consulta%20sobre%20Norte360.';

    $links = $options['links'] ?? [
        [
            'label' => 'Panel principal',
            'url' => $homeUrl,
            'icon' => 'bi bi-house-door-fill',
        ],
        [
            'label' => 'Soporte',
            'url' => $supportUrl,
            'icon' => 'bi bi-whatsapp',
            'target' => '_blank',
        ],
    ];

    $eggImg = trim((string)($h2bd_img ?? ''));
    $eggName = trim((string)($h2bd_name ?? ''));
    ?>
    <footer class="n360-footer" role="contentinfo">
        <div class="n360-footer__inner">
            <div class="n360-footer__brand">
                <a href="<?= n360_footer_h($homeUrl) ?>" class="n360-footer__logo-link" aria-label="Ir al panel principal">
                    <img src="<?= n360_footer_h($logoUrl) ?>" alt="Norte 360" class="n360-footer__logo">
                </a>
                <div>
                    <strong>Norte360 Web</strong>
                    <span>ERP Operativo de Transporte</span>
                </div>
            </div>

            <nav class="n360-footer__links" aria-label="Enlaces del pie de pagina">
                <?php foreach ($links as $link): ?>
                    <?php
                    $target = $link['target'] ?? '';
                    $rel = $target === '_blank' ? 'noopener noreferrer' : '';
                    ?>
                    <a href="<?= n360_footer_h($link['url'] ?? '#') ?>"
                       <?= $target !== '' ? 'target="'.n360_footer_h($target).'"' : '' ?>
                       <?= $rel !== '' ? 'rel="'.n360_footer_h($rel).'"' : '' ?>>
                        <i class="<?= n360_footer_h($link['icon'] ?? 'bi bi-link-45deg') ?>" aria-hidden="true"></i>
                        <span><?= n360_footer_h($link['label'] ?? 'Enlace') ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="n360-footer__meta">
                <span class="n360-footer__version">
                    <i class="bi bi-tag-fill" aria-hidden="true"></i>
                    Version <?= n360_footer_h($version) ?>
                </span>
                <span>&copy; <?= n360_footer_h($year) ?> Norte 360. Todos los derechos reservados.</span>
            </div>
        </div>

        <?php if ($eggImg !== '' && $eggName !== ''): ?>
            <div class="n360-footer__egg" id="n360FooterEgg" hidden>
                <img src="<?= n360_footer_h($eggImg) ?>" alt="Credito interno">
                <span><?= n360_footer_h($eggName) ?></span>
            </div>
            <script>
                document.addEventListener('keydown', function (event) {
                    if (!event.ctrlKey || !event.altKey || event.key.toLowerCase() !== 'm') return;

                    const egg = document.getElementById('n360FooterEgg');
                    if (!egg) return;

                    egg.hidden = !egg.hidden;
                });
            </script>
        <?php endif; ?>
    </footer>
    <?php
}