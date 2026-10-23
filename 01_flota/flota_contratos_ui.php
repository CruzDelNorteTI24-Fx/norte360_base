<?php
require_once __DIR__ . '/flota_contratos.php';

function n360_contract_button(mysqli $conn, string $class = 'btn btn-outline-secondary btn-sm'): void {
    $ready = n360_contract_ready($conn);
    echo '<button type="button" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" data-contract-open'
        . ($ready ? '' : ' disabled title="Instala la query de contratos de Flota"')
        . '><i class="bi bi-bus-front"></i> Contratos <span data-contract-count></span></button>';
}

function n360_contract_render(mysqli $conn, bool $edit, bool $admin): void {
    $cfg = ['ready' => n360_contract_ready($conn), 'canEdit' => $edit, 'admin' => $admin,
        'csrf' => n360_contract_csrf(), 'endpoint' => 'flota_contratos_api.php'];
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(n360_asset_url('assets/css/flota_contratos_n360.css') . '&contracts=1', ENT_QUOTES, 'UTF-8') ?>">
    <div class="modal fade n360-contract-modal" id="n360ContractsModal" tabindex="-1" aria-labelledby="n360ContractsTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><div><small>FLOTA / RESERVAS DE UNIDADES</small><h2 class="modal-title" id="n360ContractsTitle">Contratos</h2></div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div class="n360-contract-toolbar">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Estado del contrato">
                        <button type="button" class="btn btn-primary" data-contract-tab="reserved">Reservados <span data-contract-reserved-count>0</span></button>
                        <button type="button" class="btn btn-outline-secondary" data-contract-tab="pending">Retorno pendiente <span data-contract-pending-count>0</span></button>
                    </div>
                    <input type="search" class="form-control form-control-sm" data-contract-search placeholder="Bus, placa o recorrido" aria-label="Buscar contrato">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-contract-refresh title="Actualizar contratos" aria-label="Actualizar contratos"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div class="alert d-none" role="status" data-contract-notice></div>
                <div class="table-responsive"><table class="table table-sm n360-contract-table">
                    <thead><tr><th>Unidad / contrato</th><th>Recorrido</th><th>Salida real</th><th>Retorno previsto</th><th>Reserva</th><th>Accion</th></tr></thead>
                    <tbody data-contract-rows></tbody>
                </table></div>
                <div class="n360-contract-confirm d-none" data-contract-confirm role="alert">
                    <strong data-contract-confirm-label></strong>
                    <button type="button" class="btn btn-success btn-sm" data-contract-confirm-yes><i class="bi bi-unlock"></i> Confirmar liberacion</button>
                    <button type="button" class="btn btn-light btn-sm" data-contract-confirm-no>Cancelar</button>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button></div>
        </div></div>
    </div>
    <div class="modal fade n360-contract-modal" id="n360ContractAuthorization" tabindex="-1" aria-labelledby="n360ContractAuthorizationTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><form class="modal-content" data-contract-auth-form autocomplete="off">
            <div class="modal-header"><h2 class="modal-title" id="n360ContractAuthorizationTitle">Unidad reservada por contrato</h2>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cancelar"></button></div>
            <div class="modal-body"><div data-contract-auth-summary></div>
                <label class="form-label mt-3" for="n360ContractPassword">Contrasena de autorizacion</label>
                <input type="password" id="n360ContractPassword" class="form-control" autocomplete="new-password" required>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning"><i class="bi bi-shield-lock"></i> Autorizar asignacion</button></div>
        </form></div>
    </div>
    <script>window.N360ContractConfig = <?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
    <script defer src="<?= htmlspecialchars(n360_asset_url('assets/js/flota_contratos_n360.js') . '&contracts=1', ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php
}
