<?php
// index.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

// تنظیم کوکی سشن برای ۳۰ روز
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// یوزر/پسورد ساده (در صورت نیاز تغییر دهید)
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'Admin123!';

// هندل لاگین ساده (غیر AJAX)
$loginError = null;
if (isset($_POST['do_login'])) {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';

    if ($u === ADMIN_USER && $p === ADMIN_PASS) {
        $_SESSION['auth'] = true;
        header('Location: index.php');
        exit;
    } else {
        $loginError = 'نام کاربری یا رمز عبور اشتباه است.';
    }
}

// لاگ‌اوت
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$loggedIn = !empty($_SESSION['auth']);

// APIها
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    require __DIR__ . '/excel_db.php';

    $action = $_GET['api'];

    if (!$loggedIn) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
        exit;
    }

    try {
        if ($action === 'add_word') {
            $word    = trim($_POST['word'] ?? '');
            $meaning = trim($_POST['meaning'] ?? '');
            $example = trim($_POST['example'] ?? '');

            if ($word === '' || $meaning === '') {
                echo json_encode(['ok' => false, 'error' => 'لغت و معنی الزامی است.']);
                exit;
            }

            // چک تکراری بودن لغت
            $existing = excel_find_by_word($word);
            if ($existing !== null) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'duplicate',
                    'item'  => $existing,
                ]);
                exit;
            }

            excel_add_word($word, $meaning, $example);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'update_word') {
            $id      = (int) ($_POST['id'] ?? 0);
            $word    = trim($_POST['word'] ?? '');
            $meaning = trim($_POST['meaning'] ?? '');
            $example = trim($_POST['example'] ?? '');

            if (!$id || $word === '' || $meaning === '') {
                echo json_encode(['ok' => false, 'error' => 'ورودی نامعتبر برای ویرایش.']);
                exit;
            }

            // اگر کلمه جدید با لغت دیگری (غیر از خودش) تکراری است، خطا بده
            $dup = excel_find_by_word($word);
            if ($dup !== null && (int) $dup['id'] !== $id) {
                echo json_encode(['ok' => false, 'error' => 'این لغت برای رکورد دیگری ثبت شده است.']);
                exit;
            }

            $updated = excel_update_word($id, $word, $meaning, $example);
            echo json_encode(['ok' => $updated !== null, 'item' => $updated]);
            exit;
        }

        if ($action === 'import_excel') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['ok' => false, 'error' => 'فایل ارسال نشده یا خطای آپلود.']);
                exit;
            }

            //require __DIR__ . '/excel_db.php';
            $tmp  = $_FILES['file']['tmp_name'];
            $cnt  = excel_import_from_file($tmp);
            echo json_encode(['ok' => true, 'inserted' => $cnt]);
            exit;
        }

        if ($action === 'get_today') {
            //require __DIR__ . '/excel_db.php';
            $session = leitner_get_today_session();
            echo json_encode(['ok' => true, 'session' => $session]);
            exit;
        }

        if ($action === 'update_result') {
            $id     = (int) ($_POST['id'] ?? 0);
            $result = $_POST['result'] ?? '';

            if (!$id || !in_array($result, ['right', 'wrong'], true)) {
                echo json_encode(['ok' => false, 'error' => 'ورودی نامعتبر.']);
                exit;
            }

            $res = leitner_update_result($id, $result);
            echo json_encode(['ok' => $res !== null, 'data' => $res]);
            exit;
        }

        if ($action === 'search') {
            $q      = $_GET['q'] ?? '';
            $status = $_GET['status'] ?? null;
            if ($status === '') {
                $status = null;
            }

            $rows = excel_search($q, $status);
            echo json_encode(['ok' => true, 'items' => $rows]);
            exit;
        }

        if ($action === 'list_words') {
            $page    = (int) ($_GET['page'] ?? 1);
            $perPage = (int) ($_GET['per_page'] ?? 20);
            $data    = excel_list_paginated($page, $perPage);
            echo json_encode(['ok' => true] + $data);
            exit;
        }

        if ($action === 'restore') {
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['ok' => false, 'error' => 'id نامعتبر.']);
                exit;
            }
            $res = excel_restore_word($id);
            echo json_encode(['ok' => $res !== null, 'data' => $res]);
            exit;
        }

        if ($action === 'get_settings') {
            $settings = settings_get();
            echo json_encode(['ok' => true, 'settings' => $settings]);
            exit;
        }

        if ($action === 'save_settings') {
            $newLimit     = (int) ($_POST['new_limit'] ?? 50);
            $sessionLimit = (int) ($_POST['session_limit'] ?? 10);
            settings_save([
                'new_limit'     => $newLimit,
                'session_limit' => $sessionLimit,
            ]);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'delete_word') {
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['ok' => false, 'error' => 'id نامعتبر.']);
                exit;
            }

            $ok = excel_delete_word($id);
            echo json_encode(['ok' => $ok]);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'عملیات ناشناخته.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// دانلود Excel
if (isset($_GET['download']) && $_GET['download'] === 'excel') {
    if (!$loggedIn) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    require __DIR__ . '/excel_db.php';
    excel_init();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="vocab.xlsx"');
    header('Content-Length: ' . filesize(EXCEL_FILE));
    readfile(EXCEL_FILE);
    exit;
}

// اگر وارد نشده، فرم لاگین را نشان بده
if (!$loggedIn) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <meta http-equiv="content-language" content="fa-ir">
        <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="HandheldFriendly" content="true" />
        <meta name="format-detection" content="telephone=no" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <head>
        <meta charset="UTF-8">
        <title>ورود به جعبه لایتنر</title>
        <link
            rel="stylesheet"
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
        >
    </head>
    <body class="bg-light">
    <div class="container d-flex align-items-center justify-content-center" style="min-height:100vh;">
        <div class="card shadow" style="max-width:400px;width:100%;">
            <div class="card-header text-center">
                ورود
            </div>
            <div class="card-body">
                <?php if ($loginError): ?>
                    <div class="alert alert-danger small">
                        <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">نام کاربری</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رمز عبور</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" name="do_login" value="1" class="btn btn-primary w-100">
                        ورود
                    </button>
                </form>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// از این‌جا به بعد: کاربر وارد شده است
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="content-language" content="fa-ir">
        <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="HandheldFriendly" content="true" />
        <meta name="format-detection" content="telephone=no" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <title>جعبه لایتنر لغات (Excel + PHP + jQuery)</title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
    >

    <style>
        body {
            background-color: #f8f9fa;
        }
        .leitner-card {
            max-width: 420px;
            margin: 0 auto;
            padding: 2rem;
            border-radius: 1rem;
            background: #fff;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.08);
            cursor: pointer;
            min-height: 230px;
        }
        .leitner-card .front,
        .leitner-card .back {
            font-size: 1.4rem;
        }
        .leitner-card .back {
            display: none;
        }
        .leitner-card.flipped .front {
            display: none;
        }
        .leitner-card.flipped .back {
            display: block;
        }
        .big-btn {
            padding: 0.8rem 1.4rem;
            font-size: 1.05rem;
        }
        .tab-content {
            margin-top: 1.5rem;
        }
        .ltr {
            direction: ltr;
            text-align: left;
        }
    </style>
</head>
<body>

<div class="container py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">جعبه لایتنر لغات با Excel</h1>
        <div>
            <a href="index.php?download=excel" class="btn btn-sm btn-outline-secondary me-2">
                دانلود Excel
            </a>
            <a href="index.php?logout=1" class="btn btn-sm btn-outline-danger">
                خروج
            </a>
        </div>
    </div>

    <ul class="nav nav-tabs" id="mainTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" id="tab-add-tab" data-bs-toggle="tab" data-bs-target="#tab-add" type="button" role="tab">
                افزودن لغت
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-session-tab" data-bs-toggle="tab" data-bs-target="#tab-session" type="button" role="tab">
                مرور امروز (جعبه لایتنر)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-search-tab" data-bs-toggle="tab" data-bs-target="#tab-search" type="button" role="tab">
                جستجو / لیست همه لغات
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-learned-tab" data-bs-toggle="tab" data-bs-target="#tab-learned" type="button" role="tab">
                لغات یادگرفته / بازگردانی
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-settings-tab" data-bs-toggle="tab" data-bs-target="#tab-settings" type="button" role="tab">
                تنظیمات
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- Tab 1: افزودن لغت -->
        <div class="tab-pane fade show active" id="tab-add" role="tabpanel">
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-header">افزودن / ویرایش لغت</div>
                        <div class="card-body">
                        <form id="form-add-word">
                            <input type="hidden" name="edit_id" id="edit-id" value="">

                            <div class="mb-3">
                                <label class="form-label">لغت (انگلیسی)</label>
                                <input type="text" class="form-control" name="word" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">معنی (فارسی)</label>
								<input type="text" class="form-control" name="meaning" required> 
                            </div>

                            <div class="mb-3">
                                <label class="form-label">مثال (جمله انگلیسی)</label>
                                <textarea class="form-control ltr" name="example" rows="2"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                ذخیره در Excel
                            </button>
                            <button type="button" id="btn-update-word" class="btn btn-warning ms-2" style="display:none;">
                                به‌روزرسانی رکورد موجود
                            </button>
                            <span id="add-word-msg" class="ms-2 text-success small"></span>
                        </form>

                        </div>
                    </div>
                </div>

                <div class="col-md-6">

                    <div class="card mb-3">
                        <div class="card-header">ورود گروهی از فایل Excel</div>
                        <div class="card-body">
                            <p class="small text-muted">
                                فایل Excel باید حداقل ۳ ستون داشته باشد:
                                <strong>لغت</strong> در ستون A،
                                <strong>معنی</strong> در ستون B،
                                <strong>مثال</strong> در ستون C (اختیاری).
                                لغات تکراری از روی ستون A نادیده گرفته می‌شوند.
                            </p>
                            <form id="form-import-excel" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <input type="file" class="form-control" name="file" accept=".xlsx,.xls" required>
                                </div>
                                <button type="submit" class="btn btn-secondary">
                                    آپلود و اضافه‌کردن
                                </button>
                                <span id="import-msg" class="ms-2 text-success small"></span>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Tab 2: مرور امروز -->
        <div class="tab-pane fade" id="tab-session" role="tabpanel">
            <div class="mt-3">
                <button id="btn-start-session" class="btn btn-success mb-3">
                    شروع مرور امروز
                </button>
                <span id="session-info" class="ms-3 text-muted small"></span>
            </div>

            <div id="session-area" class="text-center" style="display:none;">
                <p class="mb-2">
                    وضعیت: <span id="session-stage-label" class="fw-bold"></span>
                </p>
                <p class="mb-2">
                    کارت <span id="session-index">0</span> از
                    <span id="session-total">0</span>
                </p>

                <div id="leitner-card" class="leitner-card mb-3">
                    <div class="front">
                        <div class="text-muted small mb-2"></div>
                        <div id="card-front-word" class="fw-bold"></div>
                    </div>
                    <div class="back">
                        <div class="text-muted small mb-2"></div>
                        <div id="card-back-meaning"></div>
                        <div id="card-back-example" class="ltr small"></div>
                    </div>
                </div>

               <!-- <p class="small text-muted">
                    با کلیک روی کارت، معنی/مثال نمایش داده می‌شود.
                </p> -->

                <div id="session-buttons-study" class="mb-3">
                    <button id="btn-next-study" class="btn btn-primary big-btn">
                        لغت بعدی
                    </button>
                </div>

                <div id="session-buttons-test" class="mb-3" style="display:none;">
                    <button id="btn-wrong" class="btn btn-outline-danger big-btn me-2">
                        بلد نیستم 
                    </button>
                    <button id="btn-right" class="btn btn-outline-success big-btn">
                        بلدم 
                    </button>
                </div>
            </div>

            <div id="session-done" class="alert alert-info mt-3" style="display:none;">
                مرور امروز تمام شد.
            </div>
        </div>

        <!-- Tab 3: جستجو / لیست همه لغات -->
        <div class="tab-pane fade" id="tab-search" role="tabpanel">
            <div class="mt-3">
                <form id="form-search" class="row g-2">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control" placeholder="لغت / معنی / مثال ... (برای جستجوی زنده تایپ کنید)">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">همه وضعیت‌ها</option>
                            <option value="active">در حال یادگیری</option>
                            <option value="mastered">یادگرفته</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">
                            جستجو
                        </button>
                    </div>
                    <div class="col-12 mt-2">
                        <small class="text-muted">
                            وقتی فیلد جستجو خالی است، لیست همه لغات به صورت صفحه‌بندی نمایش داده می‌شود.
                        </small>
                    </div>
                </form>
            </div>

            <div class="table-responsive mt-3">
                <table class="table table-striped table-sm align-middle" id="tbl-search-results">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>لغت</th>
                        <th>معنی</th>
                        <th>مثال</th>
                        <th>خانه</th>
                        <th>وضعیت</th>
                        <th>مرور بعدی</th>
                        <th>عملیات</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <nav>
                <ul class="pagination pagination-sm justify-content-center" id="search-pagination"></ul>
            </nav>
        </div>

        <!-- Tab 4: لغات یادگرفته / بازگردانی -->
        <div class="tab-pane fade" id="tab-learned" role="tabpanel">
            <div class="mt-3">
                <form id="form-search-learned" class="row g-2">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control" placeholder="جستجو در لغات یادگرفته / بلندمدت">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-secondary">
                            جستجو
                        </button>
                    </div>
                </form>
            </div>

            <div class="table-responsive mt-3">
                <table class="table table-striped table-sm align-middle" id="tbl-learned-results">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>لغت</th>
                        <th>معنی</th>
                        <th>خانه</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- Tab 5: تنظیمات -->
        <div class="tab-pane fade" id="tab-settings" role="tabpanel">
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">تنظیمات جعبه لایتنر</div>
                        <div class="card-body">
                            <form id="form-settings">
                                <div class="mb-3">
                                    <label class="form-label">
                                        تعداد لغات جدید از خانه صفر در روز
                                    </label>
                                    <input type="number" min="1" class="form-control" name="new_limit">
                                    <div class="form-text">پیش‌فرض: ۵۰</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">
                                        تعداد کارت برای جلسه امروز (از بین لغات جدید)
                                    </label>
                                    <input type="number" min="1" class="form-control" name="session_limit">
                                    <div class="form-text">پیش‌فرض: ۱۰</div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    ذخیره تنظیمات
                                </button>
                                <span id="settings-msg" class="ms-2 small text-success"></span>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script
    src="https://code.jquery.com/jquery-3.7.1.min.js"
></script>
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>
    // --- State جلسه امروز ---
    let sessionState = {
        reviewCards: [],
        newCards: [],
        stage: 'idle',
        round: 1,
        index: 0,
        currentList: [],
        currentCard: null
    };

    let searchCurrentPage = 1;
    const searchPerPage = 20;

    function showMessage(selector, message, isError = false) {
        const el = $(selector);
        el.text(message);
        el.removeClass('text-success text-danger');
        el.addClass(isError ? 'text-danger' : 'text-success');
        if (message) {
            setTimeout(() => el.text(''), 4000);
        }
    }

    // --- افزودن لغت / جلوگیری از تکرار ---
    $('#form-add-word').on('submit', function (e) {
        e.preventDefault();
        const formData = $(this).serialize();

        $.post('index.php?api=add_word', formData, function (res) {
            if (res.ok) {
                showMessage('#add-word-msg', 'لغت جدید ذخیره شد.');
                $('#form-add-word')[0].reset();
                $('#edit-id').val('');
                $('#btn-update-word').hide();
                $('input[name="word"]').focus();
            } else if (res.error === 'duplicate') {
                // لغت تکراری: رکورد موجود را در فرم نمایش بده و دکمه ویرایش را فعال کن
                const item = res.item;
                if (item) {
                    $('input[name="word"]').val(item.word);
                    $('textarea[name="meaning"]').val(item.meaning);
                    $('textarea[name="example"]').val(item.example);
                    $('#edit-id').val(item.id);
                    $('#btn-update-word').show();
                    showMessage('#add-word-msg', 'این لغت قبلاً ثبت شده است. می‌توانید آن را ویرایش کنید.', true);
                } else {
                    showMessage('#add-word-msg', 'این لغت قبلاً ثبت شده است.', true);
                }
            } else {
                showMessage('#add-word-msg', res.error || 'خطا در ذخیره.', true);
            }
        }, 'json');
    });

    // --- ویرایش لغت موجود ---
    $('#btn-update-word').on('click', function () {
        const id = $('#edit-id').val();
        if (!id) {
            return;
        }

        const data = {
            id: id,
            word: $('input[name="word"]').val(),
            meaning: $('textarea[name="meaning"]').val(),
            example: $('textarea[name="example"]').val()
        };

        $.post('index.php?api=update_word', data, function (res) {
            if (res.ok) {
                showMessage('#add-word-msg', 'رکورد با موفقیت به‌روزرسانی شد.');
                $('#edit-id').val('');
                $('#btn-update-word').hide();
				$('input[name="word"]').focus();
            } else {
                showMessage('#add-word-msg', res.error || 'خطا در به‌روزرسانی.', true);
            }
        }, 'json');
    });

    // --- ایمپورت Excel ---
    $('#form-import-excel').on('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        $.ajax({
            url: 'index.php?api=import_excel',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if (res.ok) {
                    showMessage('#import-msg', res.inserted + ' لغت جدید (غیرتکراری) اضافه شد.');
                    $('#form-import-excel')[0].reset();
                } else {
                    showMessage('#import-msg', res.error || 'خطا در ایمپورت.', true);
                }
            }
        });
    });

    // --- شروع جلسه امروز ---
    $('#btn-start-session').on('click', function () {
        $.getJSON('index.php?api=get_today', function (res) {
            if (!res.ok) {
                alert(res.error || 'خطا در دریافت داده‌های امروز');
                return;
            }

            const ses = res.session;
            sessionState.reviewCards = ses.review_cards || [];
            sessionState.newCards = ses.new_cards || [];
            sessionState.stage = 'idle';
            sessionState.round = 1;
            sessionState.index = 0;
            sessionState.currentList = [];
            sessionState.currentCard = null;

            let info = 'تاریخ امروز: ' + ses.today +
                ' | کارت‌های مرور: ' + sessionState.reviewCards.length +
                ' | لغت جدید در دسترس (box0): ' + ses.total_new_box0 +
                ' | امروز از جدیدها: ' + sessionState.newCards.length;
            $('#session-info').text(info);

            $('#session-done').hide();
            $('#session-area').show();

            if (sessionState.reviewCards.length > 0) {
                startReviewStage();
            } else if (sessionState.newCards.length > 0) {
                startNewStudyStage();
            } else {
                $('#session-area').hide();
                $('#session-done').show().text('امروز کارتی برای مرور وجود ندارد.');
            }
        });
    });

    function updateCardView() {
        const idx = sessionState.index + 1;
        $('#session-index').text(idx);
        $('#session-total').text(sessionState.currentList.length);

        const card = sessionState.currentCard;
        if (!card) {
            $('#card-front-word').text('');
            $('#card-back-meaning').text('');
            $('#card-back-example').text('');
            return;
        }

        $('#card-front-word').text(card.word);
        $('#card-back-meaning').text(card.meaning);
        $('#card-back-example').text(card.example || '');
        $('#leitner-card').removeClass('flipped');
    }

    function setStageLabel(text) {
        $('#session-stage-label').text(text);
    }

    function startReviewStage() {
        sessionState.stage = 'review';
        sessionState.currentList = sessionState.reviewCards.slice();
        sessionState.index = 0;
        setStageLabel('مرور لغات قدیمی (خانه 1 تا 7 و بلندمدت)');
        $('#session-buttons-study').hide();
        $('#session-buttons-test').show();
        loadCurrentCard();
    }

    function startNewStudyStage() {
        if (!sessionState.newCards.length) {
            startNewTestStage();
            return;
        }
        sessionState.stage = 'new-study';
        sessionState.currentList = sessionState.newCards.slice();
        sessionState.index = 0;
        sessionState.round = 1;
        setStageLabel('مرحله مطالعه (دور ' + sessionState.round + ' از 3) برای لغات جدید');
        $('#session-buttons-study').show();
        $('#session-buttons-test').hide();
        loadCurrentCard();
    }

    function startNewTestStage() {
        if (!sessionState.newCards.length) {
            sessionFinished();
            return;
        }
        sessionState.stage = 'new-test';
        sessionState.currentList = sessionState.newCards.slice();
        sessionState.index = 0;
        setStageLabel('مرحله پرسش از لغات جدید (بلدم = خانه 1، بلد نیستم = ماندن در خانه 0)');
        $('#session-buttons-study').hide();
        $('#session-buttons-test').show();
        loadCurrentCard();
    }

    function sessionFinished() {
        sessionState.stage = 'done';
        $('#session-area').hide();
        $('#session-done').show().text('مرور امروز تمام شد.');
    }

    function loadCurrentCard() {
        if (sessionState.index >= sessionState.currentList.length) {
            if (sessionState.stage === 'review') {
                if (sessionState.newCards.length > 0) {
                    startNewStudyStage();
                } else {
                    sessionFinished();
                }
            } else if (sessionState.stage === 'new-study') {
                if (sessionState.round < 3) {
                    sessionState.round++;
                    sessionState.index = 0;
                    setStageLabel('مرحله مطالعه (دور ' + sessionState.round + ' از 3) برای لغات جدید');
                    loadCurrentCard();
                } else {
                    startNewTestStage();
                }
            } else if (sessionState.stage === 'new-test') {
                sessionFinished();
            }
            return;
        }

        sessionState.currentCard = sessionState.currentList[sessionState.index];
        updateCardView();
    }

    $('#leitner-card').on('click', function () {
        $(this).toggleClass('flipped');
    });

    $('#btn-next-study').on('click', function () {
        sessionState.index++;
        loadCurrentCard();
    });

    function sendResultForCurrentCard(isRight) {
        const card = sessionState.currentCard;
        if (!card) return;

        $.post('index.php?api=update_result', {
            id: card.id,
            result: isRight ? 'right' : 'wrong'
        }, function (res) {
            // لاگ اختیاری
        }, 'json');
    }

    $('#btn-wrong').on('click', function () {
        if (sessionState.stage === 'review' || sessionState.stage === 'new-test') {
            sendResultForCurrentCard(false);
        }
        sessionState.index++;
        loadCurrentCard();
    });

    $('#btn-right').on('click', function () {
        if (sessionState.stage === 'review' || sessionState.stage === 'new-test') {
            sendResultForCurrentCard(true);
        }
        sessionState.index++;
        loadCurrentCard();
    });

    // --- جستجو / لیست همه لغات با صفحه‌بندی ---
    function renderSearchTable(items) {
    const tbody = $('#tbl-search-results tbody');
    tbody.empty();

    items.forEach(function (item) {
        const tr = $('<tr>');

        tr.append('<td>' + item.id + '</td>');
        tr.append('<td>' + $('<div>').text(item.word).html() + '</td>');
        tr.append('<td>' + $('<div>').text(item.meaning).html() + '</td>');
        tr.append('<td class="ltr small">' + $('<div>').text(item.example || '').html() + '</td>');
        tr.append('<td>' + item.box + '</td>');
        tr.append('<td>' + item.status + '</td>');
        tr.append('<td>' + (item.next_review || '') + '</td>');

        // ستون عملیات
        const tdActions = $('<td>');

        // دکمه ویرایش
        const btnEdit = $('<button class="btn btn-sm btn-outline-warning me-1">ویرایش</button>');
        btnEdit.on('click', function () {
            // رفتن به تب افزودن لغت
            const tabTrigger = document.querySelector('#tab-add-tab');
            if (tabTrigger && window.bootstrap) {
                const tab = new bootstrap.Tab(tabTrigger);
                tab.show();
            }

            // پر کردن فرم با داده‌های رکورد
            $('#edit-id').val(item.id);
            $('input[name="word"]').val(item.word);
            $('textarea[name="meaning"]').val(item.meaning || '');
            $('textarea[name="example"]').val(item.example || '');

            // دکمه آپدیت را نشان بده
            $('#btn-update-word').show();

            // فوکوس روی لغت
            $('input[name="word"]').focus().select();

            // اسکرول به بالای صفحه
            $('html, body').animate({scrollTop: 0}, 300);
        });

        // دکمه حذف (اگر قبلاً اضافه کرده‌ای)
        const btnDelete = $('<button class="btn btn-sm btn-outline-danger">حذف</button>');
        btnDelete.on('click', function () {
            if (!confirm('آیا از حذف این لغت مطمئن هستید؟')) {
                return;
            }
            $.post('index.php?api=delete_word', {id: item.id}, function (res) {
                if (!res.ok) {
                    alert(res.error || 'خطا در حذف لغت');
                    return;
                }

                const q = $('#form-search input[name="q"]').val().trim();
                if (q === '') {
                    loadWordPage(searchCurrentPage);
                } else {
                    $('#form-search').submit();
                }
            }, 'json');
        });

        tdActions.append(btnEdit).append(btnDelete);
        tr.append(tdActions);

        tbody.append(tr);
    });
}



    function renderSearchPagination(page, totalPages) {
        const ul = $('#search-pagination');
        ul.empty();
        if (totalPages <= 1) return;

        const addPageItem = function (p, label, disabled, active) {
            const li = $('<li class="page-item"></li>');
            if (disabled) li.addClass('disabled');
            if (active) li.addClass('active');
            const a = $('<a class="page-link" href="#"></a>').text(label);
            a.on('click', function (e) {
                e.preventDefault();
                if (!disabled && !active) {
                    loadWordPage(p);
                }
            });
            li.append(a);
            ul.append(li);
        };

        addPageItem(page - 1, 'قبلی', page === 1, false);

        const maxPagesToShow = 7;
        let start = Math.max(1, page - 3);
        let end = Math.min(totalPages, start + maxPagesToShow - 1);
        if (end - start < maxPagesToShow - 1) {
            start = Math.max(1, end - maxPagesToShow + 1);
        }

        if (start > 1) {
            addPageItem(1, '1', false, page === 1);
            if (start > 2) {
                ul.append('<li class="page-item disabled"><span class="page-link">…</span></li>');
            }
        }

        for (let p = start; p <= end; p++) {
            addPageItem(p, String(p), false, p === page);
        }

        if (end < totalPages) {
            if (end < totalPages - 1) {
                ul.append('<li class="page-item disabled"><span class="page-link">…</span></li>');
            }
            addPageItem(totalPages, String(totalPages), false, page === totalPages);
        }

        addPageItem(page + 1, 'بعدی', page === totalPages, false);
    }

    function loadWordPage(page) {
        $.getJSON('index.php?api=list_words', {page: page, per_page: searchPerPage}, function (res) {
            if (!res.ok) {
                alert(res.error || 'خطا در لیست لغات');
                return;
            }
            searchCurrentPage = res.page;
            renderSearchTable(res.items);
            renderSearchPagination(res.page, res.total_pages);
        });
    }

    // سابمیت فرم جستجو (کلیک روی دکمه)
    $('#form-search').on('submit', function (e) {
        e.preventDefault();
        const q = $(this).find('input[name="q"]').val();
        const status = $(this).find('select[name="status"]').val();

        if (q.trim() === '') {
            // اگر جستجو خالی است، لیست صفحه‌بندی همه لغات
            loadWordPage(1);
            return;
        }

        $.getJSON('index.php?api=search', {q: q, status: status}, function (res) {
            if (!res.ok) {
                alert(res.error || 'خطا در جستجو');
                return;
            }
            renderSearchTable(res.items);
            $('#search-pagination').empty();
        });
    });

    // جستجوی زنده به ازای هر کاراکتر
    $('#form-search input[name="q"]').on('keyup', function () {
        const q = $(this).val();
        const status = $('#form-search').find('select[name="status"]').val();

        if (q.trim() === '') {
            loadWordPage(1);
            return;
        }

        $.getJSON('index.php?api=search', {q: q, status: status}, function (res) {
            if (!res.ok) return;
            renderSearchTable(res.items);
            $('#search-pagination').empty();
        });
    });

    // اولین بار که تب جستجو باز می‌شود، صفحه ۱ را لود کن
    $('#tab-search-tab').on('shown.bs.tab', function () {
        loadWordPage(1);
    });

    // --- لغات یادگرفته / بازگردانی ---
    $('#form-search-learned').on('submit', function (e) {
        e.preventDefault();
        const q = $(this).find('input[name="q"]').val();

        $.getJSON('index.php?api=search', {q: q, status: ''}, function (res) {
            if (!res.ok) {
                alert(res.error || 'خطا در جستجو');
                return;
            }
            const tbody = $('#tbl-learned-results tbody');
            tbody.empty();

            res.items.forEach(function (item) {
                // فقط رکوردهایی که یا mastered هستند یا در box8 (بلندمدت)
                if (item.status !== 'mastered' && item.box !== 8) {
                    return;
                }

                const tr = $('<tr>');
                tr.append('<td>' + item.id + '</td>');
                tr.append('<td>' + $('<div>').text(item.word).html() + '</td>');
                tr.append('<td>' + $('<div>').text(item.meaning).html() + '</td>');
                tr.append('<td>' + item.box + '</td>');
                tr.append('<td>' + item.status + '</td>');
                const btn = $('<button class="btn btn-sm btn-outline-primary">بازگرداندن به جعبه</button>');
                btn.on('click', function () {
                    if (!confirm('این لغت دوباره به چرخه یادگیری (خانه 1) برگردانده شود؟')) {
                        return;
                    }
                    $.post('index.php?api=restore', {id: item.id}, function (res2) {
                        if (!res2.ok) {
                            alert(res2.error || 'خطا در بازگردانی');
                            return;
                        }
                        alert('لغت به خانه 1 برگشت.');
                    }, 'json');
                });
                const tdOp = $('<td>');
                tdOp.append(btn);
                tr.append(tdOp);
                tbody.append(tr);
            });
        });
    });

    // --- تنظیمات ---
    function loadSettings() {
        $.getJSON('index.php?api=get_settings', function (res) {
            if (!res.ok) {
                showMessage('#settings-msg', res.error || 'خطا در دریافت تنظیمات.', true);
                return;
            }
            const s = res.settings;
            $('#form-settings [name="new_limit"]').val(s.new_limit);
            $('#form-settings [name="session_limit"]').val(s.session_limit);
        });
    }

    $('#form-settings').on('submit', function (e) {
        e.preventDefault();
        const data = $(this).serialize();
        $.post('index.php?api=save_settings', data, function (res) {
            if (res.ok) {
                showMessage('#settings-msg', 'تنظیمات ذخیره شد.');
            } else {
                showMessage('#settings-msg', res.error || 'خطا در ذخیره تنظیمات.', true);
            }
        }, 'json');
    });

    $('#tab-settings-tab').on('shown.bs.tab', function () {
        loadSettings();
    });

    // برای راحتی: وقتی صفحه لود می‌شود، تنظیمات را یک‌بار بگیر (اگر کاربر مستقیم به تب تنظیمات نرفت)
    $(function () {
        // هیچ کار اجباری در load اولیه نمی‌کنیم تا سبک بماند
    });
</script>

</body>
</html>
