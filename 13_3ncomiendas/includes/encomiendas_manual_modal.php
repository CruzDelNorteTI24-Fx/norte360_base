<?php if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.'); ?>
<div class="modal fade enc-manual-modal" id="encManualRezagadoModal" tabindex="-1" aria-labelledby="encManualRezagadoTitle" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-lg"><form class="modal-content" action="actions/agregar_rezagado_manual.php" method="post" data-enc-manual-form data-confirm="Se agregará esta encomienda manual como rezagado.">
<input type="hidden" name="csrf_token" value="<?= enc_h($csrf) ?>"><input type="hidden" name="estado" value="REZAGADO">
<div class="modal-header"><div><span class="stock-eyebrow">Registro manual</span><h2 class="modal-title" id="encManualRezagadoTitle">Agregar encomienda manual</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
<div class="modal-body enc-manual-modal__body">
<label class="stock-field"><span>Documento</span><input name="documento" maxlength="100" placeholder="Código o comprobante"></label>
<label class="stock-field"><span>Guía de remisión</span><input name="guia_remision" maxlength="100" placeholder="Opcional"></label>
<label class="stock-field stock-field--wide"><span>Consignado</span><input name="consignado" maxlength="255" placeholder="Cliente o destinatario"></label>
<label class="stock-field stock-field--wide"><span>Referencia</span><input name="referencia_envio" maxlength="1000" placeholder="Detalle de encomienda"></label>
<label class="stock-field"><span>Peso</span><input type="number" name="peso" step="0.0001" min="0" placeholder="0.00"></label>
<label class="stock-field"><span>Tipo de pago</span><input name="tipo_pago" maxlength="80" placeholder="Efectivo, Yape..."></label>
<label class="stock-field"><span>Importe</span><input type="number" name="importe_cobrado" step="0.0001" min="0" placeholder="0.00"></label>
<label class="stock-field stock-field--wide"><span>Observación</span><input name="observacion" maxlength="1000"></label>
</div><div class="modal-footer"><button class="stock-btn stock-btn--soft" type="button" data-bs-dismiss="modal">Cancelar</button><button class="stock-btn stock-btn--primary" type="submit"><i class="bi bi-save2"></i> Guardar manual</button></div>
</form></div></div>
