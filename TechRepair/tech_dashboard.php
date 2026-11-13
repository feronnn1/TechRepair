<?php
session_start();
require_once 'db.php';
require_once 'functions.php';

if (!defined('APP_NAME')) define('APP_NAME', 'TechRepair');

// Restrict access to technicians only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
    redirect('login.php');
}

$username = $_SESSION['username'];
$flash = take_flash();

// Handle Status Updates & Price Proposals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_valid()) {
        set_flash('error', 'Session expired. Please try again.');
    } else {
        $booking_id = (int) $_POST['booking_id'];
        
        if ($_POST['action'] === 'confirm') {
            // Open requests (no technician chosen) can be claimed by anyone.
            // Requests made for a specific technician can only be accepted by that technician.
            $stmt = $conn->prepare(
                "UPDATE bookings SET status = 'Confirmed', technician = ?
                 WHERE id = ? AND status = 'Pending' AND (technician IS NULL OR technician = '' OR technician = ?)"
            );
            $stmt->bind_param("sis", $username, $booking_id, $username);
            $stmt->execute();
            if ($stmt->affected_rows > 0) set_flash('success', 'Repair claimed and confirmed.');
            else set_flash('error', 'That request is no longer available to you.');
            $stmt->close();
            
        } elseif ($_POST['action'] === 'reject') {
            // Cancel a request specifically assigned to this technician
            $stmt = $conn->prepare("UPDATE bookings SET status = 'Cancelled' WHERE id = ? AND status = 'Pending' AND technician = ?");
            $stmt->bind_param("is", $booking_id, $username);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                // Auto post a polite cancellation comment
                $msg = "I'm unable to take this request right now, so it has been cancelled. Please feel free to book again and select 'Any available technician'.";
                $ins = $conn->prepare("INSERT INTO booking_comments (booking_id, user_id, comment) VALUES (?, ?, ?)");
                $uid = (int) $_SESSION['user_id'];
                $ins->bind_param("iis", $booking_id, $uid, $msg);
                $ins->execute();
                $ins->close();
                set_flash('success', 'Repair request rejected and cancelled.');
            } else {
                set_flash('error', 'Could not reject request. It may no longer be available.');
            }
            $stmt->close();

        } elseif ($_POST['action'] === 'propose_price') {
            $price = (float) $_POST['proposed_price'];
            $stmt = $conn->prepare(
                "UPDATE bookings SET proposed_price = ?, price_status = 'Negotiating', technician = ? 
                 WHERE id = ? AND status = 'Pending' AND (technician IS NULL OR technician = '' OR technician = ?)"
            );
            $stmt->bind_param("dsis", $price, $username, $booking_id, $username);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                // Auto post a comment showing the offer
                $msg = "I can do this repair for ₱" . number_format($price, 2) . ". Let me know if this works for you!";
                $ins = $conn->prepare("INSERT INTO booking_comments (booking_id, user_id, comment) VALUES (?, ?, ?)");
                $uid = (int) $_SESSION['user_id'];
                $ins->bind_param("iis", $booking_id, $uid, $msg);
                $ins->execute();
                $ins->close();
                set_flash('success', 'Price proposal sent to client. Waiting for them to accept.');
            } else {
                set_flash('error', 'Could not propose price. The request may no longer be available.');
            }
            $stmt->close();
            
        } elseif ($_POST['action'] === 'progress') {
            $stmt = $conn->prepare("UPDATE bookings SET status = 'In Progress' WHERE id = ? AND status = 'Confirmed' AND technician = ?");
            $stmt->bind_param("is", $booking_id, $username);
            $stmt->execute();
            if ($stmt->affected_rows > 0) set_flash('success', 'Repair marked as In Progress.');
            else set_flash('error', 'You can only update your own confirmed repairs.');
            $stmt->close();
            
        } elseif ($_POST['action'] === 'complete') {
            $stmt = $conn->prepare("UPDATE bookings SET status = 'Completed' WHERE id = ? AND status = 'In Progress' AND technician = ?");
            $stmt->bind_param("is", $booking_id, $username);
            $stmt->execute();
            if ($stmt->affected_rows > 0) set_flash('success', 'Repair marked as Completed.');
            else set_flash('error', 'You can only update your own repairs in progress.');
            $stmt->close();
        }
        redirect('tech_dashboard.php');
    }
}

// Fetch all bookings with client names
$bookings = [];
try {
    $result = $conn->query("SELECT b.*, u.username as client_name, u.phone as client_phone FROM bookings b JOIN users u ON b.user_id = u.id ORDER BY b.appointment_date ASC");
} catch (mysqli_sql_exception $ex) {
    // phone column not added yet (run migration.sql)
    $result = $conn->query("SELECT b.*, u.username as client_name FROM bookings b JOIN users u ON b.user_id = u.id ORDER BY b.appointment_date ASC");
}
while ($row = $result->fetch_assoc()) {
    $row['dt'] = new DateTime($row['appointment_date']);
    
    // Grouping for the technician UI tabs
    if ($row['status'] === 'Pending') {
        // Requested for a different technician? Not yours to see or accept.
        $row['group'] = (empty($row['technician']) || $row['technician'] === $username) ? 'pending' : 'other';
    } elseif (in_array($row['status'], ['Confirmed', 'In Progress']) && $row['technician'] === $username) {
        $row['group'] = 'my_jobs';
    } elseif ($row['status'] === 'Completed') {
        $row['group'] = 'completed';
    } else {
        $row['group'] = 'other'; // Cancelled or claimed by another tech
    }
    
    $bookings[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Workspace - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        a.nav-link { color: #cbd5e1; text-decoration: none; font-size: 0.9rem; display: inline-block; padding: 8px 16px; border-radius: 8px; background: rgba(255,255,255,0.06); transition: 0.3s ease; }
        a.nav-link:hover { background: rgba(255,255,255,0.12); }
    </style>
</head>
<body class="dash">
<div class="dash-shell">
    <header class="topbar">
        <a class="brand" href="tech_dashboard.php"><span class="brand-mark"></span><?= e(APP_NAME) ?> Workspace</a>
        <div class="topbar-user">
            <span class="topbar-name"><?= e($username) ?> (Tech)</span>
            <div class="avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
            <a href="board.php" class="nav-link" style="background: linear-gradient(135deg, #4f46e5, #9333ea); color: #fff;">Repair board</a>
            <a href="tech_profile.php" class="nav-link">My profile</a>
            <a href="logout.php" class="logout-link">Log out</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="toast <?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <section class="hero" style="margin-bottom: 30px;">
        <div>
            <div class="hero-greet">Technician Control Center</div>
            <h1 class="hero-when">Service <span>Requests</span></h1>
            <p class="hero-what">Review incoming client requests, assign yourself to jobs, and update repair progress.</p>
        </div>
    </section>

    <section class="panel" style="max-width: 900px; margin: 0 auto;">
        <div class="panel-head">
            <h3>Service Queue</h3>
            <div class="tabs" role="tablist">
                <button type="button" class="tab is-active" data-filter="pending">Pending Requests</button>
                <button type="button" class="tab" data-filter="my_jobs">My Active Jobs</button>
                <button type="button" class="tab" data-filter="completed">Completed</button>
            </div>
        </div>

        <div class="booking-list" id="bookingList">
            <?php foreach ($bookings as $b): ?>
                <?php if ($b['group'] === 'other') continue; // Hide cancelled jobs, other techs' jobs and requests made for another tech ?>
                
                <article class="booking" data-group="<?= e($b['group']) ?>" <?= $b['group'] !== 'pending' ? 'hidden' : '' ?>>
                    <div class="booking-top">
                        <div>
                            <h4><?= e($b['device_model']) ?> <span style="font-size:0.85rem; color: #94a3b8; font-weight: 400;">for <?= e($b['client_name']) ?></span></h4>
                            <p class="booking-service"><?= e($b['service_type']) ?></p>
                            <?php if ($b['status'] === 'Pending' && $b['technician'] === $username): ?>
                                <p class="booking-line" style="margin-top: 6px;"><span>Request</span>The client asked for you specifically</p>
                            <?php endif; ?>
                        </div>
                        <span class="status-badge"><?= e($b['status']) ?></span>
                    </div>

                    <ul class="booking-meta">
                        <li><?= e($b['dt']->format('D, M j')) ?> at <?= e($b['dt']->format('g:i A')) ?></li>
                        <li class="chip <?= $b['service_mode'] === 'online' ? 'chip-online' : 'chip-home' ?>">
                            <?= $b['service_mode'] === 'online' ? 'Remote Session' : 'Home Visit' ?>
                        </li>
                    </ul>

                    <?php if ($b['service_mode'] === 'home' && $b['address']): ?>
                        <p class="booking-line"><span>Location</span><?= e($b['address']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($b['client_phone']) && $b['technician'] === $username && in_array($b['status'], ['Confirmed', 'In Progress', 'Completed'], true)): ?>
                        <p class="booking-line"><span>Contact</span><a href="tel:<?= e($b['client_phone']) ?>" style="color: #c4b5fd;"><?= e($b['client_phone']) ?></a></p>
                    <?php endif; ?>
                    <?php if ($b['issue_notes']): ?>
                        <p class="booking-line"><span>Notes</span><?= e($b['issue_notes']) ?></p>
                    <?php endif; ?>
                    
                    <!-- Pricing Information -->
                    <?php if (!empty($b['client_budget'])): ?>
                        <p class="booking-line"><span>Budget</span>₱<?= number_format((float)$b['client_budget'], 2) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($b['proposed_price'])): ?>
                        <p class="booking-line" style="color:#c4b5fd;"><span>Offer Sent</span>₱<?= number_format((float)$b['proposed_price'], 2) ?> (<?= e($b['price_status']) ?>)</p>
                    <?php endif; ?>

                    <!-- Action Buttons based on Status -->
                    <div class="booking-actions" style="flex-wrap: wrap; gap: 8px;">
                        
                        <!-- Propose Price Form for Pending / Negotiating -->
                        <?php if ($b['status'] === 'Pending' || $b['price_status'] === 'Negotiating'): ?>
                            <form method="POST" action="" style="display: flex; gap: 8px; align-items: center; margin-right: auto;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                <input type="hidden" name="action" value="propose_price">
                                <input type="number" name="proposed_price" placeholder="Propose price (₱)" step="50" required style="width: 140px; padding: 8px 12px; border-radius: 8px; background: rgba(0,0,0,0.3); border: 1px solid var(--line); color: #fff; font-size: 0.85rem;">
                                <button type="submit" class="btn-ghost" style="padding: 8px 14px; font-size: 0.85rem;">Send Offer</button>
                            </form>
                        <?php endif; ?>

                        <form method="POST" action="" style="display: flex; gap: 8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                            
                            <?php if ($b['status'] === 'Pending'): ?>
                                <button type="submit" name="action" value="confirm" style="width: auto; padding: 10px 20px; font-size: 0.85rem;" title="Accept client budget as-is">Accept as-is & Confirm</button>
                                <?php if ($b['technician'] === $username): ?>
                                    <button type="submit" name="action" value="reject" class="btn-ghost danger" style="padding: 10px 20px; font-size: 0.85rem;" onclick="return confirm('Reject this booking? It will be cancelled and sent back to the client.');">Reject</button>
                                <?php endif; ?>
                            <?php elseif ($b['status'] === 'Confirmed'): ?>
                                <button type="submit" name="action" value="progress" style="width: auto; padding: 10px 20px; font-size: 0.85rem; background: linear-gradient(135deg, #38bdf8, #818cf8);">Start Repair (In Progress)</button>
                            <?php elseif ($b['status'] === 'In Progress'): ?>
                                <button type="submit" name="action" value="complete" style="width: auto; padding: 10px 20px; font-size: 0.85rem; background: linear-gradient(135deg, #34d399, #059669);">Mark as Completed</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            <p class="empty" id="emptyState" hidden>No bookings found in this category.</p>
        </div>
    </section>
</div>

<script>
    // Tab switching logic
    var tabs = document.querySelectorAll('.tab');
    var cards = document.querySelectorAll('.booking');
    var empty = document.getElementById('emptyState');
    
    function filterTabs(filterClass) {
        var shown = 0;
        cards.forEach(function (c) {
            var show = c.dataset.group === filterClass;
            c.hidden = !show;
            if (show) shown++;
        });
        empty.hidden = shown > 0;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            filterTabs(tab.dataset.filter);
        });
    });

    // Run once on load to show default tab
    filterTabs('pending');
</script>
</body>
</html>