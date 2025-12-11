<?php
// excel_db.php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

require __DIR__ . '/vendor/autoload.php';

const EXCEL_FILE    = __DIR__ . '/data/vocab.xlsx';
const SETTINGS_FILE = __DIR__ . '/data/settings.json';

function excel_init(): void
{
    if (!is_dir(__DIR__ . '/data')) {
        mkdir(__DIR__ . '/data', 0777, true);
    }

    if (!file_exists(EXCEL_FILE)) {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        $headers = [
            'id',           // A
            'word',         // B
            'meaning',      // C
            'example',      // D
            'box',          // E  (0..7, 8 = long-term)
            'status',       // F  (active, mastered, archived)
            'created_at',   // G  (Y-m-d)
            'last_review',  // H
            'next_review',  // I
            'lt_successes', // J  (0..4 برای حافظه بلندمدت)
        ];

        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col . '1', $h);
            $col++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save(EXCEL_FILE);
    }
}

/**
 * تنظیمات برنامه (تعداد لغات جدید و تعداد کارت جلسه)
 * ذخیره در data/settings.json
 */
function settings_get(): array
{
    if (!file_exists(SETTINGS_FILE)) {
        return [
            'new_limit'     => 50, // تعداد لغات جدید از box0
            'session_limit' => 10, // تعداد کارت جلسه از آن 50 تا
        ];
    }

    $json = file_get_contents(SETTINGS_FILE);
    $data = json_decode($json, true) ?: [];

    return [
        'new_limit'     => isset($data['new_limit']) ? (int) $data['new_limit'] : 50,
        'session_limit' => isset($data['session_limit']) ? (int) $data['session_limit'] : 10,
    ];
}

function settings_save(array $settings): void
{
    if (!is_dir(__DIR__ . '/data')) {
        mkdir(__DIR__ . '/data', 0777, true);
    }

    $current = settings_get();

    $newLimit     = max(1, (int) ($settings['new_limit'] ?? $current['new_limit']));
    $sessionLimit = max(1, (int) ($settings['session_limit'] ?? $current['session_limit']));

    $data = [
        'new_limit'     => $newLimit,
        'session_limit' => $sessionLimit,
    ];

    file_put_contents(
        SETTINGS_FILE,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

function excel_load_all(): array
{
    excel_init();

    $spreadsheet = IOFactory::load(EXCEL_FILE);
    $sheet       = $spreadsheet->getActiveSheet();

    $rows        = [];
    $highestRow  = $sheet->getHighestDataRow();

    for ($row = 2; $row <= $highestRow; $row++) {
        $id = (int) $sheet->getCell("A{$row}")->getValue();
        if (!$id) {
            continue;
        }

        $rows[] = [
            'row'          => $row,
            'id'           => $id,
            'word'         => (string) $sheet->getCell("B{$row}")->getValue(),
            'meaning'      => (string) $sheet->getCell("C{$row}")->getValue(),
            'example'      => (string) $sheet->getCell("D{$row}")->getValue(),
            'box'          => (int) $sheet->getCell("E{$row}")->getValue(),
            'status'       => (string) ($sheet->getCell("F{$row}")->getValue() ?: 'active'),
            'created_at'   => (string) $sheet->getCell("G{$row}")->getValue(),
            'last_review'  => (string) $sheet->getCell("H{$row}")->getValue(),
            'next_review'  => (string) $sheet->getCell("I{$row}")->getValue(),
            'lt_successes' => (int) $sheet->getCell("J{$row}")->getValue(),
        ];
    }

    return [$spreadsheet, $sheet, $rows];
}

function excel_save($spreadsheet): void
{
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save(EXCEL_FILE);
}

/** جستجوی لغت تکراری (case-insensitive) */
function excel_find_by_word(string $word): ?array
{
    [, , $rows] = excel_load_all();
    $needle     = mb_strtolower(trim($word), 'UTF-8');

    foreach ($rows as $r) {
        if (mb_strtolower($r['word'], 'UTF-8') === $needle) {
            return $r;
        }
    }
    return null;
}

function excel_add_word(string $word, string $meaning, string $example = ''): void
{
    [$spreadsheet, $sheet, $rows] = excel_load_all();

    $highestRow = $sheet->getHighestDataRow();
    $nextRow    = $highestRow + 1;

    $maxId = 0;
    foreach ($rows as $r) {
        if ($r['id'] > $maxId) {
            $maxId = $r['id'];
        }
    }
    $nextId = $maxId + 1;
    $today  = (new DateTime('today'))->format('Y-m-d');

    $sheet->setCellValue("A{$nextRow}", $nextId);
    $sheet->setCellValue("B{$nextRow}", $word);
    $sheet->setCellValue("C{$nextRow}", $meaning);
    $sheet->setCellValue("D{$nextRow}", $example);
    $sheet->setCellValue("E{$nextRow}", 0);          // box 0 (جدید)
    $sheet->setCellValue("F{$nextRow}", 'active');   // فعال
    $sheet->setCellValue("G{$nextRow}", $today);
    $sheet->setCellValue("H{$nextRow}", '');
    $sheet->setCellValue("I{$nextRow}", '');
    $sheet->setCellValue("J{$nextRow}", 0);          // lt_successes

    excel_save($spreadsheet);
}

/** ویرایش لغت موجود */
function excel_update_word(int $id, string $word, string $meaning, string $example = ''): ?array
{
    [$spreadsheet, $sheet, $rows] = excel_load_all();

    foreach ($rows as $r) {
        if ($r['id'] !== $id) {
            continue;
        }

        $row = $r['row'];

        $sheet->setCellValue("B{$row}", $word);
        $sheet->setCellValue("C{$row}", $meaning);
        $sheet->setCellValue("D{$row}", $example);

        excel_save($spreadsheet);

        $r['word']    = $word;
        $r['meaning'] = $meaning;
        $r['example'] = $example;

        return $r;
    }

    return null;
}

/**
 * ورود گروهی از فایل Excel آپلودی:
 * انتظار دارد ستون‌ها: word, meaning, example (یا A,B,C)
 */
function excel_import_from_file(string $tmpFilePath): int
{
    [$mainSpreadsheet, $mainSheet, $rows] = excel_load_all();

    $importSpreadsheet = IOFactory::load($tmpFilePath);
    $importSheet       = $importSpreadsheet->getActiveSheet();
    $highestRow        = $importSheet->getHighestDataRow();

    // پیدا کردن بیشترین id موجود
    $maxId = 0;
    foreach ($rows as $r) {
        if ($r['id'] > $maxId) {
            $maxId = $r['id'];
        }
    }

    $inserted   = 0;
    $targetRow  = $mainSheet->getHighestDataRow() + 1;
    $today      = (new DateTime('today'))->format('Y-m-d');

    for ($row = 2; $row <= $highestRow; $row++) {
        $word    = trim((string) $importSheet->getCell("A{$row}")->getValue());
        $meaning = trim((string) $importSheet->getCell("B{$row}")->getValue());
        $example = trim((string) $importSheet->getCell("C{$row}")->getValue());

        if ($word === '' || $meaning === '') {
            continue;
        }

        // جلوگیری از ایمپورت لغت تکراری
        if (excel_find_by_word($word) !== null) {
            continue;
        }

        $maxId++;
        $mainSheet->setCellValue("A{$targetRow}", $maxId);
        $mainSheet->setCellValue("B{$targetRow}", $word);
        $mainSheet->setCellValue("C{$targetRow}", $meaning);
        $mainSheet->setCellValue("D{$targetRow}", $example);
        $mainSheet->setCellValue("E{$targetRow}", 0);          // box 0
        $mainSheet->setCellValue("F{$targetRow}", 'active');
        $mainSheet->setCellValue("G{$targetRow}", $today);
        $mainSheet->setCellValue("H{$targetRow}", '');
        $mainSheet->setCellValue("I{$targetRow}", '');
        $mainSheet->setCellValue("J{$targetRow}", 0);

        $targetRow++;
        $inserted++;
    }

    excel_save($mainSpreadsheet);
    return $inserted;
}

/**
 * بر اساس توضیحات شما:
 * - box 0: لغت جدید؛
 * - box 1..7: لایتنر عادی
 * - box 8: حافظه بلندمدت
 */
function leitner_update_result(int $id, string $result): ?array
{
    [$spreadsheet, $sheet, $rows] = excel_load_all();
    $today = (new DateTime('today'))->format('Y-m-d');

    foreach ($rows as $r) {
        if ($r['id'] !== $id) {
            continue;
        }

        $row          = $r['row'];
        $box          = (int) $r['box'];
        $status       = $r['status'] ?: 'active';
        $lt_successes = (int) $r['lt_successes'];

        if ($status !== 'active' && $box !== 8) {
            return null;
        }

        if ($box === 0) {
            if ($result === 'right') {
                $box         = 1;
                $next_review = (new DateTime($today))->modify('+1 day')->format('Y-m-d');
            } else {
                $box         = 0;
                $next_review = (new DateTime($today))->modify('+1 day')->format('Y-m-d');
            }
        } elseif ($box >= 1 && $box <= 7) {
            if ($result === 'right') {
                $box++;
                if ($box > 7) {
                    $box          = 8;
                    $lt_successes = 0;
                    $next_review  = (new DateTime($today))->modify('+7 days')->format('Y-m-d');
                } else {
                    $interval    = $box;
                    $next_review = (new DateTime($today))->modify("+{$interval} days")->format('Y-m-d');
                }
            } else {
                $box         = max(0, $box - 1);
                $next_review = (new DateTime($today))->modify('+1 day')->format('Y-m-d');
            }
        } elseif ($box === 8) {
            if ($result === 'right') {
                $lt_successes++;
                if ($lt_successes >= 4) {
                    $status      = 'mastered';
                    $next_review = '';
                } else {
                    $next_review = (new DateTime($today))->modify('+7 days')->format('Y-m-d');
                }
            } else {
                $box          = 6;
                $status       = 'active';
                $lt_successes = 0;
                $next_review  = (new DateTime($today))->modify('+1 day')->format('Y-m-d');
            }
        } else {
            $next_review = $r['next_review'] ?: '';
        }

        $sheet->setCellValue("E{$row}", $box);
        $sheet->setCellValue("F{$row}", $status);
        $sheet->setCellValue("H{$row}", $today);
        $sheet->setCellValue("I{$row}", $next_review);
        $sheet->setCellValue("J{$row}", $lt_successes);

        excel_save($spreadsheet);

        return [
            'id'           => $id,
            'box'          => $box,
            'status'       => $status,
            'last_review'  => $today,
            'next_review'  => $next_review,
            'lt_successes' => $lt_successes,
        ];
    }

    return null;
}

/**
 * گرفتن لغت‌های امروز با توجه به تنظیمات کاربر
 */

function leitner_get_today_session(): array
{
    [$spreadsheet, $sheet, $rows] = excel_load_all();
    $today    = (new DateTime('today'))->format('Y-m-d');
    $settings = settings_get();

    $newLimit     = (int) $settings['new_limit'];     // مثلا 10
    $sessionLimit = (int) $settings['session_limit']; // مثلا 2

    $reviewCards = [];
    $newPool     = [];

    foreach ($rows as $r) {
        if ($r['status'] !== 'active' && (int) $r['box'] !== 8) {
            continue;
        }

        $box         = (int) $r['box'];
        $next_review = $r['next_review'];

        if ($box >= 1 && $box <= 7) {
            if ($next_review !== '' && $next_review <= $today) {
                $reviewCards[] = $r;
            }
        } elseif ($box === 8) {
            if ($next_review !== '' && $next_review <= $today) {
                $reviewCards[] = $r;
            }
        } elseif ($box === 0) {
            $newPool[] = $r;
        }
    }

    usort($reviewCards, function ($a, $b) {
        $cmp = $a['box'] <=> $b['box'];
        if ($cmp !== 0) {
            return $cmp;
        }
        return $a['id'] <=> $b['id'];
    });

    usort($newPool, fn($a, $b) => $a['id'] <=> $b['id']);

    // بسته امروز از خانه صفر (مثلا 10 لغت)
    $newPack = array_slice($newPool, 0, $newLimit);

    // برای سازگاری با قبل، هنوز new_cards را هم برمی‌گردانیم (فقط دسته اول)
    $firstChunk = array_slice($newPack, 0, $sessionLimit);

    return [
        'today'          => $today,
        'review_cards'   => $reviewCards,
        'new_pack'       => $newPack,         // تمام بسته امروز
        'new_cards'      => $firstChunk,      // فقط دسته اول (دیگر در JS به آن وابسته نمی‌شویم)
        'total_new_box0' => count($newPool),
        'total_new_N'    => count($newPack),  // اندازه واقعی بسته امروز
        'session_limit'  => $sessionLimit,    // سایز هر دسته
    ];
}



/**
 * جستجو
 */
function excel_search(string $query, ?string $status = null): array
{
    [, , $rows] = excel_load_all();
    $query      = mb_strtolower(trim($query), 'UTF-8');
    $result     = [];

    foreach ($rows as $r) {
        if ($status !== null && $r['status'] !== $status) {
            continue;
        }

        if ($query === '') {
            $result[] = $r;
            continue;
        }

        $hay = mb_strtolower(
            $r['word'] . ' ' . $r['meaning'] . ' ' . $r['example'],
            'UTF-8'
        );
        if (mb_strpos($hay, $query) !== false) {
            $result[] = $r;
        }
    }

    return array_slice($result, 0, 200);
}

/**
 * صفحه‌بندی همه لغات
 */
function excel_list_paginated(int $page, int $perPage): array
{
    [, , $rows] = excel_load_all();

    usort($rows, fn($a, $b) => $a['id'] <=> $b['id']);

    $total    = count($rows);
    $perPage  = max(1, $perPage);
    $pages    = (int) ceil($total / $perPage);

    if ($pages === 0) {
        return [
            'items'       => [],
            'total'       => 0,
            'page'        => 1,
            'per_page'    => $perPage,
            'total_pages' => 0,
        ];
    }

    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $items  = array_slice($rows, $offset, $perPage);

    return [
        'items'       => $items,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $pages,
    ];
}

/**
 * برگرداندن لغت به چرخه یادگیری (خانه 1)
 */
function excel_restore_word(int $id): ?array
{
    [$spreadsheet, $sheet, $rows] = excel_load_all();
    $today = (new DateTime('today'))->format('Y-m-d');

    foreach ($rows as $r) {
        if ($r['id'] !== $id) {
            continue;
        }
        $row = $r['row'];

        $sheet->setCellValue("E{$row}", 1);
        $sheet->setCellValue("F{$row}", 'active');
        $sheet->setCellValue("H{$row}", $today);
        $sheet->setCellValue("I{$row}", $today);
        $sheet->setCellValue("J{$row}", 0);

        excel_save($spreadsheet);

        return [
            'id'     => $id,
            'box'    => 1,
            'status' => 'active',
        ];
    }

    return null;
}


/** حذف کامل یک لغت از Excel (با خالی کردن سطر) */
function excel_delete_word(int $id): bool
{
    [$spreadsheet, $sheet, $rows] = excel_load_all();

    foreach ($rows as $r) {
        if ($r['id'] !== $id) {
            continue;
        }

        $row = $r['row'];

        // ستون‌های A تا J را خالی می‌کنیم
        foreach (range('A', 'J') as $col) {
            $sheet->setCellValue($col . $row, '');
        }

        excel_save($spreadsheet);
        return true;
    }

    return false;
}
