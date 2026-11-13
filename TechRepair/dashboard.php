<?php
session_start();
require 'db.php';

if (isset($_SESSION['role']) && $_SESSION['role'] === 'technician') {
    header("Location: tech_dashboard.php");
    exit();
}

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id  = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'there';
$brand    = 'TechRepair';

/* ---------- Config ---------- */
const MAX_PER_SLOT  = 2;
const LEAD_HOURS    = 2;
const BOOKING_MINUTES = 60;

$services = [
    'Desktop Reformatting'           => 'Clean OS install, drivers and your essential apps. Back up your files first.',
    'Laptop Thermal System Cleaning' => 'Dust removal, fresh thermal paste and fan check to stop overheating.',
    'OS Diagnostics & Repair'        => 'Slow, crashing or won\'t boot? We find the cause and fix it.',
    'Component Upgrade'              => 'RAM, SSD or other parts installed and tested.',
    'Virus & Malware Removal'        => 'Full scan, cleanup and protection setup.',
    'Data Recovery'                  => 'Attempt to recover files from failing drives or accidental deletion.',
    'Other Repair'                   => 'Any other computer or laptop repair not listed above. We\'ll assess and quote.'
];

$steps = ['Pending' => 'Requested', 'Confirmed' => 'Confirmed', 'In Progress' => 'In progress', 'Completed' => 'Done'];

/* ---------- Helpers ---------- */
function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function flash($type, $text) { $_SESSION['flash'] = ['type' => $type, 'text' => $text]; }
function back() { header("Location: dashboard.php"); exit(); }

function day_label(DateTime $d) {
    $today = new DateTime('today');
    $that  = (clone $d)->setTime(0, 0);
    $diff  = (int) $today->diff($that)->format('%r%a');
    if ($diff === 0) return 'Today';
    if ($diff === 1) return 'Tomorrow';
    if ($diff > 1 && $diff < 7) return $d->format('l');
    return $d->format('D, M j');
}

/* ---------- Fetch Reviews for Technician Profiles ---------- */
$reviews_data = [];
try {
    $rev_query = $conn->query("SELECT r.technician_username, r.rating, r.feedback, r.created_at, u.username as client_username, u.first_name FROM technician_reviews r JOIN users u ON r.client_id = u.id ORDER BY r.created_at DESC");
    if ($rev_query) {
        while ($r = $rev_query->fetch_assoc()) {
            $client = !empty($r['first_name']) ? $r['first_name'] : $r['client_username'];
            $reviews_data[$r['technician_username']][] = [
                'client'   => $client,
                'rating'   => (int)$r['rating'],
                'feedback' => $r['feedback'],
                'date'     => (new DateTime($r['created_at']))->format('M j, Y')
            ];
        }
    }
} catch (Throwable $ex) {}

/* ---------- Fetch Discussion Comments ---------- */
$comments_data = [];
try {
    $c_res = $conn->query("SELECT c.booking_id, c.comment, c.created_at, u.username, u.role FROM booking_comments c JOIN users u ON c.user_id = u.id ORDER BY c.created_at ASC");
    if ($c_res) {
        while ($c = $c_res->fetch_assoc()) {
            $comments_data[$c['booking_id']][] = $c;
        }
    }
} catch (Throwable $ex) {}

/* ---------- Technician accounts ---------- */
$technicians = [];
$tech_info   = [];
$tech_names  = [];

try {
    $tres = $conn->query(
        "SELECT u.username, u.first_name, u.last_name, p.headline, p.bio, p.specialties, p.experience_years, p.service_area
         FROM users u LEFT JOIN technician_profiles p ON p.user_id = u.id
         WHERE u.role = 'technician' ORDER BY u.first_name ASC, u.username ASC"
    );
} catch (mysqli_sql_exception $ex) {
    $tres = $conn->query("SELECT username FROM users WHERE role = 'technician' ORDER BY username ASC");
}
while ($t = $tres->fetch_assoc()) {
    $full = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
    $t['display']   = $full !== '' ? $full : $t['username'];
    $t['spec_list'] = !empty($t['specialties']) ? array_values(array_filter(explode('|', $t['specialties']))) : [];
    
    $tn = $t['username'];
    $t['review_count'] = isset($reviews_data[$tn]) ? count($reviews_data[$tn]) : 0;
    $t['avg_rating'] = 0;
    if ($t['review_count'] > 0) {
        $sum = array_sum(array_column($reviews_data[$tn], 'rating'));
        $t['avg_rating'] = round($sum / $t['review_count'], 1);
    }
    
    $technicians[]               = $tn;
    $tech_names[$tn]             = $t['display'];
    $tech_info[$tn]              = $t;
}

function overlap_peak(array $starts, int $t) {
    $dur    = BOOKING_MINUTES * 60;
    $points = [$t];
    foreach ($starts as $s) {
        if ($s > $t && $s < $t + $dur) $points[] = $s;
    }
    $peak = 0;
    foreach ($points as $p) {
        $n = 0;
        foreach ($starts as $s) {
            if ($s <= $p && $p < $s + $dur) $n++;
        }
        $peak = max($peak, $n);
    }
    return $peak;
}

function tech_name($u) {
    global $tech_names;
    return $tech_names[$u] ?? $u;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ---------- Handle actions (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('error', 'Your session expired. Please try again.');
        back();
    }

    $action = $_POST['action'] ?? '';

    // Handle Comment Submission
    if ($action === 'add_comment') {
        $booking_id = (int)$_POST['booking_id'];
        $comment = trim($_POST['comment']);
        if ($comment !== '') {
            $chk = $conn->prepare("SELECT id FROM bookings WHERE id = ? AND user_id = ?");
            $chk->bind_param("ii", $booking_id, $user_id);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $ins = $conn->prepare("INSERT INTO booking_comments (booking_id, user_id, comment) VALUES (?, ?, ?)");
                $ins->bind_param("iis", $booking_id, $user_id, $comment);
                $ins->execute();
                $ins->close();
            }
            $chk->close();
        }
        back();
    }

    // Handle Review Submission
    if ($action === 'submit_review') {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        $rating     = (int)($_POST['rating'] ?? 0);
        $feedback   = trim($_POST['feedback'] ?? '');
        $tech       = $_POST['technician'] ?? '';

        if ($rating < 1 || $rating > 5) {
            flash('error', 'Please select a star rating.');
        } else {
            $stmt = $conn->prepare("SELECT id FROM bookings WHERE id = ? AND user_id = ? AND status = 'Completed' AND technician = ?");
            $stmt->bind_param("iis", $booking_id, $user_id, $tech);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $stmt->close();
                $chk = $conn->prepare("SELECT id FROM technician_reviews WHERE booking_id = ?");
                $chk->bind_param("i", $booking_id);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    flash('error', 'You have already reviewed this repair.');
                } else {
                    $ins = $conn->prepare("INSERT INTO technician_reviews (booking_id, technician_username, client_id, rating, feedback) VALUES (?, ?, ?, ?, ?)");
                    $ins->bind_param("isiis", $booking_id, $tech, $user_id, $rating, $feedback);
                    $ins->execute();
                    $ins->close();
                    flash('success', 'Thank you for your feedback! Your review has been added to their profile.');
                }
                $chk->close();
            } else {
                flash('error', 'Invalid booking for review.');
            }
        }
        back();
    }

    // Handle Accept Price
    if ($action === 'accept_price') {
        $booking_id = (int) $_POST['booking_id'];
        $stmt = $conn->prepare("UPDATE bookings SET final_price = proposed_price, price_status = 'Accepted', status = 'Confirmed' WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $booking_id, $user_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            flash('success', 'You accepted the price! Your booking is now confirmed.');
        } else {
            flash('error', 'Could not confirm the booking. It may have been updated.');
        }
        $stmt->close();
        back();
    }

    if ($action === 'book') {
        $device  = trim($_POST['device_model'] ?? '');
        $service = $_POST['service_type'] ?? '';
        $mode    = $_POST['service_mode'] ?? '';
        $address = trim($_POST['address'] ?? '');
        $notes   = trim($_POST['issue_notes'] ?? '');
        $tech    = trim($_POST['technician'] ?? '');
        $raw     = $_POST['appointment_date'] ?? '';

        $_SESSION['old'] = $_POST;
        $err = null;

        $tz = new DateTimeZone('Asia/Manila');
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw, $tz);

        if (mb_strlen($device) < 2 || mb_strlen($device) > 100) {
            $err = 'Enter your device model (2 to 100 characters).';
        } elseif (!isset($services[$service])) {
            $err = 'Choose a service from the list.';
        } elseif (!in_array($mode, ['home', 'online'], true)) {
            $err = 'Choose how you want the repair done: at home or online.';
        } elseif ($mode === 'home' && ($address === '' || mb_strlen($address) > 255)) {
            $err = 'Enter the address where the technician should go.';
        } elseif ($tech !== '' && !in_array($tech, $technicians, true)) {
            $err = 'That technician account was not found. Please pick one from the list.';
        } elseif (mb_strlen($notes) > 500) {
            $err = 'Describe the problem in 500 characters or fewer.';
        } elseif (!$dt) {
            $err = 'Pick a valid date and time.';
        } elseif ($dt < new DateTime('+' . LEAD_HOURS . ' hours', $tz)) {
            $err = 'Please book at least ' . LEAD_HOURS . ' hours ahead.';
        }

        if (!$err) {
            $slot = $dt->format('Y-m-d H:i:s');
            $lo = (clone $dt)->modify('-' . BOOKING_MINUTES . ' minutes')->format('Y-m-d H:i:s');
            $hi = (clone $dt)->modify('+' . BOOKING_MINUTES . ' minutes')->format('Y-m-d H:i:s');
            $chk = $conn->prepare("SELECT appointment_date, technician FROM bookings WHERE status <> 'Cancelled' AND appointment_date > ? AND appointment_date < ?");
            $chk->bind_param("ss", $lo, $hi);
            $chk->execute();
            $near = $chk->get_result()->fetch_all(MYSQLI_ASSOC);
            $chk->close();

            $all_starts = $tech_starts = [];
            foreach ($near as $n) {
                $ts = strtotime($n['appointment_date']);
                $all_starts[] = $ts;
                if ($tech !== '' && $n['technician'] === $tech) $tech_starts[] = $ts;
            }
            $want = $dt->getTimestamp();

            if (overlap_peak($all_starts, $want) >= MAX_PER_SLOT) {
                $err = 'We\'re fully booked around that time. Please choose a different time.';
            } elseif ($tech !== '' && overlap_peak($tech_starts, $want) >= 1) {
                $err = tech_name($tech) . ' is already booked around that time. Pick another time or another technician.';
            }
        }

        if ($err) {
            flash('error', $err);
            back();
        }

        $addr_val  = $mode === 'home' ? $address : null;
        $notes_val = $notes !== '' ? $notes : null;
        $tech_val  = $tech !== '' ? $tech : null;
        $budget    = !empty($_POST['client_budget']) ? (float)$_POST['client_budget'] : null;

        $stmt = $conn->prepare(
            "INSERT INTO bookings (user_id, device_model, service_type, service_mode, address, issue_notes, client_budget, price_status, technician, appointment_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Proposal', ?, ?, 'Pending')"
        );
        $stmt->bind_param("isssssdss", $user_id, $device, $service, $mode, $addr_val, $notes_val, $budget, $tech_val, $slot);

        if ($stmt->execute()) {
            unset($_SESSION['old']);
            flash('success', 'Booking requested for ' . day_label($dt) . ' at ' . $dt->format('g:i A') . ($tech_val ? ' with ' . tech_name($tech_val) : '') . '. Wait for a technician to confirm.');
        } else {
            flash('error', 'We couldn\'t save your booking. Please try again.');
        }
        $stmt->close();
        back();
    }

    if ($action === 'cancel') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE bookings SET status = 'Cancelled' WHERE id = ? AND user_id = ? AND status IN ('Pending','Confirmed')");
        $stmt->bind_param("ii", $booking_id, $user_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            flash('success', 'Booking cancelled.');
        } else {
            flash('error', 'That booking can no longer be cancelled.');
        }
        $stmt->close();
        back();
    }
    back();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$old = $_SESSION['old'] ?? [];
unset($_SESSION['old']);

/* ---------- Fetch bookings with review status ---------- */
$bookings = [];
try {
    $stmt = $conn->prepare(
        "SELECT b.id, b.device_model, b.service_type, b.service_mode, b.address, b.issue_notes, b.client_budget, b.proposed_price, b.price_status, b.technician, b.appointment_date, b.status, r.id as review_id
         FROM bookings b
         LEFT JOIN technician_reviews r ON b.id = r.booking_id
         WHERE b.user_id = ? ORDER BY b.appointment_date ASC"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $stmt = $conn->prepare("SELECT *, NULL as review_id FROM bookings WHERE user_id = ? ORDER BY appointment_date ASC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $bookings[] = $row; }
    $stmt->close();
}

$now = new DateTime('now');
$count_active = $count_done = 0;
$next = null;

foreach ($bookings as &$b) {
    $b['dt'] = new DateTime($b['appointment_date']);
    if (in_array($b['status'], ['Pending', 'Confirmed', 'In Progress'], true)) {
        $b['group'] = 'active';
        $count_active++;
        if (!$next && ($b['dt'] >= $now || $b['status'] === 'In Progress')) {
            $next = $b;
        }
    } elseif ($b['status'] === 'Completed') {
        $b['group'] = 'completed';
        $count_done++;
    } else {
        $b['group'] = 'cancelled';
    }
}
unset($b);

usort($bookings, function ($a, $b) {
    $order = ['active' => 0, 'completed' => 1, 'cancelled' => 2];
    if ($a['group'] !== $b['group']) return $order[$a['group']] <=> $order[$b['group']];
    return $a['group'] === 'active' ? $a['dt'] <=> $b['dt'] : $b['dt'] <=> $a['dt'];
});

$hour     = (int) $now->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$min = new DateTime('+' . LEAD_HOURS . ' hours');
$min_attr = $min->format('Y-m-d\TH:i');

$booking_times = [];
$sres = $conn->query("SELECT appointment_date, technician FROM bookings WHERE status <> 'Cancelled' AND appointment_date >= CURDATE()");
while ($s = $sres->fetch_assoc()) {
    $booking_times[] = [
        't'    => (new DateTime($s['appointment_date']))->format('Y-m-d\TH:i'),
        'tech' => $s['technician'] ?? '',
    ];
}
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

function stepper($status, $steps) {
    $keys = array_keys($steps);
    $idx  = array_search($status, $keys, true);
    $html = '<ol class="stepper" aria-label="Repair progress">';
    foreach ($steps as $key => $label) {
        $i = array_search($key, $keys, true);
        $cls = $i < $idx ? 'done' : ($i === $idx ? 'current' : '');
        $html .= '<li class="' . $cls . '"' . ($i === $idx ? ' aria-current="step"' : '') . '><span>' . e($label) . '</span></li>';
    }
    return $html . '</ol>';
}
function mode_text($b) { return $b['service_mode'] === 'online' ? 'Online session' : 'Home visit'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= e($brand) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        a.nav-link { color: #cbd5e1; text-decoration: none; font-size: 0.9rem; display: inline-block; padding: 8px 16px; border-radius: 8px; background: rgba(255,255,255,0.06); transition: 0.3s ease; }
        a.nav-link:hover { background: rgba(255,255,255,0.12); }
        .tech-search { width: 100%; box-sizing: border-box; }
        .tech-list { margin-top: 8px; max-height: 190px; overflow-y: auto; border: 1px solid rgba(128,128,128,.3); border-radius: 8px; }
        .tech-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; cursor: pointer; border-bottom: 1px solid rgba(128,128,128,.15); }
        .tech-item:last-child { border-bottom: 0; }
        .tech-item:hover { background: rgba(128,128,128,.08); }
        .tech-item:has(input:checked) { background: rgba(80,120,255,.12); font-weight: 600; }
        .tech-item[hidden] { display: none; }
        .tech-avatar { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: rgba(128,128,128,.2); font-size: .8rem; font-weight: 600; flex: none; }
        .dash input[type="date"], .dash input[type="time"], .dash input[type="number"] { width: 100%; box-sizing: border-box; padding: 14px 16px; background: var(--surface); border: 1px solid transparent; border-radius: 12px; color: #fff; outline: none; font: inherit; color-scheme: dark; appearance: none; box-shadow: inset 4px 4px 8px rgba(0,0,0,0.5), inset -4px -4px 8px rgba(255,255,255,0.02); }
        .dash input[type="date"]:focus, .dash input[type="time"]:focus, .dash input[type="number"]:focus { border: 1px solid rgba(168, 85, 247, 0.4); }
        .dash select:disabled { opacity: .6; cursor: not-allowed; }
        .tech-text { display: flex; flex-direction: column; min-width: 0; }
        .tech-text strong { font-weight: 600; color: #fff; font-size: .92rem; }
        .tech-text small { color: #94a3b8; font-size: .78rem; font-weight: 400; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tech-info { margin-top: 12px; padding: 16px; border-radius: 14px; background: rgba(99,102,241,.07); border: 1px solid rgba(99,102,241,.25); }
        .tech-info-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
        .tech-info-head .avatar-box { display: flex; align-items: center; gap: 12px;}
        .tech-info-head .tech-avatar { width: 42px; height: 42px; font-size: 1.05rem; background: linear-gradient(135deg, #6366f1, #a855f7); color: #fff; }
        .tech-info-head strong { display: block; color: #fff; }
        .tech-info-head small { color: #94a3b8; }
        .tech-headline { color: #cbd5e1; font-size: .9rem; margin-bottom: 10px; }
        .tech-facts { list-style: none; display: flex; flex-wrap: wrap; gap: 6px 16px; margin-bottom: 10px; font-size: .82rem; color: #94a3b8; }
        .tech-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .tech-chips span { padding: 3px 10px; border-radius: 999px; font-size: .75rem; color: #c4b5fd; background: rgba(168,85,247,.14); border: 1px solid rgba(168,85,247,.35); }
        .tech-bio { color: #cbd5e1; font-size: .87rem; line-height: 1.5; white-space: pre-line; }
        .tech-none { padding: 10px 12px; font-size: .9rem; opacity: .7; }
        
        .star-rating span { transition: color 0.2s; }
        .star-rating span:hover, .star-rating span.active { color: #fbbf24; }
        .review-box { background: rgba(0,0,0,0.2); padding: 12px; border-radius: 10px; font-size: 0.85rem; border: 1px solid rgba(255,255,255,0.05); }

        /* Discussion Thread CSS */
        .comments-section { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--line); }
        .comments-section h4 { font-size: 0.95rem; color: #e2e8f0; margin-bottom: 12px; }
        .comment-list { max-height: 220px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; padding-right: 4px; }
        .comment-bubble { background: rgba(255,255,255,0.05); padding: 10px 14px; border-radius: 12px; font-size: 0.85rem; border: 1px solid transparent; }
        .comment-bubble.tech-reply { background: rgba(99, 102, 241, 0.12); border-color: rgba(99, 102, 241, 0.25); }
        .comment-meta { display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--muted); margin-bottom: 6px; }
        .comment-meta strong { color: #cbd5e1; }
        .comment-bubble.tech-reply .comment-meta strong { color: #a5b4fc; }
        .comment-form { display: flex; gap: 8px; }
        .comment-form input { flex: 1; padding: 10px 14px; border-radius: 10px; background: rgba(0,0,0,0.25); border: 1px solid var(--line); color: #fff; font-size: 0.85rem; outline: none; transition: border-color 0.2s; }
        .comment-form input:focus { border-color: rgba(168, 85, 247, 0.5); }
        .comment-form button { width: auto; padding: 10px 18px; border-radius: 10px; font-size: 0.85rem; box-shadow: none; }
    </style>
</head>
<body class="dash">
<div class="dash-shell">

    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true"></span><?= e($brand) ?></a>
        <div class="topbar-user">
            <span class="avatar" aria-hidden="true"><?= e(strtoupper(mb_substr($username, 0, 1))) ?></span>
            <span class="topbar-name"><?= e($username) ?></span>
            <a href="board.php" class="nav-link" style="background: linear-gradient(135deg, #4f46e5, #9333ea); color: #fff;">Repair board</a>
            <a href="profile.php" class="nav-link">My profile</a>
            <a href="logout.php" class="logout-link">Log out</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="toast <?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <section class="hero">
        <div class="hero-main">
            <p class="hero-greet"><?= e($greeting) ?>, <?= e($username) ?></p>
            <?php if ($next): ?>
                <h1 class="hero-when">
                    <?= e($next['status'] === 'In Progress' ? 'Happening now' : day_label($next['dt'])) ?>
                    <?php if ($next['status'] !== 'In Progress'): ?><span><?= e($next['dt']->format('g:i A')) ?></span><?php endif; ?>
                </h1>
                <p class="hero-what">
                    <?= e($next['service_type']) ?> for your <?= e($next['device_model']) ?>
                </p>
                <ul class="hero-meta">
                    <li class="chip chip-<?= e($next['service_mode']) ?>"><?= e(mode_text($next)) ?></li>
                    <li><?= e($next['technician'] ? tech_name($next['technician']) : 'Technician assigned once confirmed') ?></li>
                </ul>
                <?= stepper($next['status'], $steps) ?>
            <?php else: ?>
                <h1 class="hero-when hero-empty">No repairs booked</h1>
                <p class="hero-what">Pick a service and a time. A technician comes to your home or fixes it online.</p>
                <a href="#book" class="btn-link">Book a repair</a>
            <?php endif; ?>
        </div>

        <dl class="hero-stats">
            <div><dt>Active bookings</dt><dd><?= $count_active ?></dd></div>
            <div><dt>Completed repairs</dt><dd><?= $count_done ?></dd></div>
            <div><dt>All bookings</dt><dd><?= count($bookings) ?></dd></div>
        </dl>
    </section>

    <div class="dash-cols">

        <!-- Booking form -->
        <section class="panel" id="book">
            <h3>Book a repair</h3>
            <form method="POST" action="" id="bookForm" novalidate>
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="book">

                <fieldset class="mode-pick">
                    <legend>Where should we fix it?</legend>
                    <?php $mode_old = $old['service_mode'] ?? 'home'; ?>
                    <label class="mode-card">
                        <input type="radio" name="service_mode" value="home" <?= $mode_old === 'home' ? 'checked' : '' ?>>
                        <span class="mode-body"><strong>At my home</strong><small>Technician visits you</small></span>
                    </label>
                    <label class="mode-card">
                        <input type="radio" name="service_mode" value="online" <?= $mode_old === 'online' ? 'checked' : '' ?>>
                        <span class="mode-body"><strong>Online</strong><small>Remote session, no visit</small></span>
                    </label>
                </fieldset>

                <div class="form-group">
                    <label for="device_model">Device model</label>
                    <input type="text" id="device_model" name="device_model" placeholder="e.g. Acer Nitro V15" maxlength="100" required autocomplete="off" value="<?= e($old['device_model'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="service_type">Service</label>
                    <select id="service_type" name="service_type" required>
                        <?php foreach ($services as $name => $desc): ?>
                            <option value="<?= e($name) ?>" data-desc="<?= e($desc) ?>" <?= ($old['service_type'] ?? '') === $name ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint" id="serviceDesc"></p>
                </div>

                <div class="form-group" id="techGroup">
                    <label for="techSearch">Technician <span class="optional">(optional)</span></label>
                    <input type="search" id="techSearch" class="tech-search" placeholder="Search by name or specialty..." autocomplete="off" aria-controls="techList">
                    <?php $tech_old = $old['technician'] ?? ''; ?>
                    <div class="tech-list" id="techList" role="radiogroup" aria-label="Technician accounts">
                        <label class="tech-item" data-name="any available technician">
                            <input type="radio" name="technician" value="" <?= $tech_old === '' ? 'checked' : '' ?>>
                            <span class="tech-avatar" aria-hidden="true">*</span>
                            <span class="tech-text"><strong>Any available technician</strong><small>We'll match you with someone free</small></span>
                        </label>
                        <?php foreach ($technicians as $tn): $ti = $tech_info[$tn]; 
                            $revs = $reviews_data[$tn] ?? [];
                        ?>
                            <label class="tech-item"
                                   data-name="<?= e(mb_strtolower($ti['display'] . ' ' . $tn . ' ' . implode(' ', $ti['spec_list']))) ?>"
                                   data-display="<?= e($ti['display']) ?>"
                                   data-handle="@<?= e($tn) ?>"
                                   data-headline="<?= e($ti['headline'] ?? '') ?>"
                                   data-bio="<?= e($ti['bio'] ?? '') ?>"
                                   data-spec="<?= e(implode('|', $ti['spec_list'])) ?>"
                                   data-exp="<?= e($ti['experience_years'] ?? '') ?>"
                                   data-area="<?= e($ti['service_area'] ?? '') ?>"
                                   data-rating="<?= e($ti['avg_rating']) ?>"
                                   data-reviewcount="<?= e($ti['review_count']) ?>"
                                   data-reviews="<?= htmlspecialchars(json_encode($revs, $json_flags), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="radio" name="technician" value="<?= e($tn) ?>" <?= $tech_old === $tn ? 'checked' : '' ?>>
                                <span class="tech-avatar" aria-hidden="true"><?= e(strtoupper(mb_substr($ti['display'], 0, 1))) ?></span>
                                <span class="tech-text">
                                    <strong><?= e($ti['display']) ?></strong>
                                    <small><?= e(($ti['headline'] ?? '') !== '' ? $ti['headline'] : '@' . $tn) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <p class="tech-none" id="techNone" hidden>No technician matches your search.</p>
                    </div>

                    <div class="tech-info" id="techInfo" hidden>
                        <div class="tech-info-head">
                            <div class="avatar-box">
                                <span class="tech-avatar" id="tiAvatar" aria-hidden="true"></span>
                                <div><strong id="tiName"></strong><small id="tiHandle"></small></div>
                            </div>
                            <div id="tiRatingDisplay" style="text-align: right; color: #fbbf24; font-size: 1rem; font-weight: 600;"></div>
                        </div>
                        <p class="tech-headline" id="tiHeadline" hidden></p>
                        <ul class="tech-facts">
                            <li id="tiExp" hidden></li>
                            <li id="tiArea" hidden></li>
                        </ul>
                        <div class="tech-chips" id="tiSpec"></div>
                        <p class="tech-bio" id="tiBio"></p>
                        
                        <div id="tiReviewsSection" style="margin-top: 18px; border-top: 1px solid var(--line); padding-top: 16px; max-height: 240px; overflow-y: auto;" hidden>
                            <h4 style="font-size: 0.9rem; color: #cbd5e1; margin-bottom: 12px;">Client Feedback</h4>
                            <div id="tiReviewsList" style="display: flex; flex-direction: column; gap: 12px;"></div>
                        </div>
                    </div>
                    <p class="hint">Pick a specific technician, or leave it on "Any available".</p>
                </div>

                <div class="form-group" id="addressGroup">
                    <label for="address">Home address</label>
                    <input type="text" id="address" name="address" placeholder="Street, barangay, city" maxlength="255" value="<?= e($old['address'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="appt_day">Date</label>
                    <input type="date" id="appt_day" min="<?= e($min->format('Y-m-d')) ?>" required>
                </div>

                <div class="form-group">
                    <label for="appt_time">Time</label>
                    <input type="time" id="appt_time" step="60" required>
                    <input type="hidden" name="appointment_date" id="appointment_date" value="<?= e($old['appointment_date'] ?? '') ?>">
                    <p class="form-error" id="timeNote" role="status" hidden style="margin-top: 10px; margin-bottom: 0;"></p>
                    <p class="hint">Pick any time you like. A booking holds the technician for <?= BOOKING_MINUTES ?> minutes. Book at least <?= LEAD_HOURS ?> hours ahead.</p>
                </div>

                <div class="form-group">
                    <label for="issue_notes">What's wrong? <span class="optional">(Required)</span></label>
                    <textarea id="issue_notes" name="issue_notes" rows="3" maxlength="500" placeholder="Slow startup, loud fan, blue screen..."><?= e($old['issue_notes'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="client_budget">Your Budget / Offer (₱) <span class="optional">(optional)</span></label>
                    <input type="number" id="client_budget" name="client_budget" min="0" step="50" placeholder="e.g. 500" value="<?= e($old['client_budget'] ?? '') ?>">
                    <p class="hint">Let the technician know how much you are willing to pay for this repair.</p>
                </div>

                <p class="form-error" id="formError" role="alert" hidden></p>
                <button type="submit" id="bookBtn">Confirm booking</button>
            </form>
        </section>

        <!-- Bookings list -->
        <section class="panel">
            <div class="panel-head">
                <h3>Your bookings</h3>
                <div class="tabs" role="tablist">
                    <button type="button" class="tab is-active" data-filter="all" role="tab">All</button>
                    <button type="button" class="tab" data-filter="active" role="tab">Active</button>
                    <button type="button" class="tab" data-filter="completed" role="tab">Completed</button>
                    <button type="button" class="tab" data-filter="cancelled" role="tab">Cancelled</button>
                </div>
            </div>

            <div class="booking-list" id="bookingList">
                <?php foreach ($bookings as $b): ?>
                    <article class="booking" data-group="<?= e($b['group']) ?>">
                        <div class="booking-top">
                            <div>
                                <h4><?= e($b['device_model']) ?></h4>
                                <p class="booking-service"><?= e($b['service_type']) ?></p>
                            </div>
                            <span class="status-badge status-<?= e(strtolower(str_replace(' ', '-', $b['status']))) ?>"><?= e($b['status']) ?></span>
                        </div>

                        <ul class="booking-meta">
                            <li><?= e($b['dt']->format('D, M j')) ?> at <?= e($b['dt']->format('g:i A')) ?></li>
                            <li class="chip chip-<?= e($b['service_mode']) ?>"><?= e(mode_text($b)) ?></li>
                            <li><?= e($b['technician'] ? tech_name($b['technician']) : 'Technician not assigned yet') ?></li>
                        </ul>

                        <?php if ($b['service_mode'] === 'home' && $b['address']): ?>
                            <p class="booking-line"><span>Address</span><?= e($b['address']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($b['client_budget'])): ?>
                            <p class="booking-line"><span>Budget</span>₱<?= number_format((float)$b['client_budget'], 2) ?></p>
                        <?php endif; ?>
                        <?php if ($b['issue_notes']): ?>
                            <p class="booking-line"><span>Notes</span><?= e($b['issue_notes']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($b['proposed_price']) && $b['price_status'] === 'Negotiating'): ?>
                            <div style="background: rgba(99,102,241,0.1); padding: 14px; border-radius: 12px; margin-top: 14px; border: 1px solid rgba(99,102,241,0.3);">
                                <p style="font-size: 0.95rem; color: #c4b5fd; margin-bottom: 12px;">
                                    Technician proposed price: <strong>₱<?= number_format((float)$b['proposed_price'], 2) ?></strong>
                                </p>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="accept_price">
                                    <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                    <button type="submit" class="btn-main" style="padding: 10px 20px; font-size: 0.85rem; width: auto; display: inline-flex;">Accept Price & Confirm</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php if ($b['group'] === 'active'): ?>
                            <?= stepper($b['status'], $steps) ?>
                        <?php endif; ?>
                        
                        <!-- Thread / Comments Section -->
                        <div class="comments-section">
                            <h4>Discussion</h4>
                            <div class="comment-list">
                                <?php foreach (($comments_data[$b['id']] ?? []) as $c): ?>
                                    <div class="comment-bubble <?= $c['role'] === 'technician' ? 'tech-reply' : '' ?>">
                                        <div class="comment-meta">
                                            <strong><?= e($c['username']) ?> <?= $c['role'] === 'technician' ? '(Tech)' : '' ?></strong>
                                            <span><?= e((new DateTime($c['created_at']))->format('M j, g:i A')) ?></span>
                                        </div>
                                        <p><?= e($c['comment']) ?></p>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($comments_data[$b['id']])): ?>
                                    <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 4px;">No comments yet. Chat with your technician here.</p>
                                <?php endif; ?>
                            </div>
                            <form method="POST" action="" class="comment-form">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="add_comment">
                                <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                <input type="text" name="comment" placeholder="Write a message..." required autocomplete="off">
                                <button type="submit" class="btn-main">Send</button>
                            </form>
                        </div>

                        <div class="booking-actions" style="gap:10px;">
                            <?php if (in_array($b['status'], ['Pending', 'Confirmed'], true)): ?>
                                <button type="button" class="btn-ghost danger js-cancel"
                                        data-id="<?= (int) $b['id'] ?>"
                                        data-label="<?= e($b['service_type'] . ' on ' . $b['dt']->format('M j, g:i A')) ?>">Cancel booking</button>
                            <?php endif; ?>
                            
                            <?php if ($b['status'] === 'Completed' && !$b['review_id'] && !empty($b['technician'])): ?>
                                <button type="button" class="btn-ghost js-review" style="border-color: rgba(99, 102, 241, 0.4); color: #a5b4fc;"
                                        data-id="<?= (int) $b['id'] ?>"
                                        data-tech="<?= e($b['technician']) ?>"
                                        data-label="<?= e($b['service_type'] . ' by ' . tech_name($b['technician'])) ?>">Leave a Review</button>
                            <?php elseif ($b['status'] === 'Completed' && $b['review_id']): ?>
                                <span style="font-size: 0.8rem; color: #34d399;">✓ You reviewed this repair</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>

                <p class="empty" id="emptyState" <?= count($bookings) ? 'hidden' : '' ?>>
                    <?= count($bookings) ? 'Nothing here yet.' : 'You have no bookings yet. Use the form to book your first repair.' ?>
                </p>
            </div>
        </section>
    </div>
</div>

<dialog id="cancelDialog">
    <form method="POST" action="">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="booking_id" id="cancelId" value="">
        <h3>Cancel this booking?</h3>
        <p id="cancelLabel"></p>
        <div class="dialog-actions">
            <button type="button" class="btn-ghost" id="cancelKeep">Keep booking</button>
            <button type="submit" class="btn-danger">Cancel booking</button>
        </div>
    </form>
</dialog>

<dialog id="reviewDialog">
    <form method="POST" action="">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="submit_review">
        <input type="hidden" name="booking_id" id="reviewBookingId" value="">
        <input type="hidden" name="technician" id="reviewTech" value="">
        
        <h3>Rate this Repair</h3>
        <p id="reviewLabel"></p>
        
        <div class="form-group" style="text-align: center; margin-bottom: 24px;">
            <div class="star-rating" style="font-size: 2.5rem; color: #64748b; cursor: pointer; user-select: none;">
                <span data-val="1">☆</span><span data-val="2">☆</span><span data-val="3">☆</span><span data-val="4">☆</span><span data-val="5">☆</span>
            </div>
            <input type="hidden" name="rating" id="reviewRating" required>
        </div>
        
        <div class="form-group">
            <label for="reviewFeedback">Feedback <span class="optional">(optional)</span></label>
            <textarea id="reviewFeedback" name="feedback" rows="3" maxlength="500" placeholder="Did they do a great job? Let others know!"></textarea>
        </div>
        
        <div class="dialog-actions">
            <button type="button" class="btn-ghost" id="cancelReview">Cancel</button>
            <button type="submit" class="btn-main" style="width: auto; padding: 11px 22px;">Submit Review</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var MAX = <?= MAX_PER_SLOT ?>;
    var MIN = <?= json_encode($min_attr) ?>;
    var BOOKINGS = <?= json_encode($booking_times, $json_flags) ?>;
    var DUR = <?= BOOKING_MINUTES ?>, LEAD = <?= LEAD_HOURS ?>;

    var modes = document.querySelectorAll('input[name="service_mode"]');
    var addressGroup = document.getElementById('addressGroup');
    var address = document.getElementById('address');
    function syncMode() {
        var home = document.querySelector('input[name="service_mode"]:checked').value === 'home';
        addressGroup.hidden = !home;
        address.required = home;
    }
    modes.forEach(function (m) { m.addEventListener('change', syncMode); });
    syncMode();

    var service = document.getElementById('service_type');
    var desc = document.getElementById('serviceDesc');
    function syncService() { desc.textContent = service.options[service.selectedIndex].dataset.desc || ''; }
    service.addEventListener('change', syncService);
    syncService();

    var techSearch = document.getElementById('techSearch');
    var techItems = document.querySelectorAll('.tech-item');
    var techNone = document.getElementById('techNone');
    techSearch.addEventListener('input', function () {
        var q = techSearch.value.trim().toLowerCase(), visible = 0;
        techItems.forEach(function (it) {
            var match = q === '' || it.dataset.name.indexOf(q) !== -1;
            it.hidden = !match;
            if (match) visible++;
        });
        techNone.hidden = visible > 0;
    });
    techSearch.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });

    var info = document.getElementById('techInfo');
    function showTech() {
        var r = document.querySelector('input[name="technician"]:checked');
        var box = r ? r.closest('.tech-item') : null;
        if (!r || !r.value || !box) { info.hidden = true; return; }
        var d = box.dataset;
        document.getElementById('tiAvatar').textContent = d.display.charAt(0).toUpperCase();
        document.getElementById('tiName').textContent = d.display;
        document.getElementById('tiHandle').textContent = d.handle;
        
        var ratingBox = document.getElementById('tiRatingDisplay');
        var avg = parseFloat(d.rating || 0);
        var revCount = parseInt(d.reviewcount || 0);
        
        if (revCount > 0) {
            ratingBox.innerHTML = '★ ' + avg.toFixed(1) + '<br><span style="color:#94a3b8; font-weight:400; font-size:0.75rem;">' + revCount + ' review' + (revCount > 1 ? 's' : '') + '</span>';
        } else {
            ratingBox.innerHTML = '<span style="color:#94a3b8; font-weight:400; font-size:0.8rem;">No reviews yet</span>';
        }

        var hl = document.getElementById('tiHeadline');
        hl.textContent = d.headline; hl.hidden = !d.headline;
        var ex = document.getElementById('tiExp');
        ex.textContent = d.exp !== '' ? d.exp + (d.exp === '1' ? ' year' : ' years') + ' of experience' : ''; ex.hidden = d.exp === '';
        var ar = document.getElementById('tiArea');
        ar.textContent = d.area ? 'Serves ' + d.area : ''; ar.hidden = !d.area;
        var sp = document.getElementById('tiSpec');
        sp.innerHTML = '';
        (d.spec ? d.spec.split('|') : []).forEach(function (s) {
            var c = document.createElement('span'); c.textContent = s; sp.appendChild(c);
        });
        document.getElementById('tiBio').textContent = d.bio || (d.headline || d.spec || d.exp || d.area ? '' : 'This technician has not added profile details yet.');
        
        var reviewsSection = document.getElementById('tiReviewsSection');
        var reviewsList = document.getElementById('tiReviewsList');
        var revs = JSON.parse(d.reviews || '[]');
        
        if (revs.length > 0) {
            reviewsList.innerHTML = '';
            revs.forEach(function(rev) {
                var starsHtml = '';
                for(var i=1; i<=5; i++) { starsHtml += i <= rev.rating ? '★' : '☆'; }
                var item = document.createElement('div');
                item.className = 'review-box';
                item.innerHTML = '<div style="display:flex; justify-content:space-between; margin-bottom:4px;">' +
                                 '<strong style="color:#fff;">' + rev.client + '</strong>' +
                                 '<span style="color:#fbbf24; letter-spacing: 2px;">' + starsHtml + '</span></div>' +
                                 (rev.feedback ? '<p style="color:#cbd5e1; margin-bottom:6px; line-height: 1.4;">' + rev.feedback + '</p>' : '') +
                                 '<small style="color:#64748b;">' + rev.date + '</small>';
                reviewsList.appendChild(item);
            });
            reviewsSection.hidden = false;
        } else {
            reviewsSection.hidden = true;
        }
        info.hidden = false;
    }

    var day = document.getElementById('appt_day');
    var time = document.getElementById('appt_time');
    var stamp = document.getElementById('appointment_date');
    var note = document.getElementById('timeNote');

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function chosenTech() { var r = document.querySelector('input[name="technician"]:checked'); return r ? r.value : ''; }
    function chosenTechName() { var r = document.querySelector('input[name="technician"]:checked'); return r && r.closest('.tech-item').dataset.display || 'That technician'; }
    function syncStamp() { stamp.value = (day.value && time.value) ? day.value + 'T' + time.value : ''; }

    function peak(starts, t) {
        var d = DUR * 60000, pts = [t], best = 0;
        starts.forEach(function (s) { if (s > t && s < t + d) pts.push(s); });
        pts.forEach(function (p) {
            var n = 0;
            starts.forEach(function (s) { if (s <= p && p < s + d) n++; });
            if (n > best) best = n;
        });
        return best;
    }

    function problem() {
        syncStamp();
        if (!day.value || !time.value) return '';
        if (stamp.value < MIN) return 'Please choose a later time (at least ' + LEAD + ' hours from now).';
        var t = new Date(stamp.value).getTime(), d = DUR * 60000, tech = chosenTech(), all = [], mine = [];
        BOOKINGS.forEach(function (b) {
            var s = new Date(b.t).getTime();
            if (s > t - d && s < t + d) { all.push(s); if (tech && b.tech === tech) mine.push(s); }
        });
        if (peak(all, t) >= MAX) return 'We are fully booked around that time. Try a different time.';
        if (tech && peak(mine, t) >= 1) return chosenTechName() + ' is already booked around that time. Try another time or another technician.';
        return '';
    }
    function refreshNote() { var m = problem(); note.textContent = m; note.hidden = !m; }

    day.addEventListener('change', refreshNote);
    time.addEventListener('input', refreshNote);
    time.addEventListener('change', refreshNote);
    document.querySelectorAll('input[name="technician"]').forEach(function (r) {
        r.addEventListener('change', function () { showTech(); refreshNote(); });
    });

    if (stamp.value.indexOf('T') > 0) {
        day.value = stamp.value.split('T')[0];
        time.value = stamp.value.split('T')[1].slice(0, 5);
    }
    showTech();
    refreshNote();

    var form = document.getElementById('bookForm');
    var err = document.getElementById('formError');
    var btn = document.getElementById('bookBtn');
    form.addEventListener('submit', function (ev) {
        var msg = '';
        syncStamp();
        if (!document.getElementById('device_model').value.trim()) msg = 'Enter your device model.';
        else if (address.required && !address.value.trim()) msg = 'Enter the address where the technician should go.';
        else if (!day.value) msg = 'Pick a date.';
        else if (!time.value) msg = 'Pick a time for your appointment.';
        else msg = problem();
        if (msg) {
            ev.preventDefault();
            err.textContent = msg;
            err.hidden = false;
        } else {
            err.hidden = true;
            btn.disabled = true;
            btn.textContent = 'Booking...';
        }
    });

    var tabs = document.querySelectorAll('.tab');
    var cards = document.querySelectorAll('.booking');
    var empty = document.getElementById('emptyState');
    var total = cards.length;
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            var f = tab.dataset.filter, shown = 0;
            cards.forEach(function (c) {
                var show = f === 'all' || c.dataset.group === f;
                c.hidden = !show;
                if (show) shown++;
            });
            empty.hidden = shown > 0;
            if (shown === 0) empty.textContent = total ? 'No ' + f + ' bookings.' : empty.textContent;
        });
    });

    var dlg = document.getElementById('cancelDialog');
    document.querySelectorAll('.js-cancel').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('cancelId').value = b.dataset.id;
            document.getElementById('cancelLabel').textContent = b.dataset.label;
            dlg.showModal();
        });
    });
    document.getElementById('cancelKeep').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });

    var revDlg = document.getElementById('reviewDialog');
    var stars = document.querySelectorAll('.star-rating span');
    var ratingInput = document.getElementById('reviewRating');

    stars.forEach(function(star) {
        star.addEventListener('mouseover', function() {
            var val = this.dataset.val;
            stars.forEach(function(s, i) { s.style.color = (i < val) ? '#fbbf24' : '#64748b'; });
        });
        star.addEventListener('mouseout', function() {
            var val = ratingInput.value || 0;
            stars.forEach(function(s, i) { s.style.color = (i < val) ? '#fbbf24' : '#64748b'; });
        });
        star.addEventListener('click', function() {
            var val = this.dataset.val;
            ratingInput.value = val;
            stars.forEach(function(s, i) {
                s.textContent = (i < val) ? '★' : '☆';
                s.style.color = (i < val) ? '#fbbf24' : '#64748b';
            });
        });
    });

    document.querySelectorAll('.js-review').forEach(function(b) {
        b.addEventListener('click', function() {
            document.getElementById('reviewBookingId').value = b.dataset.id;
            document.getElementById('reviewTech').value = b.dataset.tech;
            document.getElementById('reviewLabel').textContent = b.dataset.label;
            ratingInput.value = '';
            document.getElementById('reviewFeedback').value = '';
            stars.forEach(function(s) { s.textContent = '☆'; s.style.color = '#64748b'; });
            revDlg.showModal();
        });
    });
    
    document.getElementById('cancelReview').addEventListener('click', function () { revDlg.close(); });
    revDlg.addEventListener('click', function (e) { if (e.target === revDlg) revDlg.close(); });

    // Auto-scroll comments to bottom
    document.querySelectorAll('.comment-list').forEach(function(list) {
        list.scrollTop = list.scrollHeight;
    });
})();
</script>
</body>
</html>