<?php
// Shared helpers for login, register, forgot_password and reset_password.

function e($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect($to) {
    header("Location: $to");
    exit();
}

/* ---------- CSRF ---------- */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/* ---------- Flash messages (shown once after a redirect) ---------- */
function flash($type, $text) {
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

// Alias used by the technician and profile pages
if (!function_exists('set_flash')) {
    function set_flash($type, $text) { flash($type, $text); }
}

function take_flash() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Password rules ---------- */
// config.php can change these. The defaults apply if it is not loaded.
if (!defined('PASSWORD_MIN_LENGTH'))  define('PASSWORD_MIN_LENGTH', 8);
if (!defined('PASSWORD_MIN_LETTERS')) define('PASSWORD_MIN_LETTERS', 1);
if (!defined('PASSWORD_MIN_NUMBERS')) define('PASSWORD_MIN_NUMBERS', 1);

function pw_count($n, $word) {
    return $n . ' ' . $word . ($n === 1 ? '' : 's');
}

// e.g. "at least 8 characters, with at least 1 letter and 1 number"
function password_rules_summary() {
    $need = [];
    if (PASSWORD_MIN_LETTERS > 0) $need[] = pw_count(PASSWORD_MIN_LETTERS, 'letter');
    if (PASSWORD_MIN_NUMBERS > 0) $need[] = pw_count(PASSWORD_MIN_NUMBERS, 'number');
    $s = 'at least ' . PASSWORD_MIN_LENGTH . ' characters';
    if ($need) $s .= ', with at least ' . implode(' and ', $need);
    return $s;
}

function validate_password($pw) {
    if (strlen($pw) > 72) {
        return 'Use 72 characters or fewer for your password.';
    }
    $letters = preg_match_all('/[A-Za-z]/', $pw);
    $numbers = preg_match_all('/[0-9]/', $pw);
    if (strlen($pw) < PASSWORD_MIN_LENGTH || $letters < PASSWORD_MIN_LETTERS || $numbers < PASSWORD_MIN_NUMBERS) {
        return 'Your password needs ' . password_rules_summary() . '.';
    }
    return null;
}

// The live checklist under a password field (auth.js turns each line green as it is met)
function render_password_rules($for = 'password') {
    ?>
    <ul class="pw-rules" data-pw-rules data-for="<?= e($for) ?>"
        data-min-length="<?= (int) PASSWORD_MIN_LENGTH ?>" data-min-letters="<?= (int) PASSWORD_MIN_LETTERS ?>"
        data-min-numbers="<?= (int) PASSWORD_MIN_NUMBERS ?>" data-summary="<?= e(password_rules_summary()) ?>">
        <li data-rule="length">At least <?= (int) PASSWORD_MIN_LENGTH ?> characters</li>
        <?php if (PASSWORD_MIN_LETTERS > 0): ?><li data-rule="letters">At least <?= e(pw_count(PASSWORD_MIN_LETTERS, 'letter')) ?></li><?php endif; ?>
        <?php if (PASSWORD_MIN_NUMBERS > 0): ?><li data-rule="numbers">At least <?= e(pw_count(PASSWORD_MIN_NUMBERS, 'number')) ?></li><?php endif; ?>
    </ul>
    <?php
}

/* ---------- Password reset ---------- */
function app_url() {
    if (APP_URL !== '') return rtrim(APP_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
}

/* ---------- Mobile numbers ---------- */
// Accepts "0917 123 4567", "+63 917 123 4567" and "09171234567"; returns digits only.
function normalize_phone($raw) {
    $d = preg_replace('/\D+/', '', (string) $raw);
    if (strlen($d) === 12 && strpos($d, '63') === 0) {
        $d = '0' . substr($d, 2);
    }
    return $d;
}

function valid_phone($digits) {
    return (bool) preg_match('/^\d{11}$/', (string) $digits);
}

// True when the text a user typed looks like a phone number rather than a username
function looks_like_phone($raw) {
    return (bool) preg_match('/^\+?[\d\s\-()]{7,}$/', (string) $raw);
}

// Sends a text message. SMS needs a provider (Semaphore, Twilio, Vonage, ...).
// Add the provider's API call here and return true when the message was accepted.
function send_sms($to, $message) {
    error_log('SMS not configured. Could not send to ' . $to);
    return false;
}

function send_reset_sms($to, $link) {
    return send_sms($to, APP_NAME . ': reset your password here (valid ' . RESET_MINUTES . ' minutes): ' . $link);
}

/* ---------- Icons ---------- */
function icon($name, $size = 20) {
    $p = [
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
        'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'monitor'  => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'cpu'      => '<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'phone'    => '<rect x="7" y="2" width="10" height="20" rx="2.5"/><path d="M11 18h2"/>',
        'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'  => '<path d="M3 3l18 18"/><path d="M10.6 5.1A10.9 10.9 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6A17.5 17.5 0 0 0 2 12s3.5 7 10 7a10.4 10.4 0 0 0 5.4-1.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'truck'    => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

// The eye button inside a password field. $for is the id of the password input it controls.
function pw_toggle($for) {
    return '<button type="button" class="pw-toggle" data-toggle-pw="' . e($for) . '" aria-pressed="false" aria-label="Show password">'
         . '<span class="eye-on">' . icon('eye', 18) . '</span>'
         . '<span class="eye-off">' . icon('eye-off', 18) . '</span>'
         . '</button>';
}

/* ---------- Shared layout ---------- */
function render_bg() {
    ?>
    <div class="auth-bg" aria-hidden="true">
        <svg class="circuit" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
            <g fill="none" stroke="currentColor" stroke-width="1.2">
                <path d="M0 640 H170 L220 590 H430 L480 640 H660"/>
                <path d="M0 170 H110 L160 220 H350 L400 170 H590"/>
                <path d="M90 800 V710 L140 660 V510 L190 460 H300"/>
                <path d="M310 0 V80 L360 130 H540 L580 90 H720"/>
                <path d="M720 800 V750 L760 710 H930 L970 750 H1200"/>
                <path d="M1200 120 H1060 L1020 160 H900"/>
            </g>
            <g fill="currentColor">
                <circle cx="660" cy="640" r="4"/><circle cx="590" cy="170" r="4"/><circle cx="300" cy="460" r="4"/>
                <circle cx="720" cy="90" r="4"/><circle cx="1200" cy="750" r="4"/><circle cx="900" cy="160" r="4"/>
                <circle cx="0" cy="640" r="4"/><circle cx="0" cy="170" r="4"/>
            </g>
            <g fill="none" stroke-width="2" stroke-linecap="round">
                <path class="pulse" pathLength="100" d="M0 640 H170 L220 590 H430 L480 640 H660"/>
                <path class="pulse" pathLength="100" d="M0 170 H110 L160 220 H350 L400 170 H590"/>
                <path class="pulse" pathLength="100" d="M90 800 V710 L140 660 V510 L190 460 H300"/>
                <path class="pulse" pathLength="100" d="M310 0 V80 L360 130 H540 L580 90 H720"/>
                <path class="pulse" pathLength="100" d="M720 800 V750 L760 710 H930 L970 750 H1200"/>
            </g>
        </svg>
    </div>
    <?php
}

function render_stage($mode) {
    $copy = [
        'login'    => ['Repairs that <em>come to you</em>.',
                       'Book a technician for a home visit or a remote session, and follow every step from your dashboard.'],
        'register' => ['Your tech trouble, <em>sorted</em> in minutes.',
                       'Create an account to schedule a repair, choose where it happens and watch it progress.'],
        'recover'  => ['Back in <em>in a minute</em>.',
                       'Enter the mobile number on your account and we\'ll text you a secure link to set a new password.'],
    ];
    [$headline, $sub] = $copy[$mode] ?? $copy['login'];
    ?>
    <section class="stage">
        <a class="brand" href="login.php"><span class="brand-mark" aria-hidden="true"></span><?= e(APP_NAME) ?></a>

        <div class="stage-copy">
            <h1><?= $headline ?></h1>
            <p><?= e($sub) ?></p>
        </div>

        <?php if ($mode === 'login'): ?>
            <div class="stage-visual">
                <div class="float-stack">
                    <div class="mock mock-booking">
                        <div class="mock-head">
                            <span class="chip chip-home">Home visit</span>
                            <span class="status-badge status-in-progress">In progress</span>
                        </div>
                        <h4>Laptop thermal system cleaning</h4>
                        <p>Acer Nitro V15, Tomorrow at 2:00 PM</p>
                        <ol class="stepper">
                            <li class="done"><span>Requested</span></li>
                            <li class="done"><span>Confirmed</span></li>
                            <li class="current"><span>In progress</span></li>
                            <li><span>Done</span></li>
                        </ol>
                    </div>
                    <div class="mock mock-tech">
                        <span class="pulse-dot" aria-hidden="true"></span>
                        <div><strong>Technician on the way</strong><small>Arriving in about 12 minutes</small></div>
                    </div>
                    <div class="mock mock-temp">
                        <span class="mock-icon"><?= icon('cpu') ?></span>
                        <div>
                            <small>CPU temperature</small>
                            <strong><s>94&deg;C</s> &rarr; 61&deg;C</strong>
                            <div class="temp-bar"><span></span></div>
                        </div>
                    </div>
                </div>
                <p class="stage-note">Sample preview of your dashboard</p>
            </div>

            <ul class="features">
                <li><span class="f-icon"><?= icon('home') ?></span><strong>Home visits</strong><small>We come to your door</small></li>
                <li><span class="f-icon"><?= icon('monitor') ?></span><strong>Online sessions</strong><small>Fixed remotely</small></li>
                <li><span class="f-icon"><?= icon('activity') ?></span><strong>Live tracking</strong><small>Follow every step</small></li>
            </ul>

        <?php elseif ($mode === 'register'): ?>
            <ol class="timeline">
                <li><span class="tl-icon"><?= icon('user') ?></span><div><strong>Create your account</strong><small>A username, a mobile number and a password</small></div></li>
                <li><span class="tl-icon"><?= icon('calendar') ?></span><div><strong>Pick a time and place</strong><small>Home visit or online, in 30-minute slots</small></div></li>
                <li><span class="tl-icon"><?= icon('activity') ?></span><div><strong>Follow the repair</strong><small>Status updates right on your dashboard</small></div></li>
            </ol>
            <div class="mock mock-static">
                <span class="pulse-dot" aria-hidden="true"></span>
                <div><strong>Technician on the way</strong><small>Sample update from your dashboard</small></div>
            </div>

        <?php else: ?>
            <ol class="timeline">
                <li><span class="tl-icon"><?= icon('phone') ?></span><div><strong>Enter your mobile number</strong><small>The one on your account</small></div></li>
                <li><span class="tl-icon"><?= icon('key') ?></span><div><strong>Open your secure link</strong><small>It works for <?= (int) RESET_MINUTES ?> minutes</small></div></li>
                <li><span class="tl-icon"><?= icon('lock') ?></span><div><strong>Choose a new password</strong><small>Then log in as usual</small></div></li>
            </ol>
            <div class="mock mock-static">
                <span class="mock-icon"><?= icon('shield') ?></span>
                <div><strong>Single-use link</strong><small>It stops working once you use it</small></div>
            </div>
        <?php endif; ?>
    </section>
    <?php
}