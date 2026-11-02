<?php
/** هوية الموقع من إعداد النشر أو مساره الموثوق، قبل الجلسة وأي حراسة. */
if (is_file(dirname(__DIR__) . '/config.php')) {
    require_once dirname(__DIR__) . '/config.php';
}

function site_id(): string
{
    $id = defined('SITE_ID') ? SITE_ID : (basename(dirname(__DIR__)) === 'ashraqa' ? 'ashraqa' : 'fagala');
    if (!in_array($id, ['fagala', 'ashraqa'], true)) {
        throw new RuntimeException('هوية الموقع مش صحيحة');
    }
    return $id;
}

function site_profile(): array
{
    static $profile;
    if ($profile !== null) return $profile;
    $ashraqa = site_id() === 'ashraqa';
    $branding = $ashraqa ? 'assets/branding/ashraqa/' : '';
    $manifest = $ashraqa ? json_decode(file_get_contents(dirname(__DIR__) . '/' . $branding . 'manifest.json'), true, 512, JSON_THROW_ON_ERROR) : [];
    return $profile = [
        'id' => site_id(),
        'display_name' => $ashraqa ? 'إشراقة' : 'الفجالة ستور',
        'store_name_default' => $ashraqa ? 'إشراقة' : 'مكتبات الفجالة ستور',
        'short_name' => $ashraqa ? 'إشراقة' : 'الفجالة',
        'public_base_url' => $ashraqa ? 'https://hosnimanagement.com/ashraqa' : 'https://hosnimanagement.com',
        'session_name' => $ashraqa ? 'ASHSESS' : 'FGSESS',
        'app' => site_id(),
        'theme_css' => $ashraqa ? 'assets/ashraqa-theme.css?v=1' : null,
        'theme_colors' => $ashraqa ? ['light' => $manifest['theme_color'], 'dark' => $manifest['background_color']] : null,
        'manifest' => $ashraqa ? $branding . 'manifest.json?v=4' : 'manifest.json?v=4',
        'icons' => array_combine(
            ['apple-touch-icon', 'favicon-32', 'favicon', 'icon-192', 'icon-512', 'icon-maskable', 'badge-96'],
            array_map(static fn($name) => $branding . $name . '?v=4', [
                'apple-touch-icon.png', 'favicon-32.png', 'favicon.ico', 'icon-192.png',
                'icon-512.png', 'icon-maskable.png', 'badge-96.png',
            ])
        ),
        'true_integration' => !$ashraqa,
        // استقبال Redux على الفجالة بيخدم فرع إشراقة برضه، زي السلوك الحالي.
        'redux_sync' => true,
        'enabled_modules' => $ashraqa ? [
            'dashboard', 'cashier', 'review', 'treasury', 'custody', 'hr', 'attendance',
            'tasks', 'tasks_issue', 'suppliers', 'stocktake', 'reports', 'definitions',
            'settings', 'errors', 'sms_inbox', 'view_all', 'ashraqa_purchases',
        ] : null,
        // القفل الأساسي بالـallowlist؛ القايمة دي للتوثيق بس.
        'disabled_modules' => $ashraqa ? ['price_inquiry', 'remote_scan', 'machines', 'branch_recon', 'invoices', 'receiving', 'branch_invoices', 'labels', 'shortage', 'reorder', 'analysis', 'item_sales', 'ops', 'calls', 'stock_ledger', 'purchase_docs', 'item_master', 'supplier_card', 'sale_prices', 'customers', 'pos', 'transfers'] : [],
        'disabled_parts' => $ashraqa ? [
            'settings:agents', 'settings:integration', 'settings:invoice_guard',
            'settings:guard_invoices', 'settings:guard_list', 'settings:daily_summary_save',
            'dashboard:agents', 'definitions:true_suppliers', 'definitions:stores',
            'suppliers:summary', 'suppliers:statement', 'suppliers:all',
            'reports:dist_invoices', 'reports:noprice', 'reports:stock', 'reports:receiving',
            'reports:daily', 'reports:supplier_purchases', 'reports:purchase_by_item',
            'reports:stock_recon', 'reports:stock_movement', 'reports:item_movement',
            'reports:customers', 'reports:transfers', 'reports:transfer_shorts', 'reports:purchase_docs',
        ] : [],
        'shift_expenses_mode' => $ashraqa ? 'ashraqa' : 'fagala',
        'allow_cash_disbursement' => $ashraqa,
        'supplier_create' => false,
    ];
}

/** القفل ده قبل صلاحيات الأونر والمشاهدة، وأي موديول جديد يفضل مقفول في إشراقة. */
function site_permission_enabled(string $key): bool
{
    $profile = site_profile();
    return ($profile['enabled_modules'] === null
            || in_array(explode(':', $key, 2)[0], $profile['enabled_modules'], true))
        && !in_array($key, $profile['disabled_parts'], true);
}

function site_require_enabled(string $key): void
{
    if (!site_permission_enabled($key)) {
        http_response_code(403);
        exit('غير مصرح');
    }
}

/** نفس سجل الموديولات للقائمة وشاشة الصلاحيات وحفظها. */
function site_enabled_modules(): array
{
    return array_filter(all_modules(), static fn($key) => site_permission_enabled($key), ARRAY_FILTER_USE_KEY);
}

function site_enabled_parts(): array
{
    $parts = module_parts();
    foreach ($parts as $module => &$items) {
        $items = array_filter($items, static fn($part) => site_permission_enabled($module . ':' . $part), ARRAY_FILTER_USE_KEY);
    }
    unset($items);
    return $parts;
}
