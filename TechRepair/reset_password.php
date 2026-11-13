<?php
session_start();
require 'db.php';
require 'config.php';
require 'functions.php';

if (isset($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$token   = $_GET['token'] ?? $_POST['token'] ?? '';
$error   = '';
$reset   = null; // the matching, unexpired reset row

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hash = hash('sha256', $token);
    $now  = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("SELECT id, user_id FROM password_resets WHERE token_hash = ? AND expires_at > ? LIMIT 1");
    $stmt->bind_param("ss", $hash, $now);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($pw_error = validate_password($password)) {
        $error = $pw_error;
    } elseif ($password !== $confirm) {
        $error = 'The passwords don\'t match.';
    } else {
        $uid     = (int) $reset['user_id'];
        $new     = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?");
        $stmt->bind_param("si", $new, $uid);
        $stmt->execute();
        $stmt->close();

        // The link is single-use: remove every reset link for this account
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();

        flash('success', 'Password updated. You can log in with your new password.');
        redirect('login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset password - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth">
<?php render_bg(); ?>
<main class="auth-layout">
    <?php render_stage('recover'); ?>

    <section class="auth-side">
        <div class="auth-card">
        <?php if (!$reset): ?>
            <span class="auth-badge"><?= icon('key', 22) ?></span>
            <h2>This link has expired</h2>
            <p class="auth-sub">Reset links work once and for <?= (int) RESET_MINUTES ?> minutes. Request a new one and we'll send it right over.</p>
            <a class="btn-link btn-block" href="forgot_password.php">Request a new link</a>
            <p class="auth-foot"><a class="text-link" href="login.php">Back to log in</a></p>

        <?php else: ?>
            <span class="auth-badge"><?= icon('lock', 22) ?></span>
            <h2>Choose a new password</h2>
            <p class="auth-sub">Pick something you don't use anywhere else.</p>

            <?php if ($error): ?>
                <p class="form-error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>

            <form method="POST" action="" novalidate data-confirm>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <p class="form-error js-error" role="alert" hidden></p>

                <div class="form-group">
                    <label for="password">New password</label>
                    <div class="field has-toggle">
                        <span class="field-icon"><?= icon('lock') ?></span>
                        <input type="password" id="password" name="password" required minlength="<?= (int) PASSWORD_MIN_LENGTH ?>" maxlength="72" autocomplete="new-password" autofocus data-strength="pwMeter">
                        <?= pw_toggle('password') ?>
                    </div>
                    <div class="meter" id="pwMeter" hidden>
                        <div class="meter-track"><div class="meter-bar"></div></div>
                        <span class="meter-label"></span>
                    </div>
                    <?php render_password_rules('password'); ?>
                </div>

                <div class="form-group">
                    <label for="confirm">Confirm new password</label>
                    <div class="field has-toggle">
                        <span class="field-icon"><?= icon('lock') ?></span>
                        <input type="password" id="confirm" name="confirm" required maxlength="72" autocomplete="new-password">
                        <?= pw_toggle('confirm') ?>
                    </div>
                    <p class="match-note" aria-live="polite" hidden></p>
                </div>

                <button type="submit" class="btn-main"><span>Update password</span><?= icon('arrow', 18) ?></button>
            </form>
        <?php endif; ?>
        </div>
        <p class="auth-secure"><?= icon('shield', 15) ?> Your old password stops working as soon as you save.</p>
    </section>
</main>
<script src="auth.js"></script>
</body>
</html>