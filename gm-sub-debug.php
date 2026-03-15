<?php
/**
 * GarageMinder — Subscription Debug Page
 *
 * DROP THIS FILE in the garage/ directory (same folder as api.php and upload.php).
 * Open it in a browser while logged in as the user you want to diagnose.
 * DELETE IT when done — it exposes internal configuration.
 *
 * URL: https://yesca.st/gm/garage/gm-sub-debug.php
 */

// ── Bootstrap: load exactly what api.php and upload.php load ─────────────────
ob_start();
require __DIR__ . '/config.php';
ob_end_clean();

// ── Helpers ───────────────────────────────────────────────────────────────────
function dbg_row(string $label, $value, string $note = ''): void {
    $display = is_bool($value) ? ($value ? 'TRUE' : 'FALSE')
             : (is_null($value) ? 'NULL'
             : (is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : (string) $value));
    $color = '';
    if (is_bool($value)) {
        $color = $value ? '#22c55e' : '#ef4444';
    } elseif (is_int($value) || is_numeric($display)) {
        $color = ((int)$display > 0) ? '#22c55e' : '#ef4444';
    }
    $style = $color ? "color:{$color};font-weight:600" : '';
    echo "<tr><td class='lbl'>" . htmlspecialchars($label) . "</td>"
       . "<td class='val' style='{$style}'>" . nl2br(htmlspecialchars($display)) . "</td>"
       . "<td class='note'>" . htmlspecialchars($note) . "</td></tr>\n";
}

function dbg_section(string $title): void {
    echo "<tr><th colspan='3' class='section'>" . htmlspecialchars($title) . "</th></tr>\n";
}

function dbg_ok(bool $pass, string $msg): string {
    return $pass ? "✅ $msg" : "❌ $msg";
}

// ── Gather data ───────────────────────────────────────────────────────────────
$userId  = null;
$isMulti = defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER;

if ($isMulti) {
    $userId = gm_get_current_user_id();
}
$userId = $userId ?: 'default';

// --- 1. WP load status ---
$wpLoaded     = function_exists('gm_load_wordpress') && gm_load_wordpress();
$hasAbspath   = defined('ABSPATH');
$hasTmwLimits = function_exists('tmw_get_user_limits');
$hasTmwTier   = function_exists('tmw_get_user_tier');
$hasTmwTierLimits = function_exists('tmw_get_tier_limits');
$hasTmwTierValues = function_exists('tmw_get_tier_values');
$hasGetOption = function_exists('get_option');

// --- 2. WP option: tmw_tier_values (the raw WP admin setting) ---
$rawTierValues = [];
if ($hasGetOption) {
    $rawTierValues = get_option('tmw_tier_values', []);
}
$rawTierValuesEmpty = empty($rawTierValues);

// --- 3. Tier resolution chain ---
$tierViaAdapter = 'N/A (WP not loaded)';
$adapterClass   = 'N/A';
if ($wpLoaded && $hasTmwTier && $userId !== 'default') {
    $tierViaAdapter = tmw_get_user_tier((int)$userId);
    // Detect which adapter is in use
    if (function_exists('tmw_get_membership_adapter')) {
        $adapter = tmw_get_membership_adapter();
        $adapterClass = get_class($adapter);
    }
}

$tierViaSub    = 'N/A';
if ($userId !== 'default' && function_exists('gm_get_user_subscription_tier')) {
    $tierViaSub = gm_get_user_subscription_tier($userId);
}

// --- 4. Limits from each source ---
// 4a. Raw WP tier values for whatever tier the user is on
$rawFreeLimits  = $rawTierValues['free']  ?? null;
$rawPaidLimits  = $rawTierValues['paid']  ?? null;
$rawFleetLimits = $rawTierValues['fleet'] ?? null;

// 4b. tmw_get_user_limits (WP theme function)
$tmwLimits = [];
if ($wpLoaded && $hasTmwLimits && $userId !== 'default') {
    $tmwLimits = tmw_get_user_limits((int)$userId);
}

// 4c. gm_get_user_limits (GarageMinder integration, with safety net)
$gmLimits = [];
if ($userId !== 'default' && function_exists('gm_get_user_limits')) {
    $gmLimits = gm_get_user_limits($userId);
}

// 4d. gm_sub_fallback_limits
$fallbackLimits = function_exists('gm_sub_fallback_limits') ? gm_sub_fallback_limits() : ['error' => 'function not found'];

// 4e. Fallback tier slug
$fallbackTier = function_exists('gm_sub_fallback_tier') ? gm_sub_fallback_tier() : 'N/A';

// --- 5. Full subscription API response (what frontend receives) ---
$subApiResponse = null;
$subApiError    = null;
if ($isMulti && $userId !== 'default' && function_exists('gm_get_subscription_api_response')) {
    try {
        $pdo = db_get_pdo();
        $subApiResponse = gm_get_subscription_api_response($pdo, $userId);
    } catch (Throwable $e) {
        $subApiError = $e->getMessage();
    }
}

// --- 6. config.php constants ---
$configAttachments = defined('ENTRY_MAX_ATTACHMENTS')       ? ENTRY_MAX_ATTACHMENTS       : 'NOT DEFINED';
$configSizeMb      = defined('ENTRY_MAX_ATTACHMENT_SIZE_MB') ? ENTRY_MAX_ATTACHMENT_SIZE_MB : 'NOT DEFINED';
$configGdrive      = defined('GOOGLE_DRIVE_ENABLED')         ? GOOGLE_DRIVE_ENABLED         : 'NOT DEFINED';

// --- 7. User meta ---
$userMeta = [];
if ($wpLoaded && $userId !== 'default' && function_exists('get_user_meta')) {
    $metaKeys = ['tmw_subscription_tier', 'tmw_subscription_status', 'tmw_stripe_customer_id',
                 'tmw_stripe_subscription_id', 'tmw_subscription_current_period_end'];
    foreach ($metaKeys as $key) {
        $userMeta[$key] = get_user_meta((int)$userId, $key, true) ?: '(empty)';
    }
}

// --- 8. Stripe subscription record ---
$stripeRecord = null;
if ($wpLoaded && $userId !== 'default') {
    global $wpdb;
    if ($wpdb) {
        $table = $wpdb->prefix . 'tmw_stripe_subscriptions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $stripeRecord = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE user_id = %d", (int)$userId
            ), ARRAY_A);
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GarageMinder — Subscription Debug</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 24px; font-size: 13px; }
h1 { color: #f8fafc; font-size: 1.3rem; margin-bottom: 4px; }
.subtitle { color: #64748b; margin-bottom: 24px; font-size: 0.85rem; }
.warning { background: #7f1d1d; color: #fca5a5; padding: 10px 16px; border-radius: 6px;
           margin-bottom: 20px; border: 1px solid #991b1b; }
table { width: 100%; border-collapse: collapse; margin-bottom: 28px;
        background: #1e293b; border-radius: 8px; overflow: hidden; }
th.section { background: #334155; color: #94a3b8; text-align: left;
             padding: 8px 12px; font-size: 0.75rem; text-transform: uppercase;
             letter-spacing: 0.08em; }
td { padding: 7px 12px; border-bottom: 1px solid #1a2740; vertical-align: top; }
td.lbl { color: #94a3b8; width: 280px; white-space: nowrap; }
td.val { color: #e2e8f0; font-weight: 500; }
td.note { color: #475569; font-size: 0.8rem; width: 260px; }
tr:last-child td { border-bottom: none; }
.pass { color: #22c55e; }
.fail { color: #ef4444; }
.warn { color: #f59e0b; }
.json-block { background: #0f172a; padding: 12px 14px; border-radius: 6px;
              white-space: pre-wrap; font-size: 0.8rem; color: #93c5fd;
              border: 1px solid #1e3a5f; max-height: 300px; overflow-y: auto; }
.summary-box { background: #1e293b; border: 2px solid #334155; border-radius: 8px;
               padding: 16px 20px; margin-bottom: 28px; }
.summary-box h2 { color: #f8fafc; font-size: 1rem; margin-bottom: 12px; }
.summary-item { padding: 4px 0; font-size: 0.9rem; }
</style>
</head>
<body>

<h1>🔧 GarageMinder — Subscription Debug</h1>
<p class="subtitle">Loaded at <?= date('Y-m-d H:i:s T') ?> &nbsp;|&nbsp; User ID: <strong><?= htmlspecialchars($userId) ?></strong></p>

<div class="warning">
  ⚠️ <strong>Security reminder:</strong> Delete this file from the server after debugging. It exposes internal configuration to anyone who can access it.
</div>

<?php
// ── Quick summary box ─────────────────────────────────────────────────────────
$summaryPerEntry = $subApiResponse['features']['attachments_per_entry'] ?? 'N/A';
$summaryLocalUpload = $subApiResponse['features']['local_upload'] ?? 'N/A';
$summaryTier = $subApiResponse['tier'] ?? $tierViaSub;
?>
<div class="summary-box">
  <h2>🎯 Quick Diagnosis</h2>
  <div class="summary-item"><?= dbg_ok($wpLoaded, 'WordPress loaded by gm_load_wordpress()') ?></div>
  <div class="summary-item"><?= dbg_ok(!$rawTierValuesEmpty, 'tmw_tier_values WP option found (not empty/default)') ?></div>
  <div class="summary-item"><?= dbg_ok(!empty($tmwLimits), 'tmw_get_user_limits() returned data') ?></div>
  <div class="summary-item"><?= dbg_ok(!empty($gmLimits), 'gm_get_user_limits() returned data') ?></div>
  <div class="summary-item"><?= dbg_ok(
      is_numeric($summaryPerEntry) && (int)$summaryPerEntry > 0,
      "Frontend subscription payload: attachments_per_entry = {$summaryPerEntry}"
  ) ?></div>
  <div class="summary-item"><?= dbg_ok(
      $summaryLocalUpload === true || $summaryLocalUpload === 'true' || $summaryLocalUpload == 1,
      "Frontend subscription payload: local_upload = " . json_encode($summaryLocalUpload)
  ) ?></div>
</div>

<table>
<?php dbg_section('1. Current User') ?>
<?php dbg_row('User ID', $userId) ?>
<?php dbg_row('Multi-user enabled', $isMulti) ?>
<?php
if ($wpLoaded && $userId !== 'default' && function_exists('wp_get_current_user')) {
    $u = wp_get_current_user();
    dbg_row('WP username', $u->user_login ?? 'N/A');
    dbg_row('WP email', $u->user_email ?? 'N/A');
    dbg_row('WP roles', implode(', ', $u->roles ?? []));
}
?>

<?php dbg_section('2. WordPress Load Status') ?>
<?php dbg_row('ABSPATH defined', $hasAbspath, 'WP was loaded somewhere before this file') ?>
<?php dbg_row('gm_load_wordpress() result', $wpLoaded) ?>
<?php dbg_row('tmw_get_user_tier() exists', $hasTmwTier, 'From WP theme subscription.php') ?>
<?php dbg_row('tmw_get_user_limits() exists', $hasTmwLimits, 'From WP theme subscription.php') ?>
<?php dbg_row('tmw_get_tier_limits() exists', $hasTmwTierLimits) ?>
<?php dbg_row('tmw_get_tier_values() exists', $hasTmwTierValues) ?>
<?php dbg_row('get_option() exists', $hasGetOption, 'Core WP function') ?>
<?php dbg_row('WP_PATH constant', defined('WP_PATH') ? WP_PATH : 'NOT DEFINED') ?>

<?php dbg_section('3. Tier Resolution Chain') ?>
<?php dbg_row('Membership adapter class', $adapterClass) ?>
<?php dbg_row('Tier via tmw_get_user_tier()', $tierViaAdapter, 'Direct WP adapter result') ?>
<?php dbg_row('Tier via gm_get_user_subscription_tier()', $tierViaSub, 'GarageMinder integration result') ?>
<?php dbg_row('gm_sub_fallback_tier()', $fallbackTier, 'Used when WP fails — should be "paid" or custom') ?>

<?php dbg_section('4. WP Option: tmw_tier_values (raw WP admin settings)') ?>
<?php dbg_row('Option is empty / missing', $rawTierValuesEmpty, 'If true: default values are used, NOT your WP admin settings') ?>
<?php
if (!$rawTierValuesEmpty) {
    foreach ($rawTierValues as $tierSlug => $tierLimits) {
        $att = $tierLimits['attachments_per_entry'] ?? 'NOT SET';
        dbg_row("  [{$tierSlug}] attachments_per_entry", $att,
            $tierSlug === $tierViaSub ? '← CURRENT USER TIER' : '');
        dbg_row("  [{$tierSlug}] recalls_enabled", $tierLimits['recalls_enabled'] ?? 'NOT SET');
        dbg_row("  [{$tierSlug}] export_level", $tierLimits['export_level'] ?? 'NOT SET');
        dbg_row("  [{$tierSlug}] max_vehicles", $tierLimits['max_vehicles'] ?? 'NOT SET');
    }
} else {
    dbg_row('Raw free tier defaults', json_encode(
        function_exists('tmw_get_default_tier_values')
            ? (tmw_get_default_tier_values()['free'] ?? [])
            : ['error' => 'tmw_get_default_tier_values not found']
    ), 'These are the HARDCODED defaults, NOT your WP admin settings');
}
?>

<?php dbg_section('5. tmw_get_user_limits() — WP theme function result for current user') ?>
<?php
if (!empty($tmwLimits)) {
    foreach ($tmwLimits as $key => $val) {
        $note = ($key === 'attachments_per_entry') ? '← KEY VALUE for upload gating' : '';
        dbg_row($key, $val, $note);
    }
} elseif ($userId === 'default') {
    dbg_row('Status', 'N/A — single user mode (userId=default)');
} else {
    dbg_row('Status', 'EMPTY — tmw_get_user_limits() returned nothing', '⚠️ Will use fallback');
}
?>

<?php dbg_section('6. gm_get_user_limits() — GarageMinder integration result (with safety net)') ?>
<?php
if (!empty($gmLimits)) {
    foreach ($gmLimits as $key => $val) {
        $note = ($key === 'attachments_per_entry') ? '← What upload.php uses' : '';
        dbg_row($key, $val, $note);
    }
} elseif ($userId === 'default') {
    dbg_row('Status', 'N/A — single user mode');
} else {
    dbg_row('Status', 'EMPTY', '⚠️ Something went wrong');
}
?>

<?php dbg_section('7. gm_sub_fallback_limits() — Hardcoded fallback (used when WP fails)') ?>
<?php foreach ($fallbackLimits as $key => $val): ?>
<?php dbg_row($key, $val, ($key === 'attachments_per_entry' && (int)$val === 0) ? '⚠️ This blocks uploads if fallback fires' : '') ?>
<?php endforeach ?>

<?php dbg_section('8. config.php Constants') ?>
<?php dbg_row('ENTRY_MAX_ATTACHMENTS', $configAttachments, 'Safety-net floor for attachments') ?>
<?php dbg_row('ENTRY_MAX_ATTACHMENT_SIZE_MB', $configSizeMb) ?>
<?php dbg_row('GOOGLE_DRIVE_ENABLED', $configGdrive) ?>
<?php dbg_row('ENABLE_MULTI_USER', defined('ENABLE_MULTI_USER') ? ENABLE_MULTI_USER : 'NOT DEFINED') ?>

<?php dbg_section('9. User Meta (Stripe / subscription)') ?>
<?php
if (!empty($userMeta)) {
    foreach ($userMeta as $key => $val) {
        dbg_row($key, $val);
    }
} else {
    dbg_row('Status', $userId === 'default' ? 'N/A (single user mode)' : 'WP not loaded or no meta found');
}
?>

<?php dbg_section('10. Stripe Subscription Record (wp_tmw_stripe_subscriptions)') ?>
<?php
if ($stripeRecord !== null) {
    foreach ($stripeRecord as $col => $val) {
        dbg_row($col, $val ?? 'NULL');
    }
} elseif ($userId === 'default') {
    dbg_row('Status', 'N/A — single user mode');
} elseif (!$wpLoaded) {
    dbg_row('Status', 'WP not loaded — cannot query DB');
} else {
    dbg_row('Status', 'No record found in wp_tmw_stripe_subscriptions for this user',
        'Free users with no Stripe record get get_free_tier_slug() → "free"');
}
?>

<?php dbg_section('11. Full gm_get_subscription_api_response() — What frontend JS receives') ?>
<?php
if ($subApiError) {
    dbg_row('Error', $subApiError);
} elseif ($subApiResponse !== null) {
    dbg_row('tier', $subApiResponse['tier']);
    dbg_row('tier_name', $subApiResponse['tier_name']);
    dbg_row('features.attachments_per_entry', $subApiResponse['features']['attachments_per_entry'] ?? 'NOT SET',
        '← JS reads this to allow/block uploads');
    dbg_row('features.local_upload', $subApiResponse['features']['local_upload'] ?? 'NOT SET',
        '← canUseLocalUpload() in JS reads this');
    dbg_row('features.gdrive', $subApiResponse['features']['gdrive'] ?? 'NOT SET');
    dbg_row('features.recalls', $subApiResponse['features']['recalls'] ?? 'NOT SET');
    dbg_row('features.export_level', $subApiResponse['features']['export_level'] ?? 'NOT SET');
    dbg_row('usage.vehicles.used', $subApiResponse['usage']['vehicles']['used'] ?? 'N/A');
    dbg_row('usage.vehicles.max', $subApiResponse['usage']['vehicles']['max'] ?? 'N/A');
    dbg_row('limits (raw)', json_encode($subApiResponse['limits'] ?? [], JSON_PRETTY_PRINT));
} elseif ($userId === 'default') {
    dbg_row('Status', 'N/A — single user mode (subscription data not generated)');
} else {
    dbg_row('Status', 'gm_get_subscription_api_response() not available');
}
?>

</table>

<?php
// ── Diagnosis conclusion ──────────────────────────────────────────────────────
$attFromApi = $subApiResponse['features']['attachments_per_entry'] ?? -1;
$attFromGm  = $gmLimits['attachments_per_entry'] ?? -1;
$attFromTmw = $tmwLimits['attachments_per_entry'] ?? -1;
$attFromWp  = $rawTierValues[$tierViaSub]['attachments_per_entry'] ?? -1;
?>

<div class="summary-box">
  <h2>🔬 Trace Summary for attachments_per_entry</h2>
  <div style="font-family:monospace;line-height:2;font-size:0.85rem;">
    <div>WP option <code>tmw_tier_values[<?= htmlspecialchars((string)$tierViaSub) ?>]</code>
         → <strong style="color:<?= ((int)$attFromWp > 0) ? '#22c55e' : '#ef4444' ?>">
         <?= htmlspecialchars((string)$attFromWp) ?></strong></div>
    <div><code>tmw_get_user_limits()</code>
         → <strong style="color:<?= ((int)$attFromTmw > 0) ? '#22c55e' : '#ef4444' ?>">
         <?= htmlspecialchars((string)$attFromTmw) ?></strong></div>
    <div><code>gm_get_user_limits()</code>
         → <strong style="color:<?= ((int)$attFromGm > 0) ? '#22c55e' : '#ef4444' ?>">
         <?= htmlspecialchars((string)$attFromGm) ?></strong></div>
    <div>API response → JS <code>GM_SUBSCRIPTION.features.attachments_per_entry</code>
         → <strong style="color:<?= ((int)$attFromApi > 0) ? '#22c55e' : '#ef4444' ?>">
         <?= htmlspecialchars((string)$attFromApi) ?></strong></div>
  </div>
  <div style="margin-top:12px;padding-top:12px;border-top:1px solid #334155;">
  <?php
  if ((int)$attFromWp <= 0) {
      echo "<span class='fail'>❌ WP option tmw_tier_values has attachments_per_entry=0 for tier '{$tierViaSub}'. Check WP Admin → TrackMyWrench → Tier Settings and save again.</span>";
  } elseif ((int)$attFromTmw <= 0 && (int)$attFromWp > 0) {
      echo "<span class='fail'>❌ tmw_get_user_limits() returned 0 despite WP option having {$attFromWp}. Check that tmw_get_tier_limits() is reading the correct tier slug.</span>";
  } elseif ((int)$attFromGm <= 0 && (int)$attFromTmw > 0) {
      echo "<span class='fail'>❌ gm_get_user_limits() returned 0 despite tmw_get_user_limits() having {$attFromTmw}. Safety net should fix this — check ENTRY_MAX_ATTACHMENTS constant.</span>";
  } elseif ((int)$attFromApi <= 0 && (int)$attFromGm > 0) {
      echo "<span class='fail'>❌ API response has 0 despite gm_get_user_limits() having {$attFromGm}. Check gm_get_subscription_api_response() remaining calculation.</span>";
  } elseif ((int)$attFromApi > 0) {
      echo "<span class='pass'>✅ Chain looks correct! attachments_per_entry={$attFromApi} is reaching the frontend. If uploads still fail, the issue is in the JS — check browser console for [GM-SUB] logs, and check if an offline snapshot is overriding the fresh value.</span>";
  } else {
      echo "<span class='warn'>⚠️ Could not determine full chain. Review the rows above manually.</span>";
  }
  ?>
  </div>
</div>

</body>
</html>
