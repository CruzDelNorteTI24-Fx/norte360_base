<?php
if (!defined('N360_LAYOUT')) exit('Acceso no permitido.');

function n360_render_space_personnel_dialog(string $csrf): void {
    ?>
    <dialog class="sedeesp-dialog sedeesp-personnel" id="sedeespPersonnelDialog" aria-labelledby="sedeespPersonnelTitle"
            data-api="<?= n360_admin_h(n360_base_url('admin/espacios_personal_api.php')) ?>" data-csrf="<?= n360_admin_h($csrf) ?>">
        <header class="sedeesp-dialog__head"><div><span class="sedeesp-dialog__kicker"><i class="bi bi-people" aria-hidden="true"></i> Administración - Ubicaciones</span><h2 id="sedeespPersonnelTitle">Personal del espacio</h2><p>Organiza las personas asignadas a cada turno.</p></div><button type="button" class="sedeesp-dialog__close" data-personnel-close aria-label="Cerrar personal del espacio">&times;</button></header>
        <div class="sedeesp-dialog__body">
            <div class="sedeesp-preview sedeesp-personnel__location"><span>Ubicación</span><p id="sedeespPersonnelLocation"></p></div>
            <p class="sedeesp-personnel__status" id="sedeespPersonnelStatus" role="status" aria-live="polite"></p>
            <div class="sedeesp-form-error" id="sedeespPersonnelError" role="alert" hidden></div>
            <div class="sedeesp-personnel__turns">
                <?php foreach ([1, 2] as $turn): ?>
                <section class="sedeesp-personnel__turn" data-personnel-turn="<?= $turn ?>" aria-labelledby="sedeespPersonnelTurn<?= $turn ?>">
                    <header><div><span class="sedeesp-personnel__turn-icon"><i class="bi bi-people" aria-hidden="true"></i></span><h3 id="sedeespPersonnelTurn<?= $turn ?>">Turno <?= $turn ?></h3></div><span class="sedeesp-total" data-personnel-count>0 personas</span></header>
                    <ul class="sedeesp-personnel__people" data-personnel-people aria-label="Personal del turno <?= $turn ?>"></ul>
                    <form class="sedeesp-personnel__add" data-personnel-form data-turn="<?= $turn ?>" autocomplete="off">
                        <fieldset disabled>
                            <label for="sedeespPersonnelSource<?= $turn ?>">Agregar persona<select name="origen" id="sedeespPersonnelSource<?= $turn ?>"><option value="RRHH">Seleccionar de RRHH</option><option value="LIBRE">Escribir nombre libre</option></select></label>
                            <div data-personnel-worker>
                                <label for="sedeespPersonnelQuery<?= $turn ?>">Buscar trabajador<input type="search" id="sedeespPersonnelQuery<?= $turn ?>" name="busqueda" maxlength="100" placeholder="Nombre, abreviatura o DNI..." aria-controls="sedeespPersonnelResults<?= $turn ?>" aria-autocomplete="list" aria-expanded="false" role="combobox"></label>
                                <input type="hidden" name="trabajador_id">
                                <div class="sedeesp-personnel__results" id="sedeespPersonnelResults<?= $turn ?>" role="listbox" aria-label="Trabajadores encontrados" hidden></div>
                                <small class="sedeesp-personnel__search-status" data-personnel-search-status role="status">Escribe al menos 2 caracteres y elige una persona.</small>
                            </div>
                            <div data-personnel-free hidden><label for="sedeespPersonnelFree<?= $turn ?>">Nombre abreviado<input type="text" name="nombre" id="sedeespPersonnelFree<?= $turn ?>" maxlength="100" placeholder="Ej. Juan P."></label></div>
                            <button type="submit" class="sedeesp-button"><i class="bi bi-person-plus" aria-hidden="true"></i> Agregar al turno</button>
                        </fieldset>
                    </form>
                </section>
                <?php endforeach; ?>
            </div>
            <details class="sedeesp-personnel__history"><summary><i class="bi bi-clock-history" aria-hidden="true"></i> Historial de asignaciones retiradas</summary><p>Se muestran las últimas 50 asignaciones retiradas de este espacio.</p><div data-personnel-history></div></details>
        </div>
        <footer class="sedeesp-dialog__footer"><button type="button" class="sedeesp-button sedeesp-button--soft" data-personnel-reload><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Actualizar</button><button type="button" class="sedeesp-button" data-personnel-close>Cerrar</button></footer>
    </dialog>
    <script src="<?= n360_asset('assets/js/admin_espacios_personal_n360.js?personal=2') ?>"></script>
    <?php
}
