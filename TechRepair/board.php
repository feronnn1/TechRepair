<?php
session_start();
require_once 'db.php';
require_once 'functions.php';

if (!defined('APP_NAME')) define('APP_NAME', 'TechRepair');
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    redirect('login.php');
}

$uid         = (int) $_SESSION['user_id'];
$uname       = $_SESSION['username'] ?? '';
$role        = $_SESSION['role'] ?? 'customer';
$is_tech     = $role === 'technician';
$is_admin    = $role === 'admin';
$is_customer = !$is_tech && !$is_admin;
$home_link   = $is_admin ? 'admin.php' : ($is_tech ? 'tech_dashboard.php' : 'dashboard.php');
$profile_link = $is_tech ? 'tech_profile.php' : ($is_admin ? '' : 'profile.php');

const BOARD_PAGE_SIZE = 8;

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

/* =========================================================
   Helpers
   ========================================================= */
function bd_name($first, $last, $username) {
    $full = trim(trim((string) $first) . ' ' . trim((string) $last));
    return $full !== '' ? $full : (string) $username;
}

function bd_initial($name) {
    $n = trim((string) $name);
    return mb_strtoupper(mb_substr($n !== '' ? $n : '?', 0, 1));
}

function bd_av($name, $size = 40, $role = '') {
    $cls = $role === 'technician' ? 'tech' : ($role === 'admin' ? 'admin' : '');
    return '<span class="bd-av ' . $cls . '" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px;font-size:' . (int) round($size * 0.42) . 'px" aria-hidden="true">'
         . e(bd_initial($name)) . '</span>';
}

function bd_ago($datetime) {
    $t = strtotime((string) $datetime);
    if (!$t) return '';
    $s = max(0, time() - $t);
    if ($s < 60) return 'Just now';
    if ($s < 3600)   { $m = (int) floor($s / 60);    return $m . ' min' . ($m === 1 ? '' : 's') . ' ago'; }
    if ($s < 86400)  { $h = (int) floor($s / 3600);  return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
    if ($s < 604800) { $d = (int) floor($s / 86400); return $d . ' day' . ($d === 1 ? '' : 's') . ' ago'; }
    return date('M j, Y', $t);
}

function bd_when($datetime) {
    $t = strtotime((string) $datetime);
    return $t ? date('D, M j \a\t g:i A', $t) : '';
}

function bd_icon($name, $size = 18) {
    $p = [
        'message' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
        'check'   => '<path d="m5 12 5 5L20 7"/>',
        'send'    => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'pin'     => '<path d="M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'home'    => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'monitor' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'lock'    => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'tool'    => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
        'layers'  => '<path d="M12 3 3 8l9 5 9-5z"/><path d="m3 13 9 5 9-5"/>',
    ];
    return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function bd_clean_qs($qs) {
    return preg_match('/^[A-Za-z0-9_=&%+.\-]{0,200}$/', (string) $qs) ? (string) $qs : '';
}

function bd_redirect($qs, $anchor = '') {
    redirect('board.php' . ($qs !== '' ? '?' . $qs : '') . ($anchor !== '' ? '#' . $anchor : ''));
}

function bd_scalar($conn, $sql, $types = '', $params = []) {
    try {
        $st = $conn->prepare($sql);
        if (!$st) return 0;
        if ($types !== '') $st->bind_param($types, ...$params);
        $st->execute();
        $st->bind_result($n);
        $st->fetch();
        $st->close();
        return (int) $n;
    } catch (Throwable $ex) {
        return 0;
    }
}

function bd_get_post($conn, $id) {
    $st = $conn->prepare("SELECT * FROM repair_posts WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/* One comment as HTML (used on the page and for comments added without a reload) */
function bd_comment_html($c, $viewer_id, $viewer_is_admin, $post_owner_id, $back, $folded = false) {
    $name  = bd_name($c['first_name'] ?? '', $c['last_name'] ?? '', $c['username'] ?? '');
    $crole = $c['role'] ?? '';
    $can   = $viewer_is_admin || (int) $c['user_id'] === $viewer_id || $post_owner_id === $viewer_id;

    $badge = '';
    if ($crole === 'technician') $badge = '<span class="bd-badge tech">Technician</span>';
    elseif ($crole === 'admin')  $badge = '<span class="bd-badge admin">Admin</span>';
    elseif ((int) $c['user_id'] === $post_owner_id) $badge = '<span class="bd-badge owner">Author</span>';

    $html  = '<div class="bd-comment' . ($folded ? ' is-folded' : '') . '" id="comment-' . (int) $c['id'] . '">';
    $html .= bd_av($name, 34, $crole);
    $html .= '<div class="bd-cbody"><div class="bd-bubble"><div class="bd-bubble-top"><strong>' . e($name) . '</strong>' . $badge . '</div>';
    $html .= '<p>' . nl2br(e($c['body'])) . '</p></div>';
    $html .= '<div class="bd-cmeta"><time title="' . e($c['created_at']) . '">' . e(bd_ago($c['created_at'])) . '</time>';
    if ($can) {
        $html .= '<form method="POST" action="board.php" class="bd-inline js-confirm" data-confirm="Delete this comment?">'
               . csrf_field()
               . '<input type="hidden" name="action" value="delete_comment">'
               . '<input type="hidden" name="comment_id" value="' . (int) $c['id'] . '">'
               . '<input type="hidden" name="back" value="' . e($back) . '">'
               . '<button type="submit" class="bd-link-btn">Delete</button></form>';
    }
    $html .= '</div></div></div>';
    return $html;
}

/* =========================================================
   Who is viewing
   ========================================================= */
$me_first = '';
$me_last  = '';
try {
    $st = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $st->bind_param("i", $uid);
    $st->execute();
    $mr = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    $me_first = trim($mr['first_name'] ?? '');
    $me_last  = trim($mr['last_name'] ?? '');
} catch (Throwable $ex) { /* names not available: fall back to the username */ }
$me_name = bd_name($me_first, $me_last, $uname);

/* =========================================================
   Is the database ready?
   ========================================================= */
$setup_error = '';
try {
    $ok1 = $conn->query("SELECT 1 FROM repair_posts LIMIT 1");
    $ok2 = $conn->query("SELECT 1 FROM post_comments LIMIT 1");
    if (!$ok1 || !$ok2) throw new Exception('missing tables');
} catch (Throwable $ex) {
    $setup_error = 'The repair board tables are not set up yet. Run schema_board.sql in phpMyAdmin, then reload this page.';
}
$ready = $setup_error === '';

$cur_qs = bd_clean_qs($_SERVER['QUERY_STRING'] ?? '');

/* =========================================================
   Actions
   ========================================================= */
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $is_ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    $back_qs = bd_clean_qs($_POST['back'] ?? '');

    $json = function ($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    };

    if (!csrf_valid()) {
        if ($is_ajax) $json(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
        flash('error', 'Your session expired. Please try again.');
        bd_redirect($back_qs);
    }

    /* ----- Customer posts a repair request ----- */
    if ($action === 'post') {
        if (!$is_customer) {
            flash('error', 'Only customers can post repair requests.');
            bd_redirect($back_qs);
        }
        $device  = trim($_POST['device_model'] ?? '');
        $service = $_POST['service_type'] ?? '';
        $mode    = $_POST['service_mode'] ?? '';
        $area    = trim($_POST['area'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $raw     = $_POST['appointment_date'] ?? '';
        $tz      = new DateTimeZone('Asia/Manila');
        $dt      = DateTime::createFromFormat('Y-m-d\TH:i', $raw, $tz);
        $_SESSION['board_old'] = $_POST;
        $err = null;

        if (mb_strlen($device) < 2 || mb_strlen($device) > 100) {
            $err = 'Enter your device model (2 to 100 characters).';
        } elseif (!in_array($service, $services, true)) {
            $err = 'Choose a service.';
        } elseif (!in_array($mode, ['home', 'online'], true)) {
            $err = 'Choose where the repair should happen: at your home or online.';
        } elseif ($mode === 'home' && (mb_strlen($area) < 2 || mb_strlen($area) > 120)) {
            $err = 'Enter the area shown on your post, like "Lahug, Cebu City".';
        } elseif ($mode === 'home' && (mb_strlen($address) < 5 || mb_strlen($address) > 255)) {
            $err = 'Enter your full address. It is only shared with the technician who accepts.';
        } elseif (mb_strlen($desc) < 5 || mb_strlen($desc) > 500) {
            $err = 'Describe the problem in 5 to 500 characters.';
        } elseif (!$dt) {
            $err = 'Pick a valid date and time.';
        } elseif ($dt < new DateTime('now', $tz)) {
            $err = 'That time has already passed. Please choose a later time.';
        }

        if ($err) {
            flash('error', $err);
            $_SESSION['board_open_composer'] = 1;
            bd_redirect($back_qs);
        }

        $area_v = $mode === 'home' ? $area : null;
        $addr_v = $mode === 'home' ? $address : null;
        $slot   = $dt->format('Y-m-d H:i:s');
        $now    = date('Y-m-d H:i:s');

        try {
            $st = $conn->prepare(
                "INSERT INTO repair_posts (user_id, device_model, service_type, service_mode, area, address, description, appointment_date, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?)"
            );
            $st->bind_param("issssssss", $uid, $device, $service, $mode, $area_v, $addr_v, $desc, $slot, $now);
            $st->execute();
            $new_id = (int) $conn->insert_id;
            $st->close();
            unset($_SESSION['board_old']);
            flash('success', 'Your request is posted. Technicians can now see it, ask questions and accept it.');
            bd_redirect('', 'post-' . $new_id);
        } catch (Throwable $ex) {
            flash('error', 'We couldn\'t post your request. Please try again.');
            $_SESSION['board_open_composer'] = 1;
            bd_redirect($back_qs);
        }
    }

    /* ----- Anyone can comment ----- */
    if ($action === 'comment') {
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $body    = trim($_POST['body'] ?? '');
        $post    = bd_get_post($conn, $post_id);
        $err     = null;

        if ($body === '') {
            $err = 'Write something first.';
        } elseif (mb_strlen($body) > 1000) {
            $err = 'Keep comments to 1000 characters or fewer.';
        } elseif (!$post || $post['status'] === 'Withdrawn') {
            $err = 'This request is no longer available.';
        }

        if (!$err) {
            try {
                $now = date('Y-m-d H:i:s');
                $st = $conn->prepare("INSERT INTO post_comments (post_id, user_id, body, created_at) VALUES (?, ?, ?, ?)");
                $st->bind_param("iiss", $post_id, $uid, $body, $now);
                $st->execute();
                $cid = (int) $conn->insert_id;
                $st->close();

                if ($is_ajax) {
                    $count = bd_scalar($conn, "SELECT COUNT(*) FROM post_comments WHERE post_id = ?", "i", [$post_id]);
                    $c = ['id' => $cid, 'user_id' => $uid, 'body' => $body, 'created_at' => $now,
                          'username' => $uname, 'first_name' => $me_first, 'last_name' => $me_last, 'role' => $role];
                    $json(['ok' => true, 'html' => bd_comment_html($c, $uid, $is_admin, (int) $post['user_id'], $back_qs), 'count' => $count]);
                }
                bd_redirect($back_qs, 'post-' . $post_id);
            } catch (Throwable $ex) {
                $err = 'We couldn\'t post your comment. Please try again.';
            }
        }

        if ($is_ajax) $json(['ok' => false, 'error' => $err]);
        flash('error', $err);
        bd_redirect($back_qs, 'post-' . $post_id);
    }

    /* ----- Delete a comment (its author, the post's author, or an admin) ----- */
    if ($action === 'delete_comment') {
        $cid = (int) ($_POST['comment_id'] ?? 0);
        $st = $conn->prepare("SELECT c.user_id, c.post_id, p.user_id AS owner FROM post_comments c JOIN repair_posts p ON p.id = c.post_id WHERE c.id = ?");
        $st->bind_param("i", $cid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if ($row && ($is_admin || (int) $row['user_id'] === $uid || (int) $row['owner'] === $uid)) {
            $st = $conn->prepare("DELETE FROM post_comments WHERE id = ?");
            $st->bind_param("i", $cid);
            $st->execute();
            $st->close();
        }
        bd_redirect($back_qs, $row ? 'post-' . (int) $row['post_id'] : '');
    }

    /* ----- Only a technician can accept. Accepting creates a confirmed booking. ----- */
    if ($action === 'accept') {
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $anchor  = 'post-' . $post_id;

        if (!$is_tech) {
            flash('error', 'Only technicians can accept repair requests.');
            bd_redirect($back_qs, $anchor);
        }

        $post = bd_get_post($conn, $post_id);
        if (!$post || $post['status'] !== 'Open') {
            flash('error', 'That request is no longer available. Another technician may have just accepted it.');
            bd_redirect($back_qs, $anchor);
        }
        if (strtotime($post['appointment_date']) < time()) {
            flash('error', 'The requested time for this repair has already passed.');
            bd_redirect($back_qs, $anchor);
        }

        try {
            $conn->begin_transaction();

            // Claim it first, so two technicians can never both get it
            $now = date('Y-m-d H:i:s');
            $st = $conn->prepare("UPDATE repair_posts SET status = 'Accepted', accepted_by = ?, accepted_at = ? WHERE id = ? AND status = 'Open'");
            $st->bind_param("ssi", $uname, $now, $post_id);
            $st->execute();
            $claimed = $st->affected_rows;
            $st->close();

            if ($claimed !== 1) {
                $conn->rollback();
                flash('error', 'Another technician just accepted this request.');
                bd_redirect($back_qs, $anchor);
            }

            $st = $conn->prepare(
                "INSERT INTO bookings (user_id, device_model, service_type, service_mode, address, issue_notes, technician, appointment_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed')"
            );
            $st->bind_param("isssssss", $post['user_id'], $post['device_model'], $post['service_type'], $post['service_mode'],
                            $post['address'], $post['description'], $uname, $post['appointment_date']);
            $st->execute();
            $booking_id = (int) $conn->insert_id;
            $st->close();

            $st = $conn->prepare("UPDATE repair_posts SET booking_id = ? WHERE id = ?");
            $st->bind_param("ii", $booking_id, $post_id);
            $st->execute();
            $st->close();

            $conn->commit();
            flash('success', 'You accepted this request. It is now a confirmed job under My Active Jobs.');
        } catch (Throwable $ex) {
            try { $conn->rollback(); } catch (Throwable $ex2) { /* nothing to undo */ }
            flash('error', 'We couldn\'t accept that request. Please try again.');
        }
        bd_redirect($back_qs, $anchor);
    }

    /* ----- The author takes their request down (only while it is still open) ----- */
    if ($action === 'withdraw') {
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $st = $conn->prepare("UPDATE repair_posts SET status = 'Withdrawn' WHERE id = ? AND user_id = ? AND status = 'Open'");
        $st->bind_param("ii", $post_id, $uid);
        $st->execute();
        $done = $st->affected_rows > 0;
        $st->close();
        flash($done ? 'success' : 'error', $done ? 'Your request was withdrawn.' : 'That request can no longer be withdrawn.');
        bd_redirect($back_qs);
    }

    /* ----- Admin removes a post ----- */
    if ($action === 'delete_post' && $is_admin) {
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $st = $conn->prepare("DELETE FROM post_comments WHERE post_id = ?");
        $st->bind_param("i", $post_id);
        $st->execute();
        $st->close();
        $st = $conn->prepare("DELETE FROM repair_posts WHERE id = ?");
        $st->bind_param("i", $post_id);
        $st->execute();
        $st->close();
        flash('success', 'Post deleted.');
        bd_redirect($back_qs);
    }

    bd_redirect($back_qs);
}

$flash = take_flash();
$old   = $_SESSION['board_old'] ?? [];
unset($_SESSION['board_old']);
$open_composer = !empty($_SESSION['board_open_composer']);
unset($_SESSION['board_open_composer']);

/* =========================================================
   Load the feed
   ========================================================= */
$filter = $_GET['f'] ?? 'all';
if (!in_array($filter, ['all', 'open', 'accepted', 'mine'], true)) $filter = 'all';
$q    = trim($_GET['q'] ?? '');
$svc  = $_GET['service'] ?? '';
if ($svc !== '' && !in_array($svc, $services, true)) $svc = '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$posts      = [];
$comments   = [];
$total      = 0;
$counts     = ['all' => 0, 'open' => 0, 'accepted' => 0, 'mine' => 0];
$ratings    = [];   // technician username => [avg, count]
$techs      = [];   // technician list for the sidebar
$tech_names = [];

if ($ready) {
    $where = [];
    $types = '';
    $params = [];

    if (!$is_admin) {
        $where[] = "(p.status <> 'Withdrawn' OR p.user_id = ?)";
        $types .= 'i'; $params[] = $uid;
    }
    if ($filter === 'open') {
        $where[] = "p.status = 'Open'";
    } elseif ($filter === 'accepted') {
        $where[] = "p.status = 'Accepted'";
    } elseif ($filter === 'mine') {
        if ($is_tech) { $where[] = "p.accepted_by = ?"; $types .= 's'; $params[] = $uname; }
        elseif ($is_customer) { $where[] = "p.user_id = ?"; $types .= 'i'; $params[] = $uid; }
    }
    if ($svc !== '') {
        $where[] = "p.service_type = ?";
        $types .= 's'; $params[] = $svc;
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = "(p.device_model LIKE ? OR p.description LIKE ? OR p.area LIKE ? OR p.service_type LIKE ?)";
        $types .= 'ssss'; array_push($params, $like, $like, $like, $like);
    }
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = bd_scalar($conn, "SELECT COUNT(*) FROM repair_posts p $where_sql", $types, $params);
    $pages = max(1, (int) ceil($total / BOARD_PAGE_SIZE));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * BOARD_PAGE_SIZE;

    try {
        $st = $conn->prepare(
            "SELECT p.*, u.username AS a_username, u.first_name AS a_first, u.last_name AS a_last, b.status AS booking_status,
                    (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id) AS comment_count
             FROM repair_posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN bookings b ON b.id = p.booking_id
             $where_sql
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ? OFFSET ?"
        );
        $limit = BOARD_PAGE_SIZE;
        $st->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
        $st->execute();
        $posts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    } catch (Throwable $ex) {
        $setup_error = 'We couldn\'t load the board. Make sure the users table has first_name and last_name columns (run migration.sql).';
        $posts = [];
    }

    if ($posts) {
        $in = implode(',', array_map('intval', array_column($posts, 'id')));
        $cres = $conn->query(
            "SELECT c.id, c.post_id, c.user_id, c.body, c.created_at, u.username, u.first_name, u.last_name, u.role
             FROM post_comments c JOIN users u ON u.id = c.user_id
             WHERE c.post_id IN ($in) ORDER BY c.created_at ASC, c.id ASC"
        );
        if ($cres) {
            while ($c = $cres->fetch_assoc()) {
                $comments[(int) $c['post_id']][] = $c;
            }
        }
    }

    // Sidebar counts
    $counts['open']     = bd_scalar($conn, "SELECT COUNT(*) FROM repair_posts WHERE status = 'Open'");
    $counts['accepted'] = bd_scalar($conn, "SELECT COUNT(*) FROM repair_posts WHERE status = 'Accepted'");
    $counts['all']      = $counts['open'] + $counts['accepted'];
    if ($is_tech) {
        $counts['mine'] = bd_scalar($conn, "SELECT COUNT(*) FROM repair_posts WHERE accepted_by = ?", "s", [$uname]);
    } elseif ($is_customer) {
        $counts['mine'] = bd_scalar($conn, "SELECT COUNT(*) FROM repair_posts WHERE user_id = ? AND status <> 'Withdrawn'", "i", [$uid]);
    }

    // Technicians (with ratings from your existing reviews table, if there is one)
    try {
        $tr = $conn->query("SELECT username, first_name, last_name FROM users WHERE role = 'technician' ORDER BY username ASC");
        while ($t = $tr->fetch_assoc()) {
            $techs[$t['username']] = ['name' => bd_name($t['first_name'], $t['last_name'], $t['username']), 'avg' => 0, 'n' => 0, 'done' => 0];
            $tech_names[$t['username']] = $techs[$t['username']]['name'];
        }
    } catch (Throwable $ex) { /* no technician list */ }
    try {
        $rr = $conn->query("SELECT technician_username, AVG(rating) AS a, COUNT(*) AS n FROM technician_reviews GROUP BY technician_username");
        while ($r = $rr->fetch_assoc()) {
            if (isset($techs[$r['technician_username']])) {
                $techs[$r['technician_username']]['avg'] = round((float) $r['a'], 1);
                $techs[$r['technician_username']]['n']   = (int) $r['n'];
            }
        }
    } catch (Throwable $ex) { /* reviews table not created: no ratings shown */ }
    try {
        $dr = $conn->query("SELECT technician, COUNT(*) AS n FROM bookings WHERE status = 'Completed' AND technician IS NOT NULL AND technician <> '' GROUP BY technician");
        while ($d = $dr->fetch_assoc()) {
            if (isset($techs[$d['technician']])) $techs[$d['technician']]['done'] = (int) $d['n'];
        }
    } catch (Throwable $ex) { /* leave at 0 */ }
    uasort($techs, function ($a, $b) {
        if (($a['n'] > 0) !== ($b['n'] > 0)) return $a['n'] > 0 ? -1 : 1;
        if ($a['avg'] != $b['avg']) return $b['avg'] <=> $a['avg'];
        if ($a['done'] != $b['done']) return $b['done'] <=> $a['done'];
        return strcasecmp($a['name'], $b['name']);
    });
}

function bd_url($extra = []) {
    global $filter, $q, $svc;
    $base = ['f' => $filter, 'q' => $q, 'service' => $svc];
    $m = array_merge($base, $extra);
    if (($m['f'] ?? 'all') === 'all') unset($m['f']);
    $m = array_filter($m, function ($v) { return $v !== '' && $v !== null && $v !== 1; });
    return 'board.php' . ($m ? '?' . http_build_query($m) : '');
}

$min_attr   = (new DateTime('now'))->format('Y-m-d\TH:i');
$role_label = $is_tech ? 'Technician' : ($is_admin ? 'Admin' : 'Customer');
$old_mode   = $old['service_mode'] ?? 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repair board - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .bd { --bd-card: linear-gradient(135deg, rgba(30,30,45,.62), rgba(15,15,25,.82)); }
        :where(.bd) button { width: auto; padding: 0; background: none; box-shadow: none; border: none; border-radius: 0; font: inherit; color: inherit; cursor: pointer; }
        :where(.bd) button:hover, :where(.bd) button:active { transform: none; box-shadow: none; }
        .bd a { text-decoration: none; }
        [hidden] { display: none !important; }

        /* ----- Top bar (full width) ----- */
        .bd-top { position: sticky; top: 0; z-index: 30; background: rgba(8,9,20,.78); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-bottom: 1px solid var(--line); }
        .bd-top-in { display: flex; align-items: center; gap: 24px; padding: 12px 28px; max-width: 1800px; margin: 0 auto; }
        .bd-brand-sub { font-family: 'Inter', sans-serif; font-weight: 500; font-size: .8rem; color: #a5b4fc; margin-left: 8px; padding: 3px 10px; border-radius: 999px; background: rgba(99,102,241,.16); border: 1px solid rgba(99,102,241,.35); }
        .bd-search { position: relative; flex: 1; max-width: 560px; margin: 0 auto; }
        .bd-search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; pointer-events: none; }
        .bd-search input { width: 100%; padding: 11px 16px 11px 42px; border-radius: 999px; font-size: .9rem; }
        .bd-top-actions { display: flex; align-items: center; gap: 10px; margin-left: auto; }
        .bd-nav-btn { color: #cbd5e1; font-size: .88rem; padding: 8px 16px; border-radius: 10px; background: rgba(255,255,255,.06); transition: .2s; }
        .bd-nav-btn:hover { background: rgba(255,255,255,.12); color: #fff; }
        .bd-user { display: flex; align-items: center; gap: 10px; font-size: .9rem; color: #cbd5e1; }

        /* ----- Three-column layout using the whole screen ----- */
        .bd-layout { display: grid; grid-template-columns: 280px minmax(0, 1fr) 340px; grid-template-areas: "left main right"; gap: 28px; align-items: start; max-width: 1800px; margin: 0 auto; padding: 28px; }
        .bd-left { grid-area: left; } .bd-main { grid-area: main; } .bd-right { grid-area: right; }
        .bd-left, .bd-right { position: sticky; top: 86px; display: grid; gap: 18px; }
        .bd-main { max-width: 860px; width: 100%; margin: 0 auto; display: grid; gap: 20px; }

        .bd-card { background: var(--bd-card); border: 1px solid var(--line); border-radius: 22px; padding: 22px; box-shadow: 0 14px 34px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.05); }
        .bd-card h4 { font-size: .95rem; color: #fff; margin: 0 0 14px; }

        .bd-av { display: inline-grid; place-items: center; flex: none; border-radius: 50%; color: #fff; font-weight: 600; font-family: 'Sora', 'Inter', sans-serif; line-height: 1; background: linear-gradient(135deg, #6366f1, #a855f7); }
        .bd-av.tech { background: linear-gradient(135deg, #0ea5e9, #6366f1); }
        .bd-av.admin { background: linear-gradient(135deg, #f59e0b, #ef4444); }

        /* Left: me + navigation */
        .bd-me { text-align: center; }
        .bd-me .bd-av { margin: 0 auto 12px; box-shadow: 0 0 0 3px rgba(129,140,248,.35), 0 10px 26px rgba(99,102,241,.35); }
        .bd-me strong { display: block; color: #fff; font-size: 1.05rem; }
        .bd-role { display: inline-block; margin-top: 6px; padding: 2px 12px; border-radius: 999px; font-size: .74rem; color: #c4b5fd; background: rgba(168,85,247,.14); border: 1px solid rgba(168,85,247,.32); }
        .bd-me .bd-back { display: block; margin-top: 16px; padding: 10px; border-radius: 12px; font-size: .86rem; color: #e2e8f0; background: rgba(255,255,255,.06); }
        .bd-me .bd-back:hover { background: rgba(255,255,255,.12); }

        .bd-nav { display: grid; gap: 4px; padding: 12px; }
        .bd-nav a { display: flex; align-items: center; justify-content: space-between; padding: 11px 14px; border-radius: 12px; color: #cbd5e1; font-size: .92rem; transition: .2s; }
        .bd-nav a:hover { background: rgba(255,255,255,.06); color: #fff; }
        .bd-nav a.is-active { background: linear-gradient(135deg, rgba(79,70,229,.35), rgba(147,51,234,.28)); color: #fff; box-shadow: inset 0 0 0 1px rgba(129,140,248,.35); }
        .bd-nav .n { font-size: .75rem; padding: 1px 9px; border-radius: 999px; background: rgba(255,255,255,.09); color: #cbd5e1; }

        .bd-svc { display: flex; flex-wrap: wrap; gap: 8px; }
        .bd-svc a { font-size: .78rem; padding: 6px 12px; border-radius: 999px; color: #94a3b8; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08); transition: .2s; }
        .bd-svc a:hover { color: #fff; border-color: rgba(168,85,247,.5); }
        .bd-svc a.is-active { color: #fff; background: rgba(99,102,241,.3); border-color: rgba(129,140,248,.6); }

        /* ----- Composer ----- */
        .bd-compose-top { display: flex; align-items: center; gap: 14px; }
        .bd-compose-toggle { flex: 1; text-align: left; padding: 14px 20px; border-radius: 999px; color: #94a3b8; background: rgba(0,0,0,.28); border: 1px solid var(--line) !important; font-size: .95rem; transition: .2s; }
        .bd-compose-toggle:hover { background: rgba(0,0,0,.4); color: #e2e8f0; }
        .bd-compose-form { display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--line); }
        .bd-compose.is-open .bd-compose-form { display: block; }
        .bd-compose.is-open .bd-compose-toggle { display: none; }
        .bd-compose-head { display: none; align-items: center; justify-content: space-between; }
        .bd-compose.is-open .bd-compose-head { display: flex; }
        .bd-compose.is-open .bd-compose-top .bd-av { display: none; }
        .bd-compose-head h3 { margin: 0; font-size: 1.1rem; color: #fff; }
        .bd-x { width: 34px; height: 34px; border-radius: 10px !important; font-size: 1.3rem; background: rgba(255,255,255,.06) !important; color: #cbd5e1; }
        .bd-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .bd-compose-form .form-group { margin-bottom: 16px; }
        .bd-compose-form textarea { min-height: 96px; }
        .bd-compose-form select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='M1 1l5 5 5-5'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 16px center; padding-right: 40px; }
        .bd-compose-form input[type="datetime-local"] { color-scheme: dark; }
        .bd-count { float: right; font-weight: 400; color: #64748b; font-size: .78rem; }
        .bd-submit { display: inline-flex !important; align-items: center; gap: 8px; padding: 13px 26px !important; border-radius: 12px !important; font-weight: 600; font-size: .95rem; background: linear-gradient(135deg, #4f46e5, #9333ea) !important; color: #fff; box-shadow: 0 8px 22px rgba(99,102,241,.35) !important; }
        .bd-private { color: #64748b; font-size: .78rem; margin-top: 6px; display: flex; align-items: center; gap: 6px; }

        .bd-info { display: flex; gap: 14px; align-items: flex-start; }
        .bd-info .ico { flex: none; width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; color: #7dd3fc; background: rgba(56,189,248,.12); border: 1px solid rgba(56,189,248,.3); }
        .bd-info strong { color: #fff; display: block; margin-bottom: 2px; }
        .bd-info p { color: #94a3b8; font-size: .88rem; line-height: 1.5; }

        /* ----- Posts ----- */
        .bd-feed-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 0 4px; }
        .bd-feed-head h2 { font-family: 'Sora', 'Inter', sans-serif; font-size: 1.15rem; color: #fff; margin: 0; background: none; -webkit-text-fill-color: currentColor; }
        .bd-feed-head span { color: #64748b; font-size: .84rem; }
        .bd-post { padding: 22px 22px 8px; scroll-margin-top: 90px; }
        .bd-post.is-withdrawn { opacity: .6; }
        .bd-post-top { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .bd-post-top .who { flex: 1; min-width: 0; }
        .bd-post-top strong { color: #fff; display: block; font-size: .98rem; }
        .bd-post-top time { color: #64748b; font-size: .78rem; }
        .bd-pill { padding: 4px 12px; border-radius: 999px; font-size: .75rem; font-weight: 500; white-space: nowrap; border: 1px solid; }
        .bd-pill.open { color: #c4b5fd; background: rgba(168,85,247,.14); border-color: rgba(168,85,247,.38); }
        .bd-pill.accepted { color: #34d399; background: rgba(52,211,153,.1); border-color: rgba(52,211,153,.35); }
        .bd-pill.progress { color: #7dd3fc; background: rgba(56,189,248,.12); border-color: rgba(56,189,248,.38); }
        .bd-pill.late { color: #fbbf24; background: rgba(251,191,36,.1); border-color: rgba(251,191,36,.35); }
        .bd-pill.muted { color: #94a3b8; background: rgba(148,163,184,.1); border-color: rgba(148,163,184,.25); }
        .bd-title { font-family: 'Sora', 'Inter', sans-serif; font-size: 1.12rem; color: #fff; margin: 0 0 4px; background: none; -webkit-text-fill-color: currentColor; }
        .bd-sub { color: #a5b4fc; font-size: .88rem; margin-bottom: 12px; }
        .bd-desc { color: #cbd5e1; line-height: 1.6; white-space: pre-line; margin-bottom: 14px; }
        .bd-facts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .bd-fact { display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px; border-radius: 10px; font-size: .82rem; color: #cbd5e1; background: rgba(0,0,0,.26); border: 1px solid rgba(255,255,255,.07); }
        .bd-fact svg { color: #818cf8; }
        .bd-fact.private { color: #fcd34d; border-color: rgba(251,191,36,.3); }
        .bd-fact.private svg { color: #fbbf24; }

        .bd-accepted { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 14px; margin-bottom: 14px; background: linear-gradient(135deg, rgba(52,211,153,.12), rgba(56,189,248,.08)); border: 1px solid rgba(52,211,153,.3); }
        .bd-check { flex: none; width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; color: #06281c; background: #34d399; }
        .bd-accepted strong { color: #fff; font-size: .92rem; }
        .bd-accepted small { display: block; color: #94a3b8; font-size: .78rem; margin-top: 1px; }
        .bd-star { color: #fbbf24; font-size: .8rem; margin-left: 6px; }

        .bd-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line); }
        .bd-act { display: inline-flex !important; align-items: center; gap: 8px; padding: 8px 12px !important; border-radius: 10px !important; color: #94a3b8; font-size: .88rem; transition: .2s; }
        .bd-act:hover { background: rgba(255,255,255,.06) !important; color: #fff; }
        .bd-act-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .bd-primary { display: inline-flex !important; align-items: center; gap: 8px; padding: 10px 20px !important; border-radius: 12px !important; font-weight: 600; font-size: .88rem; color: #fff; background: linear-gradient(135deg, #059669, #10b981) !important; box-shadow: 0 8px 20px rgba(16,185,129,.28) !important; }
        .bd-primary:hover { filter: brightness(1.08); }
        .bd-ghost { padding: 9px 14px !important; border-radius: 10px !important; font-size: .82rem; color: #94a3b8; border: 1px solid var(--line) !important; }
        .bd-ghost:hover { color: #fff; background: rgba(255,255,255,.06) !important; }
        .bd-ghost.danger:hover { color: #f87171; border-color: rgba(248,113,113,.4) !important; }
        .bd-note { color: #fbbf24; font-size: .8rem; }
        .bd-inline { display: inline; margin: 0; }

        /* ----- Comments ----- */
        .bd-comments { padding: 4px 0 16px; border-top: 1px solid var(--line); }
        .bd-more { display: block; margin: 12px 0 4px; padding: 4px 0 !important; font-size: .85rem; font-weight: 500; color: #a5b4fc; }
        .bd-more:hover { color: #fff; text-decoration: underline; }
        .bd-clist { display: grid; gap: 12px; margin: 14px 0; }
        .bd-comment { display: grid; grid-template-columns: 34px 1fr; gap: 10px; }
        .bd-comment.is-folded { display: none; }
        .bd-bubble { background: rgba(255,255,255,.06); border-radius: 6px 18px 18px 18px; padding: 10px 14px; display: inline-block; max-width: 100%; }
        .bd-bubble-top { display: flex; align-items: center; gap: 8px; margin-bottom: 2px; }
        .bd-bubble-top strong { color: #fff; font-size: .86rem; }
        .bd-bubble p { color: #e2e8f0; font-size: .9rem; line-height: 1.5; overflow-wrap: anywhere; }
        .bd-badge { padding: 1px 8px; border-radius: 999px; font-size: .68rem; font-weight: 500; border: 1px solid; }
        .bd-badge.tech { color: #7dd3fc; background: rgba(56,189,248,.12); border-color: rgba(56,189,248,.38); }
        .bd-badge.owner { color: #c4b5fd; background: rgba(168,85,247,.14); border-color: rgba(168,85,247,.38); }
        .bd-badge.admin { color: #fbbf24; background: rgba(251,191,36,.1); border-color: rgba(251,191,36,.35); }
        .bd-cmeta { display: flex; align-items: center; gap: 14px; padding: 3px 6px 0; font-size: .75rem; color: #64748b; }
        .bd-link-btn { padding: 0 !important; font-size: .75rem; color: #64748b; }
        .bd-link-btn:hover { color: #f87171; text-decoration: underline; }
        .bd-cform { display: grid; grid-template-columns: 34px 1fr auto; gap: 10px; align-items: center; margin-top: 4px; }
        .bd-cform textarea { min-height: 0 !important; height: 42px; max-height: 140px; padding: 10px 16px; border-radius: 22px; font-size: .9rem; resize: none; line-height: 1.4; }
        .bd-send { width: 40px; height: 40px; border-radius: 50% !important; display: grid !important; place-items: center; color: #fff; background: linear-gradient(135deg, #4f46e5, #9333ea) !important; }
        .bd-send:disabled { opacity: .5; cursor: not-allowed; }
        .bd-cerr { grid-column: 2 / 4; color: #f87171; font-size: .8rem; }

        .bd-empty { text-align: center; padding: 48px 20px; color: #94a3b8; }
        .bd-empty strong { display: block; color: #fff; font-size: 1.05rem; margin-bottom: 6px; }
        .bd-pager { display: flex; justify-content: space-between; gap: 12px; }
        .bd-pager a { padding: 11px 20px; border-radius: 12px; font-size: .88rem; color: #e2e8f0; background: rgba(255,255,255,.06); border: 1px solid var(--line); }
        .bd-pager a:hover { background: rgba(255,255,255,.12); }

        /* ----- Right column ----- */
        .bd-tech { display: flex; align-items: center; gap: 12px; padding: 10px 0; }
        .bd-tech + .bd-tech { border-top: 1px solid var(--line); }
        .bd-tech strong { display: block; color: #fff; font-size: .9rem; }
        .bd-tech small { color: #94a3b8; font-size: .76rem; }
        .bd-steps { list-style: none; display: grid; gap: 14px; }
        .bd-steps li { display: flex; gap: 12px; font-size: .86rem; color: #cbd5e1; line-height: 1.45; }
        .bd-steps .ico { flex: none; width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; color: #c4b5fd; background: rgba(99,102,241,.16); border: 1px solid rgba(129,140,248,.35); }

        .bd-setup { padding: 18px 22px; border-radius: 16px; color: #fbbf24; background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.3); }

        /* ----- Smaller screens ----- */
        @media (max-width: 1280px) {
            .bd-layout { grid-template-columns: 250px minmax(0, 1fr); grid-template-areas: "left main" "left right"; gap: 22px; }
            .bd-right { position: static; grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 860px) {
            .bd-top-in { flex-wrap: wrap; gap: 12px; padding: 12px 16px; }
            .bd-search { order: 3; flex-basis: 100%; max-width: none; }
            .bd-user span.nm { display: none; }
            .bd-layout { grid-template-columns: minmax(0, 1fr); grid-template-areas: "left" "main" "right"; padding: 16px; gap: 18px; }
            .bd-left { position: static; }
            .bd-me { display: none; }
            .bd-nav { display: flex; overflow-x: auto; gap: 6px; padding: 8px; }
            .bd-nav a { white-space: nowrap; gap: 10px; }
            .bd-svc-card { display: none; }
            .bd-right { grid-template-columns: 1fr; }
            .bd-grid2 { grid-template-columns: 1fr; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body class="dash bd">

<header class="bd-top">
    <div class="bd-top-in">
        <a class="brand" href="board.php"><span class="brand-mark" aria-hidden="true"></span><?= e(APP_NAME) ?><span class="bd-brand-sub">Repair board</span></a>

        <form class="bd-search" method="GET" action="board.php" role="search">
            <?= bd_icon('search', 18) ?>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search requests by device, problem or area" autocomplete="off">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
            <?php if ($svc !== ''): ?><input type="hidden" name="service" value="<?= e($svc) ?>"><?php endif; ?>
        </form>

        <nav class="bd-top-actions">
            <a class="bd-nav-btn" href="<?= e($home_link) ?>">Dashboard</a>
            <?php if ($profile_link): ?><a class="bd-nav-btn" href="<?= e($profile_link) ?>">My profile</a><?php endif; ?>
            <span class="bd-user"><?= bd_av($me_name, 36, $role) ?><span class="nm"><?= e($me_name) ?></span></span>
            <a href="logout.php" class="logout-link">Log out</a>
        </nav>
    </div>
</header>

<div class="bd-layout">

    <!-- ===== Left: you and filters ===== -->
    <aside class="bd-left">
        <div class="bd-card bd-me">
            <?= bd_av($me_name, 64, $role) ?>
            <strong><?= e($me_name) ?></strong>
            <span class="bd-role"><?= e($role_label) ?></span>
            <a class="bd-back" href="<?= e($home_link) ?>">Back to my dashboard</a>
        </div>

        <nav class="bd-card bd-nav" aria-label="Feed filters">
            <a href="<?= e(bd_url(['f' => 'all', 'page' => 1])) ?>" class="<?= $filter === 'all' ? 'is-active' : '' ?>"><span>All requests</span><span class="n"><?= (int) $counts['all'] ?></span></a>
            <a href="<?= e(bd_url(['f' => 'open', 'page' => 1])) ?>" class="<?= $filter === 'open' ? 'is-active' : '' ?>"><span>Open</span><span class="n"><?= (int) $counts['open'] ?></span></a>
            <a href="<?= e(bd_url(['f' => 'accepted', 'page' => 1])) ?>" class="<?= $filter === 'accepted' ? 'is-active' : '' ?>"><span>Accepted</span><span class="n"><?= (int) $counts['accepted'] ?></span></a>
            <?php if (!$is_admin): ?>
                <a href="<?= e(bd_url(['f' => 'mine', 'page' => 1])) ?>" class="<?= $filter === 'mine' ? 'is-active' : '' ?>"><span><?= $is_tech ? 'Jobs I accepted' : 'My requests' ?></span><span class="n"><?= (int) $counts['mine'] ?></span></a>
            <?php endif; ?>
        </nav>

        <div class="bd-card bd-svc-card">
            <h4>Filter by service</h4>
            <div class="bd-svc">
                <a href="<?= e(bd_url(['service' => '', 'page' => 1])) ?>" class="<?= $svc === '' ? 'is-active' : '' ?>">All</a>
                <?php foreach ($services as $s): ?>
                    <a href="<?= e(bd_url(['service' => $s, 'page' => 1])) ?>" class="<?= $svc === $s ? 'is-active' : '' ?>"><?= e($s) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>

    <!-- ===== Center: composer and feed ===== -->
    <main class="bd-main">
        <?php if ($flash): ?>
            <div class="toast <?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
        <?php endif; ?>
        <?php if ($setup_error): ?>
            <div class="bd-setup" role="alert"><?= e($setup_error) ?></div>
        <?php endif; ?>

        <?php if ($ready && $is_customer): ?>
            <section class="bd-card bd-compose<?= $open_composer ? ' is-open' : '' ?>" id="composer">
                <div class="bd-compose-top">
                    <?= bd_av($me_name, 44, $role) ?>
                    <button type="button" class="bd-compose-toggle" id="composeToggle">What needs fixing, <?= e($me_first !== '' ? $me_first : $me_name) ?>?</button>
                    <div class="bd-compose-head" style="flex:1">
                        <h3>Post a repair request</h3>
                        <button type="button" class="bd-x" id="composeClose" aria-label="Close">&times;</button>
                    </div>
                </div>

                <form method="POST" action="board.php" class="bd-compose-form" id="composeForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="post">
                    <input type="hidden" name="back" value="<?= e($cur_qs) ?>">

                    <fieldset class="mode-pick">
                        <legend>Where should it be fixed?</legend>
                        <label class="mode-card">
                            <input type="radio" name="service_mode" value="home" <?= $old_mode !== 'online' ? 'checked' : '' ?>>
                            <span class="mode-body"><strong>At my home</strong><small>Technician visits you</small></span>
                        </label>
                        <label class="mode-card">
                            <input type="radio" name="service_mode" value="online" <?= $old_mode === 'online' ? 'checked' : '' ?>>
                            <span class="mode-body"><strong>Online</strong><small>Remote session</small></span>
                        </label>
                    </fieldset>

                    <div class="bd-grid2">
                        <div class="form-group">
                            <label for="device_model">Device model</label>
                            <input type="text" id="device_model" name="device_model" maxlength="100" required placeholder="e.g. Acer Nitro V15" autocomplete="off" value="<?= e($old['device_model'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="service_type">Service</label>
                            <select id="service_type" name="service_type" required>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?= e($s) ?>" <?= ($old['service_type'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">What is the problem? <span class="bd-count" id="descCount">0/500</span></label>
                        <textarea id="description" name="description" maxlength="500" required placeholder="Tell technicians what is happening, when it started and anything you have tried."><?= e($old['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="appointment_date">When do you need it?</label>
                        <input type="datetime-local" id="appointment_date" name="appointment_date" min="<?= e($min_attr) ?>" required value="<?= e($old['appointment_date'] ?? '') ?>">
                    </div>

                    <div id="homeFields">
                        <div class="form-group">
                            <label for="area">Area <span class="bd-count">shown on your post</span></label>
                            <input type="text" id="area" name="area" maxlength="120" placeholder="e.g. Lahug, Cebu City" autocomplete="off" value="<?= e($old['area'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="address">Full address</label>
                            <input type="text" id="address" name="address" maxlength="255" placeholder="House number, street, barangay" autocomplete="off" value="<?= e($old['address'] ?? '') ?>">
                            <p class="bd-private"><?= bd_icon('lock', 14) ?> Private. Only the technician who accepts can see it.</p>
                        </div>
                    </div>

                    <button type="submit" class="bd-submit"><?= bd_icon('send', 18) ?> Post request</button>
                </form>
            </section>
        <?php elseif ($ready && $is_tech): ?>
            <section class="bd-card bd-info">
                <span class="ico"><?= bd_icon('tool', 22) ?></span>
                <div>
                    <strong>Pick up work from the board</strong>
                    <p>Read the requests, ask questions in the comments and press <b>Accept request</b> on one you can do. It becomes a confirmed job on your dashboard, and only one technician can take it.</p>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($ready): ?>
            <div class="bd-feed-head">
                <h2><?= $filter === 'open' ? 'Open requests' : ($filter === 'accepted' ? 'Accepted requests' : ($filter === 'mine' ? ($is_tech ? 'Jobs I accepted' : 'My requests') : 'Latest requests')) ?></h2>
                <span><?= (int) $total ?> post<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' for "' . e($q) . '"' : '' ?></span>
            </div>

            <?php if (!$posts): ?>
                <div class="bd-card bd-empty">
                    <strong>Nothing here yet</strong>
                    <?= $is_customer ? 'Be the first to post a repair request and technicians will respond.' : 'No requests match this view. Try another filter.' ?>
                </div>
            <?php endif; ?>

            <?php foreach ($posts as $p):
                $pid       = (int) $p['id'];
                $author    = bd_name($p['a_first'], $p['a_last'], $p['a_username']);
                $is_owner  = (int) $p['user_id'] === $uid;
                $appt_ts   = strtotime($p['appointment_date']);
                $expired   = $p['status'] === 'Open' && $appt_ts < time();
                $acc_by    = $p['accepted_by'];
                $can_addr  = $is_admin || $is_owner || ($acc_by !== null && $acc_by === $uname);
                $clist     = $comments[$pid] ?? [];
                $ccount    = count($clist);

                // Status badge
                if ($p['status'] === 'Withdrawn')       { $pill = ['muted', 'Withdrawn']; }
                elseif ($p['status'] === 'Accepted') {
                    $bs = $p['booking_status'] ?? '';
                    if ($bs === 'Cancelled')            $pill = ['muted', 'Cancelled'];
                    elseif ($bs === 'Completed')        $pill = ['accepted', 'Completed'];
                    elseif ($bs === 'In Progress')      $pill = ['progress', 'In progress'];
                    else                                $pill = ['accepted', 'Accepted'];
                }
                elseif ($expired)                       { $pill = ['late', 'Time passed']; }
                else                                    { $pill = ['open', 'Open']; }
            ?>
                <article class="bd-card bd-post<?= $p['status'] === 'Withdrawn' ? ' is-withdrawn' : '' ?>" id="post-<?= $pid ?>">
                    <div class="bd-post-top">
                        <?= bd_av($author, 46) ?>
                        <div class="who">
                            <strong><?= e($author) ?></strong>
                            <time title="<?= e($p['created_at']) ?>"><?= e(bd_ago($p['created_at'])) ?></time>
                        </div>
                        <span class="bd-pill <?= e($pill[0]) ?>"><?= e($pill[1]) ?></span>
                    </div>

                    <h3 class="bd-title"><?= e($p['device_model']) ?></h3>
                    <p class="bd-sub"><?= e($p['service_type']) ?></p>
                    <p class="bd-desc"><?= e($p['description']) ?></p>

                    <div class="bd-facts">
                        <span class="bd-fact"><?= bd_icon('clock', 16) ?> <?= e(bd_when($p['appointment_date'])) ?></span>
                        <span class="bd-fact"><?= bd_icon($p['service_mode'] === 'online' ? 'monitor' : 'home', 16) ?> <?= $p['service_mode'] === 'online' ? 'Online session' : 'Home visit' ?></span>
                        <?php if ($p['service_mode'] === 'home' && ($p['area'] ?? '') !== ''): ?>
                            <span class="bd-fact"><?= bd_icon('pin', 16) ?> <?= e($p['area']) ?></span>
                        <?php endif; ?>
                        <?php if ($p['service_mode'] === 'home' && $can_addr && ($p['address'] ?? '') !== ''): ?>
                            <span class="bd-fact private"><?= bd_icon('lock', 16) ?> <?= e($p['address']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($p['status'] === 'Accepted' && $acc_by !== null):
                        $tinfo = $techs[$acc_by] ?? null;
                    ?>
                        <div class="bd-accepted">
                            <span class="bd-check"><?= bd_icon('check', 18) ?></span>
                            <div>
                                <strong>Accepted by <?= e($tech_names[$acc_by] ?? $acc_by) ?>
                                    <?php if ($tinfo && $tinfo['n'] > 0): ?><span class="bd-star">&#9733; <?= e(number_format($tinfo['avg'], 1)) ?> (<?= (int) $tinfo['n'] ?>)</span><?php endif; ?>
                                </strong>
                                <small><?= e(bd_ago($p['accepted_at'])) ?>. This request is now a booking<?= ($p['booking_status'] ?? '') !== '' ? ' (' . e($p['booking_status']) . ')' : '' ?>.</small>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="bd-actions">
                        <button type="button" class="bd-act js-focus-comment" data-post="<?= $pid ?>">
                            <?= bd_icon('message', 18) ?> <span class="js-count" data-post="<?= $pid ?>"><?= $ccount ?> comment<?= $ccount === 1 ? '' : 's' ?></span>
                        </button>

                        <div class="bd-act-right">
                            <?php if ($is_tech && $p['status'] === 'Open' && !$expired): ?>
                                <form method="POST" action="board.php" class="bd-inline js-confirm" data-confirm="Accept this request? It becomes a confirmed job on your schedule, and no other technician can take it.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="accept">
                                    <input type="hidden" name="post_id" value="<?= $pid ?>">
                                    <input type="hidden" name="back" value="<?= e($cur_qs) ?>">
                                    <button type="submit" class="bd-primary"><?= bd_icon('check', 18) ?> Accept request</button>
                                </form>
                            <?php elseif ($is_tech && $p['status'] === 'Open' && $expired): ?>
                                <span class="bd-note">The requested time has passed</span>
                            <?php endif; ?>

                            <?php if ($is_owner && $p['status'] === 'Open'): ?>
                                <form method="POST" action="board.php" class="bd-inline js-confirm" data-confirm="Withdraw this request? Technicians will no longer see it.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="withdraw">
                                    <input type="hidden" name="post_id" value="<?= $pid ?>">
                                    <input type="hidden" name="back" value="<?= e($cur_qs) ?>">
                                    <button type="submit" class="bd-ghost danger">Withdraw</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($is_admin): ?>
                                <form method="POST" action="board.php" class="bd-inline js-confirm" data-confirm="Delete this post and all its comments?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_post">
                                    <input type="hidden" name="post_id" value="<?= $pid ?>">
                                    <input type="hidden" name="back" value="<?= e($cur_qs) ?>">
                                    <button type="submit" class="bd-ghost danger">Delete post</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bd-comments" id="comments-<?= $pid ?>">
                        <?php if ($ccount > 3): ?>
                            <button type="button" class="bd-more" data-post="<?= $pid ?>">View <?= $ccount - 3 ?> earlier comment<?= $ccount - 3 === 1 ? '' : 's' ?></button>
                        <?php endif; ?>
                        <div class="bd-clist" id="clist-<?= $pid ?>">
                            <?php foreach ($clist as $i => $c): ?>
                                <?= bd_comment_html($c, $uid, $is_admin, (int) $p['user_id'], $cur_qs, $i < $ccount - 3) ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($p['status'] !== 'Withdrawn'): ?>
                            <form method="POST" action="board.php" class="bd-cform js-comment-form" data-post="<?= $pid ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="comment">
                                <input type="hidden" name="post_id" value="<?= $pid ?>">
                                <input type="hidden" name="back" value="<?= e($cur_qs) ?>">
                                <?= bd_av($me_name, 34, $role) ?>
                                <textarea name="body" rows="1" maxlength="1000" required placeholder="Write a comment..." id="cbox-<?= $pid ?>"></textarea>
                                <button type="submit" class="bd-send" aria-label="Send comment"><?= bd_icon('send', 18) ?></button>
                                <p class="bd-cerr" hidden></p>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if (($pages ?? 1) > 1): ?>
                <div class="bd-pager">
                    <?php if ($page > 1): ?><a href="<?= e(bd_url(['page' => $page - 1])) ?>">&larr; Newer posts</a><?php else: ?><span></span><?php endif; ?>
                    <?php if ($page < $pages): ?><a href="<?= e(bd_url(['page' => $page + 1])) ?>">Older posts &rarr;</a><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- ===== Right: technicians and how it works ===== -->
    <aside class="bd-right">
        <div class="bd-card">
            <h4>Technicians</h4>
            <?php if (!$techs): ?>
                <p class="tp-muted" style="color:#94a3b8;font-size:.86rem">No technicians have joined yet.</p>
            <?php endif; ?>
            <?php foreach (array_slice($techs, 0, 6, true) as $tu => $t): ?>
                <div class="bd-tech">
                    <?= bd_av($t['name'], 40, 'technician') ?>
                    <div>
                        <strong><?= e($t['name']) ?></strong>
                        <small>
                            <?php if ($t['n'] > 0): ?><span class="bd-star" style="margin:0">&#9733; <?= e(number_format($t['avg'], 1)) ?></span> (<?= (int) $t['n'] ?>) <?php else: ?>New <?php endif; ?>
                            &middot; <?= (int) $t['done'] ?> repair<?= $t['done'] === 1 ? '' : 's' ?> done
                        </small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="bd-card">
            <h4>How the board works</h4>
            <ol class="bd-steps">
                <li><span class="ico"><?= bd_icon('plus', 16) ?></span><span>A customer posts what needs fixing and when.</span></li>
                <li><span class="ico"><?= bd_icon('message', 16) ?></span><span>Customers and technicians can comment to ask questions or give advice.</span></li>
                <li><span class="ico"><?= bd_icon('check', 16) ?></span><span>A technician accepts the post. It becomes a confirmed booking, and only one technician can take it.</span></li>
            </ol>
        </div>
    </aside>
</div>

<script>
(function () {
    // ----- Composer -----
    var composer = document.getElementById('composer');
    if (composer) {
        var form = document.getElementById('composeForm');
        document.getElementById('composeToggle').addEventListener('click', function () {
            composer.classList.add('is-open');
            document.getElementById('device_model').focus();
        });
        document.getElementById('composeClose').addEventListener('click', function () { composer.classList.remove('is-open'); });

        var home = document.getElementById('homeFields');
        var area = document.getElementById('area');
        var addr = document.getElementById('address');
        function syncMode() {
            var isHome = form.querySelector('input[name="service_mode"]:checked').value === 'home';
            home.hidden = !isHome;
            area.required = isHome;
            addr.required = isHome;
        }
        form.querySelectorAll('input[name="service_mode"]').forEach(function (r) { r.addEventListener('change', syncMode); });
        syncMode();

        var desc = document.getElementById('description');
        var cnt = document.getElementById('descCount');
        function syncCount() { cnt.textContent = desc.value.length + '/500'; }
        desc.addEventListener('input', syncCount);
        syncCount();

        if (composer.classList.contains('is-open')) composer.scrollIntoView({ block: 'center' });
    }

    // ----- Confirm before accept, withdraw and delete -----
    document.addEventListener('submit', function (e) {
        var f = e.target.closest('.js-confirm');
        if (f && !confirm(f.dataset.confirm)) e.preventDefault();
    });

    // ----- Comments: fold, focus, grow, send without reloading -----
    document.querySelectorAll('.bd-more').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('#clist-' + b.dataset.post + ' .is-folded').forEach(function (c) { c.classList.remove('is-folded'); });
            b.remove();
        });
    });
    document.querySelectorAll('.js-focus-comment').forEach(function (b) {
        b.addEventListener('click', function () {
            var box = document.getElementById('cbox-' + b.dataset.post);
            if (box) { box.scrollIntoView({ block: 'center', behavior: 'smooth' }); box.focus(); }
        });
    });

    function grow(t) { t.style.height = '42px'; t.style.height = Math.min(t.scrollHeight, 140) + 'px'; }

    document.querySelectorAll('.js-comment-form').forEach(function (form) {
        var box = form.querySelector('textarea');
        var err = form.querySelector('.bd-cerr');
        var btn = form.querySelector('.bd-send');
        var pid = form.dataset.post;

        box.addEventListener('input', function () { grow(box); err.hidden = true; });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (form.requestSubmit) form.requestSubmit(); else btn.click(); }
        });

        form.addEventListener('submit', function (e) {
            if (!window.fetch || !window.FormData) return;   // older browsers: normal form post
            e.preventDefault();
            if (!box.value.trim()) return;
            btn.disabled = true;
            fetch('board.php', { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btn.disabled = false;
                    if (!d.ok) { err.textContent = d.error || 'Something went wrong.'; err.hidden = false; return; }
                    document.getElementById('clist-' + pid).insertAdjacentHTML('beforeend', d.html);
                    box.value = ''; grow(box);
                    document.querySelectorAll('.js-count[data-post="' + pid + '"]').forEach(function (s) {
                        s.textContent = d.count + ' comment' + (d.count === 1 ? '' : 's');
                    });
                })
                .catch(function () {
                    btn.disabled = false;
                    err.textContent = 'Could not send. Check your connection and try again.';
                    err.hidden = false;
                });
        });
    });

    // Flash the post you were just looking at (after a comment, accept, etc.)
    if (location.hash.indexOf('#post-') === 0) {
        var target = document.querySelector(location.hash);
        if (target) {
            target.animate([{ boxShadow: '0 0 0 2px rgba(168,85,247,.9)' }, { boxShadow: '0 0 0 0 rgba(168,85,247,0)' }], { duration: 1600 });
        }
    }
})();
</script>
</body>
</html>