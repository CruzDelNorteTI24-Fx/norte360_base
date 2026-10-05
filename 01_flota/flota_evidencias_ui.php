<?php

function n360_flota_evidence_token(): string
{
    if (empty($_SESSION['flota_evidencias_token'])) {
        $_SESSION['flota_evidencias_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['flota_evidencias_token'];
}

function n360_flota_evidence_render(string $start, string $end, bool $showBand): void
{
    $h = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
    ?>
    <?php if ($showBand): ?>
    <section class="n360-fe-band" data-fe-band data-fe-start="<?= $h($start) ?>" data-fe-end="<?= $h($end) ?>">
        <div class="n360-fe-band-head">
            <h2 class="n360-fe-band-title">
                <button type="button" class="n360-fe-toggle" data-bs-toggle="collapse" data-bs-target="#n360FlotaEvidenceCollapse" aria-expanded="false" aria-controls="n360FlotaEvidenceCollapse" title="Mostrar u ocultar las evidencias del periodo">
                    <span class="n360-fe-band-icon"><i class="bi bi-images" aria-hidden="true"></i></span>
                    <span class="n360-fe-toggle-label">Evidencias por d&#237;a operativo</span>
                    <i class="bi bi-chevron-down n360-fe-chevron" aria-hidden="true"></i>
                </button>
            </h2>
            <button type="button" class="btn btn-primary n360-fe-attach-button" data-fe-open data-fe-date="<?= $h($start) ?>">
                <i class="bi bi-upload" aria-hidden="true"></i> Adjuntar evidencia
            </button>
        </div>
        <div class="collapse" id="n360FlotaEvidenceCollapse" data-fe-collapse>
            <div class="n360-fe-days" data-fe-days aria-live="polite"></div>
            <div class="n360-fe-pagination" data-fe-pagination hidden>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-fe-prev title="Fechas anteriores" aria-label="Fechas anteriores"><i class="bi bi-chevron-left"></i></button>
                <span data-fe-page-label></span>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-fe-next title="Fechas siguientes" aria-label="Fechas siguientes"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div>
    </section>
    <?php endif; ?>
    <div class="modal fade n360-fe-modal" id="n360FlotaEvidenceModal" tabindex="-1" aria-labelledby="n360FlotaEvidenceTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="n360FlotaEvidenceTitle"><i class="bi bi-paperclip" aria-hidden="true"></i> Evidencias del dia</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="n360-fe-date-row">
                        <label for="n360FlotaEvidenceDate">Fecha operativa</label>
                        <input type="date" class="form-control" id="n360FlotaEvidenceDate" value="<?= $h($start) ?>" required>
                        <strong class="n360-fe-count" data-fe-count>0/2</strong>
                        <button type="button" class="btn btn-outline-secondary" data-fe-refresh title="Actualizar evidencias" aria-label="Actualizar evidencias"><i class="bi bi-arrow-repeat"></i></button>
                    </div>
                    <div class="n360-fe-notice" data-fe-notice role="status" aria-live="polite" hidden></div>
                    <div class="n360-fe-slots" data-fe-slots></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    window.N360_FLOTA_EVIDENCE = <?= json_encode([
        'endpoint' => 'flota_evidencias.php', 'csrf' => n360_flota_evidence_token(),
        'maxBytes' => 8388608,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php
}
