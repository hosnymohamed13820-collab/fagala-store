<?php
/**
 * مكوّنات الواجهة الموحّدة.
 * كل النصوص والـattributes بتتشفّر هنا. الاستثناءات المقصودة بس هي
 * $html و$control و$actionsHtml لأن المستدعي بيبعت فيهم HTML جاهز.
 */
function ui_e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ui_attrs(array $attrs): string {
    $out = '';
    foreach ($attrs as $name => $value) {
        if (!preg_match('/^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/', (string) $name) || $value === false || $value === null) continue;
        $out .= ' ' . ui_e($name) . ($value === true ? '' : '="' . ui_e($value) . '"');
    }
    return $out;
}
function ui_icon(string $name, string $extra = ''): string {
    static $icons = null;
    if ($icons === null) {
        $css = @file_get_contents(dirname(__DIR__) . '/assets/tabler-subset.css') ?: '';
        preg_match_all('/\\.ti-([a-z0-9-]+)/', $css, $matches);
        $icons = array_fill_keys($matches[1] ?? [], true);
    }
    $name = strtolower(trim($name));
    if (!isset($icons[$name])) trigger_error('الأيقونة ti-' . $name . ' مش موجودة في tabler-subset.css', E_USER_WARNING);
    $extra = preg_replace('/[^a-zA-Z0-9_-]+/', ' ', trim($extra));
    return '<i class="ti ti-' . ui_e($name) . ($extra !== '' ? ' ' . ui_e($extra) : '') . '"></i>';
}
function ui_btn(string $label, array $o = []): string {
    $variant = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($o['variant'] ?? 'primary')));
    $classes = trim('btn btn-' . $variant . ' ' . (string) ($o['class'] ?? ''));
    $attrs = (array) ($o['attrs'] ?? []);
    if (!empty($o['id'])) $attrs['id'] = $o['id'];
    $body = (!empty($o['icon']) ? ui_icon((string) $o['icon']) . ' ' : '') . ui_e($label);
    if (array_key_exists('href', $o)) { $attrs['href'] = $o['href']; return '<a class="' . ui_e($classes) . '"' . ui_attrs($attrs) . '>' . $body . '</a>'; }
    $attrs['type'] = $o['type'] ?? 'button';
    return '<button class="' . ui_e($classes) . '"' . ui_attrs($attrs) . '>' . $body . '</button>';
}
function ui_field(string $label, string $control, array $o = []): string {
    $for = !empty($o['for']) ? ' for="' . ui_e($o['for']) . '"' : '';
    $error = isset($o['error']) && $o['error'] !== '' ? '<div class="ffield-err">' . ui_e($o['error']) . '</div>' : '';
    return '<div class="' . ui_e(trim('ffield ' . (string) ($o['class'] ?? ''))) . '"' . ui_attrs((array) ($o['attrs'] ?? [])) . '><label' . $for . '>' . ui_e($label) . '</label>' . $control . $error . '</div>';
}
function ui_input(string $name, array $o = []): string {
    $attrs = (array) ($o['attrs'] ?? []); $attrs['name'] = $name; $attrs['type'] = $o['type'] ?? 'text';
    if (isset($o['id'])) $attrs['id'] = $o['id']; if (array_key_exists('value', $o)) $attrs['value'] = $o['value']; if (isset($o['placeholder'])) $attrs['placeholder'] = $o['placeholder'];
    $classes = trim('finput ' . (!empty($o['sm']) ? 'finput-sm ' : '') . (!empty($o['num']) ? 'num ' : '') . (!empty($o['invalid']) ? 'is-invalid ' : '') . (string) ($o['class'] ?? ''));
    return '<input class="' . ui_e($classes) . '"' . ui_attrs($attrs) . '>';
}
function ui_select(string $name, array $options, mixed $selected = null, array $o = []): string {
    $attrs = (array) ($o['attrs'] ?? []); $attrs['name'] = $name; if (isset($o['id'])) $attrs['id'] = $o['id'];
    $html = '<select class="' . ui_e(trim('fselect ' . (!empty($o['invalid']) ? 'is-invalid ' : '') . (string) ($o['class'] ?? ''))) . '"' . ui_attrs($attrs) . '>';
    foreach ($options as $value => $label) $html .= '<option value="' . ui_e($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') . '>' . ui_e($label) . '</option>';
    return $html . '</select>';
}
function ui_alert(string $kind, string $html): string {
    $map = ['info' => 'alert-circle', 'warn' => 'alert-triangle', 'neg' => 'alert-circle', 'pos' => 'circle-check']; $kind = isset($map[$kind]) ? $kind : 'info';
    return '<div class="falert falert-' . $kind . '">' . ui_icon($map[$kind]) . '<div>' . $html . '</div></div>';
}
function ui_page_head(string $title, string $sub = '', string $actionsHtml = ''): string {
    return '<div class="fpage-head"><div><h1 class="fpage-title">' . ui_e($title) . '</h1>' . ($sub !== '' ? '<p class="fpage-sub">' . ui_e($sub) . '</p>' : '') . '</div>' . ($actionsHtml !== '' ? '<div class="fpage-actions">' . $actionsHtml . '</div>' : '') . '</div>';
}
function ui_card_open(string $title = '', string $actionsHtml = ''): string {
    $head = ($title !== '' || $actionsHtml !== '') ? '<div class="fcard-head">' . ($title !== '' ? '<h2 class="fcard-title">' . ui_e($title) . '</h2>' : '') . ($actionsHtml !== '' ? '<div class="fcard-actions">' . $actionsHtml . '</div>' : '') . '</div>' : '';
    return '<section class="fcard">' . $head;
}
function ui_card_close(): string { return '</section>'; }
