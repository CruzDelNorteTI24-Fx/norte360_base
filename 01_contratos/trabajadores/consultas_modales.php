<?php
$rrhhEsc = static fn($value) => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');

if (!function_exists('rrhh_format_date')) {
    function rrhh_format_date($value): string
    {
        if (empty($value) || $value === '0000-00-00') {
            return '-';
        }

        try {
            return (new DateTime((string)$value))->format('d/m/Y');
        } catch (Throwable $e) {
            return '-';
        }
    }
}

$consultaLicencias = $consultaLicencias ?? [];
$consultaCumpleanos = $consultaCumpleanos ?? [];
$consultaCargos = $consultaCargos ?? [];
$consultaEmergencia = $consultaEmergencia ?? [];
?>

<div class="rrhh-modal" id="rrhhModalLicencias" aria-hidden="true">
  <div class="rrhh-modal__backdrop" data-rrhh-modal-close></div>
  <section class="rrhh-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="rrhhModalLicenciasTitle">
    <header class="rrhh-modal__head">
      <div>
        <span><i class="bi bi-award-fill"></i> Consulta RRHH</span>
        <h2 id="rrhhModalLicenciasTitle">Licencias registradas</h2>
      </div>
      <button type="button" class="rrhh-modal__close" data-rrhh-modal-close aria-label="Cerrar">
        <i class="bi bi-x-lg"></i>
      </button>
    </header>
    <div class="rrhh-modal__body">
      <div class="rrhh-modal__summary">
        <strong><?= number_format(count($consultaLicencias)) ?></strong>
        <span>trabajadores con licencia cargada</span>
      </div>
      <div class="rrhh-mini-table-wrap">
        <table class="rrhh-mini-table">
          <thead>
            <tr>
              <th>Trabajador</th>
              <th>DNI</th>
              <th>Licencia</th>
              <th>Expedici&oacute;n</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($consultaLicencias)): ?>
              <tr><td colspan="4" class="rrhh-empty-row">No hay licencias registradas.</td></tr>
            <?php else: ?>
              <?php foreach ($consultaLicencias as $row): ?>
                <tr>
                  <td><?= $rrhhEsc($row['clm_tra_nombres'] ?? '') ?></td>
                  <td><?= $rrhhEsc($row['clm_tra_dni'] ?? '') ?></td>
                  <td><span class="rrhh-chip rrhh-chip--blue"><?= $rrhhEsc($row['clm_tra_nlicenciaconducir'] ?? '') ?></span></td>
                  <td><?= rrhh_format_date($row['clm_tra_licfecha_expedicion'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<div class="rrhh-modal" id="rrhhModalCumpleanos" aria-hidden="true">
  <div class="rrhh-modal__backdrop" data-rrhh-modal-close></div>
  <section class="rrhh-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="rrhhModalCumpleanosTitle">
    <header class="rrhh-modal__head">
      <div>
        <span><i class="bi bi-calendar2-event-fill"></i> Consulta RRHH</span>
        <h2 id="rrhhModalCumpleanosTitle">Cumplea&ntilde;os cercanos</h2>
      </div>
      <button type="button" class="rrhh-modal__close" data-rrhh-modal-close aria-label="Cerrar">
        <i class="bi bi-x-lg"></i>
      </button>
    </header>
    <div class="rrhh-modal__body">
      <div class="rrhh-modal__summary">
        <strong><?= number_format(count($consultaCumpleanos)) ?></strong>
        <span>trabajadores con fecha de nacimiento</span>
      </div>
      <div class="rrhh-mini-table-wrap">
        <table class="rrhh-mini-table">
          <thead>
            <tr>
              <th>Trabajador</th>
              <th>DNI</th>
              <th>Nacimiento</th>
              <th>Pr&oacute;ximo</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($consultaCumpleanos)): ?>
              <tr><td colspan="4" class="rrhh-empty-row">No hay fechas de cumplea&ntilde;os registradas.</td></tr>
            <?php else: ?>
              <?php foreach ($consultaCumpleanos as $row): ?>
                <?php $dias = (int)($row['dias_para_cumple'] ?? 9999); ?>
                <tr>
                  <td><?= $rrhhEsc($row['clm_tra_nombres'] ?? '') ?></td>
                  <td><?= $rrhhEsc($row['clm_tra_dni'] ?? '') ?></td>
                  <td><?= rrhh_format_date($row['clm_tra_fecha_nacimiento'] ?? '') ?></td>
                  <td>
                    <span class="rrhh-chip <?= $dias === 0 ? 'rrhh-chip--green' : 'rrhh-chip--soft' ?>">
                      <?= $dias === 0 ? 'Hoy' : '+' . number_format($dias) . ' dias' ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<div class="rrhh-modal" id="rrhhModalCargos" aria-hidden="true">
  <div class="rrhh-modal__backdrop" data-rrhh-modal-close></div>
  <section class="rrhh-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="rrhhModalCargosTitle">
    <header class="rrhh-modal__head">
      <div>
        <span><i class="bi bi-briefcase-fill"></i> Consulta RRHH</span>
        <h2 id="rrhhModalCargosTitle">Cargos y posiciones</h2>
      </div>
      <button type="button" class="rrhh-modal__close" data-rrhh-modal-close aria-label="Cerrar">
        <i class="bi bi-x-lg"></i>
      </button>
    </header>
    <div class="rrhh-modal__body">
      <div class="rrhh-modal__summary">
        <strong><?= number_format(count($consultaCargos)) ?></strong>
        <span>registros con tipo o cargo asignado</span>
      </div>
      <div class="rrhh-mini-table-wrap">
        <table class="rrhh-mini-table">
          <thead>
            <tr>
              <th>Trabajador</th>
              <th>DNI</th>
              <th>Tipo</th>
              <th>Cargo</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($consultaCargos)): ?>
              <tr><td colspan="4" class="rrhh-empty-row">No hay cargos registrados.</td></tr>
            <?php else: ?>
              <?php foreach ($consultaCargos as $row): ?>
                <tr>
                  <td><?= $rrhhEsc($row['clm_tra_nombres'] ?? '') ?></td>
                  <td><?= $rrhhEsc($row['clm_tra_dni'] ?? '') ?></td>
                  <td><?= $rrhhEsc($row['clm_tra_tipo_trabajador'] ?? '-') ?></td>
                  <td><span class="rrhh-chip rrhh-chip--blue"><?= $rrhhEsc($row['clm_tra_cargo'] ?? '-') ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<div class="rrhh-modal" id="rrhhModalEmergencia" aria-hidden="true">
  <div class="rrhh-modal__backdrop" data-rrhh-modal-close></div>
  <section class="rrhh-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="rrhhModalEmergenciaTitle">
    <header class="rrhh-modal__head">
      <div>
        <span><i class="bi bi-telephone-inbound-fill"></i> Consulta RRHH</span>
        <h2 id="rrhhModalEmergenciaTitle">Contactos de emergencia</h2>
      </div>
      <button type="button" class="rrhh-modal__close" data-rrhh-modal-close aria-label="Cerrar">
        <i class="bi bi-x-lg"></i>
      </button>
    </header>
    <div class="rrhh-modal__body">
      <div class="rrhh-modal__summary">
        <strong><?= number_format(count($consultaEmergencia)) ?></strong>
        <span>contactos de emergencia registrados</span>
      </div>
      <div class="rrhh-mini-table-wrap">
        <table class="rrhh-mini-table">
          <thead>
            <tr>
              <th>Trabajador</th>
              <th>DNI</th>
              <th>Contacto</th>
              <th>Parentesco</th>
              <th>Celular</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($consultaEmergencia)): ?>
              <tr><td colspan="5" class="rrhh-empty-row">No hay contactos de emergencia registrados.</td></tr>
            <?php else: ?>
              <?php foreach ($consultaEmergencia as $row): ?>
                <tr>
                  <td><?= $rrhhEsc($row['clm_tra_nombres'] ?? '') ?></td>
                  <td><?= $rrhhEsc($row['clm_tra_dni'] ?? '') ?></td>
                  <td><?= $rrhhEsc($row['clm_emerg_nombre'] ?? '') ?></td>
                  <td><?= $rrhhEsc($row['clm_emerg_parentesco'] ?? '-') ?></td>
                  <td><span class="rrhh-chip rrhh-chip--green"><?= $rrhhEsc($row['clm_emerg_celular'] ?? '') ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>
