<?php
/**
 * includes/nav_icons.php — أيقونات السايدبار التفاعلية (SVG مقسّمة أجزاء).
 *
 * كل أيقونة متقسّمة داخلياً بعناصر <g> و<path> و<circle> و<line> تحمل كلاسات
 * دلالية (nicon-part-*) لتتحرك بحكايتها الخاصة في fagala.css.
 * لو طُلبت أيقونة مش مسجّلة، بترجع تلقائياً وسم الخط <i> بالـti الاصلي (fallback).
 */

function nav_icon(string $ti): string
{
    static $icons = null;
    if ($icons === null) {
        $icons = [
            'settings' => '<path class="nicon-part-gear" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37c1 .608 2.296.07 2.572-1.065" /><circle class="nicon-part-center" cx="12" cy="12" r="3" />',

            'scan' => '<path class="nicon-part-frame" d="M3 7v-2a2 2 0 0 1 2-2h2M3 17v2a2 2 0 0 0 2 2h2M17 3h2a2 2 0 0 1 2 2v2M17 21h2a2 2 0 0 0 2-2v-2" /><line class="nicon-part-laser" x1="5" y1="12" x2="19" y2="12" />',

            'shopping-cart' => '<g class="nicon-part-cart"><path d="M17 17h-11v-14h-2" /><path d="M6 5l14 1l-1 7h-13" /><circle cx="6" cy="19" r="2" /><circle cx="17" cy="19" r="2" /></g><rect class="nicon-part-box1" x="8" y="7" width="3.5" height="3.5" rx=".5" /><rect class="nicon-part-box2" x="13" y="7" width="3.5" height="3.5" rx=".5" />',

            'layout-dashboard' => '<path class="nicon-part-tile1" d="M5 4h4a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1" /><path class="nicon-part-tile2" d="M15 4h4a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1v-2a1 1 0 0 1 1-1" /><path class="nicon-part-tile3" d="M15 12h4a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1" /><path class="nicon-part-tile4" d="M5 16h4a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1v-2a1 1 0 0 1 1-1" />',

            'device-mobile' => '<path class="nicon-part-phone" d="M6 5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-8a2 2 0 0 1-2-2v-14M11 4h2M12 17v.01" /><path class="nicon-part-wave1" d="M20 9a3 3 0 0 1 0 6" /><path class="nicon-part-wave2" d="M22 7a6 6 0 0 1 0 10" />',

            'lock-dollar' => '<path class="nicon-part-body" d="M13 21h-6a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2h10M11 16a1 1 0 1 0 2 0a1 1 0 0 0-2 0" /><path class="nicon-part-shackle" d="M8 11v-4a4 4 0 1 1 8 0v4" /><g class="nicon-part-dollar"><path d="M21 15h-2.5a1.5 1.5 0 0 0 0 3h1a1.5 1.5 0 0 1 0 3h-2.5M19 21v1m0-8v1" /></g>',

            'cash' => '<path class="nicon-part-bill-back" d="M7 15h-3a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3" /><g class="nicon-part-bill"><path d="M7 10a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1h-12a1 1 0 0 1-1-1l0-8" /><circle class="nicon-part-coin" cx="14" cy="14" r="2" /></g>',

            'user-check' => '<g class="nicon-part-user"><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0-8 0" /><path d="M6 21v-2a4 4 0 0 1 4-4h4" /></g><path class="nicon-part-check" stroke-dasharray="14" stroke-dashoffset="0" d="M15 19l2 2l4-4" />',

            'building-bank' => '<path class="nicon-part-roof" d="M5 6l7-3l7 3" /><path class="nicon-part-base" d="M3 21l18 0M3 10l18 0M4 10l0 11M20 10l0 11" /><line class="nicon-part-col1" x1="8" y1="14" x2="8" y2="17" /><line class="nicon-part-col2" x1="12" y1="14" x2="12" y2="17" /><line class="nicon-part-col3" x1="16" y1="14" x2="16" y2="17" />',

            'users' => '<g class="nicon-part-user-back"><path d="M16 3.13a4 4 0 0 1 0 7.75" /><path d="M21 21v-2a4 4 0 0 0-3-3.85" /></g><g class="nicon-part-user-front"><path d="M5 7a4 4 0 1 0 8 0a4 4 0 0 0-8 0" /><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2" /></g>',

            'clock-hour-4' => '<path class="nicon-part-face" d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0-18 0" /><path class="nicon-part-minute" d="M12 12V7" /><path class="nicon-part-hour" d="M12 12l3 2" />',

            'inbox' => '<path class="nicon-part-box" d="M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-12a2 2 0 0 1-2-2l0-12M4 13h3l3 3h4l3-3h3" /><path class="nicon-part-paper" d="M9 2h6v4h-6z" />',

            'send' => '<g class="nicon-part-plane"><path d="M10 14l11-11" /><path d="M21 3l-6.5 18a.55.55 0 0 1-1 0l-3.5-7l-7-3.5a.55.55 0 0 1 0-1l18-6.5" /></g>',

            'building-store' => '<path class="nicon-part-awning" d="M3 7v1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1h-18l2-4h14l2 4" /><path class="nicon-part-body" d="M3 21l18 0M5 21l0-10.15M19 21l0-10.15M9 21v-4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4" />',

            'package-import' => '<path class="nicon-part-box" d="M12 21l-8-4.5v-9l8-4.5l8 4.5v4.5M12 12l8-4.5M12 12v9M12 12l-8-4.5" /><g class="nicon-part-arrow"><path d="M22 18h-7" /><path d="M18 15l-3 3l3 3" /></g>',

            'truck' => '<g class="nicon-part-lines"><line x1="1" y1="8" x2="3" y2="8" /><line x1="0" y1="12" x2="2" y2="12" /></g><path class="nicon-part-body" d="M5 17h-2v-11a1 1 0 0 1 1-1h9v12m-4 0h6m4 0h2v-6h-8m0-5h5l3 5" /><circle class="nicon-part-wheel1" cx="7" cy="17" r="2" /><circle class="nicon-part-wheel2" cx="17" cy="17" r="2" />',

            'packages' => '<g class="nicon-part-box-bl"><path d="M7 16.5l-5-3l5-3l5 3v5.5l-5 3l0-5.5" /><path d="M2 13.5v5.5l5 3" /><path d="M7 16.545l5-3.03" /></g><g class="nicon-part-box-br"><path d="M17 16.5l-5-3l5-3l5 3v5.5l-5 3l0-5.5" /><path d="M12 19l5 3" /><path d="M17 16.5l5-3" /></g><g class="nicon-part-box-top"><path d="M12 13.5v-5.5l-5-3l5-3l5 3v5.5" /><path d="M7 5.03v5.455" /><path d="M12 8l5-3" /></g>',

            'calculator' => '<path class="nicon-part-frame" d="M4 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-12a2 2 0 0 1-2-2l0-14M8 8a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1h-6a1 1 0 0 1-1-1l0-1" /><line class="nicon-part-btn1" x1="8" y1="14" x2="8.01" y2="14" stroke-width="2.5" stroke-linecap="round" /><line class="nicon-part-btn2" x1="12" y1="14" x2="12.01" y2="14" stroke-width="2.5" stroke-linecap="round" /><line class="nicon-part-btn3" x1="16" y1="14" x2="16.01" y2="14" stroke-width="2.5" stroke-linecap="round" /><line class="nicon-part-btn4" x1="8" y1="17" x2="8.01" y2="17" stroke-width="2.5" stroke-linecap="round" /><line class="nicon-part-btn5" x1="12" y1="17" x2="12.01" y2="17" stroke-width="2.5" stroke-linecap="round" /><line class="nicon-part-btn6" x1="16" y1="17" x2="16.01" y2="17" stroke-width="2.5" stroke-linecap="round" />',

            'chart-bar' => '<path class="nicon-part-axis" d="M4 20h14" /><path class="nicon-part-bar1" d="M3 13a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1l0-6" /><path class="nicon-part-bar2" d="M9 5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1l0-14" /><path class="nicon-part-bar3" d="M15 9a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1l0-10" />',

            'adjustments' => '<path class="nicon-part-tracks" d="M6 4v4M6 12v8M12 4v10M12 18v2M18 4v1M18 9v11" /><circle class="nicon-part-knob1" cx="6" cy="10" r="2" /><circle class="nicon-part-knob2" cx="12" cy="16" r="2" /><circle class="nicon-part-knob3" cx="18" cy="7" r="2" />',

            'alert-triangle' => '<path class="nicon-part-frame" d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636-2.87l-8.106-13.536a1.914 1.914 0 0 0-3.274 0" /><g class="nicon-part-exclamation"><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="16" x2="12.01" y2="16" stroke-width="2.5" stroke-linecap="round" /></g>',
        ];
    }

    $name = preg_replace('/^ti-/', '', trim($ti));

    if (isset($icons[$name])) {
        return '<svg class="nicon nicon-' . $name . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . $icons[$name]
             . '</svg>';
    }

    return '<i class="ti ' . htmlspecialchars($ti, ENT_QUOTES, 'UTF-8') . '"></i>';
}
