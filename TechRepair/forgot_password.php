<?php
session_start();
require 'db.php';
require 'config.php';
require 'functions.php';

if (isset($_SESSION['user_id'])) {
    redirect(['admin' => 'admin.php', 'technician' => 'tech_dashboard.php'][$_SESSION['role'] ?? ''] ?? 'dashboard.php');
}

$error = '';
$phone = '';
$flash = take_flash();

// In DEV_MODE the reset link is shown on screen once, since no SMS service is connected yet
$dev_link = DEV_MODE ? ($_SESSION['dev_link'] ?? null) : null;
unset($_SESSION['dev_link']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = normalize_phone($_POST['phone'] ?? '');

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!valid_phone($phone)) {
        $error = 'Enter your 11-digit mobile number.';
    } else {
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE phone = ? LIMIT 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $uid = (int) $user['id'];
            $now = date('Y-m-d H:i:s');

            // Throttle: at most one reset request per minute per account
            $recent_cutoff = date('Y-m-d H:i:s', time() - 60);
            $stmt = $conn->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?");
            $stmt->bind_param("is", $uid, $recent_cutoff);
            $stmt->execute();
            $stmt->bind_result($recent);
            $stmt->fetch();
            $stmt->close();

            if ($recent == 0) {
                // Only the newest link works
                $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $stmt->close();

                $token   = bin2hex(random_bytes(32));
                $hash    = hash('sha256', $token); // only the hash is stored
                $expires = date('Y-m-d H:i:s', time() + RESET_MINUTES * 60);

                $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isss", $uid, $hash, $expires, $now);
                $stmt->execute();
                $stmt->close();

                $link = app_url() . '/reset_password.php?token=' . $token;

                if (DEV_MODE) {
                    $_SESSION['dev_link'] = $link;
                } else {
                    send_reset_sms($phone, $link);
                }
            }
        }

        // Same message whether or not the number exists, so accounts can't be probed
        flash('success', 'If an account exists for that number, a reset link is on its way by text. It works for ' . RESET_MINUTES . ' minutes.');
        redirect('forgot_password.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot password - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Force dark background on browser autofill to match the other text boxes */
        input:-webkit-autofill,
        input:-webkit-autofill:hover, 
        input:-webkit-autofill:focus, 
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px #11121c inset !important;
            -webkit-text-fill-color: #fff !important;
            transition: background-color 5000s ease-in-out 0s;
            caret-color: #fff;
        }
    </style>
</head>
<body class="auth">
<?php render_bg(); ?>
<main class="auth-layout">
    <?php render_stage('recover'); ?>

    <section class="auth-side">
        <div class="auth-card">
            <span class="auth-badge"><?= icon('key', 22) ?></span>
            <h2>Forgot your password?</h2>
            <p class="auth-sub">Enter your mobile number and we'll text you a link to reset it.</p>

            <?php if ($flash): ?>
                <div class="toast <?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
            <?php endif; ?>
            <?php if ($dev_link): ?>
                <div class="dev-note">
                    <strong>Dev mode: text messages aren't set up</strong>
                    <p>Use this link instead of a text. Set <code>DEV_MODE</code> to false in <code>config.php</code> once an SMS service is connected.</p>
                    <a href="<?= e($dev_link) ?>"><?= e($dev_link) ?></a>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <p class="form-error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <div class="label-row"><label for="phone">Mobile number</label><span class="count-badge" data-phone-count="phone">0/11</span></div>
                    <div class="field">
                        <span class="field-icon"><?= icon('phone') ?></span>
                        <input type="tel" id="phone" name="phone" required inputmode="numeric" minlength="11" maxlength="11" placeholder="09123456789" autocomplete="off" autofocus value="<?= e($phone) ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);">
                    </div>
                </div>
                <button type="submit" class="btn-main"><span>Send reset link</span><?= icon('arrow', 18) ?></button>
            </form>

            <p class="auth-foot"><a class="text-link" href="login.php">Back to log in</a></p>
        </div>
        <p class="auth-secure"><?= icon('shield', 15) ?> Reset links are single-use and expire automatically.</p>
    </section>
</main>
<script src="auth.js"></script>
</body>
</html>