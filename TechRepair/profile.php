<?php
session_start();
require_once 'db.php';
require_once 'functions.php';

if (!defined('APP_NAME')) define('APP_NAME', 'TechRepair');

if (!isset($_SESSION['user_id'])) {
    redirect('login.php');
}
// Technicians have their own profile page
if (($_SESSION['role'] ?? '') === 'technician') {
    redirect('tech_profile.php');
}

$user_id  = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';

$flash       = take_flash();
$error       = '';
$setup_error = '';
$p = ['first_name' => '', 'last_name' => '', 'phone' => ''];

/* ---------- Load ---------- */
try {
    $stmt = $conn->prepare("SELECT first_name, last_name, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $p['first_name'] = $u['first_name'] ?? '';
    $p['last_name']  = $u['last_name'] ?? '';
    $p['phone']      = $u['phone'] ?? '';
} catch (mysqli_sql_exception $ex) {
    $setup_error = 'Your account table is missing the new columns. Run the SQL in migration.sql in phpMyAdmin, then reload this page.';
}

/* ---------- Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$setup_error) {
    $p['first_name'] = preg_replace('/\s+/u', ' ', trim($_POST['first_name'] ?? ''));
    $p['last_name']  = preg_replace('/\s+/u', ' ', trim($_POST['last_name'] ?? ''));
    $p['phone']      = trim($_POST['phone'] ?? '');

    $name_ok = function ($n) {
        return mb_strlen($n) >= 1 && mb_strlen($n) <= 50 && preg_match('/^\p{L}[\p{L}\p{M} .\'-]*$/u', $n);
    };

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$name_ok($p['first_name'])) {
        $error = 'Enter your first name (letters only, up to 50 characters).';
    } elseif (!$name_ok($p['last_name'])) {
        $error = 'Enter your last name (letters only, up to 50 characters).';
    } elseif (!preg_match('/^\d{11}$/', $p['phone'])) {
        $error = 'Enter a valid mobile number: digits only, exactly 11 digits.';
    } else {
        try {
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?");
            $stmt->bind_param("sssi", $p['first_name'], $p['last_name'], $p['phone'], $user_id);
            $stmt->execute();
            $stmt->close();

            set_flash('success', 'Profile saved.');
            redirect('profile.php');
        } catch (mysqli_sql_exception $ex) {
            $error = ($ex->getCode() === 1062)
                ? 'That mobile number is already used by another account.'
                : 'We couldn\'t save your profile. Please try again.';
        }
    }
}

$display = trim($p['first_name'] . ' ' . $p['last_name']);
if ($display === '') $display = $username;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My profile - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        a.nav-link { color: #cbd5e1; text-decoration: none; font-size: 0.9rem; display: inline-block; padding: 8px 16px; border-radius: 8px; background: rgba(255,255,255,0.06); transition: 0.3s ease; }
        a.nav-link:hover { background: rgba(255,255,255,0.12); }
        .dash input[type="tel"] { width: 100%; padding: 14px 16px; background: var(--surface); border: 1px solid transparent; border-radius: 12px; color: #fff; outline: none; font: inherit; appearance: none; box-shadow: inset 4px 4px 8px rgba(0,0,0,0.5), inset -4px -4px 8px rgba(255,255,255,0.02); transition: all 0.3s ease; }
        .dash input[type="tel"]:focus { border: 1px solid rgba(168, 85, 247, 0.4); }
        .name-row { display: flex; gap: 12px; }
        .name-row .form-group { flex: 1; min-width: 0; }
        .setup-note { padding: 14px 18px; border-radius: 14px; margin-bottom: 20px; color: #fbbf24; background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.3); }
        .readonly-value { padding: 14px 16px; border-radius: 12px; background: rgba(255,255,255,.04); color: #94a3b8; }
    </style>
</head>
<body class="dash">
<div class="dash-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true"></span><?= e(APP_NAME) ?></a>
        <div class="topbar-user">
            <span class="avatar" aria-hidden="true"><?= e(strtoupper(mb_substr($display, 0, 1))) ?></span>
            <span class="topbar-name"><?= e($display) ?></span>
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="logout.php" class="logout-link">Log out</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="toast <?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
    <?php endif; ?>
    <?php if ($setup_error): ?>
        <div class="setup-note" role="alert"><?= e($setup_error) ?></div>
    <?php endif; ?>

    <section class="panel" style="max-width: 560px; margin: 0 auto;">
        <h3>My profile</h3>

        <?php if ($error): ?>
            <p class="form-error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label>Username</label>
                <div class="readonly-value"><?= e($username) ?></div>
            </div>

            <div class="name-row">
                <div class="form-group">
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" required maxlength="50" autocomplete="given-name" value="<?= e($p['first_name']) ?>">
                </div>
                <div class="form-group">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" required maxlength="50" autocomplete="family-name" value="<?= e($p['last_name']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="phone">Mobile number</label>
                <input type="tel" id="phone" name="phone" required inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" autocomplete="tel" placeholder="09171234567" value="<?= e($p['phone']) ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);">
                <p class="hint">11 digits only. Used to recover your account and shared with the technician who accepts your booking.</p>
            </div>

            <button type="submit" <?= $setup_error ? 'disabled' : '' ?>>Save profile</button>
        </form>
    </section>
</div>

<script>
    // Mobile number: digits only (typing, pasting or dropping letters is stripped)
    document.querySelectorAll('input[type="tel"]').forEach(function (el) {
        el.addEventListener('keypress', function (e) {
            if (e.key && e.key.length === 1 && !/\d/.test(e.key) && !e.ctrlKey && !e.metaKey) e.preventDefault();
        });
        el.addEventListener('input', function () {
            var clean = el.value.replace(/\D/g, '');
            if (clean !== el.value) el.value = clean;
        });
    });
</script>
</body>
</html>