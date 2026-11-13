<?php
session_start();
require 'db.php';
require 'config.php';
require 'functions.php';

// Where each role lands after logging in
function home_for($role) {
    return ['admin' => 'admin.php', 'technician' => 'tech_dashboard.php'][$role] ?? 'dashboard.php';
}

if (isset($_SESSION['user_id'])) {
    redirect(home_for($_SESSION['role'] ?? ''));
}

$error      = '';
$identifier = '';
$flash      = take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';
        // If they typed a mobile number (0917 123 4567, +63917...), match it against the phone column
        $phone_try  = looks_like_phone($identifier) ? normalize_phone($identifier) : null;

        $stmt = $conn->prepare(
            "SELECT id, username, password, role, failed_attempts, locked_until
             FROM users WHERE username = ? OR phone = ?
             ORDER BY (username = ?) DESC LIMIT 1"
        );
        $stmt->bind_param("sss", $identifier, $phone_try, $identifier);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $locked_until = $user && $user['locked_until'] ? strtotime($user['locked_until']) : 0;

        if ($locked_until > time()) {
            $mins  = (int) ceil(($locked_until - time()) / 60);
            $error = 'Too many failed attempts. Try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
        } else {
            // Always run a hash check so response time doesn't reveal whether the user exists
            $ok = $user
                ? password_verify($password, $user['password'])
                : (bool) (password_verify($password, password_hash('placeholder', PASSWORD_DEFAULT)) && false);

            if ($ok) {
                $stmt = $conn->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();
                $stmt->close();

                session_regenerate_id(true);
                unset($_SESSION['csrf']);
                $_SESSION['user_id']  = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'] ?: 'customer';
                redirect(home_for($_SESSION['role']));
            }

            $error = 'Incorrect username or password.';

            if ($user) {
                $attempts = (int) $user['failed_attempts'] + 1;
                $lock     = null;
                if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                    $lock     = date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60);
                    $attempts = 0;
                    $error    = 'Too many failed attempts. Try again in ' . LOCK_MINUTES . ' minutes.';
                }
                $stmt = $conn->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?");
                $stmt->bind_param("isi", $attempts, $lock, $user['id']);
                $stmt->execute();
                $stmt->close();
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
    <title>Log in - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth">
<?php render_bg(); ?>
<main class="auth-layout">
    <?php render_stage('login'); ?>

    <section class="auth-side">
        <div class="auth-card">
            <span class="auth-badge"><?= icon('lock', 22) ?></span>
            <h2>Welcome back</h2>
            <p class="auth-sub">Log in to book and track your repairs.</p>

            <?php if ($flash): ?>
                <div class="toast <?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <p class="form-error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="identifier">Username or mobile number</label>
                    <div class="field">
                        <span class="field-icon"><?= icon('user') ?></span>
                        <input type="text" id="identifier" name="identifier" required autocomplete="username" autofocus value="<?= e($identifier) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <div class="label-row">
                        <label for="password">Password</label>
                        <a class="text-link" href="forgot_password.php">Forgot password?</a>
                    </div>
                    <div class="field has-toggle">
                        <span class="field-icon"><?= icon('lock') ?></span>
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                        <?= pw_toggle('password') ?>
                    </div>
                </div>

                <button type="submit" class="btn-main"><span>Log in</span><?= icon('arrow', 18) ?></button>
            </form>

            <p class="auth-foot">New to <?= e(APP_NAME) ?>? <a class="text-link" href="register.php">Create an account</a></p>
        </div>
        <p class="auth-secure"><?= icon('shield', 15) ?> Passwords are stored hashed, never in plain text.</p>
    </section>
</main>
<script src="auth.js"></script>
</body>
</html>