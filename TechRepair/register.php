<?php
session_start();
require 'db.php';
require 'config.php';
require 'functions.php';

if (isset($_SESSION['user_id'])) {
    redirect(['admin' => 'admin.php', 'technician' => 'tech_dashboard.php'][$_SESSION['role'] ?? ''] ?? 'dashboard.php');
}

$error    = '';
$role     = 'customer';
$first    = '';
$last     = '';
$username = '';
$phone    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role     = $_POST['role'] ?? 'customer';
    $first    = preg_replace('/\s+/u', ' ', trim($_POST['first_name'] ?? ''));
    $last     = preg_replace('/\s+/u', ' ', trim($_POST['last_name'] ?? ''));
    $username = trim($_POST['username'] ?? '');
    $phone    = normalize_phone($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    // Same rule as the profile page: letters, spaces, dots, apostrophes and dashes
    $name_ok = function ($n) {
        return mb_strlen($n) >= 1 && mb_strlen($n) <= 50 && preg_match('/^\p{L}[\p{L}\p{M} .\'-]*$/u', $n);
    };

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!in_array($role, ['customer', 'technician'], true)) {
        $error = 'Choose how you want to sign up: as a customer or a technician.';
    } elseif (!$name_ok($first)) {
        $error = 'Enter your first name (letters only, up to 50 characters).';
    } elseif (!$name_ok($last)) {
        $error = 'Enter your last name (letters only, up to 50 characters).';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username) || !preg_match('/[A-Za-z]/', $username)) {
        $error = 'Username must be 3 to 30 characters with at least one letter: letters, numbers, dots, dashes or underscores.';
    } elseif (!valid_phone($phone)) {
        $error = 'Enter a valid mobile number: digits only, exactly 11 digits.';
    } elseif ($pw_error = validate_password($password)) {
        $error = $pw_error;
    } elseif ($password !== $confirm) {
        $error = 'The passwords don\'t match.';
    } else {
        // Check for an existing username or mobile number
        $stmt = $conn->prepare("SELECT username, phone FROM users WHERE username = ? OR phone = ?");
        $stmt->bind_param("ss", $username, $phone);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $r) {
            if (strcasecmp($r['username'], $username) === 0) {
                $error = 'That username is already taken.';
                break;
            }
            if ($r['phone'] !== null && $r['phone'] === $phone) {
                $error = 'An account with that mobile number already exists. Try logging in instead.';
                break;
            }
        }

        if (!$error) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                if ($role === 'technician') {
                    $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, phone, password, role) VALUES (?, ?, ?, ?, ?, 'technician')");
                } else {
                    // Customers keep the database's default role
                    $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, phone, password) VALUES (?, ?, ?, ?, ?)");
                }
                $stmt->bind_param("sssss", $username, $first, $last, $phone, $hash);
                $stmt->execute();
                $stmt->close();

                flash('success', $role === 'technician'
                    ? 'Technician account created. Log in to open your workspace.'
                    : 'Account created. Log in to book your first repair.');
                redirect('login.php');
            } catch (mysqli_sql_exception $ex) {
                $error = ($ex->getCode() === 1062)
                    ? 'That username or mobile number is already registered.'
                    : 'We couldn\'t create your account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create account - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth">
<?php render_bg(); ?>
<main class="auth-layout">
    <?php render_stage('register'); ?>

    <section class="auth-side">
        <div class="auth-card">
            <span class="auth-badge"><?= icon('user', 22) ?></span>
            <h2>Create your account</h2>
            <p class="auth-sub">It takes a minute, and you can book right away.</p>

            <?php if ($error): ?>
                <p class="form-error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>

            <form method="POST" action="" novalidate data-confirm>
                <?= csrf_field() ?>
                <p class="form-error js-error" role="alert" hidden></p>

                <fieldset class="mode-pick">
                    <legend>I'm signing up as</legend>
                    <label class="mode-card">
                        <input type="radio" name="role" value="customer" <?= $role !== 'technician' ? 'checked' : '' ?>>
                        <span class="mode-body"><strong>A customer</strong><small>Book and track repairs</small></span>
                    </label>
                    <label class="mode-card">
                        <input type="radio" name="role" value="technician" <?= $role === 'technician' ? 'checked' : '' ?>>
                        <span class="mode-body"><strong>A technician</strong><small>Take on repair jobs</small></span>
                    </label>
                </fieldset>

                <div class="name-row">
                    <div class="form-group">
                        <label for="first_name">First name</label>
                        <input type="text" id="first_name" name="first_name" data-name required maxlength="50" autocomplete="given-name" autofocus value="<?= e($first) ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last name</label>
                        <input type="text" id="last_name" name="last_name" data-name required maxlength="50" autocomplete="family-name" value="<?= e($last) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="field">
                        <span class="field-icon"><?= icon('user') ?></span>
                        <input type="text" id="username" name="username" required minlength="3" maxlength="30" autocomplete="username" value="<?= e($username) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <div class="label-row"><label for="phone">Mobile number</label><span class="count-badge" data-phone-count="phone">0/11</span></div>
                    <div class="field">
                        <span class="field-icon"><?= icon('phone') ?></span>
                        <input type="tel" id="phone" name="phone" required inputmode="numeric" minlength="11" maxlength="11" placeholder="09171234567" autocomplete="off" value="<?= e($phone) ?>">
                    </div>
                    <p class="hint">11 digits only. Used to recover your account and shared with the technician who accepts your booking.</p>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="field has-toggle">
                        <span class="field-icon"><?= icon('lock') ?></span>
                        <input type="password" id="password" name="password" required minlength="<?= (int) PASSWORD_MIN_LENGTH ?>" maxlength="72" autocomplete="new-password" data-strength="pwMeter">
                        <?= pw_toggle('password') ?>
                    </div>
                    <div class="meter" id="pwMeter" hidden>
                        <div class="meter-track"><div class="meter-bar"></div></div>
                        <span class="meter-label"></span>
                    </div>
                    <?php render_password_rules('password'); ?>
                </div>

                <div class="form-group">
                    <label for="confirm">Confirm password</label>
                    <div class="field has-toggle">
                        <span class="field-icon"><?= icon('lock') ?></span>
                        <input type="password" id="confirm" name="confirm" required maxlength="72" autocomplete="new-password">
                        <?= pw_toggle('confirm') ?>
                    </div>
                    <p class="match-note" aria-live="polite" hidden></p>
                </div>

                <button type="submit" class="btn-main"><span>Create account</span><?= icon('arrow', 18) ?></button>
            </form>

            <p class="auth-foot">Already have an account? <a class="text-link" href="login.php">Log in</a></p>
        </div>
        <p class="auth-secure"><?= icon('shield', 15) ?> Passwords are stored hashed, never in plain text.</p>
    </section>
</main>
<script src="auth.js"></script>
</body>
</html>