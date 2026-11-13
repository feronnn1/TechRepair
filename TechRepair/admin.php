<?php
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'functions.php';

if (!defined('APP_NAME')) define('APP_NAME', 'TechRepair');
date_default_timezone_set('Asia/Manila');

/* ---------- Access: admins only ---------- */
if (!isset($_SESSION['user_id'])) {
    redirect('login.php');
}
$role = $_SESSION['role'] ?? '';
if ($role !== 'admin') {
    redirect($role === 'technician' ? 'tech_dashboard.php' : 'dashboard.php');
}

$username = $_SESSION['username'] ?? 'Admin';
$statuses = ['Pending', 'Confirmed', 'In Progress', 'Completed', 'Cancelled'];
$needs_tech = ['Confirmed', 'In Progress', 'Completed']; // statuses that must have a technician

function admin_msg($type, $text) {
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

/* ---------- Load technicians (needed to validate assignments) ---------- */
$techs = [];
$tech_read_ok = true;
$r = false;
try {
    $r = $conn->query("SELECT username, phone, first_name, last_name FROM users WHERE role = 'technician' ORDER BY username");
} catch (Throwable $ex) { $r = false; }
if (!$r) {
    try {
        $r = $conn->query("SELECT username, phone FROM users WHERE role = 'technician' ORDER BY username");
    } catch (Throwable $ex) { $r = false; }
}
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $techs[$row['username']] = $row;
    }
} else {
    $tech_read_ok = false;
}

/* ---------- Actions ---------- */
$tech_error = '';
$tech_old   = ['username' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_valid()) {
        admin_msg('error', 'Your session expired. Please try again.');
        redirect('admin.php');
    }

    if ($action === 'update_booking') {
        $id     = (int) ($_POST['booking_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $tech   = trim($_POST['technician'] ?? '');
        $err    = null;

        if ($id <= 0) {
            $err = 'That booking could not be found.';
        } elseif (!in_array($status, $statuses, true)) {
            $err = 'Choose a valid status.';
        } elseif ($tech !== '' && !isset($techs[$tech])) {
            $err = 'That technician account no longer exists. Choose another technician.';
        } elseif ($tech === '' && in_array($status, $needs_tech, true)) {
            $err = 'Assign a technician before setting the status to ' . $status . '.';
        }

        if ($err) {
            admin_msg('error', $err);
        } else {
            $tech_val = $tech === '' ? null : $tech;
            $stmt = $conn->prepare("UPDATE bookings SET status = ?, technician = ? WHERE id = ?");
            $stmt->bind_param("ssi", $status, $tech_val, $id);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            admin_msg($changed > 0 ? 'success' : 'error', $changed > 0 ? 'Booking updated.' : 'Nothing was changed.');
        }
        redirect('admin.php');
    }

    if ($action === 'add_technician') {
        $u  = trim($_POST['username'] ?? '');
        $ph = normalize_phone($_POST['phone'] ?? '');
        $pw = $_POST['password'] ?? '';
        $tech_old = ['username' => $u, 'phone' => $ph];

        if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $u) || !preg_match('/[A-Za-z]/', $u)) {
            $tech_error = 'Username must be 3 to 30 characters with at least one letter: letters, numbers, dots, dashes or underscores.';
        } elseif (!valid_phone($ph)) {
            $tech_error = 'Enter a valid mobile number: exactly 11 digits.';
        } elseif ($pw_err = validate_password($pw)) {
            $tech_error = $pw_err;
        } else {
            $stmt = $conn->prepare("SELECT username, phone FROM users WHERE username = ? OR phone = ?");
            $stmt->bind_param("ss", $u, $ph);
            $stmt->execute();
            $dups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($dups as $d) {
                if (strcasecmp($d['username'], $u) === 0) { $tech_error = 'That username is already taken.'; break; }
                if ($d['phone'] !== null && $d['phone'] === $ph) { $tech_error = 'That mobile number is already registered.'; break; }
            }

            if (!$tech_error) {
                try {
                    $hash = password_hash($pw, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (username, phone, password, role) VALUES (?, ?, ?, 'technician')");
                    $stmt->bind_param("sss", $u, $ph, $hash);
                    $stmt->execute();
                    $stmt->close();
                    admin_msg('success', 'Technician account created for ' . $u . '. Share the login details with them.');
                    redirect('admin.php');
                } catch (mysqli_sql_exception $ex) {
                    $tech_error = ($ex->getCode() === 1062) ? 'That username or mobile number is already registered.' : 'We couldn\'t create that account. Check that the users table has role and phone columns.';
                }
            }
        }
    }
}

$flash = take_flash();

/* ---------- Load bookings ---------- */
$bookings = [];
$res = false;
try {
    $res = $conn->query(
        "SELECT b.id, b.device_model, b.service_type, b.service_mode, b.address, b.issue_notes, b.technician,
                b.appointment_date, b.status,
                u.username AS client_username, u.first_name, u.last_name, u.phone
         FROM bookings b LEFT JOIN users u ON u.id = b.user_id"
    );
} catch (Throwable $ex) { $res = false; }
if (!$res) {
    $res = $conn->query(
        "SELECT b.id, b.device_model, b.service_type, b.service_mode, b.address, b.issue_notes, b.technician,
                b.appointment_date, b.status, u.username AS client_username
         FROM bookings b LEFT JOIN users u ON u.id = b.user_id"
    );
}

$now    = new DateTime('now');
$counts = array_fill_keys($statuses, 0);
$tech_stats = [];
foreach ($techs as $name => $_) {
    $tech_stats[$name] = ['active' => 0, 'done' => 0, 'pending' => 0];
}

while ($row = $res->fetch_assoc()) {
    $row['dt'] = new DateTime($row['appointment_date']);
    $full = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $row['client'] = $full !== '' ? $full : ($row['client_username'] ?? 'Deleted user');
    $row['late'] = $row['dt'] < $now && in_array($row['status'], ['Pending', 'Confirmed'], true);

    if (isset($counts[$row['status']])) $counts[$row['status']]++;

    $t = $row['technician'] ?? '';
    if ($t !== '' && isset($tech_stats[$t])) {
        if (in_array($row['status'], ['Confirmed', 'In Progress'], true)) $tech_stats[$t]['active']++;
        elseif ($row['status'] === 'Completed') $tech_stats[$t]['done']++;
        elseif ($row['status'] === 'Pending') $tech_stats[$t]['pending']++;
    }
    $bookings[] = $row;
}

// Needs-attention first, then by date; finished jobs newest first
$rank = array_flip($statuses);
usort($bookings, function ($a, $b) use ($rank) {
    if ($a['status'] !== $b['status']) return $rank[$a['status']] <=> $rank[$b['status']];
    return in_array($a['status'], ['Completed', 'Cancelled'], true) ? $b['dt'] <=> $a['dt'] : $a['dt'] <=> $b['dt'];
});

$total       = count($bookings);
$active      = $counts['Confirmed'] + $counts['In Progress'];
$pending     = $counts['Pending'];
$default_tab = $pending > 0 ? 'Pending' : 'all';

function status_slug($s) { return strtolower(str_replace(' ', '-', $s)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-cols { display: grid; grid-template-columns: minmax(0, 8fr) minmax(290px, 4fr); gap: 24px; align-items: start; }
        @media (max-width: 960px) { .admin-cols { grid-template-columns: 1fr; } }

        .tabs { overflow-x: auto; max-width: 100%; }
        .tab { flex: none; }
        .tab .count { margin-left: 6px; padding: 1px 7px; border-radius: 999px; background: rgba(255,255,255,0.08); font-size: 0.72rem; }

        .admin-search { margin-bottom: 8px; }
        .admin-note { color: #64748b; font-size: 0.8rem; margin-bottom: 20px; }

        .booking h4 .by { font-family: 'Inter', sans-serif; font-size: 0.85rem; color: var(--muted); font-weight: 400; }
        .chip-late { color: #fbbf24; background: rgba(251,191,36,0.1); border-color: rgba(251,191,36,0.35); }

        .admin-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--line); }
        .admin-form .mini { flex: 1 1 150px; margin: 0; }
        .admin-form .mini span { display: block; font-size: 0.75rem; color: #64748b; margin-bottom: 6px; }
        .admin-form select { padding: 11px 38px 11px 12px; font-size: 0.85rem; }
        .admin-form button { width: auto; padding: 11px 22px; font-size: 0.85rem; }

        .tech-list { list-style: none; display: grid; gap: 12px; margin-bottom: 20px; }
        .tech-list li { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 14px; background: rgba(0,0,0,0.22); border: 1px solid var(--line); }
        .tech-list .avatar { flex: none; }
        .tech-list strong { display: block; color: #fff; font-size: 0.92rem; font-weight: 600; }
        .tech-list small { display: block; color: var(--muted); font-size: 0.78rem; }
        .tech-list .tech-mail { color: #64748b; word-break: break-all; }

        .add-tech { border-top: 1px solid var(--line); padding-top: 16px; }
        .add-tech summary { cursor: pointer; color: #a5b4fc; font-size: 0.9rem; font-weight: 500; margin-bottom: 14px; }
        .add-tech summary:hover { color: #fff; }
        .add-tech button[type="submit"] { padding: 13px; font-size: 0.9rem; }
        .setup-note { padding: 14px 18px; border-radius: 14px; margin-bottom: 20px; color: #fbbf24; background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.3); }
    </style>
</head>
<body class="dash">
<div class="dash-shell">

    <header class="topbar">
        <a class="brand" href="admin.php"><span class="brand-mark" aria-hidden="true"></span><?= e(APP_NAME) ?> Admin</a>
        <div class="topbar-user">
            <span class="avatar" aria-hidden="true"><?= e(strtoupper(mb_substr($username, 0, 1))) ?></span>
            <span class="topbar-name"><?= e($username) ?> (Admin)</span>
            <a href="board.php" style="display:inline-block;padding:8px 16px;border-radius:8px;font-size:.9rem;text-decoration:none;background:linear-gradient(135deg,#4f46e5,#9333ea);color:#fff;">Repair board</a>
            <a href="logout.php" class="logout-link">Log out</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="toast <?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
    <?php endif; ?>
    <?php if (!$tech_read_ok): ?>
        <div class="setup-note" role="alert">Couldn't read technician accounts. Check that the <code>users</code> table has a <code>role</code> column (see admin_setup.sql).</div>
    <?php endif; ?>

    <section class="hero">
        <div class="hero-main">
            <p class="hero-greet">Admin console</p>
            <?php if ($pending > 0): ?>
                <h1 class="hero-when"><?= $pending ?> <span><?= $pending === 1 ? 'request is' : 'requests are' ?> pending</span></h1>
                <p class="hero-what">Assign a technician and confirm each one so customers see their repair move forward.</p>
            <?php else: ?>
                <h1 class="hero-when">All clear <span>nothing pending</span></h1>
                <p class="hero-what">Every request has been handled. New bookings will show up here.</p>
            <?php endif; ?>
        </div>
        <dl class="hero-stats">
            <div><dt>Active repairs</dt><dd><?= $active ?></dd></div>
            <div><dt>Completed repairs</dt><dd><?= $counts['Completed'] ?></dd></div>
            <div><dt>All bookings</dt><dd><?= $total ?></dd></div>
        </dl>
    </section>

    <div class="admin-cols">

        <!-- Bookings -->
        <section class="panel">
            <div class="panel-head">
                <h3>Bookings</h3>
                <div class="tabs" role="tablist">
                    <button type="button" class="tab<?= $default_tab === 'all' ? ' is-active' : '' ?>" data-filter="all">All <span class="count"><?= $total ?></span></button>
                    <?php foreach ($statuses as $s): ?>
                        <button type="button" class="tab<?= $default_tab === $s ? ' is-active' : '' ?>" data-filter="<?= e($s) ?>"><?= e($s) ?> <span class="count"><?= $counts[$s] ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="admin-search">
                <input type="text" id="q" placeholder="Search client, device, service or technician" autocomplete="off">
            </div>
            <p class="admin-note">Assigning a technician to a Pending request reserves it for them, and only that technician can accept it.</p>

            <div class="booking-list" id="bookingList">
                <?php foreach ($bookings as $b):
                    $cur_tech = $b['technician'] ?? '';
                    $names = array_keys($techs);
                    if ($cur_tech !== '' && !isset($techs[$cur_tech])) $names[] = $cur_tech;
                    $haystack = mb_strtolower($b['client'] . ' ' . ($b['client_username'] ?? '') . ' ' . $b['device_model'] . ' ' . $b['service_type'] . ' ' . $cur_tech . ' ' . ($b['phone'] ?? ''));
                ?>
                    <article class="booking" data-status="<?= e($b['status']) ?>" data-search="<?= e($haystack) ?>">
                        <div class="booking-top">
                            <div>
                                <h4><?= e($b['device_model']) ?> <span class="by">for <?= e($b['client']) ?></span></h4>
                                <p class="booking-service"><?= e($b['service_type']) ?></p>
                            </div>
                            <span class="status-badge status-<?= e(status_slug($b['status'])) ?>"><?= e($b['status']) ?></span>
                        </div>

                        <ul class="booking-meta">
                            <li><?= e($b['dt']->format('D, M j')) ?> at <?= e($b['dt']->format('g:i A')) ?></li>
                            <li class="chip chip-<?= e($b['service_mode']) ?>"><?= $b['service_mode'] === 'online' ? 'Online session' : 'Home visit' ?></li>
                            <?php if ($b['late']): ?><li class="chip chip-late">Past due</li><?php endif; ?>
                        </ul>

                        <?php if ($b['service_mode'] === 'home' && $b['address']): ?>
                            <p class="booking-line"><span>Address</span><?= e($b['address']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($b['phone'])): ?>
                            <p class="booking-line"><span>Contact</span><a href="tel:<?= e($b['phone']) ?>" style="color:#c4b5fd;"><?= e($b['phone']) ?></a></p>
                        <?php endif; ?>
                        <?php if ($b['issue_notes']): ?>
                            <p class="booking-line"><span>Notes</span><?= e($b['issue_notes']) ?></p>
                        <?php endif; ?>

                        <form method="POST" action="" class="admin-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_booking">
                            <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">

                            <label class="mini">
                                <span>Technician</span>
                                <select name="technician">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($names as $n): ?>
                                        <option value="<?= e($n) ?>" <?= $n === $cur_tech ? 'selected' : '' ?>><?= e($n) ?><?= isset($techs[$n]) ? '' : ' (account removed)' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="mini">
                                <span>Status</span>
                                <select name="status">
                                    <?php foreach ($statuses as $s): ?>
                                        <option value="<?= e($s) ?>" <?= $s === $b['status'] ? 'selected' : '' ?>><?= e($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <button type="submit">Save</button>
                        </form>
                    </article>
                <?php endforeach; ?>

                <p class="empty" id="emptyState" hidden>No bookings match.</p>
            </div>
        </section>

        <!-- Technicians -->
        <aside class="panel">
            <h3>Technicians</h3>

            <?php if ($techs): ?>
                <ul class="tech-list">
                    <?php foreach ($techs as $name => $t):
                        $full = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
                        $st = $tech_stats[$name];
                    ?>
                        <li>
                            <span class="avatar" aria-hidden="true"><?= e(strtoupper(mb_substr($name, 0, 1))) ?></span>
                            <div>
                                <strong><?= e($full !== '' ? $full : $name) ?></strong>
                                <small><?= $st['active'] ?> active, <?= $st['done'] ?> completed<?= $st['pending'] ? ', ' . $st['pending'] . ' reserved' : '' ?></small>
                                <?php if ($full !== ''): ?><small class="tech-mail"><?= e($name) ?></small><?php endif; ?>
                                <?php if (!empty($t['phone'])): ?><small class="tech-mail"><?= e($t['phone']) ?></small><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="empty" style="margin-bottom:20px;">No technician accounts yet. Add one below.</p>
            <?php endif; ?>

            <details class="add-tech" <?= $tech_error ? 'open' : '' ?>>
                <summary>Add a technician</summary>
                <?php if ($tech_error): ?>
                    <p class="form-error" role="alert"><?= e($tech_error) ?></p>
                <?php endif; ?>
                <form method="POST" action="" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_technician">

                    <div class="form-group">
                        <label for="t_username">Username</label>
                        <input type="text" id="t_username" name="username" required minlength="3" maxlength="30" autocomplete="off" value="<?= e($tech_old['username']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="t_phone">Mobile number</label>
                        <input type="tel" id="t_phone" name="phone" required inputmode="numeric" minlength="11" maxlength="11" placeholder="09171234567" autocomplete="off" value="<?= e($tech_old['phone']) ?>">
                        <p class="hint">11 digits only.</p>
                    </div>
                    <div class="form-group">
                        <label for="t_password">Temporary password</label>
                        <input type="password" id="t_password" name="password" required minlength="8" maxlength="72" autocomplete="new-password">
                        <p class="hint"><?= e(ucfirst(password_rules_summary())) ?>. They can change it later with Forgot password.</p>
                    </div>
                    <button type="submit">Create technician</button>
                </form>
            </details>
        </aside>
    </div>
</div>

<script>
(function () {
    var tabs  = document.querySelectorAll('.tab');
    var cards = document.querySelectorAll('.booking');
    var empty = document.getElementById('emptyState');
    var q     = document.getElementById('q');
    var filter = <?= json_encode($default_tab) ?>;

    function apply() {
        var term = q.value.trim().toLowerCase(), shown = 0;
        cards.forEach(function (c) {
            var okStatus = filter === 'all' || c.dataset.status === filter;
            var okSearch = !term || c.dataset.search.indexOf(term) !== -1;
            c.hidden = !(okStatus && okSearch);
            if (!c.hidden) shown++;
        });
        empty.hidden = shown > 0;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            filter = tab.dataset.filter;
            apply();
        });
    });
    q.addEventListener('input', apply);

    // Ask before cancelling a booking
    document.querySelectorAll('.admin-form').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            var s = f.querySelector('select[name="status"]');
            if (s.value === 'Cancelled' && s.querySelector('option[selected]').value !== 'Cancelled') {
                if (!confirm('Cancel this booking? The customer will see it as cancelled.')) ev.preventDefault();
            }
        });
    });

    // Mobile number: digits only
    document.querySelectorAll('input[type="tel"]').forEach(function (el) {
        el.addEventListener('input', function () {
            var clean = el.value.replace(/\D/g, '');
            if (clean !== el.value) el.value = clean;
        });
    });

    apply();
})();
</script>
</body>
</html> 