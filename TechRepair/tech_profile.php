<?php
session_start();
require_once 'db.php';
require_once 'functions.php';

if (!defined('APP_NAME')) define('APP_NAME', 'TechRepair');

// Technicians only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'technician') {
    redirect('login.php');
}

$user_id  = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';

// Keep this list in sync with the services in dashboard.php
$services = [
    'Desktop Reformatting',
    'Laptop Thermal System Cleaning',
    'OS Diagnostics & Repair',
    'Component Upgrade',
    'Virus & Malware Removal',
    'Data Recovery',
    'Other Repair',
];

$flash       = take_flash();
$error       = '';
$setup_error = '';

$p = [
    'first_name' => '', 'last_name' => '', 'phone' => '',
    'headline' => '', 'bio' => '', 'experience_years' => '', 'service_area' => '',
    'specialties' => [],
];

/* ---------- Load the current profile ---------- */
try {
    $stmt = $conn->prepare("SELECT first_name, last_name, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $p['first_name'] = $u['first_name'] ?? '';
    $p['last_name']  = $u['last_name'] ?? '';
    $p['phone']      = $u['phone'] ?? '';

    $stmt = $conn->prepare("SELECT headline, bio, specialties, experience_years, service_area FROM technician_profiles WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $p['headline']         = $row['headline'] ?? '';
        $p['bio']              = $row['bio'] ?? '';
        $p['experience_years'] = $row['experience_years'] ?? '';
        $p['service_area']     = $row['service_area'] ?? '';
        $p['specialties']      = array_values(array_filter(explode('|', $row['specialties'] ?? '')));
    }
} catch (mysqli_sql_exception $ex) {
    $setup_error = 'The profile tables are not set up yet. Run the SQL in migration.sql in phpMyAdmin, then reload this page.';
}

/* ---------- Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$setup_error) {
    $clean = function ($s) { return preg_replace('/\s+/u', ' ', trim((string) $s)); };

    $p['first_name']       = $clean($_POST['first_name'] ?? '');
    $p['last_name']        = $clean($_POST['last_name'] ?? '');
    $p['phone']            = trim((string) ($_POST['phone'] ?? ''));
    $p['headline']         = $clean($_POST['headline'] ?? '');
    $p['service_area']     = $clean($_POST['service_area'] ?? '');
    $p['bio']              = trim(str_replace("\r\n", "\n", (string) ($_POST['bio'] ?? '')));
    $p['experience_years'] = trim((string) ($_POST['experience_years'] ?? ''));
    $picked                = array_values(array_intersect($services, (array) ($_POST['specialties'] ?? [])));
    $p['specialties']      = $picked;

    $name_ok = function ($n) {
        return mb_strlen($n) >= 1 && mb_strlen($n) <= 50 && preg_match('/^\p{L}[\p{L}\p{M} .\'-]*$/u', $n);
    };

    $exp = null;
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$name_ok($p['first_name'])) {
        $error = 'Enter your first name (letters only, up to 50 characters).';
    } elseif (!$name_ok($p['last_name'])) {
        $error = 'Enter your last name (letters only, up to 50 characters).';
    } elseif (!preg_match('/^\d{11}$/', $p['phone'])) {
        $error = 'Enter a valid mobile number: digits only, exactly 11 digits.';
    } elseif (mb_strlen($p['headline']) > 100) {
        $error = 'Keep the headline to 100 characters or fewer.';
    } elseif (mb_strlen($p['service_area']) > 120) {
        $error = 'Keep the service area to 120 characters or fewer.';
    } elseif (mb_strlen($p['bio']) > 500) {
        $error = 'Keep the about text to 500 characters or fewer.';
    } elseif ($p['experience_years'] !== '' && !preg_match('/^\d{1,2}$/', $p['experience_years'])) {
        $error = 'Years of experience must be a whole number from 0 to 99.';
    } else {
        if ($p['experience_years'] !== '') $exp = (int) $p['experience_years'];
        $spec = implode('|', $picked);

        try {
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?");
            $stmt->bind_param("sssi", $p['first_name'], $p['last_name'], $p['phone'], $user_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO technician_profiles (user_id, headline, bio, specialties, experience_years, service_area)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE headline = VALUES(headline), bio = VALUES(bio), specialties = VALUES(specialties),
                                         experience_years = VALUES(experience_years), service_area = VALUES(service_area)"
            );
            $stmt->bind_param("isssis", $user_id, $p['headline'], $p['bio'], $spec, $exp, $p['service_area']);
            $stmt->execute();
            $stmt->close();

            set_flash('success', 'Profile saved. Clients will see it when they book you.');
            redirect('tech_profile.php');
        } catch (mysqli_sql_exception $ex) {
            $error = 'We couldn\'t save your profile. Please try again.';
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
        .profile-cols { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 24px; align-items: start; }
        @media (max-width: 860px) { .profile-cols { grid-template-columns: 1fr; } }
        .name-row, .two-col { display: flex; gap: 12px; }
        .name-row .form-group, .two-col .form-group { flex: 1; min-width: 0; }
        .dash input[type="number"], .dash input[type="search"] { width: 100%; padding: 14px 16px; background: var(--surface); border: 1px solid transparent; border-radius: 12px; color: #fff; outline: none; font: inherit; appearance: none; -moz-appearance: textfield; box-shadow: inset 4px 4px 8px rgba(0,0,0,0.5), inset -4px -4px 8px rgba(255,255,255,0.02); }
        .dash input[type="number"]:focus { border: 1px solid rgba(168, 85, 247, 0.4); }
        .spec-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        @media (max-width: 520px) { .spec-grid { grid-template-columns: 1fr; } }
        .spec-pick { position: relative; margin: 0; cursor: pointer; }
        .spec-pick input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
        .spec-pick span { display: block; padding: 10px 12px; border-radius: 12px; background: var(--surface); border: 1px solid var(--line); font-size: .85rem; color: #cbd5e1; transition: border-color .2s, box-shadow .2s; }
        .spec-pick input:checked + span { border-color: rgba(168, 85, 247, .6); box-shadow: 0 0 0 1px rgba(168, 85, 247, .4); color: #fff; }
        .spec-pick input:focus-visible + span { outline: 2px solid var(--sky); outline-offset: 2px; }
        .counter { text-align: right; font-size: .75rem; color: #64748b; margin-top: 6px; }
        .setup-note { padding: 14px 18px; border-radius: 14px; margin-bottom: 20px; color: #fbbf24; background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.3); }

        /* Preview card (matches what clients see when booking) */
        .preview-label { font-size: .78rem; color: #94a3b8; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px; }
        .tech-info { padding: 18px; border-radius: 16px; background: rgba(99,102,241,.07); border: 1px solid rgba(99,102,241,.25); }
        .tech-info-head { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
        .tech-avatar { width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center; font-weight: 600; font-size: 1.1rem; color: #fff; background: linear-gradient(135deg, #6366f1, #a855f7); flex: none; }
        .tech-info-head strong { display: block; color: #fff; }
        .tech-info-head small { color: #94a3b8; }
        .tech-headline { color: #cbd5e1; font-size: .9rem; margin-bottom: 10px; }
        .tech-facts { list-style: none; display: flex; flex-wrap: wrap; gap: 6px 16px; margin-bottom: 10px; font-size: .82rem; color: #94a3b8; }
        .tech-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .tech-chips span { padding: 3px 10px; border-radius: 999px; font-size: .75rem; color: #c4b5fd; background: rgba(168,85,247,.14); border: 1px solid rgba(168,85,247,.35); }
        .tech-bio { color: #cbd5e1; font-size: .87rem; line-height: 1.5; white-space: pre-line; }
    </style>
</head>
<body class="dash">
<div class="dash-shell">
    <header class="topbar">
        <a class="brand" href="tech_dashboard.php"><span class="brand-mark" aria-hidden="true"></span><?= e(APP_NAME) ?> Workspace</a>
        <div class="topbar-user">
            <span class="topbar-name"><?= e($display) ?> (Tech)</span>
            <div class="avatar"><?= e(strtoupper(mb_substr($display, 0, 1))) ?></div>
            <a href="tech_dashboard.php" class="nav-link">Dashboard</a>
            <a href="logout.php" class="logout-link">Log out</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="toast <?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
    <?php endif; ?>
    <?php if ($setup_error): ?>
        <div class="setup-note" role="alert"><?= e($setup_error) ?></div>
    <?php endif; ?>

    <div class="profile-cols">
        <section class="panel">
            <h3>My technician profile</h3>
            <p class="hint" style="margin-top: -12px; margin-bottom: 20px;">Clients see this when they pick you for a booking.</p>

            <?php if ($error): ?>
                <p class="form-error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>

            <form method="POST" action="" id="profileForm" novalidate>
                <?= csrf_field() ?>

                <div class="name-row">
                    <div class="form-group">
                        <label for="first_name">First name</label>
                        <input type="text" id="first_name" name="first_name" maxlength="50" required value="<?= e($p['first_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last name</label>
                        <input type="text" id="last_name" name="last_name" maxlength="50" required value="<?= e($p['last_name']) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="phone">Mobile number</label>
                    <input type="tel" id="phone" name="phone" required inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" autocomplete="tel" placeholder="09171234567" value="<?= e($p['phone']) ?>">
                    <p class="hint">11 digits only. Used to recover your account and shared with clients whose jobs you accept.</p>
                </div>

                <div class="form-group">
                    <label for="headline">Headline <span class="optional">(optional)</span></label>
                    <input type="text" id="headline" name="headline" maxlength="100" placeholder="e.g. Laptop repair specialist" value="<?= e($p['headline']) ?>">
                </div>

                <div class="two-col">
                    <div class="form-group">
                        <label for="experience_years">Years of experience</label>
                        <input type="number" id="experience_years" name="experience_years" min="0" max="99" step="1" placeholder="e.g. 5" value="<?= e($p['experience_years']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="service_area">Service area</label>
                        <input type="text" id="service_area" name="service_area" maxlength="120" placeholder="e.g. Rizal, Marikina" value="<?= e($p['service_area']) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Specialties</label>
                    <div class="spec-grid">
                        <?php foreach ($services as $s): ?>
                            <label class="spec-pick">
                                <input type="checkbox" name="specialties[]" value="<?= e($s) ?>" <?= in_array($s, $p['specialties'], true) ? 'checked' : '' ?>>
                                <span><?= e($s) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label for="bio">About you <span class="optional">(optional)</span></label>
                    <textarea id="bio" name="bio" rows="5" maxlength="500" placeholder="Tell clients about your experience and how you work."><?= e($p['bio']) ?></textarea>
                    <p class="counter"><span id="bioCount">0</span>/500</p>
                </div>

                <button type="submit" <?= $setup_error ? 'disabled' : '' ?>>Save profile</button>
            </form>
        </section>

        <aside>
            <p class="preview-label">How clients see you</p>
            <div class="tech-info" id="preview">
                <div class="tech-info-head">
                    <span class="tech-avatar" id="pvAvatar" aria-hidden="true"></span>
                    <div><strong id="pvName"></strong><small>@<?= e($username) ?></small></div>
                </div>
                <p class="tech-headline" id="pvHeadline" hidden></p>
                <ul class="tech-facts">
                    <li id="pvExp" hidden></li>
                    <li id="pvArea" hidden></li>
                </ul>
                <div class="tech-chips" id="pvSpec"></div>
                <p class="tech-bio" id="pvBio"></p>
            </div>
        </aside>
    </div>
</div>

<script>
(function () {
    function $(id) { return document.getElementById(id); }
    var f = {
        first: $('first_name'), last: $('last_name'), headline: $('headline'),
        exp: $('experience_years'), area: $('service_area'), bio: $('bio')
    };
    var username = <?= json_encode($username, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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

    function render() {
        var name = (f.first.value.trim() + ' ' + f.last.value.trim()).trim() || username;
        $('pvAvatar').textContent = name.charAt(0).toUpperCase();
        $('pvName').textContent = name;

        var hl = $('pvHeadline');
        hl.textContent = f.headline.value.trim(); hl.hidden = !hl.textContent;

        var years = f.exp.value.trim(), ex = $('pvExp');
        ex.textContent = years !== '' ? years + (years === '1' ? ' year' : ' years') + ' of experience' : ''; ex.hidden = years === '';

        var area = f.area.value.trim(), ar = $('pvArea');
        ar.textContent = area ? 'Serves ' + area : ''; ar.hidden = !area;

        var sp = $('pvSpec'); sp.innerHTML = '';
        document.querySelectorAll('input[name="specialties[]"]:checked').forEach(function (c) {
            var s = document.createElement('span'); s.textContent = c.value; sp.appendChild(s);
        });

        var bio = f.bio.value.trim();
        $('pvBio').textContent = bio || 'Add a short bio so clients know what to expect.';
        $('bioCount').textContent = f.bio.value.length;
    }

    $('profileForm').addEventListener('input', render);
    $('profileForm').addEventListener('change', render);
    render();
})();
</script>
</body>
</html>