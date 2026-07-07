<?php
if (!defined('N360_LAYOUT')) {
    exit('Acceso no permitido.');
}

function n360_content_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function n360_render_content_separator(string $position = 'section', array $options = []): void {
    $allowedPositions = ['top', 'bottom', 'section'];
    $position = in_array($position, $allowedPositions, true) ? $position : 'section';

    $extraClass = trim((string)($options['class'] ?? ''));
    $label = trim((string)($options['label'] ?? ''));
    $classes = 'n360-content-separator n360-content-separator--' . $position;

    if ($extraClass !== '') {
        $classes .= ' ' . $extraClass;
    }

    if ($label !== '') {
        echo '<div class="n360-content-separator-wrap n360-content-separator-wrap--'.n360_content_h($position).'">';
        echo '<hr class="'.n360_content_h($classes).'" aria-hidden="true">';
        echo '<span>'.n360_content_h($label).'</span>';
        echo '<hr class="'.n360_content_h($classes).'" aria-hidden="true">';
        echo '</div>';
        return;
    }

    echo '<hr class="'.n360_content_h($classes).'" aria-hidden="true">';
}