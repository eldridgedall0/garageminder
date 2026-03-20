<?php
/**
 * Garage Maintenance - Main Application
 * Multi-user WordPress Integration
 */

// Start output buffering to capture any WordPress output
ob_start();

require_once __DIR__ . '/config.php';

// Get app branding config
$appConfig = gm_get_app_config();
$themeMode = gm_get_user_theme_mode();
$themeColors = gm_get_theme_colors($themeMode);

// Check authentication if multi-user is enabled
if (defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER) {
    $userId = gm_get_current_user_id();
    
    if (!$userId) {
        // Clear any WordPress output
        ob_end_clean();
        // Redirect to WordPress login
        $authUrls = gm_get_auth_urls();
        header('Location: ' . $authUrls['login_url']);
        exit;
    }
    
    // Check subscription if required
    if (defined('REQUIRE_SUBSCRIPTION') && REQUIRE_SUBSCRIPTION) {
        if (!gm_user_has_subscription($userId)) {
            // Clear any WordPress output
            ob_end_clean();
            // Redirect to subscription page
            $authUrls = gm_get_auth_urls();
            header('Location: ' . $authUrls['subscribe_url']);
            exit;
        }
    }
}

// Clear any WordPress output before rendering our page
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title><?= htmlspecialchars($appConfig['appName']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  
  <!-- Bootstrap Icons (reliable icon library - replaces emojis) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  
  <!-- Garage Maintenance modular CSS (order matters) -->
  <link rel="stylesheet" href="assets/css/gm.00-tokens.css" />
  <link rel="stylesheet" href="assets/css/gm.01-base.css" />
  <link rel="stylesheet" href="assets/css/gm.02-shell.css" />
  <link rel="stylesheet" href="assets/css/gm.04-components.css" />
  <link rel="stylesheet" href="assets/css/gm.03-dashboard.css" />
  <link rel="stylesheet" href="assets/css/gm.05-entries.css" />
  <link rel="stylesheet" href="assets/css/gm.06-attachments.css" />
  <link rel="stylesheet" href="assets/css/gm.07-reminders.css" />
  <link rel="stylesheet" href="assets/css/gm.08-settings.css" />
  <link rel="stylesheet" href="assets/css/gm.09-recalls.css" />
  <link rel="stylesheet" href="assets/css/gm.10-toast.css" />
  <link rel="stylesheet" href="assets/css/gm.12-scrollbar.css" />
  <link rel="stylesheet" href="assets/css/gm.13-enhancements.css" />
  <link rel="stylesheet" href="assets/css/gm.14-service-filter.css" />
  <link rel="stylesheet" href="assets/css/gm.11-responsive.css" />
  <link rel="stylesheet" href="assets/css/gm.15-branding.css" />
  <link rel="stylesheet" href="assets/css/gm.16-overview.css" />
  <link rel="stylesheet" href="assets/css/gm.17-service-costs.css" />
  <link rel="stylesheet" href="assets/css/gm.18-user.css" />
  <link rel="stylesheet" href="assets/css/gm.19-templates.css" />
  <link rel="stylesheet" href="assets/css/gm.20-service-selector.css" />
  <link rel="stylesheet" href="assets/css/gm.21-vehicle-details.css" />
  <link rel="stylesheet" href="assets/css/gm.22-mobile-nav.css" />
  <link rel="stylesheet" href="assets/css/gm.23-pwa.css" />
  <link rel="stylesheet" href="assets/css/gm.24-theme-indicator.css" />
  <link rel="stylesheet" href="assets/css/gm.25-gdrive.css" />
  <link rel="stylesheet" href="assets/css/gm.26-offline.css" />
  <link rel="stylesheet" href="assets/css/gm.26-history-search.css" />
  <link rel="stylesheet" href="assets/css/gm.dynamic-reminders.css">
  <link rel="stylesheet" href="assets/css/gm.copy-reminder-modal.css">
  <link rel="stylesheet" href="assets/css/gm.entry-success-notification.css">
  <link rel="stylesheet" href="assets/css/gm.27-export-modal.css">
  <link rel="stylesheet" href="assets/css/gm.60-subscription.css">
  <link rel="stylesheet" href="assets/css/gm.28-preloader.css" />
  <link rel="stylesheet" href="assets/css/gm.29-mobile-improvements.css">
  
  <!-- Favicon and App Icons -->
  <link rel="icon" type="image/png" sizes="32x32" href="assets/images/icon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/images/icon-16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/images/icon-180.png">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/images/icon-192.png">
  <link rel="icon" type="image/png" sizes="512x512" href="assets/images/icon-512.png">
  
  <!-- Web App Manifest -->
  <link rel="manifest" href="manifest.php">
  <meta name="theme-color" content="<?= htmlspecialchars($themeColors['theme_color']) ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($appConfig['appShortName']) ?>">
  
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css" />
  
  <!-- App Configuration (injected from PHP) -->
<script>
  const GM_CONFIG = <?= json_encode(array_merge($appConfig, [
      'themeMode' => $themeMode,
      'themeColors' => $themeColors,
      'profileUrl' => gm_get_auth_urls()['profile_url'] ?? '/my-profile/',
      'googleDriveEnabled' => defined('GOOGLE_DRIVE_ENABLED') && GOOGLE_DRIVE_ENABLED,
      'maxAttachments' => defined('ENTRY_MAX_ATTACHMENTS') ? ENTRY_MAX_ATTACHMENTS : 2,
      'maxAttachmentSizeMB' => defined('ENTRY_MAX_ATTACHMENT_SIZE_MB') ? ENTRY_MAX_ATTACHMENT_SIZE_MB : 5,
  ]), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  
  const APP_CONFIG = GM_CONFIG; // Backwards compatibility
  
  const GM_USER = <?= json_encode(gm_get_current_user_info() ?? ['id' => 'default'], JSON_HEX_TAG) ?>;
  const GM_AUTH_URLS = <?= json_encode(gm_get_auth_urls(), JSON_HEX_TAG) ?>;
  const ATTACH_MAX_SIZE_MB = <?= defined('ENTRY_MAX_ATTACHMENT_SIZE_MB') ? ENTRY_MAX_ATTACHMENT_SIZE_MB : 5 ?>;
  const ATTACH_MAX_COUNT = <?= defined('ENTRY_MAX_ATTACHMENTS') ? ENTRY_MAX_ATTACHMENTS : 2 ?>;
</script>
  
  <style>
    /* Bootstrap icon styling */
    /* Icons use Bootstrap Icons - no custom styling needed */
  </style>
</head>
<body class="gm-theme-<?= htmlspecialchars($themeMode) ?>">

  <div id="db-error-banner" style="display:none;background:#7f1d1d;color:#fff;padding:12px 16px;font-weight:600;">
    <i class="bi bi-exclamation-triangle-fill"></i> Database connection failed. Check your config.php credentials.
  </div>

  <div class="app">
    <header>
      <div class="title-block">
        <div class="logo-title-row">
          <img src="assets/images/icon-64.png" alt="<?= htmlspecialchars($appConfig['appDomain']) ?>" class="app-logo">
          <div class="title-text">
            <h1 class="app-title"><?= htmlspecialchars($appConfig['appName']) ?></h1>
            <span id="site-title" class="custom-title"></span>
          </div>
        </div>
        <span class="tagline"><?= htmlspecialchars($appConfig['appTagline']) ?></span>
      </div>
      <div class="top-controls">
        <div class="nav">
          <button class="nav-btn active" data-view="dashboard"><i class="bi bi-speedometer2"></i> Dashboard</button>
          <button class="nav-btn" data-view="reminders"><i class="bi bi-bell-fill"></i> Reminders</button>
          <button class="nav-btn" data-view="settings"><i class="bi bi-gear-fill"></i> Settings</button>
        </div>
        <div class="vehicle-picker" id="vehicle-picker">
          <div class="vehicle-picker-select-row">
            <span>Vehicle:</span>
            <select id="active-vehicle"></select>
          </div>
          <div id="vehicle-picker-odo"></div>
        </div>
      </div>
    </header>

    <main>
      <!-- DASHBOARD -->
      <section id="view-dashboard" class="view active">
        
        <!-- Toggle button for entry form (hidden when All Vehicles selected) -->
        <div class="dashboard-actions">
          <button type="button" class="btn-primary" id="toggle-entry-form">
            <span id="toggle-form-icon">+</span> Add New Service Entry
          </button>
        </div>

        <!-- Entry form (starts hidden) -->
        <section class="card dashboard-entry-form" id="dashboard-entry-form" style="display: none;">
          <div class="card-header">
            <h2>New Service Entry</h2>
            <small>For the selected vehicle</small>
          </div>

          <form id="entry-form">
            <input type="hidden" id="entry-id" />
            
            <!-- Template Selector -->
            <div id="template-selector-container" class="template-selector-container">
              <!-- Rendered by JavaScript -->
            </div>

            <div class="field-grid">
              <div class="field">
                <label for="entry-date">Service date</label>
                <input id="entry-date" type="text" placeholder="YYYY-MM-DD" autocomplete="off" required />
              </div>
              <div class="field">
                <label for="entry-odo">Odometer (<span class="unit-label">mi</span>)</label>
                <input id="entry-odo" type="number" min="0" step="1" />
              </div>
              <div class="field" style="grid-column: 1 / -1;">
                <label>Services performed <span class="text-muted" style="font-size:0.75rem;">(check and add cost/details for each)</span></label>
                <div id="service-checklist-container">
                  <!-- Filterable checklist with cost/note fields will be rendered here by JavaScript -->
                </div>
                <input id="entry-services-other" type="text" placeholder="Other/custom services (comma or ; separated)" />
              </div>
              <div class="field">
                <label for="entry-cost">Misc/Other Cost <span class="text-muted" style="font-size:0.75rem;">(taxes, fees, etc.)</span></label>
                <input id="entry-cost" type="number" min="0" step="0.01" placeholder="0.00" />
              </div>
            </div>

            <div class="field" style="margin-top:6px;">
              <label for="entry-notes">Notes</label>
              <textarea id="entry-notes" placeholder="Shop name, part numbers, oil weight, torque specs, battery model, etc."></textarea>
            </div>

            <div class="field" style="margin-top:6px;">
              <label>Attachments (optional)</label>
              <div class="attachment-upload-area">
                <!-- Google Drive Button (shown if enabled) -->
                <?php if (defined('GOOGLE_DRIVE_ENABLED') && GOOGLE_DRIVE_ENABLED): ?>
                <button type="button" class="btn-ghost btn-attachment-gdrive" id="btn-gdrive-attach">
                  <i class="bi bi-google"></i> Add from Google Drive
                </button>
                <?php endif; ?>
                
                <!-- Local Upload Button -->
                <button type="button" class="btn-ghost btn-attachment-local" id="btn-local-attach">
                  <i class="bi bi-upload"></i> Upload File
                </button>
                <input type="file" id="entry-files" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp" style="display:none;" />
                
                <!-- Selected files preview (populated by JS) -->
                <div id="selected-files-preview" class="selected-files-list" style="margin-top:8px;"></div>
                
                <!-- Upgrade hint for free users (hidden by default, shown via JS) -->
                <div id="attachment-upgrade-hint" class="attachment-upgrade-hint text-muted" style="display:none;">
                  <i class="bi bi-lock"></i> <a href="javascript:void(0)" class="upgrade-link">Upgrade</a> for local file uploads
                </div>
                
                <div class="text-muted" style="font-size:0.7rem; margin-top:4px;">
                  PDF, Word, and image files. Max <?= defined('ENTRY_MAX_ATTACHMENT_SIZE_MB') ? ENTRY_MAX_ATTACHMENT_SIZE_MB : 5 ?>MB per file.
                </div>
              </div>
            </div>
            <div class="button-row">
              <button type="button" class="btn-ghost btn-small" id="entry-reset">Clear</button>
              <button type="submit" class="btn-primary"><span id="entry-submit-label">Save entry</span></button>
            </div>
            
            <!-- User preference for keeping form open -->
            <div class="form-preference-section" style="display:none">
              <label class="form-preference-toggle">
                <input type="checkbox" id="keep-form-open-pref" />
                <span>Keep form open after adding entry</span>
              </label>
            </div>
            
            <!-- Save as Template -->
            <div class="save-template-row">
              <button type="button" class="btn-ghost btn-small save-template-btn" id="save-as-template-btn">
                <i class="bi bi-clipboard-check"></i> Save as Template
              </button>
            </div>
          </form>
        </section>

        <!-- ========== ALL VEHICLES OVERVIEW (shown when "All Vehicles" selected) ========== -->
        <div id="all-vehicles-overview" style="display: none;">
          <!-- Content rendered by JavaScript -->
        </div>

        <!-- ========== SINGLE VEHICLE VIEW (shown when specific vehicle selected) ========== -->
        <div id="single-vehicle-view">
          <!-- Dashboard Layout with Sidebar -->
          <div class="dashboard-layout">
            <!-- Main Content: Vehicle Overview & History -->
            <div class="dashboard-main">
              <section class="card">
                <div class="card-header">
                  <h2>Vehicle Overview &amp; History</h2>
                  <small id="overview-vehicle-label"></small>
                <div class="safety-status-row" id="safety-status-container" style="display:none;">
                  <div class="safety-status">
                    <span class="safety-icon"><i class="bi bi-shield-check"></i></span>
                    <span class="safety-label">Safety:</span>
                    <span id="safety-status-badge" class="safety-badge">â€”</span>
                    <button type="button" class="btn-ghost btn-small" id="check-recalls-btn">Check Recalls</button>
                  </div>
                </div>				  
                </div>

                <div class="stats-row">
                  <div class="pill">
                    <span class="pill-dot"></span>
                    <span><strong id="history-total">0</strong> entries</span>
                  </div>
                </div>

                <!-- Recall Details Modal (hidden by default) -->
                <div id="recall-modal" class="recall-modal" style="display:none;">
                  <div class="recall-modal-overlay"></div>
                  <div class="recall-modal-content">
                    <div class="recall-modal-header">
                      <h3>Safety Recalls</h3>
                      <button type="button" class="recall-modal-close" id="close-recall-modal"><i class="bi bi-x"></i></button>
                    </div>
                    <div class="recall-modal-body" id="recall-modal-body">
                      <!-- Content inserted by JavaScript -->
                    </div>
                  </div>
                </div>

                <div id="entry-list" class="entry-list"></div>
              </section>
            </div>

            <!-- Sidebar: Quick Reminders -->
            <aside class="dashboard-sidebar">
              <section class="card sidebar-card">
                <div class="card-header">
                  <h2><i class="bi bi-bell-fill"></i> Reminders</h2>
                  <small>Quick View</small>
                </div>
                <div class="stats-row">
                  <div class="pill">
                    <span class="pill-dot warn"></span>
                    <span><strong id="rem-snippet-upcoming">0</strong> upcoming</span>
                  </div>
                  <div class="pill">
                    <span class="pill-dot bad"></span>
                    <span><strong id="rem-snippet-overdue">0</strong> overdue</span>
                  </div>
                </div>
                <div id="reminder-snippet-list" class="reminder-snippet-list"></div>
                <div class="sidebar-footer" style="display:none;">
                  <button type="button" class="btn-ghost btn-small sidebar-view-all" data-view="reminders">View All Reminders â†’</button>
                </div>
              </section>
            </aside>
          </div>
        </div>
      </section>

      <!-- REMINDERS -->
      <section id="view-reminders" class="view">
        <section class="card">
          <div class="card-header">
            <h2>Reminders Overview</h2>
            <small>Per-vehicle maintenance reminders based on intervals, last service &amp; current mileage.</small>
          </div>

          <div class="stats-row">
            <div class="pill">
              <span class="pill-dot"></span>
              <span><strong id="rem-total">0</strong> reminders</span>
            </div>
            <div class="pill">
              <span class="pill-dot warn"></span>
              <span><strong id="rem-upcoming">0</strong> upcoming</span>
            </div>
            <div class="pill">
              <span class="pill-dot bad"></span>
              <span><strong id="rem-overdue">0</strong> overdue</span>
            </div>
          </div>

          <div class="reminders-list" id="reminders-list"></div>

          <div class="settings-section" style="margin-top:10px;">
            <h3>Add new reminder</h3>
            <!-- UPDATED REMINDER FORM FOR index.php -->
<!-- Replace the existing <form id="reminder-form"> section (around line 385) with this: -->

            <form id="reminder-form">
              <div class="field-grid">
                <div class="field">
                  <label for="rem-new-service">Service name</label>
                  <select id="rem-new-service"></select>
                  <input id="rem-new-service-custom" type="text" placeholder="Custom service name (optional)" style="margin-top:4px;" />
                </div>
                <div class="field">
                  <label for="rem-new-interval-miles">Interval (<span class="unit-label">mi</span>, optional)</label>
                  <input id="rem-new-interval-miles" type="number" min="0" step="100" />
                </div>
                <div class="field">
                  <label for="rem-new-interval-months">Interval (months, optional)</label>
                  <input id="rem-new-interval-months" type="number" min="0" step="1" />
                </div>
                
                <!-- NEW FIELDS ADDED BELOW -->
                <div class="field">
                  <label for="rem-new-base-odo">Last service mileage (<span class="unit-label">mi</span>, optional)</label>
                  <input id="rem-new-base-odo" type="number" min="0" step="1" placeholder="Auto-fills from entries" />
                </div>
                <div class="field">
                  <label for="rem-new-base-date">Last service date (optional)</label>
                  <input id="rem-new-base-date" type="text" placeholder="YYYY-MM-DD" autocomplete="off" class="datepicker" />
                </div>
                <div class="field">
                  <label for="rem-new-next-odo">Next due mileage (<span class="unit-label">mi</span>, optional)</label>
                  <input id="rem-new-next-odo" type="number" min="0" step="1" placeholder="Auto-calculates" />
                </div>
                <div class="field">
                  <label for="rem-new-next-date">Next due date (optional)</label>
                  <input id="rem-new-next-date" type="text" placeholder="YYYY-MM-DD" autocomplete="off" class="datepicker" />
                </div>
                <!-- END NEW FIELDS -->
                
                <div class="field">
                  <label for="rem-new-notes">Notes (optional)</label>
                  <input id="rem-new-notes" type="text" placeholder="Any extra info or link" />
                </div>
              </div>
              <div class="button-row" style="justify-content:flex-end;">
                <button type="submit" class="btn-primary btn-small">+ Add reminder</button>
              </div>
            </form>
          </div>
        </section>
      </section>

      <!-- SETTINGS -->
      <section id="view-settings" class="view">
        <section class="card">
          <div class="card-header">
            <h2>Settings</h2>
            <small>General, vehicles, service types, templates, backup &amp; export</small>
          </div>

          <div class="settings-tabs">
            <button class="settings-tab-btn active" data-tab="general">General</button>
            <button class="settings-tab-btn" data-tab="vehicles">Vehicles</button>
            <button class="settings-tab-btn" data-tab="services">Service types</button>
            <button class="settings-tab-btn" data-tab="templates">Templates</button>
            <button class="settings-tab-btn" data-tab="backup">Backup &amp; Export</button>
          </div>

          <div class="settings-tabs-content">
            <!-- Tab: General -->
            <div id="settings-tab-general" class="settings-tab-view active">
                <!-- PWA Install Section -->
                <div id="pwa-install-container"></div>
              <div class="settings-section">
                <h3>General</h3>
                <div class="settings-help">
                  Change the custom title, distance units, and timezone. Unit affects labels and reminder
                  text (mi vs km) but does not convert stored values.
                </div>
                <div class="field-grid">
                  <div class="field">
                    <label for="settings-site-title">Personalized Custom title</label>
                    <input type="text" id="settings-site-title" placeholder="<?= htmlspecialchars($appConfig['appName']) ?>" />
                  </div>
                  <div class="field">
                    <label for="settings-unit">Distance unit</label>
                    <select id="settings-unit">
                      <option value="mi">Miles</option>
                      <option value="km">Kilometers</option>
                    </select>
                  </div>
                  <div class="field">
                    <label for="settings-timezone">Timezone</label>
                    <select id="settings-timezone">
                      <option value="">Use browser default</option>
                      <option value="Pacific/Honolulu">US - Hawaii (HST)</option>
                      <option value="America/Anchorage">US - Alaska (AKST)</option>
                      <option value="America/Los_Angeles">US - Pacific (PT)</option>
                      <option value="America/Denver">US - Mountain (MT)</option>
                      <option value="America/Chicago">US - Central (CT)</option>
                      <option value="America/New_York">US - Eastern (ET)</option>
                      <option value="Europe/London">Europe - London</option>
                      <option value="Europe/Berlin">Europe - Central</option>
                      <option value="Asia/Tokyo">Asia - Tokyo</option>
                      <option value="Asia/Singapore">Asia - Singapore</option>
                      <option value="Australia/Sydney">Australia - Sydney</option>
                    </select>
                  </div>
                </div>
                
                <!-- Reminder Threshold Settings -->
                <div class="settings-section" style="margin-top:16px;">
                  <h3>Reminder Thresholds</h3>
                  <div class="settings-help">
                    Configure when reminders are marked as "upcoming" or "overdue". 
                    Reminders within these thresholds will be highlighted accordingly.
                  </div>
                  <div class="field-grid">
                    <div class="field">
                      <label for="settings-upcoming-days">Upcoming threshold (days)</label>
                      <input type="number" id="settings-upcoming-days" min="1" max="365" placeholder="14" />
                      <small class="text-muted">Mark as "upcoming" when due within this many days</small>
                    </div>
                    <div class="field">
                      <label for="settings-upcoming-miles">Upcoming threshold (<span class="unit-label">mi</span>)</label>
                      <input type="number" id="settings-upcoming-miles" min="100" step="100" placeholder="500" />
                      <small class="text-muted">Mark as "upcoming" when due within this distance</small>
                    </div>
                    <div class="field">
                      <label for="settings-overdue-days">Overdue grace period (days)</label>
                      <input type="number" id="settings-overdue-days" min="0" max="365" placeholder="0" />
                      <small class="text-muted">Mark as "overdue" after past due by this many days (0 = immediately)</small>
                    </div>
                    <div class="field">
                      <label for="settings-overdue-miles">Overdue grace period (<span class="unit-label">mi</span>)</label>
                      <input type="number" id="settings-overdue-miles" min="0" step="100" placeholder="0" />
                      <small class="text-muted">Mark as "overdue" after past due by this distance (0 = immediately)</small>
                    </div>
                    <div class="field">
                      <label for="settings-avg-daily-miles">Average daily driving (<span class="unit-label">mi</span>)</label>
                      <input type="number" id="settings-avg-daily-miles" min="1" max="500" step="1" placeholder="40" />
                      <small class="text-muted">Used to sort mileage-based reminders by urgency (default: 40)</small>
                    </div>
                  </div>
                </div>
                
                <!-- Overview Settings -->
                <div class="settings-section" style="margin-top:16px;">
                  <h3>Fleet Overview</h3>
                  <div class="settings-help">
                    Configure the "All Vehicles" dashboard overview display.
                  </div>
                  <div class="field-grid">
                    <div class="field">
                      <label for="settings-overview-vehicles-per-page">Vehicles per page</label>
                      <input type="number" id="settings-overview-vehicles-per-page" min="1" max="50" placeholder="Auto (responsive)" />
                      <small class="text-muted">Leave empty for responsive default (4-9 based on screen size)</small>
                    </div>
                  </div>
                </div>
                
                <div class="button-row" style="justify-content:flex-start;margin-top:8px;">
                  <button type="button" class="btn-primary btn-small" id="settings-general-save">Save general settings</button>
                </div>
              </div>
            </div>

            <!-- Tab: Vehicles -->
            <div id="settings-tab-vehicles" class="settings-tab-view">
              <div class="settings-section">
                <h3>Vehicles</h3>
                <div class="settings-help">
                  Vehicles appear in the selector at the top. Set
                  <strong>current mileage</strong> for mileage-based reminders. VIN and plate
                  are optional and will be included in exports. 
                  <strong>Renewal dates</strong> automatically sync with reminders.
                </div>
                <div id="settings-vehicles" class="settings-list"></div>
                <div class="settings-add-row">
                  <input type="text" id="settings-vehicle-new" placeholder="Add new vehicle (e.g. 2018 Mazda CX-5)" />
                  <button type="button" class="btn-primary btn-small" id="settings-vehicle-add">+ Add vehicle</button>
                </div>
              </div>
            </div>

            <!-- Tab: Service types -->
            <div id="settings-tab-services" class="settings-tab-view">
              <div class="settings-section">
                <h3>Service types</h3>
                <div class="settings-help">
                  These appear as checkboxes when documenting a service and as templates for
                  reminders. Service types are universal; their default intervals apply to all vehicles.
                </div>
                <div id="settings-services" class="settings-list"></div>
                <div class="settings-add-row">
                  <input type="text" id="settings-service-new" placeholder="Add service type (e.g. Oil change)" />
                  <button type="button" class="btn-primary btn-small" id="settings-service-add">+ Add service</button>
                </div>
              </div>
            </div>

            <!-- Tab: Templates -->
            <div id="settings-tab-templates" class="settings-tab-view">
              <div class="settings-section">
                <h3>Entry Templates</h3>
                <div class="settings-help">
                  Create reusable templates for common service entries. Templates can include
                  pre-selected services with default costs, notes, and automatic next-due calculations.
                  Use the "Quick fill" dropdown in the entry form to load a template.
                </div>
                <div id="settings-templates-list" class="templates-list"></div>
                <div id="settings-template-add-form"></div>
              </div>
            </div>

            <!-- Tab: Backup & Export -->
            <div id="settings-tab-backup" class="settings-tab-view">
              <div class="settings-section">
                <h3>Backup &amp; export</h3>
                <div class="settings-help">
                  <strong>Full backup (JSON):</strong> Includes all data AND attachment files embedded in a single JSON file. Best for complete backup/restore.
                  <br>
                  <strong>Data only (JSON):</strong> Database only, no attachments. Smaller file, faster for data migration.
                  <br>
                  <strong>Table exports:</strong> Export service history for the currently selected vehicle as Excel/CSV, Word, or PDF.
                </div>
                
                <!-- Full Backup Section -->
                <div class="backup-section backup-section-primary">
                  <div class="backup-section-title"><i class="bi bi-archive-fill"></i> Complete Backup (Recommended)</div>
                  <div class="backup-section-desc">Includes all data + attachment files in a single JSON file</div>
                  <div class="button-row" style="justify-content:flex-start; margin-top:4px;">
                    <button type="button" class="btn-primary btn-small" id="backup-export-full"><i class="bi bi-download"></i> Download Full Backup</button>
                    <label class="btn-primary btn-small" style="cursor:pointer;">
                      <i class="bi bi-upload"></i> Restore from Full Backup
                      <input type="file" id="backup-import-full" accept=".json,.zip,application/json,application/zip" style="display:none;" />
                    </label>
                  </div>
                </div>
                
                <!-- Data-Only Backup Section -->
                <div class="backup-section">
                  <div class="backup-section-title"><i class="bi bi-file-earmark-code"></i> Data Only Backup</div>
                  <div class="backup-section-desc">Database only (no attachment files). Smaller file size.</div>
                  <div class="button-row" style="justify-content:flex-start; margin-top:4px;">
                    <button type="button" class="btn-ghost btn-small" id="backup-export"><i class="bi bi-download"></i> Export data (JSON)</button>
                    <label class="btn-ghost btn-small" style="cursor:pointer;">
                      <i class="bi bi-upload"></i> Import data (JSON)
                      <input type="file" id="backup-import" accept=".json,.txt,application/json" style="display:none;" />
                    </label>
                  </div>
                </div>
                
                <!-- Vehicle Report Export Section -->
                <div class="backup-section">
                  <div class="backup-section-title"><i class="bi bi-file-earmark-text"></i> Vehicle Report Export</div>
                  <div class="backup-section-desc">Export a comprehensive maintenance report for the selected vehicle. Choose format, date range, and sections to include.</div>
                  <div class="button-row" style="justify-content:flex-start; margin-top:4px;">
                    <button type="button" class="btn-primary btn-small" id="open-vehicle-report-export" onclick="openVehicleReportExportModal()">
                      <i class="bi bi-download"></i> Export Vehicle Report
                    </button>
                  </div>
                </div>
                
                <!-- Danger Zone -->
                <div class="backup-section backup-section-danger">
                  <div class="backup-section-title"><i class="bi bi-exclamation-triangle-fill"></i> Danger Zone</div>
                  <div class="backup-section-desc">This will permanently delete all data and attachments. Cannot be undone!</div>
                  <button type="button" class="btn-danger btn-small" id="backup-reset"><i class="bi bi-trash3-fill"></i> Clear all data</button>
                </div>
              </div>
            </div>
          </div>
        </section>
      </section>
    </main>

    <footer>
      <span>&copy; <?= htmlspecialchars($appConfig['copyrightYear']) ?> <?= htmlspecialchars($appConfig['appName']) ?>. All rights reserved.</span>
      <span>Version <?= htmlspecialchars($appConfig['appVersion']) ?></span>
    </footer>
  </div>

<!-- Copy Reminder Modal -->
<div id="copy-reminder-modal" class="modal-overlay" style="display: none;">
  <div class="modal-content copy-reminder-modal-content">
    <div class="modal-header">
      <h3>Copy Reminder to Another Vehicle</h3>
      <button class="modal-close" id="copy-reminder-close">&times;</button>
    </div>
    
    <div class="modal-body">
      <!-- Source reminder info (read-only display) -->
      <div class="copy-reminder-source">
        <div class="copy-reminder-source-label">Copying from:</div>
        <div class="copy-reminder-source-info">
          <strong id="copy-source-vehicle"></strong> - <span id="copy-source-service"></span>
        </div>
      </div>
      
      <!-- Target vehicle selector -->
      <div class="field">
        <label for="copy-target-vehicle">Copy to vehicle:</label>
        <select id="copy-target-vehicle">
          <option value="">-- Select vehicle --</option>
        </select>
        <!-- Duplicate warning (hidden by default) -->
        <div id="copy-duplicate-warning" class="copy-duplicate-warning" style="display: none;">
          <span class="warning-icon">⚠️</span>
          <span id="copy-duplicate-message"></span>
        </div>
      </div>
      
      <!-- Reminder details (editable) -->
      <div id="copy-reminder-fields" style="display: none;">
        <div class="copy-reminder-divider"></div>
        
        <div class="field-grid">
          <div class="field">
            <label>Service name</label>
            <input type="text" id="copy-service-name" readonly />
          </div>
          
          <div class="field">
            <label>Interval (<span class="unit-label">mi</span>)</label>
            <input type="number" id="copy-interval-miles" readonly />
          </div>
          
          <div class="field">
            <label>Interval (months)</label>
            <input type="number" id="copy-interval-months" readonly />
          </div>
          
          <div class="field">
            <label>Last service mileage (<span class="unit-label">mi</span>)</label>
            <input type="number" id="copy-base-odo" min="0" step="1" />
          </div>
          
          <div class="field">
            <label>Last service date</label>
            <input type="text" id="copy-base-date" placeholder="YYYY-MM-DD" autocomplete="off" class="datepicker" />
          </div>
          
          <div class="field">
            <label>Next due mileage (<span class="unit-label">mi</span>)</label>
            <input type="number" id="copy-next-odo" min="0" step="1" />
          </div>
          
          <div class="field">
            <label>Next due date</label>
            <input type="text" id="copy-next-date" placeholder="YYYY-MM-DD" autocomplete="off" class="datepicker" />
          </div>
          
          <div class="field" style="grid-column: 1 / -1;">
            <label>Notes</label>
            <textarea id="copy-notes" rows="2"></textarea>
          </div>
        </div>
      </div>
    </div>
    
    <div class="modal-footer">
      <button type="button" class="btn-secondary" id="copy-reminder-cancel">Cancel</button>
      <button type="button" class="btn-primary" id="copy-reminder-confirm" disabled>Copy Reminder</button>
    </div>
  </div>
</div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://code.jquery.com/ui/1.13.3/jquery-ui.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.1/jspdf.plugin.autotable.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script src="assets/js/gm.core.js"></script>
  <script src="assets/js/gm.toast.js"></script>
  <script src="assets/js/gm.api.js"></script>
  <script src="assets/js/gm.subscription.js"></script>
  <script src="assets/js/gm.state.js"></script>
  <script src="assets/js/gm.utils.js"></script>
  <script src="assets/js/gm.ui.js"></script>
  <script src="assets/js/gm.render.dashboard.js"></script>
  <script src="assets/js/gm.render.reminders.js"></script>
  <script src="assets/js/gm.render.settings.js"></script>
  <script src="assets/js/gm.router.js"></script>
  <script src="assets/js/gm.features.attachments.js"></script>
  <script src="assets/js/gm.features.templates.js"></script>
  <script src="assets/js/gm.features.recalls.js"></script>
  <script src="assets/js/gm.features.export.js"></script>
  <script src="assets/js/gm.features.vehicle-report-export.js"></script>
  <script src="assets/js/gm.user.js"></script>
  <script src="assets/js/gm.mobile-nav.js"></script>
  <script src="assets/js/gm.features.gdrive.js"></script>
  <script src="assets/js/gm.features.history-search.js"></script>
<script src="assets/js/gm.features.dynamic-reminders.js"></script>
<script src="assets/js/gm.features.copy-reminder.js"></script>
<script src="assets/js/gm.features.entry-reminders.js"></script>
<script src="assets/js/gm.reminder-handlers.js"></script>           
<script src="assets/js/gm.entry-management.js"></script>
<script src="assets/js/gm.features.offline.js"></script>
<script src="assets/js/gm.handlers.js"></script>
<script src="assets/js/gm.dynamic-reminders-integration.js"></script>
<script src="assets/js/gm.pwa.js"></script>
<script>
// Send APP_VERSION to service worker on every load.
// This is the single signal that triggers cache invalidation on deploy.
// To push an update to all users: bump APP_VERSION in config.php.
(function() {
  // Skip all SW communication when offline storage is disabled (dev mode)
  if (typeof GM_CONFIG !== 'undefined' && GM_CONFIG.offlineStorageEnabled === false) return;

  var swVersion = (typeof GM_CONFIG !== 'undefined' && GM_CONFIG.appVersion)
    ? GM_CONFIG.appVersion : '2.5.0';

  if ('serviceWorker' in navigator) {
    // Send version to active SW
    if (navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage({
        type: 'SET_VERSION',
        version: swVersion
      });
    }

    // Listen for messages from SW
    navigator.serviceWorker.addEventListener('message', function(event) {
      if (!event.data) return;

      // New SW version installed — prompt user to refresh
      if (event.data.type === 'SW_UPDATED') {
        if (typeof gmOffline !== 'undefined') {
          gmOffline.notifyUpdateAvailable();
        }
      }

      // Background sync triggered by SW
      if (event.data.type === 'TRIGGER_SYNC') {
        if (typeof gmOffline !== 'undefined') {
          gmOffline.syncPendingQueue();
        }
      }
    });

    // Also send version once SW is ready (covers first-load before controller exists)
    navigator.serviceWorker.ready.then(function(reg) {
      if (reg.active) {
        reg.active.postMessage({ type: 'SET_VERSION', version: swVersion });
      }
    });
  }
})();
</script>
  <script src="assets/js/gm.theme-indicator.js"></script>
  <script src="assets/js/gm.fixes.js"></script>
  <script src="assets/js/gm.preloader.js"></script>



</body>
</html>