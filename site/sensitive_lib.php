<?php
// قفل الأرقام الحساسة (بريف ٧٠). الحالة في الـsession: $_SESSION['sens_until'] = unix time.
const SENS_IDLE_SECONDS = 600;   // ١٠ دقايق
const SENS_MAX_FAILS    = 5;
const SENS_BLOCK_SECONDS = 300;

function sens_unlocked(): bool {
    // بريف ١٣٠: الصفحة مفتوحة بالاستعارة (view_all) ← الباسورد مابيفتحش الأرقام
    if (!empty($GLOBALS['VIEW_ALL_PAGE'])) {
        return false;
    }
    return !empty($_SESSION['sens_until']) && (int) $_SESSION['sens_until'] > time();
}

function sens_touch(): void {
    if (sens_unlocked()) {
        $_SESSION['sens_until'] = time() + SENS_IDLE_SECONDS;
    }
}

function sens_page(): void {
    $GLOBALS['SENS_PAGE'] = true;
    sens_touch();
}

function sens_left(): int {
    return max(0, (int) ($_SESSION['sens_until'] ?? 0) - time());
}

function sens_mask(string $html): string {
    if (sens_unlocked()) {
        return $html;
    }
    return '<span class="sens-blur" onclick="event.preventDefault();event.stopPropagation();sensOpen()" title="مخفي — Ctrl+L">00,000.00</span>';
}

function sens_eye(): string {
    // بريف ١٣٠: في الصفحة المستعارة مفيش زرار «اظهر الأرقام» — الباسورد مش هيفتحها
    if (!empty($GLOBALS['VIEW_ALL_PAGE'])) {
        return '<span class="sens-eye-main" style="color:inherit;opacity:.85"><i class="ti ti-lock"></i> الأرقام مقفولة — حسابك مشاهدة بس</span>';
    }
    $unlocked = sens_unlocked();
    $icon = $unlocked ? 'ti-lock' : 'ti-eye';
    $text = $unlocked ? 'اقفل' : 'اظهر الأرقام';
    $title = $unlocked ? 'اقفل الأرقام الحساسة (Ctrl+L)' : 'اظهر الأرقام الحساسة (Ctrl+L)';

    return '<button type="button" class="sens-eye" onclick="sensToggle()" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
        . '<span class="sens-eye-main"><i class="ti ' . $icon . '"></i> ' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span class="sens-eye-sub">Ctrl+L</span>'
        . '</button>';
}

function sens_lock_screen(string $what): void {
    $safeWhat = htmlspecialchars($what, ENT_QUOTES, 'UTF-8');
    // بريف ١٣٠: صفحة مستعارة ← مفيش زرار فتح، وباسورد المستخدم مش هيفيد
    if (!empty($GLOBALS['VIEW_ALL_PAGE'])) {
        echo '<div class="sens-lock-card">'
            . '<div class="sens-lock-icon"><i class="ti ti-lock"></i></div>'
            . '<h2 class="sens-lock-title">' . $safeWhat . ' مقفولة</h2>'
            . '<p class="sens-lock-msg">الأرقام مقفولة — حسابك مشاهدة بس</p>'
            . '</div>';
        return;
    }
    echo '<div class="sens-lock-card">'
        . '<div class="sens-lock-icon"><i class="ti ti-lock"></i></div>'
        . '<h2 class="sens-lock-title">' . $safeWhat . ' مقفولة</h2>'
        . '<p class="sens-lock-msg">دخّل الباسورد بتاعك عشان تشوفها</p>'
        . '<div class="sens-lock-act">' . sens_eye() . '</div>'
        . '</div>';
}
