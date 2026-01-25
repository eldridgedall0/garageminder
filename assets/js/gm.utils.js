/**
 * Garage Maintenance - Utility Functions
 * Updated: Support for per-service cost/note format
 */

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
  const maxBytes = maxSizeMB > 0 ? maxSizeMB * 1024 * 1024 : Infinity;
  return { maxCount, maxSizeMB, maxBytes };
}

function getAttachmentHelpText() {
  const { maxCount, maxSizeMB } = getAttachmentLimits();
  return `Up to ${maxCount} attachments per entry, PDF/Word/images only, max ${maxSizeMB} MB each.`;
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
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    const d = String(now.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
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
  if (!y || !m || !d) return null;
  const date = new Date(y, m - 1, d);
  date.setMonth(date.getMonth() + months);
  const newY = date.getFullYear();
  const newM = String(date.getMonth() + 1).padStart(2,"0");
  const newD = String(date.getDate()).padStart(2,"0");
  return `${newY}-${newM}-${newD}`;
}

function getIntervalForService(vehicleId, serviceName) {
  if (data.vehicleIntervals && data.vehicleIntervals[vehicleId]) {
    const vIntervals = data.vehicleIntervals[vehicleId];
    if (vIntervals[serviceName]) {
      return {
        intervalMiles: vIntervals[serviceName].intervalMiles,
        intervalMonths: vIntervals[serviceName].intervalMonths
      };
    }
  }
  const st = getServiceTypeByName(serviceName);
  if (st) {
    return {
      intervalMiles: st.intervalMiles,
      intervalMonths: st.intervalMonths
    };
  }
  return {
    intervalMiles: null,
    intervalMonths: null
  };
}

function getServiceNames(services) {
  if (typeof services === "string") {
    return services.split(",").map(s => s.trim()).filter(s => s.length);
  }
  if (!Array.isArray(services)) {
    return [];
  }
  return services.map(s => {
    if (typeof s === "string") return s.trim();
    if (s && typeof s.name === "string") return s.name.trim();
    return "";
  }).filter(n => n.length);
}

function getCostForService(services, serviceName) {
  if (!Array.isArray(services)) return null;
  const match = services.find(s => {
    if (typeof s === "object" && s.name === serviceName) return true;
    return false;
  });
  if (!match) return null;
  return match.cost != null ? match.cost : null;
}

function getNotesForService(services, serviceName) {
  if (!Array.isArray(services)) return "";
  const match = services.find(s => {
    if (typeof s === "object" && s.name === serviceName) return true;
    return false;
  });
  if (!match) return "";
  return match.notes || "";
}

function renderServiceBubbles(services) {
  if (!services || !services.length) {
    return '<span class="entry-service-tag">No services</span>';
  }

  const names = getServiceNames(services);
  if (!names.length) {
    return '<span class="entry-service-tag">No services</span>';
  }

  return names.map(name => {
    const cost = getCostForService(services, name);
    const notes = getNotesForService(services, name);
    
    let label = name;
    if (cost != null && cost > 0) {
      label += ` ($${cost.toFixed(2)})`;
    }
    
    let title = '';
    if (notes) {
      title = `title="${notes.replace(/"/g, '&quot;')}"`;
    }
    
    return `<span class="entry-service-tag" ${title}>${label}</span>`;
  }).join(" ");
}

function autoFillNextOdoFromIntervals() {
  const checked = Array.from($("#service-checklist-container input[type='checkbox']:checked"))
    .map(el => $(el).attr("data-service"));

  const other = $("#entry-services-other").val().trim();
  if (other) {
    other.split(",").forEach(s => {
      const name = s.trim();
      if (name) checked.push(name);
    });
  }

  if (!checked.length) {
    $("#entry-next-odo").val("");
    return;
  }

  const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
  if (!vehicle) return;

  const currentOdo = vehicle.currentOdo;
  const entryOdo = $("#entry-odo").val();
  const baseOdo = entryOdo !== "" ? Number(entryOdo) : (currentOdo != null ? currentOdo : null);

  if (baseOdo == null) {
    $("#entry-next-odo").val("");
    return;
  }

  let maxAdd = 0;
  checked.forEach(serviceName => {
    const iv = getIntervalForService(activeVehicleId, serviceName);
    const im = iv.intervalMiles;
    if (im && im > maxAdd) {
      maxAdd = im;
    }
  });

  if (maxAdd > 0) {
    const next = baseOdo + maxAdd;
    $("#entry-next-odo").val(next);
  } else {
    $("#entry-next-odo").val("");
  }
}

/**
 * Helper function to find the MOST RECENT entry for a given service
 * Used by resetRemindersForEntry to ensure reminders are based on the latest service
 */
function findMostRecentEntryForService(vehicleId, serviceName) {
  // Filter all entries for this vehicle and service
  const candidates = data.entries.filter(e => {
    if (e.vehicleId !== vehicleId) return false;
    const serviceNames = getServiceNames(e.services || []);
    return serviceNames.includes(serviceName);
  });
  
  // No entries found
  if (!candidates.length) return null;
  
  // Sort by date (most recent first), then by createdAt as tiebreaker
  candidates.sort((a, b) => {
    const dateCompare = (b.date || "").localeCompare(a.date || "");
    if (dateCompare !== 0) return dateCompare;
    return (b.createdAt || "").localeCompare(a.createdAt || "");
  });
  
  // Return the most recent entry
  return candidates[0];
}

function resetRemindersForEntry(entry) {
  if (!entry || !entry.vehicleId) return;
  const vehicle = data.vehicles.find(v => v.id === entry.vehicleId) || null;
  const currentOdo = vehicle && vehicle.currentOdo != null ? vehicle.currentOdo : null;
  const nowIso = new Date().toISOString();

  // Get service names from the entry (handles both old and new format)
  const serviceNames = getServiceNames(entry.services || []);

  serviceNames.forEach(serviceName => {
    if (!serviceName) return;
    const rems = data.reminders.filter(
      r => r.vehicleId === entry.vehicleId && r.serviceName === serviceName
    );
    rems.forEach(r => {
      const intervalMiles = r.intervalMiles != null ? r.intervalMiles : null;
      const intervalMonths = r.intervalMonths != null ? r.intervalMonths : null;

      // ✅ FIX: Find the MOST RECENT entry for this service, not the current entry
      // This ensures reminders are based on the latest service, even when adding old entries
      const mostRecentEntry = findMostRecentEntryForService(entry.vehicleId, serviceName);
      
      // Use most recent entry if found, otherwise fall back to current entry
      const referenceEntry = mostRecentEntry || entry;

      let baseOdo = referenceEntry.odo != null ? referenceEntry.odo
                   : (currentOdo != null ? currentOdo
                      : (r.baseOdo != null ? r.baseOdo : null));
      let baseDate = referenceEntry.date || r.baseDate || null;

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
