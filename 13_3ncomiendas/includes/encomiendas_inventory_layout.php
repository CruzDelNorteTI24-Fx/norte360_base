<?php
if (!defined('N360_ENCOMIENDAS')) exit('Acceso no permitido.');

function enc_inventory_page_start(string $title, string $subtitle): void {
    if (!defined('N360_LAYOUT')) define('N360_LAYOUT',true);
    if (!defined('N360_BASE_URL')) define('N360_BASE_URL','../');
    require_once __DIR__.'/../../layout/sidebar_n360.php';
    require_once __DIR__.'/../../layout/header_n360.php';
    require_once __DIR__.'/../../layout/footer_n360.php';
    require_once __DIR__.'/../../layout/content_n360.php';
    ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= enc_h($title) ?> | Norte360</title><link rel="icon" href="../img/norte360.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach (['header','sidebar','main','footer','content','loader','dialog','inventario_stock'] as $asset): ?>
<link rel="stylesheet" href="<?= enc_h(n360_asset('assets/css/'.$asset.'_n360.css')) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= enc_h(n360_asset('13_3ncomiendas/assets/css/encomiendas.css')) ?>">
<link rel="stylesheet" href="<?= enc_h(n360_asset('13_3ncomiendas/assets/css/kupos_inventario.css')) ?>?v=3">
</head><body>
<?php n360_render_sidebar(); n360_render_header(['title'=>'Encomiendas','subtitle'=>$subtitle]); ?>
<div class="n360-main"><?php n360_render_content_separator('top'); ?>
<main class="n360-content enc-content"><div class="n360-main__inner enc-page enc-inventory-page">
<?php
}

function enc_inventory_page_end(array $scripts=[]): void {
    ?></div></main><?php n360_render_content_separator('bottom'); ?></div><?php n360_render_footer(); ?>
<?php foreach (['loader','dialog','header','sidebar'] as $asset): ?>
<script src="<?= enc_h(n360_asset('assets/js/'.$asset.'_n360.js')) ?>"></script>
<?php endforeach; foreach ($scripts as $script): ?>
<script src="<?= enc_h(n360_asset('13_3ncomiendas/assets/js/'.$script)) ?>?v=2"></script>
<?php endforeach; ?></body></html><?php
}

function enc_inventory_navigation(string $active=''): void {
    if (in_array($active,['manual','kupos'],true)) $active='tracking';
    $links=[];
    if (enc_can_view('enc-register')) $links['registro']=['registro.php','bi-plus-circle','Registrar control'];
    if (enc_can_view('enc-tracking')) {
        $links['tracking']=['tracking.php','bi-signpost-split','Tracking de controles'];
        $links['inventario']=['inventario.php','bi-box-seam','Inventario de encomiendas'];
    }
    ?><nav class="enc-inventory-nav" aria-label="Encomiendas"><?php foreach ($links as $key=>$link): ?>
<a class="stock-btn <?= $active===$key ? 'stock-btn--primary' : 'stock-btn--soft' ?>" href="<?= enc_h($link[0]) ?>"
   <?= $active===$key ? 'aria-current="page"' : '' ?>><i class="bi <?= enc_h($link[1]) ?>"></i><?= enc_h($link[2]) ?></a>
<?php endforeach; ?></nav><?php
}

function enc_ui_url(string $path,array $params,array $changes=[]): string {
    $params=array_merge($params,$changes);
    foreach ($params as $key=>$value) if (!is_scalar($value) || $value==='' || $value===null) unset($params[$key]);
    return $path.($params ? '?'.http_build_query($params) : '');
}

// Paginacion compartida; conserva filtros sin peticiones en segundo plano.
function enc_ui_pagination(int $total,int $page,int $perPage,array $params,string $path,string $key='pagina',bool $compact=false): void {
    $pages=max(1,(int)ceil($total/max(1,$perPage)));$page=max(1,min($page,$pages));
    $first=$total ? ($page-1)*$perPage+1 : 0;$last=min($total,$page*$perPage);
    $numbers=array_unique(array_merge([1],range(max(1,$page-2),min($pages,$page+2)),[$pages]));
    ?><div class="enc-pager <?= $compact?'enc-pager--compact':'' ?>">
    <span class="enc-pager__count"><?= enc_h(number_format($first)) ?>–<?= enc_h(number_format($last)) ?> de <?= enc_h(number_format($total)) ?> registros</span>
    <nav aria-label="Páginas de resultados"><ul>
    <?php if ($page>1): ?><li><a href="<?= enc_h(enc_ui_url($path,$params,[$key=>$page-1])) ?>" aria-label="Página anterior"><i class="bi bi-chevron-left"></i></a></li>
    <?php else: ?><li><span aria-disabled="true" aria-label="Página anterior"><i class="bi bi-chevron-left"></i></span></li><?php endif; ?>
    <?php $previous=0;foreach ($numbers as $number): ?>
    <?php if ($previous && $number>$previous+1): ?><li><span class="enc-pager__ellipsis">…</span></li><?php endif; ?>
    <li><?php if ($number===$page): ?><span aria-current="page"><?= $number ?></span><?php else: ?><a href="<?= enc_h(enc_ui_url($path,$params,[$key=>$number])) ?>" aria-label="Ir a página <?= $number ?>"><?= $number ?></a><?php endif; ?></li>
    <?php $previous=$number;endforeach; ?>
    <?php if ($page<$pages): ?><li><a href="<?= enc_h(enc_ui_url($path,$params,[$key=>$page+1])) ?>" aria-label="Página siguiente"><i class="bi bi-chevron-right"></i></a></li>
    <?php else: ?><li><span aria-disabled="true" aria-label="Página siguiente"><i class="bi bi-chevron-right"></i></span></li><?php endif; ?>
    </ul></nav>
    <?php if (!$compact && $pages>1): ?><form method="get" action="<?= enc_h($path) ?>" class="enc-pager__jump">
    <?php foreach ($params as $name=>$value): if ($name!==$key && is_scalar($value) && $value!==''): ?><input type="hidden" name="<?= enc_h($name) ?>" value="<?= enc_h($value) ?>"><?php endif;endforeach; ?>
    <label>Ir a página <input type="number" name="<?= enc_h($key) ?>" value="<?= $page ?>" min="1" max="<?= $pages ?>" required aria-label="Número de página"></label><button class="stock-btn stock-btn--soft stock-btn--sm" type="submit">Ir</button>
    </form><?php endif; ?></div><?php
}
