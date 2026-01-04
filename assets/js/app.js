
    const STORAGE_KEY = "garage_maintenance_data_v2";
    const BACKEND_URL = "api.php";

const DEFAULT_SETTINGS = {
  siteTitle: "Garage Maintenance",
  unit: "mi",
  timezone: null,
  keepFormOpen: false,
  upcomingThresholdDays: 14,
  upcomingThresholdMiles: 500,
  overdueThresholdDays: 0,
  overdueThresholdMiles: 0
};

    const DEFAULT_DATA = {
      vehicles: [],
      serviceTypes: [
  // Engine & powertrain (core)
  { name: "Oil change",                          intervalMiles: 5000,  intervalMonths: 6 },
  { name: "Oil filter change",                   intervalMiles: 5000,  intervalMonths: 6 },
  { name: "Engine air filter replacement",       intervalMiles: null,  intervalMonths: 24 },
  { name: "Cabin air filter replacement",        intervalMiles: null,  intervalMonths: 12 },
  { name: "Spark plug replacement",              intervalMiles: 60000, intervalMonths: null },
  { name: "Serpentine / drive belt replacement", intervalMiles: 60000, intervalMonths: null },

  // Transmission & drivetrain
  { name: "Transmission fluid change",           intervalMiles: 60000, intervalMonths: 60 },
  { name: "Differential fluid change",           intervalMiles: 60000, intervalMonths: null },
  { name: "Transfer case fluid change",          intervalMiles: 60000, intervalMonths: null },
  { name: "Power steering fluid change",         intervalMiles: 60000, intervalMonths: null },

  // Brakes
  { name: "Brake fluid change",                  intervalMiles: null,  intervalMonths: 24 },
  { name: "Brake pad replacement",               intervalMiles: 40000, intervalMonths: null },
  { name: "Brake rotor replacement",             intervalMiles: 80000, intervalMonths: null },

  // Cooling system
  { name: "Coolant change",                      intervalMiles: 60000, intervalMonths: 60 },
  { name: "Radiator / cooling system service",   intervalMiles: null,  intervalMonths: null },

  // Tires & wheels
  { name: "Tire rotation",                       intervalMiles: 5000,  intervalMonths: 6 },
  { name: "Wheel alignment",                     intervalMiles: null,  intervalMonths: 12 },
  { name: "Wheel balance",                       intervalMiles: null,  intervalMonths: null },

  // Electrical & battery
  { name: "12V battery replacement",             intervalMiles: null,  intervalMonths: 48 },
  { name: "Charging system service",             intervalMiles: null,  intervalMonths: null },

  // Suspension & steering
  { name: "Suspension inspection",               intervalMiles: null,  intervalMonths: 12 },
  { name: "Steering inspection",                 intervalMiles: null,  intervalMonths: 12 },

  // Safety / legal / ownership
  { name: "Vehicle inspection (state / safety)", intervalMiles: null,  intervalMonths: 12 },
  { name: "Emissions test",                      intervalMiles: null,  intervalMonths: 24 },
  { name: "Registration renewal",                intervalMiles: null,  intervalMonths: 12 },
  { name: "Insurance renewal",                   intervalMiles: null,  intervalMonths: 12 },
  { name: "Recall service completed",            intervalMiles: null,  intervalMonths: null }
],
      entries: [],
      reminders: [],
      vehicleIntervals: {},
      settings: DEFAULT_SETTINGS
    };

    let data = null;
    let activeVehicleId = null;
    let dashboardHistoryPage = 1;


    function cloneDefaultData() {
      return JSON.parse(JSON.stringify(DEFAULT_DATA));
    }

    function loadData() {
  // Start with defaults in case backend is empty or fails
  data = cloneDefaultData();
if (!data.settings) {
    data.settings = JSON.parse(JSON.stringify(DEFAULT_SETTINGS));
  } else {
    if (typeof data.settings.siteTitle !== "string" || !data.settings.siteTitle) {
      data.settings.siteTitle = DEFAULT_SETTINGS.siteTitle;
    }
    if (!data.settings.unit) {
      data.settings.unit = DEFAULT_SETTINGS.unit;
    }
    if (!Object.prototype.hasOwnProperty.call(data.settings, "timezone")) {
      data.settings.timezone = DEFAULT_SETTINGS.timezone;
    }
    // Add this check for keepFormOpen
    if (!Object.prototype.hasOwnProperty.call(data.settings, "keepFormOpen")) {
      data.settings.keepFormOpen = DEFAULT_SETTINGS.keepFormOpen;
    }
  }
  try {
    $.ajax({
      url: BACKEND_URL,
      method: "GET",
      dataType: "json",
      data: { action: "load", ts: Date.now() },
      cache: false,
      async: false,
      success: function(resp) {
        if (resp && resp.db_error) {
          const banner = document.getElementById("db-error-banner");
          if (banner) banner.style.display = "block";
          return;
        }
        if (resp && resp.success && resp.data) {
          data = resp.data;
        }
      },
      error: function(xhr, status, err) {
        console.error("Error loading from backend, using defaults.", err);
      }
    });

    if (!data.vehicles)         data.vehicles = [];
    if (!data.serviceTypes)     data.serviceTypes = [];
    if (!data.entries)          data.entries = [];
    if (!data.reminders)        data.reminders = [];
    if (!data.vehicleIntervals) data.vehicleIntervals = {};
    if (!data.settings)         data.settings = JSON.parse(JSON.stringify(DEFAULT_SETTINGS));
    else {
      if (typeof data.settings.siteTitle !== "string" || !data.settings.siteTitle) {
        data.settings.siteTitle = DEFAULT_SETTINGS.siteTitle;
      }
      if (!data.settings.unit) {
        data.settings.unit = DEFAULT_SETTINGS.unit;
      }
      if (!Object.prototype.hasOwnProperty.call(data.settings, "timezone")) {
        data.settings.timezone = DEFAULT_SETTINGS.timezone;
      }
      // Ensure reminder threshold settings exist with defaults
      if (!Object.prototype.hasOwnProperty.call(data.settings, "upcomingThresholdDays")) {
        data.settings.upcomingThresholdDays = DEFAULT_SETTINGS.upcomingThresholdDays;
      }
      if (!Object.prototype.hasOwnProperty.call(data.settings, "upcomingThresholdMiles")) {
        data.settings.upcomingThresholdMiles = DEFAULT_SETTINGS.upcomingThresholdMiles;
      }
      if (!Object.prototype.hasOwnProperty.call(data.settings, "overdueThresholdDays")) {
        data.settings.overdueThresholdDays = DEFAULT_SETTINGS.overdueThresholdDays;
      }
      if (!Object.prototype.hasOwnProperty.call(data.settings, "overdueThresholdMiles")) {
        data.settings.overdueThresholdMiles = DEFAULT_SETTINGS.overdueThresholdMiles;
      }
    }

    // Normalize vehicles shape
    data.vehicles.forEach(v => {
      if (!Object.prototype.hasOwnProperty.call(v, "currentOdo")) v.currentOdo = null;
      if (!Object.prototype.hasOwnProperty.call(v, "vin"))        v.vin = null;
      if (!Object.prototype.hasOwnProperty.call(v, "plate"))      v.plate = null;
    });

    // Normalize serviceTypes: ensure objects with name/intervalMiles/intervalMonths
    if (Array.isArray(data.serviceTypes) && data.serviceTypes.length) {
      if (typeof data.serviceTypes[0] === "string") {
        data.serviceTypes = data.serviceTypes.map(n => ({
          name: n,
          intervalMiles: null,
          intervalMonths: null
        }));
      } else {
        data.serviceTypes = data.serviceTypes.map(st => {
          if (typeof st === "string") {
            return { name: st, intervalMiles: null, intervalMonths: null };
          }
          return {
            name: st.name || "",
            intervalMiles: (st.intervalMiles != null ? st.intervalMiles : null),
            intervalMonths: (st.intervalMonths != null ? st.intervalMonths : null)
          };
        });
      }
    }

  } catch (e) {
    console.error("Error loading data, resetting.", e);
    data = cloneDefaultData();
  }
}


function showToast(message) {
  try {
    // Create a unique toast element for each message
    const toastId = 'toast-' + Date.now() + '-' + Math.random().toString(36).slice(2);
    
    // Create toast container if it doesn't exist
    let container = document.getElementById('gm-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'gm-toast-container';
      container.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 10000;
        display: flex;
        flex-direction: column;
        gap: 10px;
        pointer-events: none;
      `;
      document.body.appendChild(container);
    }
    
    // Create individual toast
    const toast = document.createElement('div');
    toast.id = toastId;
    toast.textContent = message || "Changes saved";
    toast.style.cssText = `
      background: rgba(34, 197, 94, 0.95);
      color: white;
      padding: 12px 20px;
      border-radius: 8px;
      font-size: 0.875rem;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
      max-width: 300px;
      word-wrap: break-word;
      pointer-events: auto;
      opacity: 0;
      transform: translateX(20px);
      transition: opacity 0.3s ease, transform 0.3s ease;
    `;
    
    container.appendChild(toast);
    
    // Trigger animation
    requestAnimationFrame(() => {
      toast.style.opacity = '1';
      toast.style.transform = 'translateX(0)';
    });
    
    // Remove toast after duration
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(20px)';
      
      setTimeout(() => {
        if (toast.parentNode) {
          toast.parentNode.removeChild(toast);
        }
        
        // Clean up container if empty
        if (container.childNodes.length === 0) {
          if (container.parentNode) {
            container.parentNode.removeChild(container);
          }
        }
      }, 300);
    }, 3000); // Show for 3 seconds
    
  } catch (e) {
    console.error("Toast error:", e);
  }
}

function saveData() {
  try {
    if (data && typeof data === "object") {
      data.activeVehicleId = activeVehicleId || null;
    }

    $.ajax({
      url: BACKEND_URL + "?action=save",
      method: "POST",
      contentType: "application/json",
      data: JSON.stringify({ data: data || {} }),
      success: function(resp) {
        if (resp && resp.success) {
          showToast("Changes saved");
        } else if (resp && resp.db_error) {
          const banner = document.getElementById("db-error-banner");
          if (banner) banner.style.display = "block";
        }
      }
    }).fail(function(xhr, status, err) {
      console.error("Error saving data:", err);
    });
  } catch (e) {
    console.error("Error in saveData:", e);
  }
}


    function setActiveVehicleFromStorageOrDefault() {
  const stored = (data && data.activeVehicleId) || null;
  const exists = stored && data.vehicles.some(v => v.id === stored);
  if (exists) {
    activeVehicleId = stored;
  } else {
    activeVehicleId = data.vehicles[0]?.id || null;
  }
}


function setActiveVehicle(id) {
  activeVehicleId = id;
  if (data && typeof data === "object") {
    data.activeVehicleId = activeVehicleId || null;
  }
  dashboardHistoryPage = 1; // Reset to page 1 when changing vehicles
  saveData();
}


    function getUnitShort() {
      return (data.settings && data.settings.unit === "km") ? "km" : "mi";
    }

    function getSettingsTimezone() {
      const s = data.settings || DEFAULT_SETTINGS;
      if (s && typeof s.timezone === "string" && s.timezone.trim() !== "") {
        return s.timezone.trim();
      }
      try {
        const opts = Intl.DateTimeFormat().resolvedOptions();
        return opts.timeZone || "UTC";
      } catch (e) {
        return "UTC";
      }
    }
    
    // Get reminder thresholds from settings
function getReminderThresholds() {
  const s = data.settings || DEFAULT_SETTINGS;
  return {
    upcomingDays: s.upcomingThresholdDays != null ? s.upcomingThresholdDays : DEFAULT_SETTINGS.upcomingThresholdDays,
    upcomingMiles: s.upcomingThresholdMiles != null ? s.upcomingThresholdMiles : DEFAULT_SETTINGS.upcomingThresholdMiles,
    overdueDays: s.overdueThresholdDays != null ? s.overdueThresholdDays : DEFAULT_SETTINGS.overdueThresholdDays,
    overdueMiles: s.overdueThresholdMiles != null ? s.overdueThresholdMiles : DEFAULT_SETTINGS.overdueThresholdMiles
  };
}

    function getAttachmentLimits() {
      const maxCount = (data && typeof data.entryMaxAttachments === "number" && data.entryMaxAttachments > 0)
        ? data.entryMaxAttachments
        : 5;
      const maxSizeMB = (data && typeof data.entryMaxAttachmentSizeMB === "number" && data.entryMaxAttachmentSizeMB > 0)
        ? data.entryMaxAttachmentSizeMB
        : 10;
      return { maxCount, maxSizeMB };
    }

    function getAttachmentHelpText() {
      const { maxCount, maxSizeMB } = getAttachmentLimits();
      return `Up to ${maxCount} attachments per entry, PDF/Word/images only, max ${maxSizeMB} MB each. Files are stored locally; large files may exceed storage limits.`;
    }

    function getTodayIsoInSettingsTz() {
      const tz = getSettingsTimezone();
      try {
        const now = new Date();
        const fmt = new Intl.DateTimeFormat("en-CA", {
          timeZone: tz,
          year: "numeric",
          month: "2-digit",
          day: "2-digit"
        });
        const parts = fmt.formatToParts(now);
        const y = parts.find(p => p.type === "year").value;
        const m = parts.find(p => p.type === "month").value;
        const d = parts.find(p => p.type === "day").value;
        return `${y}-${m}-${d}`;
      } catch (e) {
        return getTodayIsoInSettingsTz();
      }
    }

    function getTodayDateInSettingsTz() {
      const iso = getTodayIsoInSettingsTz();
      return new Date(iso + "T00:00:00");
    }

    function formatDateNice(iso) {
      if (!iso) return "";
      const d = new Date(iso + "T00:00:00");
      if (isNaN(d.getTime())) return iso;
      return d.toLocaleDateString(undefined, { year:"numeric", month:"short", day:"numeric" });
    }

    function formatBytes(size) {
      if (size == null) return "";
      const kb = size / 1024;
      if (kb < 1024) return kb.toFixed(1) + " KB";
      const mb = kb / 1024;
      return mb.toFixed(2) + " MB";
    }

    function getServiceTypeByName(name) {
      return (data.serviceTypes || []).find(st => st && st.name === name) || null;
    }

    function addMonthsToDate(iso, months) {
      if (!iso || !months) return null;
      const [y,m,d] = iso.split("-").map(Number);
      if (!y || !m || !d) return iso;
      const date = new Date(y, m - 1, d);
      const newMonth = date.getMonth() + months;
      date.setMonth(newMonth);
      const yy = date.getFullYear();
      const mm = String(date.getMonth() + 1).padStart(2, "0");
      const dd = String(date.getDate()).padStart(2, "0");
      return `${yy}-${mm}-${dd}`;
    }

    function getIntervalForService(vehicleId, serviceName) {
      let intervalMiles = null;
      let intervalMonths = null;

      if (data.vehicleIntervals && vehicleId && serviceName) {
        const vMap = data.vehicleIntervals[vehicleId];
        if (vMap && vMap[serviceName]) {
          const vi = vMap[serviceName];
          if (vi.intervalMiles != null && vi.intervalMiles !== "") {
            intervalMiles = vi.intervalMiles;
          }
          if (vi.intervalMonths != null && vi.intervalMonths !== "") {
            intervalMonths = vi.intervalMonths;
          }
        }
      }

      const st = getServiceTypeByName(serviceName);
      if (intervalMiles == null && st && st.intervalMiles != null) {
        intervalMiles = st.intervalMiles;
      }
      if (intervalMonths == null && st && st.intervalMonths != null) {
        intervalMonths = st.intervalMonths;
      }

      return { intervalMiles, intervalMonths };
    }

    function computeReminderDerived(rem, currentOdo) {
  const unit = getUnitShort();
  const today = getTodayDateInSettingsTz();
  
  // Get configurable thresholds from settings
  const thresholds = getReminderThresholds();

  let nextOdo = rem.nextOdo != null ? rem.nextOdo : null;
  let nextDate = rem.nextDate || null;

  if (rem.intervalMiles && rem.intervalMiles > 0 && nextOdo == null) {
    if (rem.baseOdo != null) {
      nextOdo = rem.baseOdo + rem.intervalMiles;
    } else if (currentOdo != null) {
      nextOdo = currentOdo + rem.intervalMiles;
    }
  }
  if (rem.intervalMonths && rem.intervalMonths > 0 && !nextDate) {
    if (rem.baseDate) {
      nextDate = addMonthsToDate(rem.baseDate, rem.intervalMonths);
    } else {
      const todayIso = getTodayIsoInSettingsTz();
      nextDate = addMonthsToDate(todayIso, rem.intervalMonths);
    }
  }

  let milesDiff = null;
  if (nextOdo != null && currentOdo != null) {
    milesDiff = nextOdo - currentOdo;
  }

  let daysDiff = null;
  if (nextDate) {
    const due = new Date(nextDate + "T00:00:00");
    if (!isNaN(due.getTime())) {
      daysDiff = Math.round((due.getTime() - today.getTime()) / (1000*60*60*24));
    }
  }

  // Determine level based on configurable thresholds
  let level = "ok";
  
  // Check for overdue (past due by more than the grace period)
  const isOverdueMiles = milesDiff != null && milesDiff < -thresholds.overdueMiles;
  const isOverdueDays = daysDiff != null && daysDiff < -thresholds.overdueDays;
  
  // Check for upcoming (within threshold but not overdue)
  const isUpcomingMiles = milesDiff != null && milesDiff <= thresholds.upcomingMiles && milesDiff >= -thresholds.overdueMiles;
  const isUpcomingDays = daysDiff != null && daysDiff <= thresholds.upcomingDays && daysDiff >= -thresholds.overdueDays;
  
  if (isOverdueMiles || isOverdueDays) {
    level = "overdue";
  } else if (isUpcomingMiles || isUpcomingDays) {
    level = "upcoming";
  }

  let milesPart = "no mileage target";
  if (milesDiff != null) {
    if (milesDiff < 0) {
      milesPart = `overdue by ${Math.abs(milesDiff)} ${unit}`;
    } else {
      milesPart = `due in ${milesDiff} ${unit}`;
    }
  }

  let daysPart = "no date target";
  if (daysDiff != null) {
    if (daysDiff < 0) {
      const absDays = Math.abs(daysDiff);
      if (absDays >= 365) {
        const years = Math.floor(absDays / 365);
        daysPart = `overdue by ${years} year${years > 1 ? 's' : ''}`;
      } else if (absDays > 30) {
        const months = Math.floor(absDays / 30);
        daysPart = `overdue by ${months} month${months > 1 ? 's' : ''}`;
      } else {
        daysPart = `overdue by ${absDays} day${absDays !== 1 ? 's' : ''}`;
      }
    } else {
      if (daysDiff >= 365) {
        const years = Math.floor(daysDiff / 365);
        daysPart = `due in ${years} year${years > 1 ? 's' : ''}`;
      } else if (daysDiff > 30) {
        const months = Math.floor(daysDiff / 30);
        daysPart = `due in ${months} month${months > 1 ? 's' : ''}`;
      } else {
        daysPart = `due in ${daysDiff} day${daysDiff !== 1 ? 's' : ''}`;
      }
    }
  }

  let label = "";
  if (milesDiff == null && daysDiff == null) {
    label = "No mileage or date configured";
  } else if (milesDiff != null && daysDiff != null) {
    label = `${milesPart} or ${daysPart}, whichever comes first`;
  } else if (milesDiff != null) {
    label = milesPart;
  } else if (daysDiff != null) {
    label = daysPart;
  }

  return {
    level,
    label,
    milesDiff,
    daysDiff,
    nextOdo,
    nextDate
  };
}

    // NEW: Reset reminders when an entry is added/edited
    function resetRemindersForEntry(entry) {
      if (!entry || !entry.vehicleId) return;
      const vehicle = data.vehicles.find(v => v.id === entry.vehicleId) || null;
      const currentOdo = vehicle && vehicle.currentOdo != null ? vehicle.currentOdo : null;
      const nowIso = new Date().toISOString();

      (entry.services || []).forEach(serviceName => {
        if (!serviceName) return;
        const rems = data.reminders.filter(
          r => r.vehicleId === entry.vehicleId && r.serviceName === serviceName
        );
        rems.forEach(r => {
          const intervalMiles = r.intervalMiles != null ? r.intervalMiles : null;
          const intervalMonths = r.intervalMonths != null ? r.intervalMonths : null;

          let baseOdo = entry.odo != null ? entry.odo
                       : (currentOdo != null ? currentOdo
                          : (r.baseOdo != null ? r.baseOdo : null));
          let baseDate = entry.date || r.baseDate || null;

          let nextOdo = r.nextOdo != null ? r.nextOdo : null;
          let nextDate = r.nextDate || null;

          if (intervalMiles && intervalMiles > 0 && baseOdo != null) {
            nextOdo = baseOdo + intervalMiles;
          }

          if (intervalMonths && intervalMonths > 0) {
            if (baseDate) {
              nextDate = addMonthsToDate(baseDate, intervalMonths);
            } else {
              const todayIso = getTodayIsoInSettingsTz();
              baseDate = todayIso;
              nextDate = addMonthsToDate(baseDate, intervalMonths);
            }
          }

          r.baseOdo = baseOdo;
          r.baseDate = baseDate;
          r.nextOdo = nextOdo;
          r.nextDate = nextDate;
          r.updatedAt = nowIso;
        });
      });
    }

    function applyThemeFromSettings() {
      document.body.style.backgroundImage =
        "radial-gradient(circle at top, #1f2937, #020617 55%)";
    }

function applySiteTitle() {
  const title = data.settings?.siteTitle || "";
  const $customTitle = $("#site-title");
  
  // Only show custom title if it's different from default or not empty
  if (title && title !== "MyWrench.app" && title !== "Garage Maintenance") {
    $customTitle.text(title);
  } else {
    $customTitle.text(""); // Empty hides it via CSS
  }
  
  // Browser tab title
  document.title = title ? `${title} | MyWrench.app` : "MyWrench.app";
}

    function updateUnitLabels() {
      const unit = getUnitShort();
      $(".unit-label").text(unit);
    }

    function renderSettingsGeneral() {
      const s = data.settings || DEFAULT_SETTINGS;
      $("#settings-site-title").val(s.siteTitle || DEFAULT_SETTINGS.siteTitle);
      $("#settings-unit").val(s.unit || "mi");

      const $tz = $("#settings-timezone");
      if ($tz.length) {
        let currentTz =
          (s.timezone && typeof s.timezone === "string" && s.timezone.trim() !== "")
            ? s.timezone.trim()
            : (function() {
                try {
                  const opts = Intl.DateTimeFormat().resolvedOptions();
                  return opts.timeZone || "";
                } catch (e) {
                  return "";
                }
              })();

        if (currentTz) {
          if ($tz.find('option[value="' + currentTz + '"]').length === 0) {
            $tz.append($("<option>").val(currentTz).text(currentTz + " (custom)"));
          }
          $tz.val(currentTz);
        } else {
          $tz.val("");
        }
      }
// Render reminder threshold settings
$("#settings-upcoming-days").val(s.upcomingThresholdDays != null ? s.upcomingThresholdDays : DEFAULT_SETTINGS.upcomingThresholdDays);
$("#settings-upcoming-miles").val(s.upcomingThresholdMiles != null ? s.upcomingThresholdMiles : DEFAULT_SETTINGS.upcomingThresholdMiles);
$("#settings-overdue-days").val(s.overdueThresholdDays != null ? s.overdueThresholdDays : DEFAULT_SETTINGS.overdueThresholdDays);
$("#settings-overdue-miles").val(s.overdueThresholdMiles != null ? s.overdueThresholdMiles : DEFAULT_SETTINGS.overdueThresholdMiles);
    }

    function renderVehiclePicker() {
  const $sel = $("#active-vehicle");
  $sel.empty();
  data.vehicles.forEach(v => {
    $sel.append($("<option>").val(v.id).text(v.name));
  });
  if (!activeVehicleId && data.vehicles.length) {
    activeVehicleId = data.vehicles[0].id;
  }
  if (activeVehicleId) $sel.val(activeVehicleId);

  const v = data.vehicles.find(v => v.id === activeVehicleId);
  const unit = getUnitShort();
  
  // Update the overview label (bottom section)
  if (v) {
    const line1 =
      v.name +
      (v.currentOdo != null ? ` • Current: ${v.currentOdo.toLocaleString()} ${unit}` : "");
    const metaParts = [];
    if (v.vin)   metaParts.push(`VIN: ${v.vin}`);
    if (v.plate) metaParts.push(`Plate: ${v.plate}`);
    let html = `<div>${line1}</div>`;
    if (metaParts.length) {
      html += `<div style="font-size:0.75rem; color: var(--text-muted);">${metaParts.join(" • ")}</div>`;
    }
    $("#overview-vehicle-label").html(html);
  } else {
    $("#overview-vehicle-label").text("No vehicle selected");
  }
  
  // Update the inline odometer editor in vehicle picker
  renderVehiclePickerOdometer();
}

function renderVehiclePickerOdometer() {
  const $container = $("#vehicle-picker-odo");
  $container.empty();
  
  if (!activeVehicleId) {
    return;
  }
  
  const v = data.vehicles.find(v => v.id === activeVehicleId);
  if (!v) return;
  
  const unit = getUnitShort();
  const currentOdo = v.currentOdo != null ? v.currentOdo : "";
  
  // Create the inline editor
  const $odoRow = $("<div>").addClass("vehicle-picker-odo-row");
  
  const $label = $("<span>")
    .addClass("vehicle-picker-odo-label")
    .text("Current:");
  
  const $input = $("<input>")
    .attr({
      type: "number",
      min: "0",
      step: "1",
      placeholder: "0"
    })
    .addClass("vehicle-picker-odo-input")
    .val(currentOdo)
    .attr("id", "quick-odo-input");
  
  const $unit = $("<span>")
    .addClass("vehicle-picker-odo-unit")
    .text(unit);
  
  const $updateBtn = $("<button>")
    .addClass("btn-primary btn-small vehicle-picker-odo-btn")
    .attr("type", "button")
    .text("Update")
    .on("click", function() {
      updateVehicleOdometerQuick();
    });
  
  $odoRow.append($label, $input, $unit, $updateBtn);
  $container.append($odoRow);
  
  // Allow Enter key to update
  $input.on("keypress", function(e) {
    if (e.which === 13) { // Enter key
      e.preventDefault();
      updateVehicleOdometerQuick();
    }
  });
}

// New function to update the odometer quickly
function updateVehicleOdometerQuick() {
  if (!activeVehicleId) return;
  
  const v = data.vehicles.find(v => v.id === activeVehicleId);
  if (!v) return;
  
  const newOdo = $("#quick-odo-input").val();
  const odoValue = newOdo !== "" ? Number(newOdo) : null;
  
  if (odoValue !== null && odoValue < 0) {
    alert("Odometer cannot be negative.");
    return;
  }
  
  // Check if value actually changed
  if (v.currentOdo === odoValue) {
    showToast("No change in odometer");
    return;
  }
  
  // Update the vehicle's current odometer
  v.currentOdo = odoValue;
  
  saveData();
  
  // Refresh relevant sections
  renderVehiclePicker(); // This will update both the picker and overview
  renderDashboardRemindersSnippet(); // Reminders depend on current odo
  renderRemindersPage(); // If on reminders page
  
  showToast(`Odometer updated to ${odoValue !== null ? odoValue.toLocaleString() : "—"}`);
}

    function renderServiceChecklist($container, selectedList) {
      const selectedSet = new Set(selectedList || []);
      $container.empty();

      const types = data.serviceTypes || [];
      if (!types.length) {
        $container.append(
          $("<span>").addClass("text-muted").css("font-size","0.75rem")
            .text("No service types configured. Add some in Settings.")
        );
        return;
      }

      types.forEach(st => {
        const name = st.name || "";
        const id = "svc_" + name.replace(/\s+/g,"-").replace(/[^a-zA-Z0-9\-]/g,"").toLowerCase();
        const $label = $("<label>").addClass("service-chip").attr("for", id);
        const $chk = $("<input>").attr({type:"checkbox", id:id, value:name});
        if (selectedSet.has(name)) $chk.prop("checked", true);
        $label.append($chk, $("<span>").text(name));
        $container.append($label);
      });
    }
    
    /**
 * Creates a filterable service checklist with search functionality
 * @param {jQuery} $container - Container for the checklist
 * @param {Array} selectedList - Array of selected service names
 * @param {string} filterId - Unique ID for this filter instance
 */
function renderFilterableServiceChecklist($container, selectedList, filterId) {
  const selectedSet = new Set(selectedList || []);
  $container.empty();

  const types = data.serviceTypes || [];
  
  // Create filter container
  const $filterContainer = $("<div>").addClass("service-filter-container");
  const $inputWrapper = $("<div>").addClass("service-filter-input-wrapper");
  
  const $filterIcon = $("<span>").addClass("service-filter-icon").text("🔍");
  const $filterInput = $("<input>")
    .attr({
      type: "text",
      placeholder: "Filter services...",
      id: filterId + "-filter"
    })
    .addClass("service-filter-input");
  
  const $clearBtn = $("<button>")
    .attr("type", "button")
    .addClass("service-filter-clear")
    .text("×")
    .attr("title", "Clear filter");
  
  $inputWrapper.append($filterIcon, $filterInput, $clearBtn);
  
  const $hint = $("<div>").addClass("service-filter-hint").text("Press Enter to check first match");
  
  $filterContainer.append($inputWrapper, $hint);
  $container.append($filterContainer);

  // Create scrollable checklist area
  const $scrollArea = $("<div>").addClass("services-scroll");
  const $checklist = $("<div>").addClass("service-checklist").attr("id", filterId + "-checklist");
  
  if (!types.length) {
    $checklist.append(
      $("<span>").addClass("text-muted").css("font-size", "0.75rem")
        .text("No service types configured. Add some in Settings.")
    );
    $scrollArea.append($checklist);
    $container.append($scrollArea);
    return;
  }

  // Add service chips
  types.forEach((st, index) => {
    const name = st.name || "";
    const id = filterId + "_svc_" + index + "_" + name.replace(/\s+/g, "-").replace(/[^a-zA-Z0-9\-]/g, "").toLowerCase();
    const $label = $("<label>").addClass("service-chip").attr("for", id).attr("data-service-name", name.toLowerCase());
    const $chk = $("<input>").attr({ type: "checkbox", id: id, value: name });
    if (selectedSet.has(name)) $chk.prop("checked", true);
    $label.append($chk, $("<span>").text(name));
    $checklist.append($label);
  });

  // No matches message (hidden by default)
  const $noMatches = $("<div>").addClass("service-no-matches").text("No services match your filter").hide();
  $checklist.append($noMatches);

  $scrollArea.append($checklist);
  $container.append($scrollArea);

  // Filter functionality
  function applyFilter() {
    const query = $filterInput.val().trim().toLowerCase();
    const $chips = $checklist.find(".service-chip");
    let visibleCount = 0;
    let firstMatch = null;

    // Show/hide clear button
    if (query) {
      $clearBtn.addClass("visible");
    } else {
      $clearBtn.removeClass("visible");
    }

    $chips.each(function() {
      const $chip = $(this);
      const serviceName = $chip.attr("data-service-name") || "";
      const isChecked = $chip.find("input").is(":checked");
      const matches = !query || serviceName.includes(query);

      // Always show checked items, or items that match the filter
      if (isChecked || matches) {
        $chip.removeClass("filter-hidden");
        visibleCount++;
        
        // Track first matching (unchecked) item for Enter key
        if (matches && !isChecked && !firstMatch && query) {
          firstMatch = $chip;
          $chip.addClass("filter-match");
        } else {
          $chip.removeClass("filter-match");
        }
      } else {
        $chip.addClass("filter-hidden").removeClass("filter-match");
      }
    });

    // Show/hide no matches message
    if (visibleCount === 0) {
      $noMatches.show();
    } else {
      $noMatches.hide();
    }

    // Store first match for Enter key
    $filterInput.data("firstMatch", firstMatch);
  }

  // Event handlers
  $filterInput.on("input", function() {
    applyFilter();
  });

  $filterInput.on("keydown", function(e) {
    if (e.key === "Enter") {
      e.preventDefault();
      const $firstMatch = $(this).data("firstMatch");
      if ($firstMatch) {
        const $checkbox = $firstMatch.find("input");
        $checkbox.prop("checked", true).trigger("change");
        
        // Clear filter and re-apply
        $filterInput.val("");
        applyFilter();
        
        // Focus back on filter for quick multi-select
        $filterInput.focus();
      }
    } else if (e.key === "Escape") {
      $filterInput.val("");
      applyFilter();
    }
  });

  $clearBtn.on("click", function() {
    $filterInput.val("");
    applyFilter();
    $filterInput.focus();
  });

  // Re-apply filter when checkboxes change (to update visibility)
  $checklist.on("change", "input[type='checkbox']", function() {
    applyFilter();
  });
}

    function getServicesFromChecklist($container, otherValue) {
  // Find all checked checkboxes within the container (handles nested structures)
  const checked = $container
    .find("input[type='checkbox']:checked")
    .map(function() { return $(this).val(); })
    .get();

  const services = [...checked];

  const otherText = (otherValue || "").trim();
  if (otherText) {
    otherText.split(/[;,]/).forEach(part => {
      const p = part.trim();
      if (p) services.push(p);
    });
  }
  return services;
}

    function initDatePickers($scope) {
      const $ctx = $scope || $(document);
      $ctx
        .find("#entry-date, #entry-next-date, .entry-edit-date, .entry-edit-next-date, " +
              ".rem-edit-base-date, .rem-edit-next-date, #rem-new-base-date")
        .datepicker({
          dateFormat: "yy-mm-dd",
          changeMonth: true,
          changeYear: true,
          yearRange: "c-20:c+10"
        });
    }

    function renderDashboard() {
      renderVehiclePicker();
      renderNewEntryFormDefaults();
      renderDashboardHistory();
      renderDashboardRemindersSnippet();
      initDatePickers($(document));
      updateSafetyStatus();
        // Restore the "keep form open" preference checkbox state
        const keepOpen = getKeepFormOpenPreference();
        $("#keep-form-open-pref").prop("checked", keepOpen);
    }

    function renderNewEntryFormDefaults(editEntry) {
  const today = getTodayIsoInSettingsTz();
  if (!editEntry) {
    $("#entry-id").val("");
    $("#entry-submit-label").text("Save entry");
    $("#entry-date").val(today);
    $("#entry-odo").val("");
    $("#entry-services-other").val("");
    $("#entry-cost").val("");
    $("#entry-next-date").val("");
    $("#entry-next-odo").val("");
    $("#entry-notes").val("");
    $("#entry-files").val("");
    
    // Use filterable checklist - target the parent container that holds the scroll area
    const $checklistContainer = $("#service-checklist-container");
$checklistContainer.empty();
renderFilterableServiceChecklist($checklistContainer, [], "entry-form");
  } else {
    $("#entry-id").val(editEntry.id);
    $("#entry-submit-label").text("Update entry");
    $("#entry-date").val(editEntry.date || today);
    $("#entry-odo").val(editEntry.odo != null ? editEntry.odo : "");
    $("#entry-cost").val(editEntry.cost != null ? editEntry.cost : "");
    $("#entry-next-date").val(editEntry.nextDate || "");
    $("#entry-next-odo").val(editEntry.nextOdo != null ? editEntry.nextOdo : "");
    $("#entry-notes").val(editEntry.notes || "");
    $("#entry-files").val("");

    const services = editEntry.services || [];
    const stNames = new Set((data.serviceTypes || []).map(st => st.name));
    const known = services.filter(s => stNames.has(s));
    const other = services.filter(s => !stNames.has(s));
    
    // Use filterable checklist
    $checklistContainer.empty();
renderFilterableServiceChecklist($checklistContainer, known, "entry-form");
    
    $("#entry-services-other").val(other.join("; "));
  }
}

    // Replace the renderDashboardHistory function in app.js with this updated version:

function renderDashboardHistory() {
  const list = data.entries.filter(e => e.vehicleId === activeVehicleId);
  const sorted = list
    .slice()
    .sort((a,b) => (b.date || "").localeCompare(a.date || "") || (b.createdAt || "").localeCompare(a.createdAt || ""));

  const $list = $("#entry-list");
  $list.empty();

  const vehicle = data.vehicles.find(v => v.id === activeVehicleId) || null;
  const unit = getUnitShort();
  const currentOdo = vehicle && vehicle.currentOdo != null ? vehicle.currentOdo : null;

  if (!activeVehicleId || !data.vehicles.length) {
    $list.append(
      $("<div>").addClass("entry-empty")
        .text("Add a vehicle in Settings to start logging maintenance.")
    );
    $("#history-total").text(0);
    return;
  }

  if (!sorted.length) {
    $list.append(
      $("<div>").addClass("entry-empty")
        .text("No entries yet.")
    );
    $("#history-total").text(0);
    return;
  }

  $("#history-total").text(sorted.length);

  // Pagination logic
  const perPage = (data && typeof data.dashboardHistoryPerPage === "number" && data.dashboardHistoryPerPage > 0)
    ? data.dashboardHistoryPerPage
    : 10;
  
  const totalPages = Math.ceil(sorted.length / perPage);
  
  // Ensure current page is valid
  if (dashboardHistoryPage < 1) dashboardHistoryPage = 1;
  if (dashboardHistoryPage > totalPages) dashboardHistoryPage = totalPages || 1;
  
  const startIdx = (dashboardHistoryPage - 1) * perPage;
  const endIdx = startIdx + perPage;
  const pageEntries = sorted.slice(startIdx, endIdx);

  // Render entries for current page
  pageEntries.forEach(entry => {
    const services = entry.services || [];
    const mainService = services[0] || "Service";
    const otherServices = services.slice(1);

    const $card = $("<div>").addClass("entry-card").attr("data-id", entry.id);

    // Header (always visible)
    const $header = $("<div>").addClass("entry-header");
    const $main = $("<div>").addClass("entry-main");
    const $titleRow = $("<div>").addClass("entry-title-row");
    $titleRow.append(
      $("<span>").addClass("entry-date").text(formatDateNice(entry.date) || "No date"),
      $("<span>").addClass("entry-mileage").text(
        entry.odo != null ? `• ${entry.odo.toLocaleString()} ${unit}` : ""
      )
    );

    $header.append(
      $main.append(
        $titleRow,
        $("<div>").addClass("service-badges").append(
          $("<span>").addClass("service-badge main").text(mainService),
          ...otherServices.map(s =>
            $("<span>").addClass("service-badge").text(s)
          )
        )
      ),
      $("<div>").addClass("entry-toggle").text("Tap to expand ▼")
    );

    // Body container
    const $body = $("<div>").addClass("entry-body");
    
    // ========== READ-ONLY VIEW ==========
    const $viewMode = $("<div>").addClass("entry-view-mode");
    const $viewInner = $("<div>").addClass("entry-body-inner");

    const tagline = [];
    if (entry.nextDate) tagline.push("Next date: " + formatDateNice(entry.nextDate));
    if (entry.nextOdo != null) tagline.push(`Next mileage: ${entry.nextOdo.toLocaleString()} ${unit}`);
    if (!tagline.length) tagline.push("No reminder set for this entry.");
    
    $viewInner.append(
      $("<div>").addClass("entry-tagline").text(tagline.join(" • "))
    );

    // Display information in a grid
    const $viewGrid = $("<div>").addClass("entry-view-grid");
    
    // Service date
    $viewGrid.append(
      $("<div>").addClass("entry-view-field").append(
        $("<label>").text("Service date"),
        $("<div>").addClass("entry-view-value").text(formatDateNice(entry.date) || "—")
      )
    );

    // Odometer
    $viewGrid.append(
      $("<div>").addClass("entry-view-field").append(
        $("<label>").text(`Odometer (${unit})`),
        $("<div>").addClass("entry-view-value").text(
          entry.odo != null ? entry.odo.toLocaleString() + " " + unit : "—"
        )
      )
    );

    // Services
    $viewGrid.append(
      $("<div>").addClass("entry-view-field").css("grid-column", "1 / -1").append(
        $("<label>").text("Services performed"),
        $("<div>").addClass("entry-view-value").text(
          services.length ? services.join(", ") : "—"
        )
      )
    );

    // Cost
    if (entry.cost != null) {
      $viewGrid.append(
        $("<div>").addClass("entry-view-field").append(
          $("<label>").text("Cost"),
          $("<div>").addClass("entry-view-value").text("$" + entry.cost.toFixed(2))
        )
      );
    }

    // Next due date
    if (entry.nextDate) {
      $viewGrid.append(
        $("<div>").addClass("entry-view-field").append(
          $("<label>").text("Next due date"),
          $("<div>").addClass("entry-view-value").text(formatDateNice(entry.nextDate))
        )
      );
    }

    // Next due mileage
    if (entry.nextOdo != null) {
      $viewGrid.append(
        $("<div>").addClass("entry-view-field").append(
          $("<label>").text(`Next due mileage (${unit})`),
          $("<div>").addClass("entry-view-value").text(entry.nextOdo.toLocaleString() + " " + unit)
        )
      );
    }

    $viewInner.append($viewGrid);

    // Notes
    if (entry.notes && entry.notes.trim()) {
      $viewInner.append(
        $("<div>").addClass("entry-view-field").css("margin-top", "8px").append(
          $("<label>").text("Notes"),
          $("<div>").addClass("entry-view-value").text(entry.notes)
        )
      );
    }

    // Attachments
    const attachments = entry.attachments || [];
    if (attachments.length) {
      const $attSection = $("<div>").addClass("entry-view-field").css("margin-top", "8px").append(
        $("<label>").text(`Attachments (${attachments.length})`)
      );
      const $alist = $("<div>").addClass("attachments-list");
      attachments.forEach(att => {
        const $item = $("<div>").addClass("attachment-item");
        const $meta = $("<div>").addClass("attachment-meta")
          .append(
            $("<div>").text(att.name || "Attachment"),
            att.size != null
              ? $("<div>").addClass("text-muted").css("font-size","0.7rem")
                  .text(formatBytes(att.size))
              : null
          );
        const $actions = $("<div>").addClass("button-row").css({marginTop:0});
        $actions.append(
          $("<button>")
            .addClass("btn-ghost btn-small entry-attach-download")
            .attr("type","button")
            .text("Download")
            .data("att-id", att.id)
        );
        $item.append($meta, $actions);
        $alist.append($item);
      });
      $attSection.append($alist);
      $viewInner.append($attSection);
    }

    // View mode buttons
    const $viewButtons = $("<div>").addClass("entry-body-buttons").append(
      $("<button>")
        .addClass("btn-primary btn-small entry-btn-edit")
        .attr("type","button")
        .text("Edit entry"),
      $("<button>")
        .addClass("btn-danger btn-small entry-btn-delete")
        .attr("type","button")
        .text("Delete entry")
    );

    $viewInner.append($viewButtons);
    $viewMode.append($viewInner);

    // ========== EDIT MODE ==========
    const $editMode = $("<div>").addClass("entry-edit-mode").hide();
    const $editInner = $("<div>").addClass("entry-body-inner");

    const today = getTodayIsoInSettingsTz();
    const $fieldsGrid = $("<div>").addClass("entry-body-fields");
    $fieldsGrid.append(
      $("<div>").addClass("field").append(
        $("<label>").text("Service date"),
        $("<input>")
          .attr({type:"text", placeholder:"YYYY-MM-DD", autocomplete:"off"})
          .addClass("entry-edit-date")
          .val(entry.date || today)
      ),
      $("<div>").addClass("field").append(
        $("<label>").html(`Odometer (<span class="unit-label">${unit}</span>)`),
        $("<input>").attr({type:"number",min:"0",step:"1"})
          .addClass("entry-edit-odo")
          .val(entry.odo != null ? entry.odo : "")
      ),
      $("<div>").addClass("field").append(
        $("<label>").text("Services"),
        (function(){
  const $wrapper = $("<div>").addClass("entry-edit-services-wrapper");
  const stNames = new Set((data.serviceTypes || []).map(st => st.name));
  const services = entry.services || [];
  const known = services.filter(s => stNames.has(s));
  
  // Create unique filter ID for this entry
  const filterId = "edit-" + entry.id.replace(/[^a-zA-Z0-9]/g, "");
  renderFilterableServiceChecklist($wrapper, known, filterId);
  
  return $wrapper;
})(),
        $("<input>")
          .attr({type:"text", placeholder:"Other/custom (comma or ; separated)"})
          .addClass("entry-edit-services-other")
          .val((entry.services || []).filter(s => {
            const stNames = new Set((data.serviceTypes || []).map(st => st.name));
            return !stNames.has(s);
          }).join("; "))
      ),
      $("<div>").addClass("field").append(
        $("<label>").text("Cost"),
        $("<input>").attr({type:"number",min:"0",step:"0.01"})
          .addClass("entry-edit-cost")
          .val(entry.cost != null ? entry.cost : "")
      ),
      $("<div>").addClass("field").append(
        $("<label>").text("Next due date"),
        $("<input>")
          .attr({type:"text", placeholder:"YYYY-MM-DD", autocomplete:"off"})
          .addClass("entry-edit-next-date")
          .val(entry.nextDate || "")
      ),
      $("<div>").addClass("field").append(
        $("<label>").html(`Next due mileage (<span class="unit-label">${unit}</span>)`),
        $("<input>").attr({type:"number",min:"0",step:"1"})
          .addClass("entry-edit-next-odo")
          .val(entry.nextOdo != null ? entry.nextOdo : "")
      )
    );

    const $notesField = $("<div>").addClass("entry-body-notes field").append(
      $("<label>").text("Notes"),
      $("<textarea>")
        .addClass("entry-edit-notes")
        .attr("rows",2)
        .val(entry.notes || "")
    );

    const { maxCount } = getAttachmentLimits();
    const used = attachments.length;
    const labelText = maxCount > 0
      ? `Attachments (${used} / ${maxCount} used)`
      : `Attachments (${used} attached)`;

    const $attSection = $("<div>")
      .addClass("entry-body-attachments field")
      .css("margin-top","4px")
      .append($("<label>").text(labelText));

    if (attachments.length) {
      const $alist = $("<div>").addClass("attachments-list");
      attachments.forEach(att => {
        const $item = $("<div>").addClass("attachment-item");
        const $meta = $("<div>").addClass("attachment-meta")
          .append(
            $("<div>").text(att.name || "Attachment"),
            att.size != null
              ? $("<div>").addClass("text-muted").css("font-size","0.7rem")
                  .text(formatBytes(att.size))
              : null
          );
        const $actions = $("<div>").addClass("button-row").css({marginTop:0});
        $actions.append(
          $("<button>")
            .addClass("btn-ghost btn-small entry-attach-download")
            .attr("type","button")
            .text("Download")
            .data("att-id", att.id),
          $("<button>")
            .addClass("btn-danger btn-small entry-attach-delete")
            .attr("type","button")
            .text("Delete")
            .data("att-id", att.id)
        );
        $item.append($meta, $actions);
        $alist.append($item);
      });
      $attSection.append($alist);
    } else {
      $attSection.append(
        $("<div>").addClass("text-muted")
          .css("font-size","0.75rem")
          .text("No attachments.")
      );
    }

    const $addAttachField = $("<div>").addClass("field").css("margin-top","4px")
      .append(
        $("<input>").attr({type:"file", multiple:true}).addClass("entry-attach-files"),
        $("<div>").addClass("text-muted").css("font-size","0.7rem")
          .text("Add files to store with this entry.")
      );
    $attSection.append($addAttachField);

    const $editButtons = $("<div>").addClass("entry-body-buttons").append(
      $("<button>")
        .addClass("btn-ghost btn-small entry-btn-cancel")
        .attr("type","button")
        .text("Cancel"),
      $("<button>")
        .addClass("btn-primary btn-small entry-btn-save")
        .attr("type","button")
        .text("Save changes")
    );

    $editInner.append($fieldsGrid, $notesField, $attSection, $editButtons);
    $editMode.append($editInner);

    // Append both modes to body
    $body.append($viewMode, $editMode);
    $card.append($header, $body);
    $list.append($card);
  });

  // Add pagination controls if needed
  if (totalPages > 1) {
    const $pager = $("<div>").addClass("dashboard-history-pager");
    
    const $prevBtn = $("<button>")
      .text("← Prev")
      .prop("disabled", dashboardHistoryPage <= 1)
      .on("click", function() {
        if (dashboardHistoryPage > 1) {
          dashboardHistoryPage--;
          renderDashboardHistory();
        }
      });
    
    const $info = $("<span>")
      .addClass("pager-info")
      .text(`Page ${dashboardHistoryPage} of ${totalPages}`);
    
    const $nextBtn = $("<button>")
      .text("Next →")
      .prop("disabled", dashboardHistoryPage >= totalPages)
      .on("click", function() {
        if (dashboardHistoryPage < totalPages) {
          dashboardHistoryPage++;
          renderDashboardHistory();
        }
      });
    
    $pager.append($prevBtn, $info, $nextBtn);
    $list.append($pager);
  }

  initDatePickers($list);
  updateUnitLabels();
}

function getKeepFormOpenPreference() {
  return data.settings && data.settings.keepFormOpen === true;
}

// 2. Add function to set form preference
function setKeepFormOpenPreference(value) {
  if (!data.settings) {
    data.settings = JSON.parse(JSON.stringify(DEFAULT_SETTINGS));
  }
  data.settings.keepFormOpen = value === true;
  saveData();
}

// 3. Add function to toggle entry form visibility
function toggleEntryForm(forceOpen) {
  const $form = $("#dashboard-entry-form");
  const $btn = $("#toggle-entry-form");
  const isOpen = $form.is(":visible");
  
  if (forceOpen === true || !isOpen) {
    // Open the form
    $form.slideDown(300);
    $btn.addClass("form-open");
    $btn.find("span:first").text("×");
    $btn.contents().filter(function() {
      return this.nodeType === 3; // Text node
    }).last().replaceWith(" Hide Form");
  } else {
    // Close the form
    $form.slideUp(300);
    $btn.removeClass("form-open");
    $btn.find("span:first").text("+");
    $btn.contents().filter(function() {
      return this.nodeType === 3;
    }).last().replaceWith(" Add New Service Entry");
  }
}

    function renderDashboardRemindersSnippet() {
  const $list = $("#reminder-snippet-list");
  $list.empty();

  if (!activeVehicleId) {
    $list.append(
      $("<div>").addClass("reminder-snippet-empty")
        .text("Select or add a vehicle to see reminders.")
    );
    $("#rem-snippet-upcoming").text(0);
    $("#rem-snippet-overdue").text(0);
    return;
  }

  const vehicle = data.vehicles.find(v => v.id === activeVehicleId) || null;
  const currentOdo = vehicle && vehicle.currentOdo != null ? vehicle.currentOdo : null;
  const unit = getUnitShort();

  const reminders = data.reminders.filter(r => r.vehicleId === activeVehicleId);
  if (!reminders.length) {
    $list.append(
      $("<div>").addClass("reminder-snippet-empty")
        .text("No reminders yet. Add them on the Reminders page.")
    );
    $("#rem-snippet-upcoming").text(0);
    $("#rem-snippet-overdue").text(0);
    return;
  }

  let upcoming = 0;
  let overdue = 0;

  const enriched = reminders.map(r => {
    const derived = computeReminderDerived(r, currentOdo);
    if (derived.level === "upcoming") upcoming++;
    if (derived.level === "overdue") overdue++;
    return {r, derived};
  });

  // CHANGED: Filter to only show upcoming and overdue reminders (not "ok")
  const filteredReminders = enriched.filter(item => 
    item.derived.level === "upcoming" || item.derived.level === "overdue"
  );

  // Sort: overdue first, then upcoming
  filteredReminders.sort((a,b) => {
    const order = {overdue:0, upcoming:1, ok:2};
    return order[a.derived.level] - order[b.derived.level];
  });

  // CHANGED: Show message if no upcoming/overdue reminders
  if (!filteredReminders.length) {
    $list.append(
      $("<div>").addClass("reminder-snippet-empty")
        .text("✓ All maintenance is up to date! No upcoming or overdue items.")
    );
    $("#rem-snippet-upcoming").text(upcoming);
    $("#rem-snippet-overdue").text(overdue);
    return;
  }

  // CHANGED: Show all filtered reminders (removed .slice(0,6) limit since we're only showing important ones)
  filteredReminders.forEach(item => {
    const {r, derived} = item;
    const serviceName = r.serviceName || r.title || "Reminder";
    const $row = $("<div>").addClass("reminder-snippet-item");
    const $left = $("<div>").css({minWidth:0});
    $left.append(
      $("<div>").addClass("reminder-title").text(serviceName),
      $("<div>").addClass("reminder-meta").text(
        [
          derived.nextOdo != null
            ? `Next: ${derived.nextOdo.toLocaleString()} ${unit}`
            : null,
          derived.nextDate
            ? `Date: ${formatDateNice(derived.nextDate)}`
            : null
        ].filter(Boolean).join(" • ")
      )
    );

    const $status = $("<div>")
      .addClass("reminder-status-pill " + derived.level)
      .append(
        $("<span>").addClass("dot"),
        $("<span>").text(derived.label)
      );

    $row.append($left, $status);
    $list.append($row);
  });

  $("#rem-snippet-upcoming").text(upcoming);
  $("#rem-snippet-overdue").text(overdue);
}

function renderSettingsVehicles() {
  const $list = $("#settings-vehicles");
  $list.empty();
  const unit = getUnitShort();
  
  if (!data.vehicles.length) {
    $list.append(
      $("<div>").addClass("settings-vehicles-empty")
        .html("🚗 No vehicles yet.<br>Add your first vehicle below to get started.")
    );
    return;
  }
  
  // Create accordion-style cards container
  const $cardsContainer = $("<div>").addClass("settings-vehicles-list");
  
  data.vehicles.forEach(v => {
    const $card = $("<div>")
      .addClass("settings-vehicle-card")
      .attr("data-id", v.id);
    
    // Header (always visible)
    const $header = $("<div>").addClass("settings-vehicle-header");
    
    // Summary info
    const $summary = $("<div>").addClass("settings-vehicle-summary");
    $summary.append(
      $("<div>").addClass("settings-vehicle-name-display").text(v.name || "Unnamed Vehicle")
    );
    
    // Meta info (VIN and Plate if available)
    const $meta = $("<div>").addClass("settings-vehicle-meta");
    if (v.vin) {
      $meta.append(
        $("<span>").addClass("settings-vehicle-meta-item").html(
          '<span class="meta-label">VIN:</span> ' + escapeHtml(v.vin)
        )
      );
    }
    if (v.plate) {
      $meta.append(
        $("<span>").addClass("settings-vehicle-meta-item").html(
          '<span class="meta-label">Plate:</span> ' + escapeHtml(v.plate)
        )
      );
    }
    if (!v.vin && !v.plate) {
      $meta.append(
        $("<span>").addClass("settings-vehicle-meta-item").css("opacity", "0.5")
          .text("No VIN or plate set")
      );
    }
    $summary.append($meta);
    
    // Toggle indicator
    const $toggle = $("<div>").addClass("settings-vehicle-toggle").html("▼");
    
    $header.append($summary, $toggle);
    
    // Body (edit form - hidden by default)
    const $body = $("<div>").addClass("settings-vehicle-body");
    
    const $fields = $("<div>").addClass("settings-vehicle-fields");
    
    // Vehicle name field
    $fields.append(
      $("<div>").addClass("field full-width").append(
        $("<label>").text("Vehicle Name"),
        $("<input>")
          .attr({ type: "text", placeholder: "e.g., 2024 Toyota Camry" })
          .addClass("settings-vehicle-name")
          .val(v.name || "")
      )
    );
    
    // Current mileage field
    $fields.append(
      $("<div>").addClass("field").append(
        $("<label>").html(`Current Mileage (<span class="unit-label">${unit}</span>)`),
        $("<input>")
          .attr({ type: "number", min: "0", step: "1", placeholder: "0" })
          .addClass("settings-vehicle-odo")
          .val(v.currentOdo != null ? v.currentOdo : "")
      )
    );
    
    // VIN field
    $fields.append(
      $("<div>").addClass("field").append(
        $("<label>").text("VIN (optional)"),
        $("<input>")
          .attr({ type: "text", placeholder: "17-character VIN" })
          .addClass("settings-vehicle-vin")
          .val(v.vin || "")
      )
    );
    
    // Plate field
    $fields.append(
      $("<div>").addClass("field").append(
        $("<label>").text("Plate Number (optional)"),
        $("<input>")
          .attr({ type: "text", placeholder: "License plate" })
          .addClass("settings-vehicle-plate")
          .val(v.plate || "")
      )
    );
    
    $body.append($fields);
    
    // Action buttons
    const $actions = $("<div>").addClass("settings-vehicle-actions");
    $actions.append(
      $("<button>")
        .addClass("btn-danger btn-small settings-vehicle-delete")
        .attr("type", "button")
        .text("Delete Vehicle"),
      $("<button>")
        .addClass("btn-primary btn-small settings-vehicle-save")
        .attr("type", "button")
        .text("Save Changes")
    );
    
    $body.append($actions);
    
    $card.append($header, $body);
    $cardsContainer.append($card);
  });
  
  $list.append($cardsContainer);
}

// Helper function to escape HTML
function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

    function renderSettingsServices() {
      const $list = $("#settings-services");
      $list.empty();
      if (!data.serviceTypes.length) {
        $list.append(
          $("<div>").addClass("entry-empty")
            .text("No service types yet. Add one below.")
        );
        return;
      }
      data.serviceTypes.forEach((st, index) => {
        const name = st.name || "";

        const $row = $("<div>").addClass("settings-row").attr("data-index", index);
        const $main = $("<div>").addClass("settings-service-main");

        const $nameField = $("<div>").addClass("field").append(
          $("<label>").text("Service name"),
          $("<input>")
            .attr("type","text")
            .val(name)
            .addClass("settings-service-name")
        );

        $main.append($nameField);

        const $actions = $("<div>").addClass("settings-row-actions").append(
          $("<button>").addClass("btn-ghost btn-small settings-service-save")
            .attr("type","button").text("Save"),
          $("<button>").addClass("btn-danger btn-small settings-service-delete")
            .attr("type","button").text("Delete")
        );

        $row.append($main, $actions);
        $list.append($row);
      });
    }

    function renderSettingsIntervals() {
      const $sel = $("#settings-intervals-vehicle");
      const $list = $("#settings-intervals-list");
      $sel.empty();
      $list.empty();

      if (!data.vehicles.length) {
        $list.append(
          $("<div>").addClass("entry-empty")
            .text("No vehicles yet. Add one in the Vehicles tab.")
        );
        return;
      }

      data.vehicles.forEach(v => {
        $sel.append($("<option>").val(v.id).text(v.name));
      });

      let vid = activeVehicleId || data.vehicles[0].id;
      $sel.val(vid);
      renderSettingsIntervalsForVehicle(vid);
    }

    function renderSettingsIntervalsForVehicle(vehicleId) {
      const $list = $("#settings-intervals-list");
      $list.empty();
      const unit = getUnitShort();

      const services = data.serviceTypes || [];
      if (!services.length) {
        $list.append(
          $("<div>").addClass("entry-empty")
            .text("No service types yet. Add some in the Service types tab.")
        );
        return;
      }

      const vMap = (data.vehicleIntervals && data.vehicleIntervals[vehicleId]) || {};

      services.forEach(st => {
        const sName = st.name || "";
        const override = vMap[sName] || {};
        const intervalMiles = override.intervalMiles != null ? override.intervalMiles : "";
        const intervalMonths = override.intervalMonths != null ? override.intervalMonths : "";

        const defaults = getIntervalForService(null, sName);
        const placeholderMiles = defaults.intervalMiles != null ? defaults.intervalMiles : "";
        const placeholderMonths = defaults.intervalMonths != null ? defaults.intervalMonths : "";

        const $row = $("<div>")
          .addClass("settings-row settings-intervals-row")
          .attr("data-vehicle-id", vehicleId)
          .attr("data-service", sName);

        const $main = $("<div>").addClass("settings-intervals-main");

        const $serviceField = $("<div>").addClass("field").append(
          $("<label>").text("Service type"),
          $("<div>").css({fontSize:"0.8rem"}).text(sName || "(Unnamed)")
        );

        const $milesField = $("<div>").addClass("field").append(
          $("<label>").html(`Interval (<span class="unit-label">${unit}</span>, optional)`),
          $("<input>")
            .attr({type:"number",min:"0",step:"100"})
            .addClass("settings-intervals-miles")
            .val(intervalMiles)
            .prop("placeholder", placeholderMiles ? `${placeholderMiles} ${unit}` : "")
        );

        const $monthsField = $("<div>").addClass("field").append(
          $("<label>").text("Interval (months, optional)"),
          $("<input>")
            .attr({type:"number",min:"0",step:"1"})
            .addClass("settings-intervals-months")
            .val(intervalMonths)
            .prop("placeholder", placeholderMonths ? `${placeholderMonths}` : "")
        );

        $main.append($serviceField, $milesField, $monthsField);

        const $actions = $("<div>").addClass("settings-row-actions").append(
          $("<button>").addClass("btn-ghost btn-small settings-intervals-save")
            .attr("type","button").text("Save"),
          $("<button>").addClass("btn-danger btn-small settings-intervals-clear")
            .attr("type","button").text("Clear")
        );

        $row.append($main, $actions);
        $list.append($row);
      });

      updateUnitLabels();
    }

    function renderSettings() {
      renderSettingsGeneral();
      renderSettingsVehicles();
      renderSettingsServices();
      renderSettingsIntervals();
      updateUnitLabels();
    }

    function renderReminderServiceSelect() {
      const $sel = $("#rem-new-service");
      $sel.empty();
      $sel.append($("<option>").val("").text("Select service template (optional)"));
      (data.serviceTypes || []).forEach(st => {
        $sel.append($("<option>").val(st.name).text(st.name));
      });
    }

    function renderRemindersPage() {
      const $list = $("#reminders-list");
      $list.empty();
      const unit = getUnitShort();

      if (!activeVehicleId) {
        $list.append(
          $("<div>").addClass("entry-empty")
            .text("Select or add a vehicle to configure reminders.")
        );
        $("#rem-total").text(0);
        $("#rem-upcoming").text(0);
        $("#rem-overdue").text(0);
        return;
      }

      const vehicle = data.vehicles.find(v => v.id === activeVehicleId) || null;
      const currentOdo = vehicle && vehicle.currentOdo != null ? vehicle.currentOdo : null;

      const reminders = data.reminders.filter(r => r.vehicleId === activeVehicleId);
      if (!reminders.length) {
        $list.append(
          $("<div>").addClass("entry-empty")
            .text("No reminders yet. Use the form below to add one.")
        );
        $("#rem-total").text(0);
        $("#rem-upcoming").text(0);
        $("#rem-overdue").text(0);
        return;
      }

      $("#rem-total").text(reminders.length);
      let upcoming = 0;
      let overdue = 0;

      reminders
        .slice()
        .sort((a,b) => (a.serviceName || "").localeCompare(b.serviceName || ""))
        .forEach(rem => {
          const derived = computeReminderDerived(rem, currentOdo);
          if (derived.level === "upcoming") upcoming++;
          if (derived.level === "overdue") overdue++;

          const serviceName = rem.serviceName || rem.title || "Reminder";
          const $card = $("<div>").addClass("reminder-card").attr("data-id", rem.id);

          const $header = $("<div>").addClass("reminder-header");
          const $main = $("<div>").addClass("reminder-main");

          $main.append(
            $("<div>").addClass("reminder-title").text(serviceName),
            $("<div>").addClass("reminder-meta").text(
              [
                derived.nextOdo != null
                  ? `Next: ${derived.nextOdo.toLocaleString()} ${unit}`
                  : null,
                derived.nextDate
                  ? `Date: ${formatDateNice(derived.nextDate)}`
                  : null
              ].filter(Boolean).join(" • ") || "No next mileage/date set"
            )
          );

          const $status = $("<div>")
            .addClass("reminder-status-pill " + derived.level)
            .append(
              $("<span>").addClass("dot"),
              $("<span>").text(derived.label)
            );

          $header.append($main, $status);

          const $body = $("<div>").addClass("reminder-body");
          const $inner = $("<div>").addClass("reminder-body-inner");

          const $fieldsGrid = $("<div>").addClass("reminder-body-fields");
          $fieldsGrid.append(
            $("<div>").addClass("field").append(
              $("<label>").text("Service name"),
              $("<input>")
                .attr("type","text")
                .addClass("rem-edit-service")
                .val(rem.serviceName || "")
            ),
            $("<div>").addClass("field").append(
              $("<label>").html(`Interval (<span class="unit-label">${unit}</span>, optional)`),
              $("<input>")
                .attr({type:"number",min:"0",step:"100"})
                .addClass("rem-edit-interval-miles")
                .val(rem.intervalMiles != null ? rem.intervalMiles : "")
            ),
            $("<div>").addClass("field").append(
              $("<label>").text("Interval (months, optional)"),
              $("<input>")
                .attr({type:"number",min:"0",step:"1"})
                .addClass("rem-edit-interval-months")
                .val(rem.intervalMonths != null ? rem.intervalMonths : "")
            ),
            $("<div>").addClass("field").append(
              $("<label>").html(`Last service mileage (<span class="unit-label">${unit}</span>, optional)`),
              $("<input>")
                .attr({type:"number",min:"0",step:"1"})
                .addClass("rem-edit-base-odo")
                .val(rem.baseOdo != null ? rem.baseOdo : "")
            ),
            $("<div>").addClass("field").append(
              $("<label>").text("Last service date (optional)"),
              $("<input>")
                .attr({type:"text",placeholder:"YYYY-MM-DD",autocomplete:"off"})
                .addClass("rem-edit-base-date")
                .val(rem.baseDate || "")
            ),
            $("<div>").addClass("field").append(
              $("<label>").html(`Next due mileage (<span class="unit-label">${unit}</span>, optional)`),
              $("<input>")
                .attr({type:"number",min:"0",step:"1"})
                .addClass("rem-edit-next-odo")
                .val(rem.nextOdo != null ? rem.nextOdo : "")
            ),
            $("<div>").addClass("field").append(
              $("<label>").text("Next due date (optional)"),
              $("<input>")
                .attr({type:"text",placeholder:"YYYY-MM-DD",autocomplete:"off"})
                .addClass("rem-edit-next-date")
                .val(rem.nextDate || "")
            )
          );

          const $notesField = $("<div>").addClass("reminder-body-notes field").append(
            $("<label>").text("Notes"),
            $("<textarea>")
              .addClass("rem-edit-notes")
              .attr("rows",2)
              .val(rem.notes || "")
          );

          const $buttons = $("<div>").addClass("reminder-body-buttons").append(
            $("<button>")
              .addClass("btn-ghost btn-small rem-btn-google")
              .attr("type","button")
              .text("Google reminder (time-based)"),
            $("<button>")
              .addClass("btn-danger btn-small rem-btn-delete")
              .attr("type","button")
              .text("Delete"),
            $("<button>")
              .addClass("btn-secondary btn-small rem-btn-copy")
              .attr("type","button")
              .text("Copy to vehicle"),
            $("<button>")
              .addClass("btn-primary btn-small rem-btn-save")
              .attr("type","button")
              .text("Save changes")
          );

          $inner.append($fieldsGrid, $notesField, $buttons);
          $body.append($inner);
          $card.append($header, $body);
          $list.append($card);
        });

      $("#rem-upcoming").text(upcoming);
      $("#rem-overdue").text(overdue);

      initDatePickers($list);
      updateUnitLabels();
    }

    function autoFillNextOdoFromIntervals() {
      const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
      if (!vehicle) return;

      const $nextOdo = $("#entry-next-odo");
      if ($nextOdo.val()) return;

      const services = getServicesFromChecklist(
  $("#service-checklist-container"),
  $("#entry-services-other").val()
);
      if (!services.length) return;

      const intervals = [];
      services.forEach(name => {
        const iv = getIntervalForService(vehicle.id, name);
        if (iv.intervalMiles && iv.intervalMiles > 0) {
          intervals.push(iv.intervalMiles);
        }
      });

      if (!intervals.length) return;

      const odoVal = $("#entry-odo").val();
      let baseOdo = null;
      if (odoVal !== "") {
        baseOdo = Number(odoVal);
      } else if (vehicle.currentOdo != null) {
        baseOdo = vehicle.currentOdo;
      }

      if (baseOdo == null || isNaN(baseOdo)) return;

      const interval = Math.min.apply(null, intervals);
      const nextOdo = Math.round(baseOdo + interval);
      $nextOdo.val(nextOdo);
    }

function getAttachmentLimits() {

    const maxCount = (data && typeof data.entryMaxAttachments === "number" && data.entryMaxAttachments > 0)

        ?
        data.entryMaxAttachments

        :
        5;

    const maxSizeMB = (data && typeof data.entryMaxAttachmentSizeMB === "number" && data.entryMaxAttachmentSizeMB > 0)

        ?
        data.entryMaxAttachmentSizeMB

        :
        10;

    const maxBytes = maxSizeMB > 0 ? maxSizeMB * 1024 * 1024 : Infinity;

    return {
        maxCount,
        maxSizeMB,
        maxBytes
    };

}



function getAttachmentHelpText() {

    const {
        maxCount,
        maxSizeMB
    } = getAttachmentLimits();

    return `Up to ${maxCount} attachments per entry, PDF/Word/images only, max ${maxSizeMB} MB each.`;

}



const ATTACH_ALLOWED_EXT = ["pdf", "doc", "docx", "jpg", "jpeg", "png", "gif", "webp"];

const ATTACH_ALLOWED_MIME = [

    "application/pdf",

    "application/msword",

    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",

    "image/jpeg",

    "image/jpg",

    "image/png",

    "image/gif",

    "image/webp"

];



function isAttachmentFileAllowed(file) {

    const name = (file && file.name) || "";

    const type = ((file && file.type) || "").toLowerCase();

    const ext = name.includes(".") ? name.split(".").pop().toLowerCase() : "";



    if (type && ATTACH_ALLOWED_MIME.indexOf(type) !== -1) return true;

    if (type && type.startsWith("image/")) return true;

    if (ext && ATTACH_ALLOWED_EXT.indexOf(ext) !== -1) return true;

    return false;

}


async function addOrUpdateEntryFromForm() {
  if (!activeVehicleId) {
    alert("Select a vehicle first.");
    return;
  }

  const id = $("#entry-id").val();
  const isNew = !id;
  const now = new Date().toISOString();
  const date = $("#entry-date").val() || null;
  if (!date) {
    alert("Date is required.");
    return;
  }

  const odo = $("#entry-odo").val();
  const cost = $("#entry-cost").val();
  const nextDate = $("#entry-next-date").val() || null;
  const nextOdo = $("#entry-next-odo").val();

const services = getServicesFromChecklist(
  $("#service-checklist-container"),
  $("#entry-services-other").val()
);
  if (!services.length) {
    if (!confirm("No services checked or entered. Continue?")) return;
  }

  const payload = {
    id: isNew ? ("e_" + Date.now() + "_" + Math.random().toString(36).slice(2)) : id,
    vehicleId: activeVehicleId,
    date: date,
    odo: odo !== "" ? Number(odo) : null,
    services: services,
    notes: $("#entry-notes").val().trim() || "",
    cost: cost !== "" ? Number(cost) : null,
    nextDate: nextDate,
    nextOdo: nextOdo !== "" ? Number(nextOdo) : null,
    updatedAt: now
  };

  if (isNew) {
    payload.createdAt = now;
    payload.attachments = [];
    data.entries.push(payload);
  } else {
    const idx = data.entries.findIndex(e => e.id === id);
    const existing = idx >= 0 ? data.entries[idx] : null;
    payload.createdAt = existing ? (existing.createdAt || now) : now;
    payload.attachments = existing ? (existing.attachments || []) : [];
    if (idx >= 0) {
      data.entries[idx] = payload;
    } else {
      data.entries.push(payload);
    }
  }

  resetRemindersForEntry(payload);
  saveData();

  // Handle file uploads separately
  const fileInput = document.getElementById("entry-files");
  if (fileInput && fileInput.files && fileInput.files.length > 0) {
    await uploadEntryFiles(payload.id, fileInput.files);
  }

  $("#entry-files").val("");
  dashboardHistoryPage = 1;
  
  // Reload data from server to get updated attachments
  loadData();
  renderDashboard();
  renderRemindersPage();
  renderNewEntryFormDefaults();
  
  // Check user preference for keeping form open
  const keepOpen = getKeepFormOpenPreference();
  if (!keepOpen) {
    toggleEntryForm(false);
  }
}

async function uploadEntryFiles(entryId, fileList) {
  const files = Array.from(fileList || []);
  if (!files.length) return;

  const { maxCount, maxSizeMB, maxBytes } = getAttachmentLimits();
  
  // Filter valid files
  const validFiles = [];
  for (const file of files) {
    if (validFiles.length >= maxCount) break;
    
    if (!isAttachmentFileAllowed(file)) continue;
    if (maxBytes && file.size > maxBytes) continue;
    
    validFiles.push(file);
  }

  if (!validFiles.length) {
    showToast("No valid files to upload");
    return;
  }

  // Create FormData
  const formData = new FormData();
  formData.append('entry_id', entryId);
  validFiles.forEach(file => {
    formData.append('files[]', file);
  });

  try {
    const response = await fetch('upload.php', {
      method: 'POST',
      body: formData
    });

    const result = await response.json();
    
    if (result.success) {
      showToast(`${result.count} file(s) uploaded successfully`);
    } else {
      showToast("Upload failed: " + (result.message || "Unknown error"));
    }
    
    if (result.errors && result.errors.length) {
      console.warn("Upload errors:", result.errors);
    }
  } catch (error) {
    console.error("Upload error:", error);
    showToast("Upload failed: " + error.message);
  }
}

    async function saveEntryFromAccordion($card) {
  const id = $card.attr("data-id");
  const entry = data.entries.find(e => e.id === id);
  if (!entry) return;

  const now = new Date().toISOString();
  const date = $card.find(".entry-edit-date").val() || null;
  const odoVal = $card.find(".entry-edit-odo").val();
  const costVal = $card.find(".entry-edit-cost").val();
  const nextDate = $card.find(".entry-edit-next-date").val() || null;
  const nextOdoVal = $card.find(".entry-edit-next-odo").val();
  const notes = $card.find(".entry-edit-notes").val().trim() || "";

const services = getServicesFromChecklist(
  $card.find(".entry-edit-services-wrapper"),
  $card.find(".entry-edit-services-other").val()
);

  entry.date = date;
  entry.odo = odoVal !== "" ? Number(odoVal) : null;
  entry.cost = costVal !== "" ? Number(costVal) : null;
  entry.nextDate = nextDate;
  entry.nextOdo = nextOdoVal !== "" ? Number(nextOdoVal) : null;
  entry.notes = notes;
  entry.services = services;
  entry.updatedAt = now;

  resetRemindersForEntry(entry);
  saveData();

  // Handle new file uploads
  const fileInput = $card.find(".entry-attach-files")[0];
  if (fileInput && fileInput.files && fileInput.files.length > 0) {
    await uploadEntryFiles(entry.id, fileInput.files);
  }

  // Reload data from server
  loadData();
  renderDashboard();
  renderRemindersPage();
}

    function deleteEntryByCard($card) {
  const id = $card.attr("data-id");
  if (!confirm("Delete this entry? This cannot be undone.")) return;
  data.entries = data.entries.filter(e => e.id !== id);
  saveData();
  
  // Keep current page, but adjust if it becomes invalid
  // (will be handled automatically in renderDashboardHistory)
  renderDashboard();
  renderRemindersPage();
}

    function findLastEntryForService(vehicleId, serviceName) {
      const candidates = data.entries.filter(
        e => e.vehicleId === vehicleId && (e.services || []).includes(serviceName)
      );
      if (!candidates.length) return null;
      candidates.sort((a,b) => (b.date || "").localeCompare(a.date || "") || (b.createdAt || "").localeCompare(a.createdAt || ""));
      return candidates[0];
    }

    function addReminderFromForm() {
      if (!activeVehicleId) {
        alert("Select a vehicle first.");
        return;
      }

      const templateName = $("#rem-new-service").val();
      const customName = $("#rem-new-service-custom").val().trim();
      const serviceName = customName || templateName;

      if (!serviceName) {
        alert("Service name is required (select a template or type one).");
        return;
      }

      const intervalMilesVal = $("#rem-new-interval-miles").val();
      const intervalMonthsVal = $("#rem-new-interval-months").val();
      const notes = $("#rem-new-notes").val().trim() || "";

      let intervalMiles = intervalMilesVal !== "" ? Number(intervalMilesVal) : null;
      let intervalMonths = intervalMonthsVal !== "" ? Number(intervalMonthsVal) : null;

      if (templateName && (!intervalMiles && !intervalMonths)) {
        const iv = getIntervalForService(activeVehicleId, templateName);
        if (intervalMiles == null && iv.intervalMiles != null) intervalMiles = iv.intervalMiles;
        if (intervalMonths == null && iv.intervalMonths != null) intervalMonths = iv.intervalMonths;
      }

      const last = findLastEntryForService(activeVehicleId, serviceName);
      let baseOdo = last && last.odo != null ? last.odo : null;
      let baseDate = last && last.date ? last.date : null;

      const vehicle = data.vehicles.find(v => v.id === activeVehicleId) || null;
      const currentOdo = vehicle && vehicle.currentOdo != null ? vehicle.currentOdo : null;

      let nextOdo = null;
      let nextDate = null;

      if (intervalMiles && intervalMiles > 0) {
        if (baseOdo != null) nextOdo = baseOdo + intervalMiles;
        else if (currentOdo != null) nextOdo = currentOdo + intervalMiles;
      }

      if (intervalMonths && intervalMonths > 0) {
        if (baseDate) nextDate = addMonthsToDate(baseDate, intervalMonths);
        else {
          const todayIso = getTodayIsoInSettingsTz();
          baseDate = baseDate || todayIso;
          nextDate = addMonthsToDate(baseDate, intervalMonths);
        }
      }

      const now = new Date().toISOString();

      const reminder = {
        id: "r_" + Date.now() + "_" + Math.random().toString(36).slice(2),
        vehicleId: activeVehicleId,
        serviceName,
        title: "",
        baseOdo,
        baseDate,
        intervalMiles: intervalMiles,
        intervalMonths: intervalMonths,
        nextOdo,
        nextDate,
        notes,
        createdAt: now,
        updatedAt: now
      };

      data.reminders.push(reminder);
      saveData();
      $("#reminder-form")[0].reset();
      renderRemindersPage();
      renderDashboardRemindersSnippet();
    }

    function saveReminderFromCard($card) {
      const id = $card.attr("data-id");
      const rem = data.reminders.find(r => r.id === id);
      if (!rem) return;

      const now = new Date().toISOString();
      const serviceName = $card.find(".rem-edit-service").val().trim() || "Reminder";
      const intervalMilesVal = $card.find(".rem-edit-interval-miles").val();
      const intervalMonthsVal = $card.find(".rem-edit-interval-months").val();
      const baseOdoVal = $card.find(".rem-edit-base-odo").val();
      const baseDateVal = $card.find(".rem-edit-base-date").val() || null;
      const nextOdoVal = $card.find(".rem-edit-next-odo").val();
      const nextDateVal = $card.find(".rem-edit-next-date").val() || null;
      const notesVal = $card.find(".rem-edit-notes").val().trim() || "";

      let intervalMiles = intervalMilesVal !== "" ? Number(intervalMilesVal) : null;
      let intervalMonths = intervalMonthsVal !== "" ? Number(intervalMonthsVal) : null;
      let baseOdo = baseOdoVal !== "" ? Number(baseOdoVal) : null;
      let baseDate = baseDateVal;
      let nextOdo = nextOdoVal !== "" ? Number(nextOdoVal) : null;
      let nextDate = nextDateVal;

      const vehicle = data.vehicles.find(v => v.id === rem.vehicleId) || null;
      const currentOdo = vehicle && vehicle.currentOdo != null ? vehicle.currentOdo : null;

      if ((nextOdoVal === "" || nextOdo == null) && intervalMiles && intervalMiles > 0) {
        if (baseOdo != null) nextOdo = baseOdo + intervalMiles;
        else if (currentOdo != null) nextOdo = currentOdo + intervalMiles;
      }

      if (!nextDate && intervalMonths && intervalMonths > 0) {
        if (baseDate) nextDate = addMonthsToDate(baseDate, intervalMonths);
        else {
          const todayIso = getTodayIsoInSettingsTz();
          baseDate = baseDate || todayIso;
          nextDate = addMonthsToDate(baseDate, intervalMonths);
        }
      }

      rem.serviceName = serviceName;
      rem.intervalMiles = intervalMiles;
      rem.intervalMonths = intervalMonths;
      rem.baseOdo = baseOdo;
      rem.baseDate = baseDate;
      rem.nextOdo = nextOdo;
      rem.nextDate = nextDate;
      rem.notes = notesVal;
      rem.updatedAt = now;

      saveData();
      renderRemindersPage();
      renderDashboardRemindersSnippet();
    }

    function deleteReminderFromCard($card) {
      const id = $card.attr("data-id");
      const rem = data.reminders.find(r => r.id === id);
      if (!rem) return;
      if (!confirm(`Delete reminder "${rem.serviceName || "Reminder"}"?`)) return;
      data.reminders = data.reminders.filter(r => r.id !== id);
      saveData();
      renderRemindersPage();
      renderDashboardRemindersSnippet();
    }

    function copyReminderFromCard($card) {
      const id = $card.attr("data-id");
      const rem = data.reminders.find(r => r.id === id);
      if (!rem) return;

      const vehicles = (data.vehicles || []).filter(v => v.id !== rem.vehicleId);
      if (!vehicles.length) {
        alert("There are no other vehicles to copy this reminder to.");
        return;
      }

      let $panel = $card.find(".reminder-copy-panel");
      if ($panel.length) {
        $panel.remove();
        return;
      }

      $panel = $("<div>").addClass("reminder-copy-panel");
      const $label = $("<label>").text("Copy to vehicle:");
      const $select = $("<select>").addClass("rem-copy-vehicle");
      vehicles.forEach(v => {
        $select.append(
          $("<option>")
            .val(v.id)
            .text(v.name || ("Vehicle " + v.id))
        );
      });

      const $confirm = $("<button>")
        .addClass("btn-primary btn-small rem-copy-confirm")
        .attr("type","button")
        .text("Copy");
      const $cancel = $("<button>")
        .addClass("btn-ghost btn-small rem-copy-cancel")
        .attr("type","button")
        .text("Cancel");

      $panel.append($label, $select, $confirm, $cancel);
      $card.find(".reminder-body-buttons").after($panel);
    }

    function openGoogleReminderFromCard($card) {
      const id = $card.attr("data-id");
      const rem = data.reminders.find(r => r.id === id);
      if (!rem) return;

      const vehicle = data.vehicles.find(v => v.id === rem.vehicleId);
      const derived = computeReminderDerived(rem, vehicle ? vehicle.currentOdo : null);

      if (!derived.nextDate) {
        alert("This reminder does not have a next due date set. Add a date or interval first.");
        return;
      }

      const title = (rem.serviceName || "Maintenance reminder") +
        (vehicle ? ` – ${vehicle.name}` : "");
      const details = rem.notes || "";
      const ymd = derived.nextDate.replace(/-/g,"");
      const datesParam = `${ymd}/${ymd}`;

      const url =
        "https://calendar.google.com/calendar/render?action=TEMPLATE" +
        "&text=" + encodeURIComponent(title) +
        "&details=" + encodeURIComponent(details) +
        "&dates=" + encodeURIComponent(datesParam);

      window.open(url, "_blank");
    }

    function exportDataJSON() {
      const blob = new Blob([JSON.stringify(data, null, 2)], {type:"application/json"});
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "garage-maintenance-data.json";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    function buildTableRowsForActiveVehicle() {
      const unit = getUnitShort();
      const headers = [
        "Service date",
        `Odometer (${unit})`,
        "Services",
        "Cost",
        "Next due date",
        `Next due mileage (${unit})`,
        "Notes"
      ];

      if (!activeVehicleId) {
        return { headers, rows: [], vehicleName: null, vin: null, plate: null };
      }

      const vehicle = data.vehicles.find(v => v.id === activeVehicleId) || null;
      const vehicleName = vehicle ? vehicle.name : "(Unknown vehicle)";
      const vehicleVin = vehicle && vehicle.vin != null ? vehicle.vin : null;
      const vehiclePlate = vehicle && vehicle.plate != null ? vehicle.plate : null;

      const rows = data.entries
        .filter(e => e.vehicleId === activeVehicleId)
        .map(e => [
          e.date || "",
          e.odo != null ? e.odo : "",
          (e.services || []).join("; "),
          e.cost != null ? e.cost : "",
          e.nextDate || "",
          e.nextOdo != null ? e.nextOdo : "",
          e.notes || ""
        ]);

      return { headers, rows, vehicleName, vin: vehicleVin, plate: vehiclePlate };
    }

    function exportTableCSV() {
      const { headers, rows, vehicleName, vin, plate } = buildTableRowsForActiveVehicle();

      if (!vehicleName) {
        alert("Select a vehicle first.");
        return;
      }
      if (!rows.length) {
        alert(`No entries to export for "${vehicleName}".`);
        return;
      }

      const safeName = vehicleName.replace(/[^\w]+/g, "_").toLowerCase();

      const lines = [];
      lines.push(`Vehicle: ${vehicleName}`);
      if (vin)   lines.push(`VIN: ${vin}`);
      if (plate) lines.push(`Plate: ${plate}`);
      lines.push("");
      lines.push(headers.join(","));
      rows.forEach(r => {
        const line = r.map(field => {
          const s = String(field).replace(/"/g, '""');
          return `"${s}"`;
        }).join(",");
        lines.push(line);
      });
      const blob = new Blob([lines.join("\r\n")], {type:"text/csv;charset=utf-8;"});
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "garage-" + safeName + "-table.csv";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    function exportTableWord() {
      const { headers, rows, vehicleName, vin, plate } = buildTableRowsForActiveVehicle();

      if (!vehicleName) {
        alert("Select a vehicle first.");
        return;
      }
      if (!rows.length) {
        alert(`No entries to export for "${vehicleName}".`);
        return;
      }

      const safeName = vehicleName.replace(/[^\w]+/g, "_").toLowerCase();

      let html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Garage Maintenance</title></head><body>";
      html += "<h2>Garage Maintenance – Service History</h2>";
      html += "<p><strong>Vehicle:</strong> " + vehicleName.replace(/</g,"&lt;") + "</p>";
      if (vin)   html += "<p><strong>VIN:</strong> " + String(vin).replace(/</g,"&lt;") + "</p>";
      if (plate) html += "<p><strong>Plate:</strong> " + String(plate).replace(/</g,"&lt;") + "</p>";
      html += "<br/>";
      html += "<table border='1' style='border-collapse:collapse;border:1px solid #000;'>";
      html += "<thead><tr>";
      headers.forEach(h => {
        html += "<th style='padding:4px 6px;'>" + h + "</th>";
      });
      html += "</tr></thead><tbody>";
      rows.forEach(r => {
        html += "<tr>";
        r.forEach(c => {
          html += "<td style='padding:4px 6px;'>" + String(c).replace(/</g,"&lt;") + "</td>";
        });
        html += "</tr>";
      });
      html += "</tbody></table></body></html>";

      const blob = new Blob([html], {type:"application/msword"});
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "garage-" + safeName + "-table.doc";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    function exportTablePDF() {
      const { headers, rows, vehicleName, vin, plate } = buildTableRowsForActiveVehicle();

      if (!vehicleName) {
        alert("Select a vehicle first.");
        return;
      }
      if (!rows.length) {
        alert(`No entries to export for "${vehicleName}".`);
        return;
      }

      if (!window.jspdf || !window.jspdf.jsPDF) {
        alert("jsPDF not available.");
        return;
      }

      const safeName = vehicleName.replace(/[^\w]+/g, "_").toLowerCase();
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF();
      let y = 14;
      doc.setFontSize(12);
      doc.text("Garage Maintenance – Service History", 14, y);
      y += 6;
      doc.setFontSize(11);
      doc.text("Vehicle: " + vehicleName, 14, y);
      if (vin) {
        y += 6;
        doc.text("VIN: " + vin, 14, y);
      }
      if (plate) {
        y += 6;
        doc.text("Plate: " + plate, 14, y);
      }
      y += 4;
      doc.autoTable({
        startY: y,
        head: [headers],
        body: rows,
        styles: { fontSize: 8, cellPadding: 2 }
      });
      doc.save("garage-" + safeName + "-table.pdf");
    }

    function importData(file) {
      const reader = new FileReader();
      reader.onload = function(e) {
        try {
          const parsed = JSON.parse(e.target.result);
          if (!parsed || typeof parsed !== "object") {
            alert("Invalid file format.");
            return;
          }
          if (!parsed.vehicles || !Array.isArray(parsed.vehicles)) {
            alert("Invalid data (missing vehicles).");
            return;
          }

          if (!parsed.serviceTypes) parsed.serviceTypes = [];
          if (!parsed.entries) parsed.entries = [];
          if (!parsed.reminders) parsed.reminders = [];
          if (!parsed.vehicleIntervals) parsed.vehicleIntervals = {};
          if (!parsed.settings) parsed.settings = JSON.parse(JSON.stringify(DEFAULT_SETTINGS));

          parsed.vehicles.forEach(v => {
            if (!Object.prototype.hasOwnProperty.call(v, "currentOdo")) v.currentOdo = null;
            if (!Object.prototype.hasOwnProperty.call(v, "vin"))        v.vin = null;
            if (!Object.prototype.hasOwnProperty.call(v, "plate"))      v.plate = null;
          });

          if (Array.isArray(parsed.serviceTypes) && parsed.serviceTypes.length) {
            if (typeof parsed.serviceTypes[0] === "string") {
              parsed.serviceTypes = parsed.serviceTypes.map(n => ({
                name: n,
                intervalMiles: null,
                intervalMonths: null
              }));
            } else {
              parsed.serviceTypes = parsed.serviceTypes.map(st => {
                if (typeof st === "string") {
                  return { name: st, intervalMiles: null, intervalMonths: null };
                }
                return {
                  name: st.name || "",
                  intervalMiles: (st.intervalMiles != null ? st.intervalMiles : null),
                  intervalMonths: (st.intervalMonths != null ? st.intervalMonths : null)
                };
              });
            }
          }

          data = parsed;
          saveData();
          setActiveVehicleFromStorageOrDefault();
          applyThemeFromSettings();
          applySiteTitle();
          updateUnitLabels();
          renderDashboard();
          renderSettings();
          renderReminderServiceSelect();
          renderRemindersPage();
          alert("Data imported successfully.");
        } catch (err) {
          console.error(err);
          alert("Failed to parse JSON.");
        }
      };
      reader.readAsText(file);
    }

    function resetAllData() {
  const confirmMsg = "⚠️ CLEAR ALL DATA\n\n" +
    "This will permanently delete:\n" +
    "• All vehicles, entries, reminders, and settings\n" +
    "• All attachment files\n" +
    "• Everything will be reset to defaults\n\n" +
    "This CANNOT be undone!\n\n" +
    "Type 'DELETE' to confirm:";
  
  const confirmation = prompt(confirmMsg);
  
  if (confirmation !== "DELETE") {
    if (confirmation !== null) {
      alert("Clear cancelled. Must type 'DELETE' exactly.");
    }
    return;
  }
  
  // Clear data
  data = cloneDefaultData();
  saveData();
  setActiveVehicleFromStorageOrDefault();
  applyThemeFromSettings();
  applySiteTitle();
  updateUnitLabels();
  renderDashboard();
  renderSettings();
  renderReminderServiceSelect();
  renderRemindersPage();
  
  alert("✅ All data cleared successfully.\n\nNote: Attachment files on server may need manual cleanup by administrator.");
}

/**
 * Auto-check recalls on page load
 * Only checks if: vehicle has VIN AND no cached data exists
 * This prevents unnecessary API calls while keeping data fresh
 */
function autoCheckRecallsOnLoad() {
  if (!activeVehicleId) return;
  
  const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
  if (!vehicle || !vehicle.vin) return;
  
  // Only auto-check if no cached data
  const cachedData = getVehicleRecallCache(vehicle.id);
  if (cachedData) {
    return;
  }
  
  // Auto-check after a short delay, without showing modal
  setTimeout(function() {
    checkVehicleRecalls(false);  // false = don't show modal
  }, 1000);
}

/**
 * Update safety status display based on vehicle VIN
 */
function updateSafetyStatus() {
  const $container = $("#safety-status-container");
  const $badge = $("#safety-status-badge");
  const $btn = $("#check-recalls-btn");
  
  if (!activeVehicleId) {
    $container.hide();
    return;
  }
  
  const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
  
  // Only show if vehicle has VIN
  if (!vehicle || !vehicle.vin || vehicle.vin.trim() === '') {
    $container.hide();
    return;
  }
  
  $container.show();
  
  // Check if we have cached recall data
  const cachedData = getVehicleRecallCache(vehicle.id);
  
  if (cachedData) {
    displayRecallStatus(cachedData);
  } else {
    // Show unknown status with check button
    $badge.removeClass('no-recalls has-recalls').addClass('unknown').text('Not checked');
    $btn.text('Check Recalls').prop('disabled', false);
  }
}

/**
 * Check recalls for current vehicle
 * @param {boolean} showModal - Whether to show the modal after checking (default: true)
 */
async function checkVehicleRecalls(showModal = true) {
  const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
  
  if (!vehicle || !vehicle.vin) {
    if (showModal) {
      alert('Vehicle does not have a VIN set.');
    }
    return;
  }
  
  const $btn = $("#check-recalls-btn");
  const originalText = $btn.text();
  
  try {
    $btn.text('Checking...').prop('disabled', true);
    
    const response = await fetch('check-recalls.php?vin=' + encodeURIComponent(vehicle.vin));
    const result = await response.json();
    
    if (!result.success) {
      throw new Error(result.message || 'Failed to check recalls');
    }
    
    // Cache the result
    setVehicleRecallCache(vehicle.id, result);
    
    // Update display
    displayRecallStatus(result);
    
    // Only show modal if requested (e.g., when button is clicked)
    if (showModal) {
      showRecallModal(result);
    }
    
  } catch (error) {
    console.error('Recall check error:', error);
    if (showModal) {
      alert('Failed to check recalls:\n\n' + error.message + '\n\nPlease try again later.');
    }
    $btn.text(originalText).prop('disabled', false);
  }
}

/**
 * Display recall status badge
 */
function displayRecallStatus(result) {
  const $badge = $("#safety-status-badge");
  const $btn = $("#check-recalls-btn");
  
  if (result.hasRecalls) {
    $badge.removeClass('no-recalls unknown').addClass('has-recalls')
      .html(`⚠️ ${result.count} recall${result.count > 1 ? 's' : ''}`);
    $btn.text('View Details');
  } else {
    $badge.removeClass('has-recalls unknown').addClass('no-recalls')
      .html('✓ No recalls');
    $btn.text('Re-check');
  }
  
  $btn.prop('disabled', false);
}

/**
 * Show recall details modal
 */
function showRecallModal(result) {
  const $modal = $("#recall-modal");
  const $body = $("#recall-modal-body");
  
  $body.empty();
  
  if (!result.hasRecalls) {
    // No recalls found
    $body.append(`
      <div class="no-recalls-message">
        <div class="no-recalls-icon">✅</div>
        <div class="no-recalls-title">No Open Recalls</div>
        <div class="no-recalls-text">
          This vehicle has no open safety recalls according to NHTSA records.
        </div>
        <a href="${result.nhtsaUrl}" target="_blank" class="btn-ghost btn-small">
          View on NHTSA Website →
        </a>
      </div>
    `);
  } else {
    // Show recalls
    const $header = $('<div>').css({
      marginBottom: '16px',
      paddingBottom: '12px',
      borderBottom: '1px solid var(--border)'
    });
    
    $header.append(`
      <div style="font-size: 0.95rem; color: var(--text-main); margin-bottom: 4px;">
        <strong>${result.count}</strong> open recall${result.count > 1 ? 's' : ''} found
      </div>
      <div style="font-size: 0.75rem; color: var(--text-muted);">
        VIN: ${result.vin} • Checked: ${formatDateNice(result.checkedAt.split(' ')[0])}
      </div>
    `);
    
    $body.append($header);
    
    // Add each recall
    result.recalls.forEach((recall, index) => {
      const $item = $('<div>').addClass('recall-item');
      
      $item.append(`
        <div class="recall-id">Campaign #${recall.id}</div>
        <div class="recall-component">${recall.component}</div>
        <div class="recall-summary">${recall.summary || 'No summary available.'}</div>
        ${recall.manufacturer ? `<div style="font-size: 0.75rem; color: var(--text-muted);">Manufacturer: ${recall.manufacturer}</div>` : ''}
        <a href="${recall.url}" target="_blank" class="recall-link">
          View full details on NHTSA →
        </a>
      `);
      
      $body.append($item);
    });
    
    // Add general NHTSA link at bottom
    $body.append(`
      <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); text-align: center;">
        <a href="${result.nhtsaUrl}" target="_blank" class="btn-primary btn-small">
          View All Recalls on NHTSA Website →
        </a>
      </div>
    `);
  }
  
  $modal.fadeIn(200);
}

/**
 * Get cached recall data for vehicle
 */
function getVehicleRecallCache(vehicleId) {
  try {
    const cacheKey = 'recall_cache_' + vehicleId;
    const cached = localStorage.getItem(cacheKey);
    if (!cached) return null;
    
    const data = JSON.parse(cached);
    
    // Cache expires after 7 days
    const cacheAge = Date.now() - new Date(data.cachedAt).getTime();
    const maxAge = 7 * 24 * 60 * 60 * 1000; // 7 days
    
    if (cacheAge > maxAge) {
      localStorage.removeItem(cacheKey);
      return null;
    }
    
    return data;
  } catch (e) {
    return null;
  }
}

/**
 * Set cached recall data for vehicle
 */
function setVehicleRecallCache(vehicleId, data) {
  try {
    const cacheKey = 'recall_cache_' + vehicleId;
    const cacheData = {
      ...data,
      cachedAt: new Date().toISOString()
    };
    localStorage.setItem(cacheKey, JSON.stringify(cacheData));
  } catch (e) {
    console.error('Failed to cache recall data:', e);
  }
}

    $(function() {
      loadData();
      setActiveVehicleFromStorageOrDefault();
      applyThemeFromSettings();
      applySiteTitle();
      updateUnitLabels();
      renderVehiclePicker();
      renderDashboardHistory();
      renderDashboardRemindersSnippet();
      renderSettings();
      renderReminderServiceSelect();
      renderRemindersPage();
      renderNewEntryFormDefaults();
      initDatePickers($(document));
      updateSafetyStatus();
      autoCheckRecallsOnLoad();
      
    // Check recalls button
  $("#check-recalls-btn").on("click", function(e) {
    e.preventDefault();
    checkVehicleRecalls();
  });
  
  // Close recall modal
  $("#close-recall-modal, .recall-modal-overlay").on("click", function() {
    $("#recall-modal").fadeOut(200);
  });
  
  // Prevent modal close when clicking inside content
  $(".recall-modal-content").on("click", function(e) {
    e.stopPropagation();
  });
      
/*
 * UPDATED BACKUP HANDLERS FOR app.js
 * 
 * Find and replace the existing backup handlers in app.js.
 * Look for these sections and replace them:
 * 
 * 1. Replace: $("#backup-export-full").on("click", async function() { ... });
 * 2. Replace: $("#backup-import-full").on("change", async function() { ... });
 * 
 * The code below should replace approximately lines 2770-2910 in your app.js
 */

// Full backup - Download JSON with data + embedded attachments
$("#backup-export-full").on("click", async function() {
  const $btn = $(this);
  const originalText = $btn.html();
  
  try {
    // Show loading state
    $btn.prop("disabled", true).html("⏳ Creating backup...");
    
    // First check if backup can be created
    const checkResponse = await fetch('backup-create.php?t=' + Date.now());
    const checkResult = await checkResponse.json();
    
    if (!checkResult.success) {
      throw new Error(checkResult.message || 'Backup creation failed');
    }
    
    // Show info
    $btn.html("⏳ Downloading...");
    
    // Direct download - this streams the fresh backup
    const downloadUrl = 'backup-create.php?download=1&t=' + Date.now();
    
    // Create hidden link and click it
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = 'garage_maintenance_backup_' + new Date().toISOString().split('T')[0] + '.json';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Success message
    let message = `✅ Backup downloaded! (${checkResult.size_formatted})`;
    if (checkResult.attachment_count > 0) {
      message = `✅ Backup: ${checkResult.size_formatted}, ${checkResult.attachment_count} attachments`;
    }
    showToast(message);
    
    // Show warnings if any
    if (checkResult.warnings && checkResult.warnings.length > 0) {
      console.warn("Backup warnings:", checkResult.warnings);
    }
    
  } catch (error) {
    console.error("Backup error:", error);
    alert("❌ Backup failed:\n\n" + error.message);
  } finally {
    $btn.prop("disabled", false).html(originalText);
  }
});

// Full restore - Upload JSON and restore everything
$("#backup-import-full").on("change", async function() {
  const file = this.files[0];
  if (!file) return;
  
  // Check file extension
  const ext = file.name.split('.').pop().toLowerCase();
  
  if (ext !== 'json') {
    alert("Please select a .json backup file.");
    this.value = "";
    return;
  }
  
  // Confirm restore
  const confirmMsg = `⚠️ RESTORE FROM BACKUP\n\n` +
    "This will:\n" +
    "• Delete ALL current data and attachments\n" +
    "• Replace with data from the backup file\n" +
    "• Cannot be undone!\n\n" +
    "Make sure you have a current backup before proceeding.\n\n" +
    "Continue with restore?";
  
  if (!confirm(confirmMsg)) {
    this.value = "";
    return;
  }
  
  // Show loading overlay
  const $overlay = $("<div>")
    .css({
      position: "fixed",
      top: 0,
      left: 0,
      right: 0,
      bottom: 0,
      background: "rgba(0,0,0,0.8)",
      display: "flex",
      alignItems: "center",
      justifyContent: "center",
      zIndex: 9999,
      flexDirection: "column",
      gap: "20px"
    })
    .append(
      $("<div>").css({
        fontSize: "1.5rem",
        color: "#fff",
        fontWeight: "600"
      }).text("Restoring backup..."),
      $("<div>").css({
        fontSize: "1rem",
        color: "#ccc"
      }).text("Please wait, this may take a moment")
    );
  
  $("body").append($overlay);
  
  try {
    // Create FormData and upload
    const formData = new FormData();
    formData.append('backup_file', file);
    
    const response = await fetch('restore-full.php', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      // Restore successful - reload app data
      if (result.data) {
        data = result.data;
        setActiveVehicleFromStorageOrDefault();
      } else {
        loadData();
      }
      
      // Re-render everything
      applyThemeFromSettings();
      applySiteTitle();
      updateUnitLabels();
      renderDashboard();
      renderSettings();
      renderReminderServiceSelect();
      renderRemindersPage();
      
      $overlay.remove();
      
      // Build success message
      let successMsg = "✅ Backup restored successfully!";
      if (result.attachments_restored && result.attachments_restored > 0) {
        successMsg += `\n\n${result.attachments_restored} attachment(s) restored.`;
      }
      if (result.attachments_errors && result.attachments_errors.length > 0) {
        successMsg += `\n\n⚠️ ${result.attachments_errors.length} attachment(s) had issues.`;
      }
      
      alert(successMsg);
    } else {
      $overlay.remove();
      alert("❌ Restore failed:\n\n" + (result.message || "Unknown error"));
    }
    
  } catch (error) {
    console.error("Restore error:", error);
    $overlay.remove();
    alert("❌ Restore failed:\n\n" + error.message);
  }
  
  // Reset file input
  this.value = "";
});
      
      // Toggle entry form button
  $("#toggle-entry-form").on("click", function() {
    toggleEntryForm();
  });

  // Keep form open preference checkbox
  $("#keep-form-open-pref").on("change", function() {
    const checked = $(this).is(":checked");
    setKeepFormOpenPreference(checked);
    showToast(checked ? "Form will stay open after adding entries" : "Form will close after adding entries");
  });

      if (document.getElementById("entry-attach-limit-text")) {
        document.getElementById("entry-attach-limit-text").textContent = getAttachmentHelpText();
      }

      $(".nav-btn").on("click", function() {
        const view = $(this).data("view");
        $(".nav-btn").removeClass("active");
        $(this).addClass("active");
        $(".view").removeClass("active");
        $("#view-" + view).addClass("active");

        if (view === "dashboard") {
          renderDashboard();
        } else if (view === "reminders") {
          renderRemindersPage();
        } else if (view === "settings") {
          renderSettings();
        }
      });

      $(".settings-tab-btn").on("click", function() {
        const tab = $(this).data("tab");
        $(".settings-tab-btn").removeClass("active");
        $(this).addClass("active");
        $(".settings-tab-view").removeClass("active");
        $("#settings-tab-" + tab).addClass("active");

        if (tab === "intervals") {
          renderSettingsIntervals();
        }
      });

$("#active-vehicle").on("change", function() {
  dashboardHistoryPage = 1;
  setActiveVehicle($(this).val() || null);
  renderVehiclePickerOdometer(); // Add this line to update the odometer field
  renderDashboard();
  renderRemindersPage();
});

      $("#entry-form").on("submit", async function(e) {
        e.preventDefault();
        await addOrUpdateEntryFromForm();
      });

      $("#entry-reset").on("click", function() {
        renderNewEntryFormDefaults();
        initDatePickers($(document));
      });

$("#service-checklist-container").on("change", "input[type='checkbox']", function() {
  autoFillNextOdoFromIntervals();
});
      $("#entry-odo").on("change blur", function() {
        autoFillNextOdoFromIntervals();
      });
      $("#entry-services-other").on("change blur", function() {
        autoFillNextOdoFromIntervals();
      });

// Toggle expand/collapse (shows view mode)
// In the entry-header click handler, add/remove 'expanded' class
$("#entry-list").on("click", ".entry-header", function(e) {
  const $card = $(this).closest(".entry-card");
  const $body = $card.find(".entry-body");
  const open = $body.is(":visible");
  
  // Close all entries and remove expanded class
  $(".entry-body").slideUp(120);
  $(".entry-toggle").text("Tap to expand ▼");
  $(".entry-card").removeClass("expanded");  // ADD THIS LINE
  
  // Reset all to view mode
  $(".entry-view-mode").show();
  $(".entry-edit-mode").hide();
  
  // Open this one if it wasn't already open
  if (!open) {
    $body.slideDown(120);
    $card.find(".entry-toggle").text("Tap to collapse ▲");
    $card.addClass("expanded");  // ADD THIS LINE
  }
});

// Switch to edit mode
$("#entry-list").on("click", ".entry-btn-edit", function(e) {
  e.stopPropagation();
  const $card = $(this).closest(".entry-card");
  $card.find(".entry-view-mode").hide();
  $card.find(".entry-edit-mode").show();
  
  // Re-initialize datepickers for the edit fields
  initDatePickers($card.find(".entry-edit-mode"));
});

// Cancel edit mode (return to view mode)
$("#entry-list").on("click", ".entry-btn-cancel", function(e) {
  e.stopPropagation();
  const $card = $(this).closest(".entry-card");
  $card.find(".entry-edit-mode").hide();
  $card.find(".entry-view-mode").show();
});

// Save changes
$("#entry-list").on("click", ".entry-btn-save", async function(e) {
  e.stopPropagation();
  const $card = $(this).closest(".entry-card");
  await saveEntryFromAccordion($card);
  
  // After saving, return to view mode and close
  $card.find(".entry-body").slideUp(120);
  $card.find(".entry-toggle").text("Tap to expand ▼");
  $card.find(".entry-edit-mode").hide();
  $card.find(".entry-view-mode").show();
});

// Delete entry
$("#entry-list").on("click", ".entry-btn-delete", function(e) {
  e.stopPropagation();
  const $card = $(this).closest(".entry-card");
  deleteEntryByCard($card);
});

// Delete attachment
$("#entry-list").on("click", ".entry-attach-delete", async function(e) {
  e.stopPropagation();
  const $btn = $(this);
  const attId = $btn.data("att-id");
  
  if (!confirm("Delete this attachment?")) return;
  
  try {
    const formData = new FormData();
    formData.append('attachment_id', attId);
    
    const response = await fetch('delete-attachment.php', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      showToast("Attachment deleted");
      // Reload data from server
      loadData();
      renderDashboard();
      renderRemindersPage();
    } else {
      showToast("Delete failed: " + (result.message || "Unknown error"));
    }
  } catch (error) {
    console.error("Delete error:", error);
    showToast("Delete failed: " + error.message);
  }
});

// Download attachment
$("#entry-list").on("click", ".entry-attach-download", function(e) {
  e.stopPropagation();
  const $btn = $(this);
  const attId = $btn.data("att-id");
  
  // Create download link
  const downloadUrl = `download.php?id=${encodeURIComponent(attId)}`;
  
  // Trigger download
  const link = document.createElement('a');
  link.href = downloadUrl;
  link.download = ''; // Let server set filename
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
});

      $("#settings-general-save").on("click", function() {
  const title = $("#settings-site-title").val().trim() || DEFAULT_SETTINGS.siteTitle;
  const unit = $("#settings-unit").val() || "mi";
  const timezone = $("#settings-timezone").val() || "";
  
  // Get reminder threshold values
  const upcomingDays = $("#settings-upcoming-days").val();
  const upcomingMiles = $("#settings-upcoming-miles").val();
  const overdueDays = $("#settings-overdue-days").val();
  const overdueMiles = $("#settings-overdue-miles").val();

  if (!data.settings) data.settings = JSON.parse(JSON.stringify(DEFAULT_SETTINGS));

  data.settings.siteTitle = title;
  data.settings.unit = unit;
  data.settings.timezone = timezone;
  
  // Save reminder thresholds
  data.settings.upcomingThresholdDays = upcomingDays !== "" ? Number(upcomingDays) : DEFAULT_SETTINGS.upcomingThresholdDays;
  data.settings.upcomingThresholdMiles = upcomingMiles !== "" ? Number(upcomingMiles) : DEFAULT_SETTINGS.upcomingThresholdMiles;
  data.settings.overdueThresholdDays = overdueDays !== "" ? Number(overdueDays) : DEFAULT_SETTINGS.overdueThresholdDays;
  data.settings.overdueThresholdMiles = overdueMiles !== "" ? Number(overdueMiles) : DEFAULT_SETTINGS.overdueThresholdMiles;

  saveData();
  applyThemeFromSettings();
  applySiteTitle();
  updateUnitLabels();
  renderDashboard();
  renderSettings();
  renderRemindersPage();
});

      $("#settings-vehicle-add").on("click", function() {
        const name = $("#settings-vehicle-new").val().trim();
        if (!name) return;
        const id = "v_" + Date.now() + "_" + Math.random().toString(36).slice(2);
        data.vehicles.push({id, name, currentOdo: null, vin: null, plate: null});
        saveData();
        $("#settings-vehicle-new").val("");
        setActiveVehicle(id);
        renderDashboard();
        renderSettingsVehicles();
        renderSettingsIntervals();
        renderRemindersPage();
      });

// Settings Vehicle Card - Accordion toggle
$("#settings-vehicles").on("click", ".settings-vehicle-header", function(e) {
  const $card = $(this).closest(".settings-vehicle-card");
  const isExpanded = $card.hasClass("expanded");
  
  // Close all other cards
  $(".settings-vehicle-card").removeClass("expanded");
  
  // Toggle this card
  if (!isExpanded) {
    $card.addClass("expanded");
  }
});

// Prevent header click when clicking inside the body
$("#settings-vehicles").on("click", ".settings-vehicle-body", function(e) {
  e.stopPropagation();
});

$("#settings-vehicles").on("click", ".settings-vehicle-save", function(e) {
  e.stopPropagation();
  const $card = $(this).closest(".settings-vehicle-card");
  const id = $card.attr("data-id");
  const name = $card.find(".settings-vehicle-name").val().trim();
  const odoVal = $card.find(".settings-vehicle-odo").val();
  const vinVal = $card.find(".settings-vehicle-vin").val().trim();
  const plateVal = $card.find(".settings-vehicle-plate").val().trim();
  
  if (!name) {
    alert("Vehicle name is required.");
    return;
  }
  
  const v = data.vehicles.find(v => v.id === id);
  if (v) {
    v.name = name;
    v.currentOdo = odoVal !== "" ? Number(odoVal) : null;
    v.vin = vinVal || null;
    v.plate = plateVal || null;
    saveData();
    
    // Collapse the card after saving
    $card.removeClass("expanded");
    
    renderDashboard();
    renderRemindersPage();
    renderSettingsIntervals();
    renderSettingsVehicles();
    
    showToast("Vehicle saved successfully");
  }
});

$("#settings-vehicles").on("click", ".settings-vehicle-delete", function(e) {
  e.stopPropagation();
  const $card = $(this).closest(".settings-vehicle-card");
  const id = $card.attr("data-id");
  const v = data.vehicles.find(v => v.id === id);
  if (!v) return;
  
  if (!confirm(`Delete "${v.name}"?\n\nThis will also remove all entries and reminders for this vehicle. This cannot be undone.`)) return;
  
  // Remove vehicle and associated data
  data.vehicles = data.vehicles.filter(v => v.id !== id);
  data.entries = data.entries.filter(e => e.vehicleId !== id);
  data.reminders = data.reminders.filter(r => r.vehicleId !== id);
  if (data.vehicleIntervals && data.vehicleIntervals[id]) {
    delete data.vehicleIntervals[id];
  }
  
  if (activeVehicleId === id) {
    activeVehicleId = data.vehicles[0]?.id || null;
  }
  
  saveData();
  renderDashboard();
  renderSettingsVehicles();
  renderSettingsIntervals();
  renderRemindersPage();
  
  showToast("Vehicle deleted");
});

      $("#settings-service-add").on("click", function() {
        const name = $("#settings-service-new").val().trim();
        if (!name) return;
        const existingNames = new Set((data.serviceTypes || []).map(st => st.name));
        if (!existingNames.has(name)) {
          data.serviceTypes.push({ name, intervalMiles: null, intervalMonths: null });
          saveData();
          $("#settings-service-new").val("");
          renderSettingsServices();
          renderDashboard();
          renderReminderServiceSelect();
          renderSettingsIntervals();
          renderRemindersPage();
        }
      });

      $("#settings-services").on("click", ".settings-service-save", function() {
        const $row = $(this).closest(".settings-row");
        const index = Number($row.attr("data-index"));
        const name = $row.find(".settings-service-name").val().trim();
        if (!name) return;
        if (index >= 0 && index < data.serviceTypes.length) {
          const st = data.serviceTypes[index];
          st.name = name;
          saveData();
          renderSettingsServices();
          renderDashboard();
          renderReminderServiceSelect();
          renderSettingsIntervals();
          renderRemindersPage();
        }
      });

      $("#settings-services").on("click", ".settings-service-delete", function() {
        const $row = $(this).closest(".settings-row");
        const index = Number($row.attr("data-index"));
        const st = data.serviceTypes[index];
        const name = st ? st.name : "";
        if (!name && name !== "") return;
        if (!confirm(`Delete service type "${name}"? Existing entries and reminders keep their text/intervals.`)) return;
        data.serviceTypes.splice(index, 1);
        saveData();
        renderSettingsServices();
        renderDashboard();
        renderReminderServiceSelect();
        renderSettingsIntervals();
        renderRemindersPage();
      });

      $("#settings-intervals-vehicle").on("change", function() {
        const vid = $(this).val();
        renderSettingsIntervalsForVehicle(vid);
      });

      $("#settings-intervals-list").on("click", ".settings-intervals-save", function() {
        const $row = $(this).closest(".settings-row");
        const vehicleId = $row.attr("data-vehicle-id");
        const serviceName = $row.attr("data-service");

        const milesVal = $row.find(".settings-intervals-miles").val();
        const monthsVal = $row.find(".settings-intervals-months").val();

        if (!data.vehicleIntervals) data.vehicleIntervals = {};
        if (!data.vehicleIntervals[vehicleId]) data.vehicleIntervals[vehicleId] = {};
        const vMap = data.vehicleIntervals[vehicleId];

        const intervalMiles = milesVal !== "" ? Number(milesVal) : null;
        const intervalMonths = monthsVal !== "" ? Number(monthsVal) : null;

        if (intervalMiles == null && intervalMonths == null) {
          delete vMap[serviceName];
        } else {
          vMap[serviceName] = { intervalMiles, intervalMonths };
        }

        saveData();
        renderSettingsIntervalsForVehicle(vehicleId);
      });

      $("#settings-intervals-list").on("click", ".settings-intervals-clear", function() {
        const $row = $(this).closest(".settings-row");
        const vehicleId = $row.attr("data-vehicle-id");
        const serviceName = $row.attr("data-service");
        $row.find(".settings-intervals-miles").val("");
        $row.find(".settings-intervals-months").val("");

        if (data.vehicleIntervals && data.vehicleIntervals[vehicleId]) {
          delete data.vehicleIntervals[vehicleId][serviceName];
          saveData();
        }
      });

      $("#backup-export").on("click", function() {
        exportDataJSON();
      });

      $("#backup-import").on("change", function() {
  const file = this.files[0];
  if (!file) return;
  
  const confirmMsg = "⚠️ IMPORT DATA (JSON)\n\n" +
    "Note: This imports database data only.\n" +
    "Attachment files are NOT included in JSON backups.\n\n" +
    "For complete backup with attachments, use 'Full Backup (ZIP)'.\n\n" +
    "Continue with JSON import?";
  
  if (confirm(confirmMsg)) {
    importData(file);
  }
  
  this.value = ""; // Reset file input
});

      $("#backup-reset").on("click", function() {
        resetAllData();
      });

      $("#export-excel").on("click", function() {
        exportTableCSV();
      });

      $("#export-word").on("click", function() {
        exportTableWord();
      });

      $("#export-pdf").on("click", function() {
        exportTablePDF();
      });

      $("#reminder-form").on("submit", function(e) {
        e.preventDefault();
        addReminderFromForm();
      });

      $("#rem-new-service").on("change", function() {
        const name = $(this).val();
        if (!name) {
          $("#rem-new-interval-miles").val("");
          $("#rem-new-interval-months").val("");
          return;
        }
        const iv = getIntervalForService(activeVehicleId, name);
        $("#rem-new-interval-miles").val(iv.intervalMiles != null ? iv.intervalMiles : "");
        $("#rem-new-interval-months").val(iv.intervalMonths != null ? iv.intervalMonths : "");
      });

      $("#reminders-list").on("click", ".reminder-header", function() {
        const $card = $(this).closest(".reminder-card");
        const $body = $card.find(".reminder-body");
        const open = $body.is(":visible");
        $(".reminder-body").slideUp(120);
        if (!open) {
          $body.slideDown(120);
        }
      });

      $("#reminders-list").on("click", ".rem-btn-save", function(e) {
        e.stopPropagation();
        const $card = $(this).closest(".reminder-card");
        saveReminderFromCard($card);
      });

      $("#reminders-list").on("click", ".rem-btn-delete", function(e) {
        e.stopPropagation();
        const $card = $(this).closest(".reminder-card");
        deleteReminderFromCard($card);
      });

      $("#reminders-list").on("click", ".rem-btn-copy", function(e) {
        e.stopPropagation();
        const $card = $(this).closest(".reminder-card");
        copyReminderFromCard($card);
      });

      $("#reminders-list").on("click", ".rem-copy-cancel", function(e) {
        e.stopPropagation();
        $(this).closest(".reminder-copy-panel").remove();
      });

      $("#reminders-list").on("click", ".rem-copy-confirm", function(e) {
        e.stopPropagation();
        const $panel = $(this).closest(".reminder-copy-panel");
        const $card = $(this).closest(".reminder-card");
        const id = $card.attr("data-id");
        const rem = data.reminders.find(r => r.id === id);
        if (!rem) return;
        const targetId = $panel.find(".rem-copy-vehicle").val();
        if (!targetId) return;

        const now = new Date().toISOString();
        const clone = Object.assign({}, rem, {
          id: "r_" + Date.now() + "_" + Math.random().toString(36).slice(2),
          vehicleId: targetId,
          createdAt: now,
          updatedAt: now
        });

        data.reminders.push(clone);
        saveData();
        $panel.remove();
        renderRemindersPage();
        renderDashboardRemindersSnippet();
        showToast("Reminder copied to selected vehicle.");
      });

      $("#reminders-list").on("click", ".rem-btn-google", function(e) {
        e.stopPropagation();
        const $card = $(this).closest(".reminder-card");
        openGoogleReminderFromCard($card);
      });
    });
  