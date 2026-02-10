/**
 * GarageMinder - Vehicle Report Export v2.0
 * Comprehensive export system with modal options
 * Supports CSV, Word, and PDF formats
 * B&W design with color logo/branding
 */

// ========================================
// EXPORT MODAL MANAGEMENT
// ========================================

function openVehicleReportExportModal() {
  // Check if a vehicle is selected
  if (!activeVehicleId || activeVehicleId === "all") {
    alert("Please select a specific vehicle first (not 'All Vehicles').");
    return;
  }
  
  const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
  if (!vehicle) {
    alert("Vehicle not found.");
    return;
  }
  
  // Check if there are entries
  const entries = data.entries.filter(e => e.vehicleId === activeVehicleId);
  if (!entries.length) {
    alert('No service records to export for "' + vehicle.name + '".');
    return;
  }
  
  // Load saved preferences
  const savedPrefs = loadExportPreferences();
  
  // Create modal HTML
  const modalHtml = `
    <div id="export-modal-overlay" class="export-modal-overlay">
      <div class="export-modal">
        <div class="export-modal-header">
          <h2><i class="bi bi-file-earmark-text"></i> Vehicle Report Export</h2>
          <button type="button" class="export-modal-close" onclick="closeVehicleReportExportModal()">&times;</button>
        </div>
        
        <div class="export-modal-body">
          <div class="export-vehicle-info">
            <strong>${escapeHtml(vehicle.name)}</strong>
            <span>${entries.length} service record${entries.length !== 1 ? 's' : ''}</span>
          </div>
          
          <!-- Format Selection -->
          <div class="export-section">
            <label class="export-section-label">Export Format</label>
            <div class="export-format-options">
              <label class="export-format-option ${savedPrefs.format === 'pdf' ? 'selected' : ''}">
                <input type="radio" name="export-format" value="pdf" ${savedPrefs.format === 'pdf' ? 'checked' : ''}>
                <i class="bi bi-file-earmark-pdf"></i>
                <span>PDF</span>
                <small>Recommended</small>
              </label>
              <label class="export-format-option ${savedPrefs.format === 'word' ? 'selected' : ''}">
                <input type="radio" name="export-format" value="word" ${savedPrefs.format === 'word' ? 'checked' : ''}>
                <i class="bi bi-file-earmark-word"></i>
                <span>Word</span>
                <small>.doc file</small>
              </label>
              <label class="export-format-option ${savedPrefs.format === 'xlsx' ? 'selected' : ''}">
                <input type="radio" name="export-format" value="xlsx" ${savedPrefs.format === 'xlsx' ? 'checked' : ''}>
                <i class="bi bi-file-earmark-spreadsheet"></i>
                <span>Excel</span>
                <small>.xlsx file</small>
              </label>
              <label class="export-format-option ${savedPrefs.format === 'csv' ? 'selected' : ''}">
                <input type="radio" name="export-format" value="csv" ${savedPrefs.format === 'csv' ? 'checked' : ''}>
                <i class="bi bi-filetype-csv"></i>
                <span>CSV</span>
                <small>Simple data</small>
              </label>
            </div>
          </div>
          
          <!-- Date Range Filter -->
          <div class="export-section">
            <label class="export-section-label">Date Range</label>
            <div class="export-date-options">
              <select id="export-date-range" class="export-select">
                <option value="all" ${savedPrefs.dateRange === 'all' ? 'selected' : ''}>All Time</option>
                <option value="year" ${savedPrefs.dateRange === 'year' ? 'selected' : ''}>Last 12 Months</option>
                <option value="6months" ${savedPrefs.dateRange === '6months' ? 'selected' : ''}>Last 6 Months</option>
                <option value="3months" ${savedPrefs.dateRange === '3months' ? 'selected' : ''}>Last 3 Months</option>
                <option value="ytd" ${savedPrefs.dateRange === 'ytd' ? 'selected' : ''}>Year to Date</option>
                <option value="custom" ${savedPrefs.dateRange === 'custom' ? 'selected' : ''}>Custom Range</option>
              </select>
              <div id="export-custom-dates" class="export-custom-dates" style="display: ${savedPrefs.dateRange === 'custom' ? 'flex' : 'none'};">
                <input type="date" id="export-date-from" value="${savedPrefs.dateFrom || ''}">
                <span>to</span>
                <input type="date" id="export-date-to" value="${savedPrefs.dateTo || ''}">
              </div>
            </div>
          </div>
          
          <!-- Entry Type Filter -->
          <div class="export-section">
            <label class="export-section-label">Entry Types</label>
            <div class="export-checkbox-group">
              <label class="export-checkbox">
                <input type="checkbox" id="export-type-all" ${savedPrefs.typeAll ? 'checked' : ''}>
                <span>All Types</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" class="export-type-item" value="service" ${savedPrefs.types?.includes('service') ? 'checked' : ''}>
                <span>Service</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" class="export-type-item" value="repair" ${savedPrefs.types?.includes('repair') ? 'checked' : ''}>
                <span>Repair</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" class="export-type-item" value="upgrade" ${savedPrefs.types?.includes('upgrade') ? 'checked' : ''}>
                <span>Upgrade</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" class="export-type-item" value="tax" ${savedPrefs.types?.includes('tax') ? 'checked' : ''}>
                <span>Tax/Fees</span>
              </label>
            </div>
          </div>
          
          <!-- Include Sections (PDF/Word only) -->
          <div class="export-section export-sections-group">
            <label class="export-section-label">Include Sections <small>(PDF/Word only)</small></label>
            <div class="export-checkbox-group">
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-vehicleinfo" ${savedPrefs.includeVehicleInfo !== false ? 'checked' : ''}>
                <span>Vehicle Information</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-costsummary" ${savedPrefs.includeCostSummary !== false ? 'checked' : ''}>
                <span>Cost Summary</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-reminders" ${savedPrefs.includeReminders !== false ? 'checked' : ''}>
                <span>Upcoming Reminders</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-history" ${savedPrefs.includeHistory !== false ? 'checked' : ''}>
                <span>Service History</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-servicesummary" ${savedPrefs.includeServiceSummary !== false ? 'checked' : ''}>
                <span>Service Type Summary</span>
              </label>
            </div>
          </div>
        </div>
        
        <div class="export-modal-footer">
          <button type="button" class="export-btn-secondary" onclick="closeVehicleReportExportModal()">Cancel</button>
          <button type="button" class="export-btn-primary" onclick="executeVehicleReportExport()">
            <i class="bi bi-download"></i> Export Report
          </button>
        </div>
      </div>
    </div>
  `;
  
  // Add modal to page
  document.body.insertAdjacentHTML('beforeend', modalHtml);
  
  // Setup event listeners
  setupExportModalListeners();
}

function setupExportModalListeners() {
  // Format selection highlighting
  document.querySelectorAll('input[name="export-format"]').forEach(radio => {
    radio.addEventListener('change', function() {
      document.querySelectorAll('.export-format-option').forEach(opt => opt.classList.remove('selected'));
      this.closest('.export-format-option').classList.add('selected');
      
      // Show/hide sections group for non-CSV formats
      const sectionsGroup = document.querySelector('.export-sections-group');
      if (this.value === 'csv') {
        sectionsGroup.style.display = 'none';
      } else {
        sectionsGroup.style.display = 'block';
      }
    });
  });
  
  // Date range custom toggle
  document.getElementById('export-date-range').addEventListener('change', function() {
    const customDates = document.getElementById('export-custom-dates');
    customDates.style.display = this.value === 'custom' ? 'flex' : 'none';
  });
  
  // "All Types" checkbox logic
  document.getElementById('export-type-all').addEventListener('change', function() {
    const typeItems = document.querySelectorAll('.export-type-item');
    typeItems.forEach(item => {
      item.checked = this.checked;
      item.disabled = this.checked;
    });
  });
  
  // Initial state for type checkboxes
  const allTypesChecked = document.getElementById('export-type-all').checked;
  if (allTypesChecked) {
    document.querySelectorAll('.export-type-item').forEach(item => {
      item.checked = true;
      item.disabled = true;
    });
  }
  
  // Close on overlay click
  document.getElementById('export-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) {
      closeVehicleReportExportModal();
    }
  });
  
  // Close on Escape key
  document.addEventListener('keydown', function escHandler(e) {
    if (e.key === 'Escape') {
      closeVehicleReportExportModal();
      document.removeEventListener('keydown', escHandler);
    }
  });
}

function closeVehicleReportExportModal() {
  const modal = document.getElementById('export-modal-overlay');
  if (modal) {
    modal.remove();
  }
}

function loadExportPreferences() {
  try {
    const saved = localStorage.getItem('gm_export_prefs');
    if (saved) {
      return JSON.parse(saved);
    }
  } catch (e) {}
  
  // Defaults
  return {
    format: 'pdf',
    dateRange: 'all',
    typeAll: true,
    types: ['service', 'repair', 'upgrade', 'tax'],
    includeVehicleInfo: true,
    includeCostSummary: true,
    includeReminders: true,
    includeHistory: true,
    includeServiceSummary: true
  };
}

function saveExportPreferences(prefs) {
  try {
    localStorage.setItem('gm_export_prefs', JSON.stringify(prefs));
  } catch (e) {}
}

function getExportOptions() {
  const format = document.querySelector('input[name="export-format"]:checked')?.value || 'pdf';
  const dateRange = document.getElementById('export-date-range').value;
  const dateFrom = document.getElementById('export-date-from')?.value || null;
  const dateTo = document.getElementById('export-date-to')?.value || null;
  
  const typeAll = document.getElementById('export-type-all').checked;
  const types = [];
  if (!typeAll) {
    document.querySelectorAll('.export-type-item:checked').forEach(cb => {
      types.push(cb.value);
    });
  }
  
  return {
    format,
    dateRange,
    dateFrom,
    dateTo,
    typeAll,
    types,
    includeVehicleInfo: document.getElementById('export-inc-vehicleinfo')?.checked ?? true,
    includeCostSummary: document.getElementById('export-inc-costsummary')?.checked ?? true,
    includeReminders: document.getElementById('export-inc-reminders')?.checked ?? true,
    includeHistory: document.getElementById('export-inc-history')?.checked ?? true,
    includeServiceSummary: document.getElementById('export-inc-servicesummary')?.checked ?? true
  };
}

function executeVehicleReportExport() {
  const options = getExportOptions();
  
  // Save preferences
  saveExportPreferences(options);
  
  // Close modal
  closeVehicleReportExportModal();
  
  // Execute export based on format
  switch (options.format) {
    case 'pdf':
      exportVehicleReportPDF(options);
      break;
    case 'word':
      exportVehicleReportWord(options);
      break;
    case 'xlsx':
      exportVehicleReportXLSX(options);
      break;
    case 'csv':
      exportVehicleReportCSV(options);
      break;
  }
}

// ========================================
// BRANDING HELPERS
// ========================================

function getAppBranding() {
  // Get branding from GM_CONFIG (set by PHP) or fallback
  const config = window.GM_CONFIG || window.APP_CONFIG || {};
  
  // User's custom site title from settings (if set)
  const customTitle = data?.settings?.siteTitle || null;
  
  return {
    appName: customTitle || config.appName || 'GarageMinder',
    appShortName: config.appShortName || 'GM',
    tagline: config.appTagline || 'Vehicle Maintenance Tracker',
    version: config.appVersion || '1.0',
    copyrightYear: config.copyrightYear || new Date().getFullYear(),
    logoUrl: 'assets/images/icon-64.png' // Relative path to logo
  };
}

// ========================================
// FILTER ENTRIES BY OPTIONS
// ========================================

function filterEntriesByOptions(entries, options) {
  let filtered = [...entries];
  
  // Date range filter
  if (options.dateRange !== 'all') {
    const today = getTodayDateInSettingsTz();
    let fromDate = null;
    
    switch (options.dateRange) {
      case 'year':
        fromDate = new Date(today);
        fromDate.setFullYear(fromDate.getFullYear() - 1);
        break;
      case '6months':
        fromDate = new Date(today);
        fromDate.setMonth(fromDate.getMonth() - 6);
        break;
      case '3months':
        fromDate = new Date(today);
        fromDate.setMonth(fromDate.getMonth() - 3);
        break;
      case 'ytd':
        fromDate = new Date(today.getFullYear(), 0, 1);
        break;
      case 'custom':
        if (options.dateFrom) {
          fromDate = new Date(options.dateFrom);
        }
        break;
    }
    
    let toDate = null;
    if (options.dateRange === 'custom' && options.dateTo) {
      toDate = new Date(options.dateTo);
    }
    
    filtered = filtered.filter(e => {
      if (!e.date) return true;
      const entryDate = new Date(e.date);
      if (fromDate && entryDate < fromDate) return false;
      if (toDate && entryDate > toDate) return false;
      return true;
    });
  }
  
  // Entry type filter (simplified - based on service names or notes)
  // This is a basic implementation - you might want to add an explicit "type" field to entries
  if (!options.typeAll && options.types.length > 0) {
    // For now, include all if any type is selected since we don't have explicit types
    // A more sophisticated implementation would categorize based on service names
  }
  
  return filtered;
}

// ========================================
// REPORT DATA BUILDER (with branding)
// ========================================

function buildVehicleReportData(options) {
  const branding = getAppBranding();
  const unit = getUnitShort();
  const unitFull = unit === 'km' ? 'Kilometers' : 'Miles';
  const today = getTodayDateInSettingsTz();
  const todayIso = getTodayIsoInSettingsTz();
  
  const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
  if (!vehicle) return null;
  
  let entries = data.entries
    .filter(e => e.vehicleId === activeVehicleId)
    .sort((a, b) => (a.date || '').localeCompare(b.date || ''));
  
  // Apply filters
  entries = filterEntriesByOptions(entries, options);
  
  const reminders = data.reminders.filter(r => r.vehicleId === activeVehicleId);
  
  // Build cost breakdown
  const costBreakdown = calculateCostBreakdown(entries);
  
  // Build stats
  const stats = calculateEnhancedStats(vehicle, entries, reminders);
  
  // Build timeline
  const timeline = entries.map((e, index) => {
    const services = normalizeServices(e.services || []);
    const totalCost = calculateEntryTotalCost(e);
    return {
      index: index + 1,
      date: e.date,
      dateFormatted: e.date ? formatDateNice(e.date) : 'No date',
      odometer: e.odo,
      odometerFormatted: e.odo != null ? e.odo.toLocaleString() + ' ' + unit : '–',
      services: services,
      serviceNames: services.map(s => s.name),
      serviceCosts: services.filter(s => s.cost != null).map(s => s.name + ': $' + s.cost.toFixed(2)).join('; '),
      totalCost,
      totalCostFormatted: totalCost > 0 ? '$' + totalCost.toFixed(2) : '–',
      notes: e.notes || ''
    };
  });
  
  // Build upcoming reminders
  const upcomingReminders = reminders
    .map(r => {
      const derived = computeReminderDerived(r, vehicle.currentOdo);
      return {
        serviceName: r.serviceName || r.title || 'Reminder',
        status: derived.level,
        statusLabel: derived.label,
        nextOdo: derived.nextOdo,
        nextDate: derived.nextDate,
        nextDateFormatted: derived.nextDate ? formatDateNice(derived.nextDate) : null,
        urgency: derived.level === 'overdue' ? 0 : (derived.level === 'upcoming' ? 1 : 2)
      };
    })
    .sort((a, b) => a.urgency - b.urgency)
    .slice(0, 5);
  
  // Build service summary
  const serviceSummary = buildServiceSummary(entries);
  
  return {
    branding,
    reportDate: todayIso,
    reportDateFormatted: formatDateNice(todayIso),
    reportTime: new Date().toLocaleTimeString(),
    unit,
    unitFull,
    vehicle: {
      id: vehicle.id,
      name: vehicle.name,
      vin: vehicle.vin || 'Not Recorded',
      plate: vehicle.plate || 'Not Recorded',
      currentOdo: vehicle.currentOdo,
      photo: vehicle.photo || null
    },
    costBreakdown,
    stats,
    timeline,
    upcomingReminders,
    serviceSummary,
    recordCount: timeline.length,
    options
  };
}

// Cost breakdown calculation (reuse from v2 or define here)
function calculateCostBreakdown(entries) {
  let partsCost = 0;
  let laborCost = 0;
  let miscCost = 0;
  
  entries.forEach(entry => {
    if (Array.isArray(entry.services)) {
      entry.services.forEach(s => {
        if (typeof s === 'object' && s.cost != null) {
          partsCost += Number(s.cost) || 0;
        }
      });
    }
    if (entry.cost != null) {
      miscCost += Number(entry.cost) || 0;
    }
    if (entry.laborCost != null) {
      laborCost += Number(entry.laborCost) || 0;
    }
  });
  
  const total = partsCost + laborCost + miscCost;
  
  return {
    parts: partsCost,
    labor: laborCost,
    misc: miscCost,
    total: total,
    partsFormatted: '$' + partsCost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}),
    laborFormatted: '$' + laborCost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}),
    miscFormatted: '$' + miscCost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}),
    totalFormatted: '$' + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})
  };
}

// Enhanced stats calculation
function calculateEnhancedStats(vehicle, entries, reminders) {
  const unit = getUnitShort();
  const today = getTodayDateInSettingsTz();
  const currentYear = today.getFullYear();
  
  let totalCost = 0, ytdCost = 0;
  let firstDate = null, lastDate = null;
  let firstOdo = null, lastOdo = null;
  let serviceCount = 0;
  const serviceTypeCounts = {};
  
  entries.forEach(e => {
    serviceCount++;
    const cost = calculateEntryTotalCost(e);
    totalCost += cost;
    
    if (e.date) {
      if (!firstDate || e.date < firstDate) {
        firstDate = e.date;
        firstOdo = e.odo;
      }
      if (!lastDate || e.date > lastDate) {
        lastDate = e.date;
        lastOdo = e.odo;
      }
      const year = parseInt(e.date.substring(0, 4), 10);
      if (year === currentYear) ytdCost += cost;
    }
    
    const services = normalizeServices(e.services || []);
    services.forEach(s => {
      serviceTypeCounts[s.name] = (serviceTypeCounts[s.name] || 0) + 1;
    });
  });
  
  const distanceTracked = (lastOdo && firstOdo) ? lastOdo - firstOdo : 0;
  
  let yearsTracked = 0, monthsTracked = 0;
  if (firstDate && lastDate) {
    const first = new Date(firstDate);
    const last = new Date(lastDate);
    const diffMs = last - first;
    yearsTracked = Math.floor(diffMs / (1000 * 60 * 60 * 24 * 365));
    monthsTracked = Math.floor(diffMs / (1000 * 60 * 60 * 24 * 30));
  }
  
  const avgPerYear = yearsTracked > 0 ? Math.round(distanceTracked / yearsTracked) : distanceTracked;
  const avgCostPerService = serviceCount > 0 ? totalCost / serviceCount : 0;
  const costPerMile = distanceTracked > 0 ? totalCost / distanceTracked : 0;
  
  let overdueCount = 0, upcomingCount = 0;
  reminders.forEach(r => {
    const derived = computeReminderDerived(r, vehicle.currentOdo);
    if (derived.level === 'overdue') overdueCount++;
    else if (derived.level === 'upcoming') upcomingCount++;
  });
  
  return {
    totalCost,
    ytdCost,
    avgCostPerService,
    costPerMile,
    firstDate,
    firstDateFormatted: firstDate ? formatDateNice(firstDate) : null,
    lastDate,
    lastDateFormatted: lastDate ? formatDateNice(lastDate) : null,
    firstOdo,
    lastOdo,
    distanceTracked,
    yearsTracked,
    monthsTracked,
    avgPerYear,
    serviceCount,
    overdueCount,
    upcomingCount,
    totalReminders: reminders.length,
    uniqueServiceTypes: Object.keys(serviceTypeCounts).length
  };
}

// ========================================
// CSV EXPORT
// ========================================

function exportVehicleReportCSV(options) {
  const rpt = buildVehicleReportData(options);
  if (!rpt) return;
  
  const branding = rpt.branding;
  const unit = rpt.unit;
  const v = rpt.vehicle;
  const safeName = v.name.replace(/[^\w]+/g, "_").toLowerCase();
  
  const lines = [];
  
  // Header with branding
  lines.push(branding.appName + ' - Vehicle Maintenance Report');
  lines.push('Generated: ' + rpt.reportDateFormatted);
  lines.push('');
  
  // Vehicle info
  lines.push('Vehicle Information');
  lines.push('Vehicle Name,' + v.name);
  lines.push('VIN,' + v.vin);
  lines.push('License Plate,' + v.plate);
  lines.push('Current Odometer,' + (v.currentOdo != null ? v.currentOdo.toLocaleString() + ' ' + unit : 'Not recorded'));
  lines.push('');
  
  // Cost summary
  lines.push('Cost Summary');
  lines.push('Total Cost,' + rpt.costBreakdown.totalFormatted);
  lines.push('Parts/Services,' + rpt.costBreakdown.partsFormatted);
  lines.push('Misc/Fees,' + rpt.costBreakdown.miscFormatted);
  lines.push('');
  
  // Service history header
  const headers = ['Date', 'Odometer (' + unit + ')', 'Services', 'Service Costs', 'Total Cost', 'Notes'];
  lines.push('Service History');
  lines.push(headers.join(','));
  
  // Service history rows (reverse chronological)
  rpt.timeline.slice().reverse().forEach(entry => {
    const row = [
      entry.dateFormatted,
      entry.odometer != null ? entry.odometer : '',
      '"' + entry.serviceNames.join('; ').replace(/"/g, '""') + '"',
      '"' + entry.serviceCosts.replace(/"/g, '""') + '"',
      entry.totalCost > 0 ? entry.totalCost.toFixed(2) : '',
      '"' + (entry.notes || '').replace(/"/g, '""') + '"'
    ];
    lines.push(row.join(','));
  });
  
  lines.push('');
  lines.push('Report generated by ' + branding.appName);
  
  // Create and download file
  const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = branding.appName.toLowerCase().replace(/\s+/g, '-') + '-' + safeName + '-report.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  
  showToast('CSV report exported');
}

// ========================================
// XLSX EXPORT (Better formatting)
// ========================================

function exportVehicleReportXLSX(options) {
  const rpt = buildVehicleReportData(options);
  if (!rpt) return;
  
  // Check for SheetJS
  if (!window.XLSX) {
    alert('Excel export requires SheetJS library. Falling back to CSV.');
    exportVehicleReportCSV(options);
    return;
  }
  
  const branding = rpt.branding;
  const unit = rpt.unit;
  const v = rpt.vehicle;
  const safeName = v.name.replace(/[^\w]+/g, "_").toLowerCase();
  
  // Create workbook
  const wb = XLSX.utils.book_new();
  
  // Build data array for the sheet
  const wsData = [];
  
  // Header rows
  wsData.push([branding.appName + ' - Vehicle Maintenance Report']);
  wsData.push(['Generated: ' + rpt.reportDateFormatted]);
  wsData.push([]);
  
  // Vehicle info
  wsData.push(['Vehicle Information']);
  wsData.push(['Vehicle Name', v.name]);
  wsData.push(['VIN', v.vin]);
  wsData.push(['License Plate', v.plate]);
  wsData.push(['Current Odometer', v.currentOdo != null ? v.currentOdo.toLocaleString() + ' ' + unit : 'Not recorded']);
  wsData.push([]);
  
  // Cost summary
  wsData.push(['Cost Summary']);
  wsData.push(['Total Cost', rpt.costBreakdown.total]);
  wsData.push(['Parts/Services', rpt.costBreakdown.parts]);
  wsData.push(['Misc/Fees', rpt.costBreakdown.misc]);
  wsData.push([]);
  
  // Service history
  wsData.push(['Service History']);
  wsData.push(['Date', 'Odometer (' + unit + ')', 'Services', 'Service Costs', 'Total Cost', 'Notes']);
  
  rpt.timeline.slice().reverse().forEach(entry => {
    wsData.push([
      entry.dateFormatted,
      entry.odometer,
      entry.serviceNames.join('; '),
      entry.serviceCosts,
      entry.totalCost > 0 ? entry.totalCost : '',
      entry.notes || ''
    ]);
  });
  
  // Create worksheet
  const ws = XLSX.utils.aoa_to_sheet(wsData);
  
  // Set column widths
  ws['!cols'] = [
    { wch: 14 },  // Date
    { wch: 14 },  // Odometer
    { wch: 40 },  // Services
    { wch: 30 },  // Service Costs
    { wch: 12 },  // Total Cost
    { wch: 40 }   // Notes
  ];
  
  // Add worksheet to workbook
  XLSX.utils.book_append_sheet(wb, ws, 'Vehicle Report');
  
  // Generate and download
  const filename = branding.appName.toLowerCase().replace(/\s+/g, '-') + '-' + safeName + '-report.xlsx';
  XLSX.writeFile(wb, filename);
  
  showToast('Excel report exported');
}

// ========================================
// PDF EXPORT (B&W with color logo)
// ========================================

async function exportVehicleReportPDF(options) {
  const rpt = buildVehicleReportData(options);
  if (!rpt) return;
  
  if (!window.jspdf || !window.jspdf.jsPDF) {
    alert('PDF export requires jsPDF library.');
    return;
  }
  
  const branding = rpt.branding;
  const safeName = rpt.vehicle.name.replace(/[^\w]+/g, "_").toLowerCase();
  
  // Try to load logo
  let logoData = null;
  try {
    logoData = await loadImageAsDataUrl(branding.logoUrl);
  } catch (e) {
    console.warn('Could not load logo:', e);
  }
  
  // Try to load vehicle photo
  let vehiclePhotoData = null;
  if (rpt.vehicle.photo && options.includeVehicleInfo) {
    try {
      vehiclePhotoData = await loadImageAsDataUrl(rpt.vehicle.photo);
    } catch (e) {
      console.warn('Could not load vehicle photo:', e);
    }
  }
  
  generateBWPDF(rpt, safeName, logoData, vehiclePhotoData);
}

async function loadImageAsDataUrl(url) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      const canvas = document.createElement('canvas');
      canvas.width = img.width;
      canvas.height = img.height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      resolve(canvas.toDataURL('image/png'));
    };
    img.onerror = reject;
    img.src = url;
  });
}

function generateBWPDF(rpt, safeName, logoData, vehiclePhotoData) {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'letter' });
  
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const margin = 15;
  const contentWidth = pageWidth - (margin * 2);
  
  const branding = rpt.branding;
  const unit = rpt.unit;
  const v = rpt.vehicle;
  const s = rpt.stats;
  const cost = rpt.costBreakdown;
  const opts = rpt.options;
  
  // B&W Color palette
  const black = [0, 0, 0];
  const darkGray = [51, 51, 51];
  const mediumGray = [102, 102, 102];
  const lightGray = [153, 153, 153];
  const veryLightGray = [230, 230, 230];
  const white = [255, 255, 255];
  
  let y = 0;
  
  // ========================================
  // HEADER WITH LOGO
  // ========================================
  
  // Top line
  doc.setDrawColor(...black);
  doc.setLineWidth(0.5);
  doc.line(margin, 10, pageWidth - margin, 10);
  
  y = 18;
  
  // Logo (in color)
  if (logoData) {
    try {
      doc.addImage(logoData, 'PNG', margin, y - 4, 10, 10);
    } catch (e) {}
  }
  
  // App name
  const logoOffset = logoData ? 14 : 0;
  doc.setFontSize(18);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(...black);
  doc.text(branding.appName, margin + logoOffset, y + 2);
  
  // Tagline
  doc.setFontSize(9);
  doc.setFont('helvetica', 'normal');
  doc.setTextColor(...mediumGray);
  doc.text('Vehicle Maintenance Report', margin + logoOffset, y + 8);
  
  // Report info (right side)
  doc.setFontSize(8);
  doc.setTextColor(...darkGray);
  doc.text('Report Date: ' + rpt.reportDateFormatted, pageWidth - margin, y, { align: 'right' });
  doc.text(rpt.recordCount + ' Service Records', pageWidth - margin, y + 5, { align: 'right' });
  
  // Separator line
  y = 32;
  doc.setDrawColor(...veryLightGray);
  doc.setLineWidth(0.3);
  doc.line(margin, y, pageWidth - margin, y);
  
  y = 38;
  
  // ========================================
  // VEHICLE INFORMATION SECTION
  // ========================================
  
  if (opts.includeVehicleInfo) {
    // Section header
    doc.setFillColor(...black);
    doc.rect(margin, y, contentWidth, 6, 'F');
    doc.setTextColor(...white);
    doc.setFontSize(9);
    doc.setFont('helvetica', 'bold');
    doc.text('Vehicle Information', margin + 3, y + 4.5);
    y += 8;
    
    // Vehicle info box
    const infoBoxHeight = vehiclePhotoData ? 35 : 25;
    doc.setDrawColor(...veryLightGray);
    doc.setLineWidth(0.3);
    doc.rect(margin, y, contentWidth, infoBoxHeight, 'S');
    
    // Vehicle photo (if available)
    const photoWidth = vehiclePhotoData ? 35 : 0;
    const infoStartX = margin + photoWidth + (vehiclePhotoData ? 8 : 5);
    
    if (vehiclePhotoData) {
      try {
        doc.addImage(vehiclePhotoData, 'JPEG', margin + 3, y + 3, 32, 24);
      } catch (e) {}
    }
    
    // Vehicle name
    doc.setFontSize(13);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(...black);
    doc.text(v.name, infoStartX, y + 8);
    
    // Details grid
    const detailY = y + 14;
    const colWidth = (contentWidth - photoWidth - 15) / 3;
    
    const vehicleDetails = [
      { label: 'VIN', value: v.vin },
      { label: 'LICENSE PLATE', value: v.plate },
      { label: 'CURRENT ODOMETER', value: v.currentOdo != null ? v.currentOdo.toLocaleString() + ' ' + unit : 'Not recorded' }
    ];
    
    vehicleDetails.forEach((detail, i) => {
      const x = infoStartX + (i * colWidth);
      doc.setFontSize(6);
      doc.setTextColor(...lightGray);
      doc.setFont('helvetica', 'normal');
      doc.text(detail.label, x, detailY);
      doc.setFontSize(8);
      doc.setTextColor(...darkGray);
      doc.setFont('helvetica', 'bold');
      const displayValue = detail.value.length > 20 ? detail.value.substring(0, 20) + '...' : detail.value;
      doc.text(displayValue, x, detailY + 4);
    });
    
    // Second row of details
    if (s.firstDate || s.distanceTracked > 0) {
      const detailY2 = detailY + 12;
      const details2 = [
        { label: 'HISTORY SPAN', value: s.yearsTracked > 0 ? s.yearsTracked + ' year' + (s.yearsTracked > 1 ? 's' : '') : (s.monthsTracked > 0 ? s.monthsTracked + ' months' : '–') },
        { label: 'DISTANCE TRACKED', value: s.distanceTracked.toLocaleString() + ' ' + unit },
        { label: 'AVG. ANNUAL', value: s.avgPerYear.toLocaleString() + ' ' + unit + '/yr' }
      ];
      
      details2.forEach((detail, i) => {
        const x = infoStartX + (i * colWidth);
        doc.setFontSize(6);
        doc.setTextColor(...lightGray);
        doc.setFont('helvetica', 'normal');
        doc.text(detail.label, x, detailY2);
        doc.setFontSize(8);
        doc.setTextColor(...darkGray);
        doc.setFont('helvetica', 'bold');
        doc.text(detail.value, x, detailY2 + 4);
      });
    }
    
    y += infoBoxHeight + 6;
  }
  
  // ========================================
  // COST SUMMARY SECTION
  // ========================================
  
  if (opts.includeCostSummary) {
    // Section header
    doc.setFillColor(...black);
    doc.rect(margin, y, contentWidth, 6, 'F');
    doc.setTextColor(...white);
    doc.setFontSize(9);
    doc.setFont('helvetica', 'bold');
    doc.text('Cost Summary', margin + 3, y + 4.5);
    y += 8;
    
    // Cost cards
    const cardWidth = (contentWidth - 9) / 4;
    const costItems = [
      { label: 'Parts/Services', value: cost.partsFormatted },
      { label: 'Labor', value: cost.laborFormatted },
      { label: 'Misc/Fees', value: cost.miscFormatted },
      { label: 'TOTAL', value: cost.totalFormatted, bold: true }
    ];
    
    costItems.forEach((item, i) => {
      const x = margin + (i * (cardWidth + 3));
      
      // Card border
      doc.setDrawColor(...veryLightGray);
      doc.rect(x, y, cardWidth, 16, 'S');
      
      // Label
      doc.setFontSize(6);
      doc.setTextColor(...lightGray);
      doc.setFont('helvetica', 'normal');
      doc.text(item.label.toUpperCase(), x + cardWidth / 2, y + 5, { align: 'center' });
      
      // Value
      doc.setFontSize(item.bold ? 11 : 10);
      doc.setTextColor(...(item.bold ? black : darkGray));
      doc.setFont('helvetica', item.bold ? 'bold' : 'normal');
      doc.text(item.value, x + cardWidth / 2, y + 12, { align: 'center' });
    });
    
    y += 20;
    
    // Additional stats
    const statItems = [
      { label: 'Year-to-Date', value: '$' + s.ytdCost.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}) },
      { label: 'Avg per Service', value: '$' + s.avgCostPerService.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}) },
      { label: 'Cost per ' + (unit === 'km' ? 'km' : 'mile'), value: '$' + s.costPerMile.toFixed(2) },
      { label: 'Service Types', value: s.uniqueServiceTypes.toString() }
    ];
    
    statItems.forEach((item, i) => {
      const x = margin + (i * (cardWidth + 3));
      doc.setFontSize(6);
      doc.setTextColor(...lightGray);
      doc.text(item.label, x + cardWidth / 2, y, { align: 'center' });
      doc.setFontSize(9);
      doc.setTextColor(...darkGray);
      doc.setFont('helvetica', 'bold');
      doc.text(item.value, x + cardWidth / 2, y + 5, { align: 'center' });
    });
    
    y += 12;
  }
  
  // ========================================
  // UPCOMING REMINDERS SECTION
  // ========================================
  
  if (opts.includeReminders && rpt.upcomingReminders.length > 0) {
    // Section header
    doc.setFillColor(...black);
    doc.rect(margin, y, contentWidth, 6, 'F');
    doc.setTextColor(...white);
    doc.setFontSize(9);
    doc.setFont('helvetica', 'bold');
    doc.text('Upcoming Maintenance', margin + 3, y + 4.5);
    
    // Status badge
    const statusText = s.overdueCount > 0 ? s.overdueCount + ' Overdue' : 'All OK';
    doc.setFontSize(7);
    const badgeWidth = doc.getTextWidth(statusText) + 6;
    doc.setFillColor(...(s.overdueCount > 0 ? darkGray : lightGray));
    doc.roundedRect(pageWidth - margin - badgeWidth - 3, y + 1, badgeWidth, 4, 1, 1, 'F');
    doc.setTextColor(...white);
    doc.text(statusText, pageWidth - margin - badgeWidth / 2 - 3, y + 3.8, { align: 'center' });
    
    y += 8;
    
    // Reminders list
    rpt.upcomingReminders.forEach((reminder, i) => {
      const rowY = y + (i * 6);
      
      // Status indicator
      const dotFill = reminder.status === 'overdue' ? black : (reminder.status === 'upcoming' ? mediumGray : lightGray);
      doc.setFillColor(...dotFill);
      doc.circle(margin + 3, rowY + 2, 1.2, 'F');
      
      // Service name
      doc.setFontSize(8);
      doc.setTextColor(...darkGray);
      doc.setFont('helvetica', 'normal');
      doc.text(reminder.serviceName, margin + 8, rowY + 3);
      
      // Due info
      doc.setFontSize(7);
      doc.setTextColor(...mediumGray);
      const dueText = reminder.nextDateFormatted || (reminder.nextOdo ? reminder.nextOdo.toLocaleString() + ' ' + unit : '–');
      doc.text(dueText, pageWidth - margin, rowY + 3, { align: 'right' });
    });
    
    y += (rpt.upcomingReminders.length * 6) + 6;
  }
  
  // ========================================
  // SERVICE HISTORY TABLE
  // ========================================
  
  if (opts.includeHistory && rpt.timeline.length > 0) {
    // Section header
    doc.setFillColor(...black);
    doc.rect(margin, y, contentWidth, 6, 'F');
    doc.setTextColor(...white);
    doc.setFontSize(9);
    doc.setFont('helvetica', 'bold');
    doc.text('Service History', margin + 3, y + 4.5);
    y += 8;
    
    // Table
    const headers = ['Date', 'Odometer', 'Services', 'Cost', 'Notes'];
    const rows = rpt.timeline.slice().reverse().map(entry => [
      entry.dateFormatted,
      entry.odometerFormatted,
      entry.serviceNames.join(', ') || '–',
      entry.totalCostFormatted,
      entry.notes ? (entry.notes.length > 30 ? entry.notes.substring(0, 30) + '...' : entry.notes) : '–'
    ]);
    
    doc.autoTable({
      startY: y,
      head: [headers],
      body: rows,
      styles: {
        fontSize: 7,
        cellPadding: 2.5,
        textColor: darkGray,
        lineColor: veryLightGray,
        lineWidth: 0.2
      },
      headStyles: {
        fillColor: veryLightGray,
        textColor: darkGray,
        fontStyle: 'bold',
        fontSize: 6
      },
      columnStyles: {
        0: { cellWidth: 22 },
        1: { cellWidth: 24 },
        2: { cellWidth: 65 },
        3: { cellWidth: 20, halign: 'right', fontStyle: 'bold' },
        4: { cellWidth: 44 }
      },
      alternateRowStyles: {
        fillColor: [252, 252, 252]
      },
      margin: { left: margin, right: margin },
      didDrawPage: function(data) {
        // Footer on each page
        doc.setFontSize(6);
        doc.setTextColor(...mediumGray);
        const pageNum = doc.internal.getCurrentPageInfo().pageNumber;
        doc.text('Page ' + pageNum, pageWidth / 2, pageHeight - 8, { align: 'center' });
        doc.text(v.name + ' | VIN: ' + v.vin, margin, pageHeight - 8);
        doc.text(branding.appName, pageWidth - margin, pageHeight - 8, { align: 'right' });
        
        // Header line on subsequent pages
        if (pageNum > 1) {
          doc.setDrawColor(...black);
          doc.setLineWidth(0.3);
          doc.line(margin, 8, pageWidth - margin, 8);
          doc.setFontSize(8);
          doc.setTextColor(...darkGray);
          doc.text(branding.appName + ' - ' + v.name, margin, 14);
        }
      }
    });
    
    y = doc.lastAutoTable.finalY + 6;
  }
  
  // ========================================
  // SERVICE TYPE SUMMARY
  // ========================================
  
  if (opts.includeServiceSummary && rpt.serviceSummary.length > 0 && y < pageHeight - 60) {
    doc.setFillColor(...black);
    doc.rect(margin, y, contentWidth, 6, 'F');
    doc.setTextColor(...white);
    doc.setFontSize(9);
    doc.setFont('helvetica', 'bold');
    doc.text('Service Type Summary', margin + 3, y + 4.5);
    y += 8;
    
    const summaryHeaders = ['Service Type', 'Count', 'Total Cost', 'Last Performed'];
    const summaryRows = rpt.serviceSummary.slice(0, 10).map(svc => [
      svc.name,
      svc.count.toString(),
      '$' + svc.totalCost.toFixed(2),
      svc.lastPerformedFormatted || '–'
    ]);
    
    doc.autoTable({
      startY: y,
      head: [summaryHeaders],
      body: summaryRows,
      styles: {
        fontSize: 7,
        cellPadding: 2,
        textColor: darkGray,
        lineColor: veryLightGray,
        lineWidth: 0.2
      },
      headStyles: {
        fillColor: veryLightGray,
        textColor: darkGray,
        fontStyle: 'bold',
        fontSize: 6
      },
      columnStyles: {
        0: { cellWidth: 60 },
        1: { cellWidth: 18, halign: 'center' },
        2: { cellWidth: 28, halign: 'right', fontStyle: 'bold' },
        3: { cellWidth: 35 }
      },
      margin: { left: margin, right: margin }
    });
    
    y = doc.lastAutoTable.finalY + 6;
  }
  
  // ========================================
  // FOOTER / DISCLAIMER
  // ========================================
  
  if (y < pageHeight - 25) {
    doc.setDrawColor(...veryLightGray);
    doc.setLineWidth(0.3);
    doc.rect(margin, y, contentWidth, 14, 'S');
    
    doc.setFontSize(6);
    doc.setTextColor(...mediumGray);
    doc.setFont('helvetica', 'normal');
    doc.text('This report is generated from records in ' + branding.appName + '. It may not include all maintenance performed on this vehicle.', margin + 3, y + 4);
    doc.text('For vehicle purchase decisions, always verify records and perform an independent inspection.', margin + 3, y + 8);
    doc.text('Report generated: ' + rpt.reportDateFormatted + ' at ' + rpt.reportTime, margin + 3, y + 12);
  }
  
  // Save
  const filename = branding.appName.toLowerCase().replace(/\s+/g, '-') + '-' + safeName + '-report.pdf';
  doc.save(filename);
  showToast('PDF report exported');
}

// ========================================
// WORD EXPORT (Matches PDF design)
// ========================================

function exportVehicleReportWord(options) {
  const rpt = buildVehicleReportData(options);
  if (!rpt) return;
  
  const branding = rpt.branding;
  const unit = rpt.unit;
  const v = rpt.vehicle;
  const s = rpt.stats;
  const cost = rpt.costBreakdown;
  const opts = rpt.options;
  const safeName = v.name.replace(/[^\w]+/g, "_").toLowerCase();
  
  // Build HTML document that Word can open
  let html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>${escapeHtml(branding.appName)} - Vehicle Report - ${escapeHtml(v.name)}</title>
<style>
@page { margin: 0.75in; size: letter; }
* { box-sizing: border-box; }
body { 
  font-family: Arial, Helvetica, sans-serif; 
  font-size: 10pt; 
  line-height: 1.4; 
  color: #333; 
  margin: 0; 
  padding: 0;
  background: #fff;
}
.header { 
  border-bottom: 2px solid #000; 
  padding-bottom: 10px; 
  margin-bottom: 15px;
  display: table;
  width: 100%;
}
.header-left { display: table-cell; vertical-align: top; }
.header-right { display: table-cell; vertical-align: top; text-align: right; }
.app-name { font-size: 18pt; font-weight: bold; color: #000; }
.app-tagline { font-size: 9pt; color: #666; }
.report-meta { font-size: 8pt; color: #333; }
.record-badge { 
  display: inline-block;
  background: #333; 
  color: #fff; 
  padding: 3px 10px; 
  font-size: 8pt;
  font-weight: bold;
  margin-top: 5px;
}
.section { margin: 15px 0; page-break-inside: avoid; }
.section-header { 
  background: #000; 
  color: #fff; 
  padding: 6px 10px; 
  font-size: 10pt; 
  font-weight: bold;
}
.section-body { 
  border: 1px solid #ddd; 
  border-top: none; 
  padding: 12px;
}
.vehicle-info { display: table; width: 100%; }
.vehicle-name { font-size: 14pt; font-weight: bold; color: #000; margin-bottom: 10px; }
.info-grid { display: table; width: 100%; }
.info-row { display: table-row; }
.info-cell { display: table-cell; width: 33%; padding: 5px 10px 5px 0; vertical-align: top; }
.info-label { font-size: 7pt; color: #999; text-transform: uppercase; letter-spacing: 0.3px; }
.info-value { font-size: 9pt; color: #333; font-weight: bold; }
.cost-grid { display: table; width: 100%; border-collapse: separate; border-spacing: 5px; }
.cost-card { 
  display: table-cell; 
  width: 25%; 
  border: 1px solid #ddd; 
  padding: 8px; 
  text-align: center;
  vertical-align: top;
}
.cost-card.total { background: #f5f5f5; }
.cost-label { font-size: 7pt; color: #999; text-transform: uppercase; }
.cost-value { font-size: 12pt; color: #333; margin-top: 3px; }
.cost-card.total .cost-value { font-weight: bold; color: #000; font-size: 14pt; }
.stats-row { display: table; width: 100%; margin-top: 10px; }
.stat-item { display: table-cell; width: 25%; text-align: center; }
.stat-label { font-size: 7pt; color: #999; }
.stat-value { font-size: 9pt; color: #333; font-weight: bold; }
.reminder-row { padding: 5px 0; border-bottom: 1px solid #eee; }
.reminder-row:last-child { border-bottom: none; }
.reminder-dot { 
  display: inline-block; 
  width: 8px; 
  height: 8px; 
  border-radius: 50%; 
  margin-right: 8px;
  vertical-align: middle;
}
.reminder-dot.overdue { background: #000; }
.reminder-dot.upcoming { background: #666; }
.reminder-dot.ok { background: #ccc; }
.reminder-name { font-size: 9pt; color: #333; }
.reminder-due { float: right; font-size: 8pt; color: #666; }
table.data-table { 
  width: 100%; 
  border-collapse: collapse; 
  font-size: 9pt;
}
table.data-table th { 
  background: #f0f0f0; 
  padding: 6px 8px; 
  text-align: left; 
  font-size: 8pt; 
  text-transform: uppercase;
  color: #666;
  border-bottom: 1px solid #333;
  font-weight: bold;
}
table.data-table td { 
  padding: 6px 8px; 
  border-bottom: 1px solid #eee;
  vertical-align: top;
  color: #333;
}
table.data-table tr:nth-child(even) td { background: #fafafa; }
.cost-cell { font-weight: bold; text-align: right; }
.footer { 
  margin-top: 20px; 
  padding: 10px; 
  border: 1px solid #ddd;
  font-size: 7pt; 
  color: #666;
  line-height: 1.5;
}
</style>
</head>
<body>`;

  // Header
  html += `<div class="header">
    <div class="header-left">
      <div class="app-name">${escapeHtml(branding.appName)}</div>
      <div class="app-tagline">Vehicle Maintenance Report</div>
    </div>
    <div class="header-right">
      <div class="report-meta">Report Date: ${rpt.reportDateFormatted}</div>
      <div class="record-badge">${rpt.recordCount} RECORDS</div>
    </div>
  </div>`;

  // Vehicle Information
  if (opts.includeVehicleInfo) {
    html += `<div class="section">
      <div class="section-header">Vehicle Information</div>
      <div class="section-body">
        <div class="vehicle-name">${escapeHtml(v.name)}</div>
        <div class="info-grid">
          <div class="info-row">
            <div class="info-cell">
              <div class="info-label">VIN</div>
              <div class="info-value">${escapeHtml(v.vin)}</div>
            </div>
            <div class="info-cell">
              <div class="info-label">License Plate</div>
              <div class="info-value">${escapeHtml(v.plate)}</div>
            </div>
            <div class="info-cell">
              <div class="info-label">Current Odometer</div>
              <div class="info-value">${v.currentOdo != null ? v.currentOdo.toLocaleString() + ' ' + unit : 'Not recorded'}</div>
            </div>
          </div>
          <div class="info-row">
            <div class="info-cell">
              <div class="info-label">History Span</div>
              <div class="info-value">${s.yearsTracked > 0 ? s.yearsTracked + ' year' + (s.yearsTracked > 1 ? 's' : '') : (s.monthsTracked > 0 ? s.monthsTracked + ' months' : '–')}</div>
            </div>
            <div class="info-cell">
              <div class="info-label">Distance Tracked</div>
              <div class="info-value">${s.distanceTracked.toLocaleString()} ${unit}</div>
            </div>
            <div class="info-cell">
              <div class="info-label">Avg. Annual</div>
              <div class="info-value">${s.avgPerYear.toLocaleString()} ${unit}/yr</div>
            </div>
          </div>
        </div>
      </div>
    </div>`;
  }

  // Cost Summary
  if (opts.includeCostSummary) {
    html += `<div class="section">
      <div class="section-header">Cost Summary</div>
      <div class="section-body">
        <div class="cost-grid">
          <div class="cost-card">
            <div class="cost-label">Parts/Services</div>
            <div class="cost-value">${cost.partsFormatted}</div>
          </div>
          <div class="cost-card">
            <div class="cost-label">Labor</div>
            <div class="cost-value">${cost.laborFormatted}</div>
          </div>
          <div class="cost-card">
            <div class="cost-label">Misc/Fees</div>
            <div class="cost-value">${cost.miscFormatted}</div>
          </div>
          <div class="cost-card total">
            <div class="cost-label">Total</div>
            <div class="cost-value">${cost.totalFormatted}</div>
          </div>
        </div>
        <div class="stats-row">
          <div class="stat-item">
            <div class="stat-label">Year-to-Date</div>
            <div class="stat-value">$${s.ytdCost.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0})}</div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Avg per Service</div>
            <div class="stat-value">$${s.avgCostPerService.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0})}</div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Cost per ${unit === 'km' ? 'km' : 'mile'}</div>
            <div class="stat-value">$${s.costPerMile.toFixed(2)}</div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Service Types</div>
            <div class="stat-value">${s.uniqueServiceTypes}</div>
          </div>
        </div>
      </div>
    </div>`;
  }

  // Upcoming Reminders
  if (opts.includeReminders && rpt.upcomingReminders.length > 0) {
    html += `<div class="section">
      <div class="section-header">Upcoming Maintenance</div>
      <div class="section-body">`;
    
    rpt.upcomingReminders.forEach(reminder => {
      const dueText = reminder.nextDateFormatted || (reminder.nextOdo ? reminder.nextOdo.toLocaleString() + ' ' + unit : '–');
      html += `<div class="reminder-row">
        <span class="reminder-dot ${reminder.status}"></span>
        <span class="reminder-name">${escapeHtml(reminder.serviceName)}</span>
        <span class="reminder-due">${dueText}</span>
      </div>`;
    });
    
    html += `</div></div>`;
  }

  // Service History
  if (opts.includeHistory && rpt.timeline.length > 0) {
    html += `<div class="section">
      <div class="section-header">Service History</div>
      <div class="section-body">
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:12%">Date</th>
              <th style="width:12%">Odometer</th>
              <th style="width:35%">Services</th>
              <th style="width:12%">Cost</th>
              <th style="width:29%">Notes</th>
            </tr>
          </thead>
          <tbody>`;
    
    rpt.timeline.slice().reverse().forEach(entry => {
      html += `<tr>
        <td>${entry.dateFormatted}</td>
        <td>${entry.odometerFormatted}</td>
        <td>${escapeHtml(entry.serviceNames.join(', ') || '–')}</td>
        <td class="cost-cell">${entry.totalCostFormatted}</td>
        <td>${escapeHtml(entry.notes ? (entry.notes.length > 40 ? entry.notes.substring(0, 40) + '...' : entry.notes) : '–')}</td>
      </tr>`;
    });
    
    html += `</tbody></table></div></div>`;
  }

  // Service Type Summary
  if (opts.includeServiceSummary && rpt.serviceSummary.length > 0) {
    html += `<div class="section">
      <div class="section-header">Service Type Summary</div>
      <div class="section-body">
        <table class="data-table">
          <thead>
            <tr>
              <th>Service Type</th>
              <th style="width:10%">Count</th>
              <th style="width:15%">Total Cost</th>
              <th style="width:20%">Last Performed</th>
            </tr>
          </thead>
          <tbody>`;
    
    rpt.serviceSummary.slice(0, 10).forEach(svc => {
      html += `<tr>
        <td>${escapeHtml(svc.name)}</td>
        <td style="text-align:center">${svc.count}</td>
        <td class="cost-cell">$${svc.totalCost.toFixed(2)}</td>
        <td>${svc.lastPerformedFormatted || '–'}</td>
      </tr>`;
    });
    
    html += `</tbody></table></div></div>`;
  }

  // Footer
  html += `<div class="footer">
    This report is generated from records in ${escapeHtml(branding.appName)}. It may not include all maintenance performed on this vehicle.
    For vehicle purchase decisions, always verify records and perform an independent inspection.<br>
    <em>Report generated: ${rpt.reportDateFormatted} at ${rpt.reportTime}</em>
  </div>`;

  html += `</body></html>`;

  // Download as .doc file
  const blob = new Blob([html], { type: 'application/msword' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = branding.appName.toLowerCase().replace(/\s+/g, '-') + '-' + safeName + '-report.doc';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  
  showToast('Word report exported');
}

// ========================================
// MAKE FUNCTIONS GLOBALLY AVAILABLE
// ========================================

window.openVehicleReportExportModal = openVehicleReportExportModal;
window.closeVehicleReportExportModal = closeVehicleReportExportModal;
window.executeVehicleReportExport = executeVehicleReportExport;
window.exportVehicleReportPDF = exportVehicleReportPDF;
window.exportVehicleReportWord = exportVehicleReportWord;
window.exportVehicleReportCSV = exportVehicleReportCSV;
window.exportVehicleReportXLSX = exportVehicleReportXLSX;
