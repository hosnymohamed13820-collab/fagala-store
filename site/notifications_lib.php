<?php
/**
 * notifications_lib.php — خريطة وتجميع إشعارات المستخدم.
 *
 * النوع بيتخزن زي ما هو، والمجموعة بتتحسب وقت العرض عشان notify() تفضل
 * نقطة الإدخال الوحيدة ومفيش إشعار قديم يختفي.
 */
declare(strict_types=1);

/** @return array<string,array{name:string,icon:string,types:array<int,string>,prefixes?:array<int,string>}> */
function notif_groups(): array
{
    return [
        'sales' => [
            'name' => 'المبيعات والورديات',
            'icon' => 'cash',
            'types' => ['shift_seq', 'shift_return', 'shift_return_expired', 'daily_summary', 'drawer_open'],
            'prefixes' => ['pos_'],
        ],
        'purchases' => [
            'name' => 'المشتريات والاستلام',
            'icon' => 'shopping-cart',
            'types' => ['receiving', 'shortage', 'transfer', 'transfer_short'],
        ],
        'rabt' => [
            'name' => 'حارس الربط',
            'icon' => 'link',
            'types' => ['rabt_guard', 'agent_repl'],
        ],
        'employees' => [
            'name' => 'الموظفين',
            'icon' => 'users',
            'types' => ['salary', 'penalty', 'advance', 'custody_return'],
        ],
        'tasks' => [
            'name' => 'المهام',
            'icon' => 'checklist',
            'types' => ['task'],
        ],
        'other' => [
            'name' => 'أخرى',
            'icon' => 'inbox',
            'types' => [],
        ],
    ];
}

function notifgrp_group_for_type(string $type): string
{
    foreach (notif_groups() as $key => $group) {
        if ($key === 'other') {
            continue;
        }
        if (in_array($type, $group['types'], true)) {
            return $key;
        }
        foreach (($group['prefixes'] ?? []) as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return $key;
            }
        }
    }
    return 'other';
}

/** CASE SQL مبني من الخريطة الثابتة بس؛ مفيهوش أي إدخال من المستخدم. */
function notifgrp_sql_case(string $column = 'type'): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
        throw new InvalidArgumentException('اسم عمود الإشعارات غير صالح.');
    }

    $parts = ['CASE'];
    foreach (notif_groups() as $key => $group) {
        if ($key === 'other') {
            continue;
        }
        $checks = [];
        if (!empty($group['types'])) {
            $types = array_map(
                static fn(string $type): string => "'" . str_replace("'", "''", $type) . "'",
                $group['types']
            );
            $checks[] = $column . ' IN (' . implode(', ', $types) . ')';
        }
        foreach (($group['prefixes'] ?? []) as $prefix) {
            $safePrefix = str_replace(['\\', '%', '_', "'"], ['\\\\', '\\%', '\\_', "''"], $prefix);
            $checks[] = $column . " LIKE '" . $safePrefix . "%' ESCAPE '\\\\'";
        }
        if ($checks) {
            $parts[] = 'WHEN (' . implode(' OR ', $checks) . ") THEN '" . $key . "'";
        }
    }
    $parts[] = "ELSE 'other' END";
    return implode(' ', $parts);
}

function notifgrp_unread_count(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    return (int) $st->fetchColumn();
}

/** @return array{total_unread:int,groups:array<int,array<string,mixed>>} */
function notifgrp_summary(int $userId): array
{
    $groups = notif_groups();
    $unread = array_fill_keys(array_keys($groups), 0);

    // الاستعلام الأول: العدّات لكل نوع، والتجميع بيتحسب من المصدر الواحد فوق.
    $countSt = db()->prepare(
        'SELECT type, COUNT(*) AS unread_count
           FROM notifications
          WHERE user_id = ? AND is_read = 0
          GROUP BY type'
    );
    $countSt->execute([$userId]);
    foreach ($countSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = notifgrp_group_for_type((string) $row['type']);
        $unread[$key] += (int) $row['unread_count'];
    }

    // الاستعلام الثاني: أحدث ٥ من كل مجموعة في ضربة واحدة (MariaDB 11).
    $case = notifgrp_sql_case('type');
    $order = implode(', ', array_map(
        static fn(string $key): string => "'" . $key . "'",
        array_keys($groups)
    ));
    $latestSt = db()->prepare(
        "SELECT id, title, body, link, is_read, created_at, notif_group
           FROM (
                SELECT grouped.*,
                       ROW_NUMBER() OVER (PARTITION BY notif_group ORDER BY id DESC) AS row_num
                  FROM (
                       SELECT id, title, body, link, is_read, created_at,
                              $case AS notif_group
                         FROM notifications
                        WHERE user_id = ?
                  ) AS grouped
           ) AS ranked
          WHERE row_num <= 5
          ORDER BY FIELD(notif_group, $order), row_num"
    );
    $latestSt->execute([$userId]);

    $items = array_fill_keys(array_keys($groups), []);
    foreach ($latestSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = isset($groups[$row['notif_group']]) ? (string) $row['notif_group'] : 'other';
        $items[$key][] = notifgrp_present_row($row);
    }

    $out = [];
    foreach ($groups as $key => $group) {
        $out[] = [
            'key' => $key,
            'name' => $group['name'],
            'icon' => $group['icon'],
            'unread' => $unread[$key],
            'items' => $items[$key],
        ];
    }

    return ['total_unread' => array_sum($unread), 'groups' => $out];
}

/** @return array{page:int,has_more:bool,items:array<int,array<string,mixed>>} */
function notifgrp_list(int $userId, string $group, int $page): array
{
    if (!isset(notif_groups()[$group])) {
        throw new InvalidArgumentException('مجموعة الإشعارات مش موجودة.');
    }
    $page = max(1, $page);
    $limit = 30;
    $offset = ($page - 1) * $limit;
    $case = notifgrp_sql_case('type');

    // صف زيادة يحدد وجود صفحة تالية من غير استعلام COUNT إضافي.
    $st = db()->prepare(
        "SELECT id, title, body, link, is_read, created_at
           FROM notifications
          WHERE user_id = ? AND ($case) = ?
          ORDER BY id DESC
          LIMIT 31 OFFSET " . (int) $offset
    );
    $st->execute([$userId, $group]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    return [
        'page' => $page,
        'has_more' => $hasMore,
        'items' => array_map('notifgrp_present_row', $rows),
    ];
}

function notifgrp_mark_read(int $userId, ?int $id, ?string $group, bool $all): int
{
    if ($id !== null && $id > 0) {
        $st = db()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0'
        );
        $st->execute([$id, $userId]);
        return $st->rowCount();
    }

    if ($group !== null && $group !== '') {
        if (!isset(notif_groups()[$group])) {
            throw new InvalidArgumentException('مجموعة الإشعارات مش موجودة.');
        }
        $case = notifgrp_sql_case('type');
        $st = db()->prepare(
            "UPDATE notifications SET is_read = 1
              WHERE user_id = ? AND is_read = 0 AND ($case) = ?"
        );
        $st->execute([$userId, $group]);
        return $st->rowCount();
    }

    if ($all) {
        $st = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return $st->rowCount();
    }

    throw new InvalidArgumentException('اختار إشعار أو مجموعة.');
}

/** @return array{id:int,title:string,body:string,link:string,time:string,is_read:bool,href:string} */
function notifgrp_present_row(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $isRead = !empty($row['is_read']);
    $link = (string) ($row['link'] ?? '');
    $safeLink = notifgrp_safe_link($link);
    return [
        'id' => $id,
        'title' => (string) ($row['title'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'link' => $link,
        'time' => date('Y-m-d H:i', strtotime((string) ($row['created_at'] ?? 'now')) ?: time()),
        'is_read' => $isRead,
        'href' => $isRead ? ($safeLink !== '' ? $safeLink : '#') : 'notifications_read.php?id=' . $id,
    ];
}

function notifgrp_safe_link(string $link): string
{
    if ($link === ''
        || !preg_match('~^[a-zA-Z0-9_./?=&%#-]+$~', $link)
        || str_contains($link, '://')
        || str_starts_with($link, '//')) {
        return '';
    }
    return $link;
}
