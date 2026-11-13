<?php
/*
 * Prices for TechRepair.
 *
 * Used by the customer dashboard, the repair board, the technician workspace and the admin page.
 * Everything here still works before schema_pricing.sql has been run: the default prices below are used.
 *
 * How a price works:
 *   - Every service has a base price (set by the admin). An empty price means "quoted after assessment".
 *   - A home visit adds the home visit fee. Online sessions have no visit fee.
 *   - The price is copied onto the booking when it is made, so later price changes never alter old bookings.
 *   - The technician can set a final price (with a short reason) while the job is open.
 *   - When the repair is done the technician records the payment.
 */

if (!defined('PRICE_CURRENCY')) define('PRICE_CURRENCY', "\u{20B1}");   // the peso sign

function pr_h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ---------- Defaults (used until the admin saves prices) ---------- */
function pr_default_prices() {
    return [
        'Desktop Reformatting'           => 500.00,
        'Laptop Thermal System Cleaning' => 800.00,
        'OS Diagnostics & Repair'        => 600.00,
        'Component Upgrade'              => 400.00,
        'Virus & Malware Removal'        => 500.00,
        'Data Recovery'                  => 1500.00,
        'Other Repair'                   => null,   // quoted by the technician after assessment
    ];
}

function pr_default_visit_fee() {
    return 200.00;
}

/* ---------- Money ---------- */
// 1500 -> "₱1,500"   850.5 -> "₱850.50"   null -> $none
function pr_money($n, $none = '') {
    if ($n === null || $n === '') return $none;
    $n = (float) $n;
    return PRICE_CURRENCY . number_format($n, abs($n - round($n)) < 0.005 ? 0 : 2);
}

// Reads a typed amount such as "850", "1,250.50" or "₱ 99". Returns ['ok' => bool, 'value' => float|null].
// An empty box is fine when $allow_blank is true (it means "no price").
function pr_parse($raw, $allow_blank = true) {
    $s = str_replace([',', ' ', PRICE_CURRENCY], '', trim((string) $raw));
    if ($s === '') {
        return ['ok' => $allow_blank, 'value' => null];
    }
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $s)) {
        return ['ok' => false, 'value' => null];
    }
    return ['ok' => true, 'value' => round((float) $s, 2)];
}

/* ---------- Load the current price list ---------- */
function pr_load($conn) {
    $out = ['services' => pr_default_prices(), 'visit_fee' => pr_default_visit_fee(), 'ready' => false];
    try {
        $r = $conn->query("SELECT service_name, price FROM service_prices");
        if ($r) {
            $out['ready'] = true;
            while ($row = $r->fetch_assoc()) {
                $out['services'][$row['service_name']] = ($row['price'] === null) ? null : (float) $row['price'];
            }
        }
        $r = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'home_visit_fee'");
        if ($r && ($row = $r->fetch_assoc())) {
            $out['visit_fee'] = (float) $row['setting_value'];
        }
    } catch (Throwable $ex) {
        // Tables not created yet: the defaults above stay in effect.
    }
    return $out;
}

// The price of a service for a mode ('home' or 'online') right now.
function pr_quote($pricing, $service, $mode) {
    $price = array_key_exists($service, $pricing['services']) ? $pricing['services'][$service] : null;
    $visit = $mode === 'home' ? (float) $pricing['visit_fee'] : 0.0;
    return [
        'service'  => $price,                                   // null = quoted after assessment
        'visit'    => $visit,
        'estimate' => $price === null ? null : $price + $visit,
    ];
}

/* ---------- Prices stored on a booking (or a board post) ---------- */
function pr_estimate($row) {
    if (!isset($row['service_price']) || $row['service_price'] === '') return null;
    return (float) $row['service_price'] + (float) ($row['visit_fee'] ?? 0);
}

// What the customer owes: the technician's final price if there is one, otherwise the estimate. null = not known yet.
function pr_total($row) {
    if (isset($row['final_price']) && $row['final_price'] !== '') return (float) $row['final_price'];
    return pr_estimate($row);
}

function pr_is_paid($row) {
    return ($row['payment_status'] ?? 'Unpaid') === 'Paid';
}

/* ---------- The price box shown on a booking card ---------- */
function pr_box($row) {
    $status   = $row['status'] ?? '';
    $service  = (isset($row['service_price']) && $row['service_price'] !== '') ? (float) $row['service_price'] : null;
    $visit    = (float) ($row['visit_fee'] ?? 0);
    $final    = (isset($row['final_price']) && $row['final_price'] !== '') ? (float) $row['final_price'] : null;
    $estimate = pr_estimate($row);
    $paid     = pr_is_paid($row);

    $h = '<div class="pr-box"><div class="pr-main"><div>';
    if ($final !== null) {
        $h .= '<span class="pr-label">Final price</span><strong class="pr-amount">' . pr_h(pr_money($final)) . '</strong>';
    } elseif ($estimate !== null) {
        $h .= '<span class="pr-label">Estimated price</span><strong class="pr-amount">' . pr_h(pr_money($estimate)) . '</strong>';
    } else {
        $h .= '<span class="pr-label">Price</span><strong class="pr-amount quote">Quoted by the technician</strong>';
    }
    $h .= '</div>';

    if ($paid) {
        $when = !empty($row['paid_at']) ? ' on ' . date('M j', strtotime($row['paid_at'])) : '';
        $h .= '<span class="pr-pay paid">Paid' . pr_h($when) . '</span>';
    } elseif ($status === 'Completed') {
        $h .= '<span class="pr-pay unpaid">Payment due</span>';
    }
    $h .= '</div>';

    $bits = [];
    if ($final !== null) {
        if ($estimate !== null && abs($final - $estimate) > 0.004) $bits[] = 'Estimate was ' . pr_money($estimate);
    } else {
        if ($service !== null) $bits[] = 'Service ' . pr_money($service);
        if ($visit > 0)        $bits[] = 'Home visit ' . pr_money($visit);
        if ($service === null && $visit > 0) $bits[] = 'plus the repair price once quoted';
    }
    if ($bits) {
        $h .= '<div class="pr-detail">' . pr_h(implode(', ', $bits)) . '</div>';
    }
    if (!empty($row['price_note'])) {
        $h .= '<div class="pr-note"><strong>Technician note:</strong> ' . pr_h($row['price_note']) . '</div>';
    }
    return $h . '</div>';
}

/* ---------- Styles for price elements (each page prints this in its <head>) ---------- */
function pr_styles() {
    return <<<'CSS'
<style>
    .pr-box { margin: 12px 0 4px; padding: 12px 14px; border-radius: 14px; background: rgba(0,0,0,.22); border: 1px solid rgba(255,255,255,.07); }
    .pr-main { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .pr-label { color: #94a3b8; font-size: .8rem; }
    .pr-amount { font-family: 'Sora', 'Inter', sans-serif; color: #fff; font-size: 1.15rem; margin-left: 8px; }
    .pr-amount.quote { font-family: 'Inter', sans-serif; font-size: .9rem; font-weight: 500; color: #fbbf24; }
    .pr-detail { color: #94a3b8; font-size: .78rem; margin-top: 6px; line-height: 1.5; }
    .pr-note { color: #cbd5e1; font-size: .82rem; margin-top: 8px; line-height: 1.45; }
    .pr-note strong { color: #e2e8f0; font-weight: 600; }
    .pr-pay { padding: 3px 12px; border-radius: 999px; font-size: .74rem; font-weight: 500; border: 1px solid; white-space: nowrap; }
    .pr-pay.paid { color: #34d399; background: rgba(52,211,153,.1); border-color: rgba(52,211,153,.35); }
    .pr-pay.unpaid { color: #fbbf24; background: rgba(251,191,36,.1); border-color: rgba(251,191,36,.35); }

    .pr-summary { margin-top: 12px; padding: 14px 16px; border-radius: 14px; background: rgba(99,102,241,.07); border: 1px solid rgba(99,102,241,.25); }
    .pr-row { display: flex; justify-content: space-between; gap: 12px; font-size: .88rem; color: #cbd5e1; padding: 3px 0; }
    .pr-row strong { color: #fff; font-weight: 600; text-align: right; }
    .pr-row.total { margin-top: 6px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,.1); font-size: .98rem; }
    .pr-row.total strong { color: #c4b5fd; font-family: 'Sora', 'Inter', sans-serif; font-size: 1.1rem; }
    .pr-summary .hint { margin-top: 8px; margin-bottom: 0; }

    .pr-edit { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-top: 12px; }
    .pr-edit .pr-field { flex: 1 1 150px; margin: 0; }
    .pr-edit .pr-field.wide { flex: 2 1 220px; }
    .pr-edit .pr-field span { display: block; font-size: .75rem; color: #64748b; margin-bottom: 6px; }
    .pr-edit input, .pr-money-input { width: 100%; padding: 11px 14px; font-size: .88rem; background: var(--surface); border: 1px solid transparent; border-radius: 12px; color: #fff; outline: none; font-family: inherit; box-shadow: inset 4px 4px 8px rgba(0,0,0,.5), inset -4px -4px 8px rgba(255,255,255,.02); }
    .pr-edit input:focus, .pr-money-input:focus { border-color: rgba(168,85,247,.45); }
    .pr-edit button { width: auto; padding: 11px 20px; font-size: .85rem; }
    .pr-due { margin: -6px 0 16px; padding: 10px 14px; border-radius: 12px; font-size: .88rem; color: #fbbf24; background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.3); }
    .pr-due strong { color: #fde68a; }
</style>
CSS;
}