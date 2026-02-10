/**
 * GarageMinder - Vehicle Report Export v2.1
 * Refined export system with comprehensive filtering
 * Clean straight borders, proper branding, full reminder details
 */

// ========================================
// EXPORT MODAL MANAGEMENT
// ========================================

function openVehicleReportExportModal() {
  if (!activeVehicleId || activeVehicleId === "all") {
    alert("Please select a specific vehicle first (not 'All Vehicles').");
    return;
  }
  
  const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
  if (!vehicle) {
    alert("Vehicle not found.");
    return;
  }
  
  const entries = data.entries.filter(e => e.vehicleId === activeVehicleId);
  if (!entries.length) {
    alert('No service records to export for "' + vehicle.name + '".');
    return;
  }
  
  const savedPrefs = loadExportPreferences();
  
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
          
          <!-- Report Sections (PDF/Word only) -->
          <div class="export-section export-sections-group">
            <label class="export-section-label">Report Sections <small>(PDF/Word)</small></label>
            <div class="export-checkbox-group">
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-vehicleinfo" ${savedPrefs.includeVehicleInfo !== false ? 'checked' : ''}>
                <span>Vehicle Information</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-renewals" ${savedPrefs.includeRenewals !== false ? 'checked' : ''}>
                <span>Renewal Dates</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-costsummary" ${savedPrefs.includeCostSummary === true ? 'checked' : ''}>
                <span>Cost Summary</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-reminders" ${savedPrefs.includeReminders !== false ? 'checked' : ''}>
                <span>Upcoming Maintenance</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-history" ${savedPrefs.includeHistory !== false ? 'checked' : ''}>
                <span>Service History</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-inc-servicesummary" ${savedPrefs.includeServiceSummary === true ? 'checked' : ''}>
                <span>Service Type Summary</span>
              </label>
            </div>
          </div>
          
          <!-- Vehicle Details Filter (PDF/Word only) -->
          <div class="export-section export-sections-group">
            <label class="export-section-label">Vehicle Details to Include <small>(PDF/Word)</small></label>
            <div class="export-checkbox-group">
              <label class="export-checkbox">
                <input type="checkbox" id="export-vd-vin" ${savedPrefs.showVin !== false ? 'checked' : ''}>
                <span>VIN</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-vd-plate" ${savedPrefs.showPlate !== false ? 'checked' : ''}>
                <span>License Plate</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-vd-odometer" ${savedPrefs.showOdometer !== false ? 'checked' : ''}>
                <span>Odometer</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-vd-yearMakeModel" ${savedPrefs.showYearMakeModel !== false ? 'checked' : ''}>
                <span>Year/Make/Model</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-vd-engine" ${savedPrefs.showEngine === true ? 'checked' : ''}>
                <span>Engine</span>
              </label>
              <label class="export-checkbox">
                <input type="checkbox" id="export-vd-stats" ${savedPrefs.showStats !== false ? 'checked' : ''}>
                <span>History Stats</span>
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
  
  document.body.insertAdjacentHTML('beforeend', modalHtml);
  setupExportModalListeners();
}

function setupExportModalListeners() {
  document.querySelectorAll('input[name="export-format"]').forEach(radio => {
    radio.addEventListener('change', function() {
      document.querySelectorAll('.export-format-option').forEach(opt => opt.classList.remove('selected'));
      this.closest('.export-format-option').classList.add('selected');
      const sectionsGroups = document.querySelectorAll('.export-sections-group');
      sectionsGroups.forEach(group => {
        group.style.display = (this.value === 'csv') ? 'none' : 'block';
      });
    });
  });
  
  document.getElementById('export-date-range').addEventListener('change', function() {
    const customDates = document.getElementById('export-custom-dates');
    customDates.style.display = this.value === 'custom' ? 'flex' : 'none';
  });
  
  document.getElementById('export-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeVehicleReportExportModal();
  });
  
  const escHandler = function(e) {
    if (e.key === 'Escape') {
      closeVehicleReportExportModal();
      document.removeEventListener('keydown', escHandler);
    }
  };
  document.addEventListener('keydown', escHandler);
}

function closeVehicleReportExportModal() {
  const modal = document.getElementById('export-modal-overlay');
  if (modal) modal.remove();
}

function loadExportPreferences() {
  try {
    const saved = localStorage.getItem('gm_export_prefs_v2');
    if (saved) return JSON.parse(saved);
  } catch (e) {}
  return {
    format: 'pdf', dateRange: 'all',
    includeVehicleInfo: true, includeRenewals: true, includeCostSummary: false,
    includeReminders: true, includeHistory: true, includeServiceSummary: false,
    showVin: true, showPlate: true, showOdometer: true,
    showYearMakeModel: true, showEngine: false, showStats: true
  };
}

function saveExportPreferences(prefs) {
  try { localStorage.setItem('gm_export_prefs_v2', JSON.stringify(prefs)); } catch (e) {}
}

function getExportOptions() {
  const format = document.querySelector('input[name="export-format"]:checked')?.value || 'pdf';
  const dateRange = document.getElementById('export-date-range').value;
  return {
    format, dateRange,
    dateFrom: document.getElementById('export-date-from')?.value || null,
    dateTo: document.getElementById('export-date-to')?.value || null,
    includeVehicleInfo: document.getElementById('export-inc-vehicleinfo')?.checked ?? true,
    includeRenewals: document.getElementById('export-inc-renewals')?.checked ?? true,
    includeCostSummary: document.getElementById('export-inc-costsummary')?.checked ?? false,
    includeReminders: document.getElementById('export-inc-reminders')?.checked ?? true,
    includeHistory: document.getElementById('export-inc-history')?.checked ?? true,
    includeServiceSummary: document.getElementById('export-inc-servicesummary')?.checked ?? false,
    showVin: document.getElementById('export-vd-vin')?.checked ?? true,
    showPlate: document.getElementById('export-vd-plate')?.checked ?? true,
    showOdometer: document.getElementById('export-vd-odometer')?.checked ?? true,
    showYearMakeModel: document.getElementById('export-vd-yearMakeModel')?.checked ?? true,
    showEngine: document.getElementById('export-vd-engine')?.checked ?? false,
    showStats: document.getElementById('export-vd-stats')?.checked ?? true
  };
}

function executeVehicleReportExport() {
  const options = getExportOptions();
  saveExportPreferences(options);
  closeVehicleReportExportModal();
  switch (options.format) {
    case 'pdf': exportVehicleReportPDF(options); break;
    case 'word': exportVehicleReportWord(options); break;
    case 'xlsx': exportVehicleReportXLSX(options); break;
    case 'csv': exportVehicleReportCSV(options); break;
  }
}

// ========================================
// BRANDING & HELPERS
// ========================================

function getAppBranding() {
  const config = window.GM_CONFIG || window.APP_CONFIG || {};
  return {
    appName: config.appName || 'GarageMinder',
    appShortName: config.appShortName || 'GM',
    tagline: config.appTagline || 'Vehicle Maintenance Tracker',
    version: config.appVersion || '1.0',
    copyrightYear: config.copyrightYear || new Date().getFullYear(),
    logoUrl: 'assets/images/icon-64.png'
  };
}

function formatReminderDue(reminder, unit) {
  const parts = [];
  if (reminder.nextOdo != null) parts.push(reminder.nextOdo.toLocaleString() + ' ' + unit);
  if (reminder.nextDate) {
    const date = new Date(reminder.nextDate + 'T00:00:00');
    const today = new Date();
    const sameYear = date.getFullYear() === today.getFullYear();
    const options = sameYear ? { month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric', year: 'numeric' };
    parts.push(date.toLocaleDateString('en-US', options));
  }
  if (parts.length === 0) return '–';
  return parts.length === 2 ? parts.join(' or ') : parts[0];
}

function filterEntriesByOptions(entries, options) {
  let filtered = [...entries];
  if (options.dateRange !== 'all') {
    const today = getTodayDateInSettingsTz();
    let fromDate = null, toDate = null;
    switch (options.dateRange) {
      case 'year': fromDate = new Date(today); fromDate.setFullYear(fromDate.getFullYear() - 1); break;
      case '6months': fromDate = new Date(today); fromDate.setMonth(fromDate.getMonth() - 6); break;
      case '3months': fromDate = new Date(today); fromDate.setMonth(fromDate.getMonth() - 3); break;
      case 'ytd': fromDate = new Date(today.getFullYear(), 0, 1); break;
      case 'custom': if (options.dateFrom) fromDate = new Date(options.dateFrom); if (options.dateTo) toDate = new Date(options.dateTo); break;
    }
    filtered = filtered.filter(e => {
      if (!e.date) return true;
      const entryDate = new Date(e.date);
      if (fromDate && entryDate < fromDate) return false;
      if (toDate && entryDate > toDate) return false;
      return true;
    });
  }
  return filtered;
}

// ========================================
// REPORT DATA BUILDER
// ========================================

function buildVehicleReportData(options) {
  const branding = getAppBranding();
  const unit = getUnitShort();
  const todayIso = getTodayIsoInSettingsTz();
  const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
  if (!vehicle) return null;
  
  let entries = data.entries.filter(e => e.vehicleId === activeVehicleId).sort((a, b) => (a.date || '').localeCompare(b.date || ''));
  entries = filterEntriesByOptions(entries, options);
  const reminders = data.reminders.filter(r => r.vehicleId === activeVehicleId);
  
  const costBreakdown = calculateCostBreakdown(entries);
  const stats = calculateEnhancedStats(vehicle, entries, reminders);
  
  const timeline = entries.map((e, index) => {
    const services = normalizeServices(e.services || []);
    const totalCost = calculateEntryTotalCost(e);
    return {
      index: index + 1, date: e.date, dateFormatted: e.date ? formatDateNice(e.date) : 'No date',
      odometer: e.odo, odometerFormatted: e.odo != null ? e.odo.toLocaleString() + ' ' + unit : '–',
      services, serviceNames: services.map(s => s.name),
      serviceCosts: services.filter(s => s.cost != null).map(s => s.name + ': $' + s.cost.toFixed(2)).join('; '),
      totalCost, totalCostFormatted: totalCost > 0 ? '$' + totalCost.toFixed(2) : '–', notes: e.notes || ''
    };
  });
  
  const upcomingReminders = reminders.map(r => {
    const derived = computeReminderDerived(r, vehicle.currentOdo);
    return {
      serviceName: r.serviceName || r.title || 'Reminder', status: derived.level, statusLabel: derived.label,
      nextOdo: derived.nextOdo, nextDate: derived.nextDate, nextDateFormatted: derived.nextDate ? formatDateNice(derived.nextDate) : null,
      intervalMiles: r.intervalMiles, intervalMonths: r.intervalMonths,
      urgency: derived.level === 'overdue' ? 0 : (derived.level === 'upcoming' ? 1 : 2)
    };
  }).sort((a, b) => a.urgency - b.urgency);
  
  const serviceSummary = typeof buildServiceSummary === 'function' ? buildServiceSummary(entries) : [];
  
  return {
    branding, reportDate: todayIso, reportDateFormatted: formatDateNice(todayIso),
    reportTime: new Date().toLocaleTimeString(), unit, unitFull: unit === 'km' ? 'Kilometers' : 'Miles',
    vehicle: {
      id: vehicle.id, name: vehicle.name, vin: vehicle.vin || 'Not Recorded', plate: vehicle.plate || 'Not Recorded',
      currentOdo: vehicle.currentOdo, year: vehicle.year || null, make: vehicle.make || null, model: vehicle.model || null,
      engine: vehicle.engine || null, bodyClass: vehicle.bodyClass || null, photo: vehicle.photo || vehicle.photoPath || null,
      insuranceExpiry: vehicle.insuranceExpiry, insuranceExpiryFormatted: vehicle.insuranceExpiry ? formatDateNice(vehicle.insuranceExpiry) : null,
      registrationExpiry: vehicle.registrationExpiry, registrationExpiryFormatted: vehicle.registrationExpiry ? formatDateNice(vehicle.registrationExpiry) : null
    },
    costBreakdown, stats, timeline, upcomingReminders, serviceSummary, recordCount: timeline.length, options
  };
}

function calculateCostBreakdown(entries) {
  let partsCost = 0, laborCost = 0, miscCost = 0;
  entries.forEach(entry => {
    if (Array.isArray(entry.services)) entry.services.forEach(s => { if (typeof s === 'object' && s.cost != null) partsCost += Number(s.cost) || 0; });
    if (entry.cost != null) miscCost += Number(entry.cost) || 0;
    if (entry.laborCost != null) laborCost += Number(entry.laborCost) || 0;
  });
  const total = partsCost + laborCost + miscCost;
  const fmt = (n) => '$' + n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  return { parts: partsCost, labor: laborCost, misc: miscCost, total, partsFormatted: fmt(partsCost), laborFormatted: fmt(laborCost), miscFormatted: fmt(miscCost), totalFormatted: fmt(total) };
}

function calculateEnhancedStats(vehicle, entries, reminders) {
  const unit = getUnitShort();
  const today = getTodayDateInSettingsTz();
  const currentYear = today.getFullYear();
  let totalCost = 0, ytdCost = 0, firstDate = null, lastDate = null, firstOdo = null, lastOdo = null, serviceCount = 0;
  const serviceTypeCounts = {};
  
  entries.forEach(e => {
    serviceCount++;
    const cost = calculateEntryTotalCost(e);
    totalCost += cost;
    if (e.date) {
      if (!firstDate || e.date < firstDate) { firstDate = e.date; firstOdo = e.odo; }
      if (!lastDate || e.date > lastDate) { lastDate = e.date; lastOdo = e.odo; }
      if (parseInt(e.date.substring(0, 4), 10) === currentYear) ytdCost += cost;
    }
    normalizeServices(e.services || []).forEach(s => { serviceTypeCounts[s.name] = (serviceTypeCounts[s.name] || 0) + 1; });
  });
  
  const distanceTracked = (lastOdo && firstOdo) ? lastOdo - firstOdo : 0;
  let yearsTracked = 0, monthsTracked = 0;
  if (firstDate && lastDate) {
    const diffMs = new Date(lastDate) - new Date(firstDate);
    yearsTracked = Math.floor(diffMs / (1000 * 60 * 60 * 24 * 365));
    monthsTracked = Math.floor(diffMs / (1000 * 60 * 60 * 24 * 30));
  }
  
  let overdueCount = 0, upcomingCount = 0;
  reminders.forEach(r => {
    const derived = computeReminderDerived(r, vehicle.currentOdo);
    if (derived.level === 'overdue') overdueCount++;
    else if (derived.level === 'upcoming') upcomingCount++;
  });
  
  return {
    totalCost, ytdCost, avgCostPerService: serviceCount > 0 ? totalCost / serviceCount : 0,
    costPerMile: distanceTracked > 0 ? totalCost / distanceTracked : 0,
    firstDate, firstDateFormatted: firstDate ? formatDateNice(firstDate) : null,
    lastDate, lastDateFormatted: lastDate ? formatDateNice(lastDate) : null,
    firstOdo, lastOdo, distanceTracked, yearsTracked, monthsTracked,
    avgPerYear: yearsTracked > 0 ? Math.round(distanceTracked / yearsTracked) : distanceTracked,
    serviceCount, overdueCount, upcomingCount, totalReminders: reminders.length,
    uniqueServiceTypes: Object.keys(serviceTypeCounts).length
  };
}

// ========================================
// CSV EXPORT
// ========================================

function exportVehicleReportCSV(options) {
  const rpt = buildVehicleReportData(options);
  if (!rpt) return;
  const { branding, unit, vehicle: v, costBreakdown: cost } = rpt;
  const safeName = v.name.replace(/[^\w]+/g, "_").toLowerCase();
  const lines = [];
  
  lines.push(branding.appName + ' - Vehicle Maintenance Report');
  lines.push('Generated: ' + rpt.reportDateFormatted);
  lines.push('');
  lines.push('Vehicle Information');
  lines.push('Vehicle Name,' + v.name);
  if (v.vin !== 'Not Recorded') lines.push('VIN,' + v.vin);
  if (v.plate !== 'Not Recorded') lines.push('License Plate,' + v.plate);
  if (v.currentOdo != null) lines.push('Current Odometer,' + v.currentOdo.toLocaleString() + ' ' + unit);
  if (v.year && v.make && v.model) lines.push('Year/Make/Model,' + v.year + ' ' + v.make + ' ' + v.model);
  if (v.insuranceExpiryFormatted) lines.push('Insurance Expiry,' + v.insuranceExpiryFormatted);
  if (v.registrationExpiryFormatted) lines.push('Registration Expiry,' + v.registrationExpiryFormatted);
  lines.push('');
  if (cost.total > 0) { lines.push('Cost Summary'); lines.push('Total Cost,' + cost.totalFormatted); lines.push(''); }
  
  lines.push('Service History');
  lines.push(['Date', 'Odometer (' + unit + ')', 'Services', 'Service Costs', 'Total Cost', 'Notes'].join(','));
  rpt.timeline.slice().reverse().forEach(entry => {
    lines.push([entry.dateFormatted, entry.odometer != null ? entry.odometer : '', '"' + entry.serviceNames.join('; ').replace(/"/g, '""') + '"', '"' + entry.serviceCosts.replace(/"/g, '""') + '"', entry.totalCost > 0 ? entry.totalCost.toFixed(2) : '', '"' + (entry.notes || '').replace(/"/g, '""') + '"'].join(','));
  });
  lines.push('');
  lines.push('Report generated by ' + branding.appName);
  
  const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = branding.appName.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-' + safeName + '-report.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  showToast('CSV report exported');
}

// ========================================
// XLSX EXPORT
// ========================================

function exportVehicleReportXLSX(options) {
  const rpt = buildVehicleReportData(options);
  if (!rpt) return;
  if (!window.XLSX) { alert('Excel export requires SheetJS library. Falling back to CSV.'); exportVehicleReportCSV(options); return; }
  
  const { branding, unit, vehicle: v } = rpt;
  const safeName = v.name.replace(/[^\w]+/g, "_").toLowerCase();
  const wb = XLSX.utils.book_new();
  const wsData = [];
  
  wsData.push([branding.appName + ' - Vehicle Maintenance Report']);
  wsData.push(['Generated: ' + rpt.reportDateFormatted]);
  wsData.push([]);
  wsData.push(['Vehicle Information']);
  wsData.push(['Vehicle Name', v.name]);
  if (v.vin !== 'Not Recorded') wsData.push(['VIN', v.vin]);
  if (v.plate !== 'Not Recorded') wsData.push(['License Plate', v.plate]);
  if (v.currentOdo != null) wsData.push(['Current Odometer', v.currentOdo.toLocaleString() + ' ' + unit]);
  wsData.push([]);
  wsData.push(['Service History']);
  wsData.push(['Date', 'Odometer (' + unit + ')', 'Services', 'Service Costs', 'Total Cost', 'Notes']);
  rpt.timeline.slice().reverse().forEach(entry => {
    wsData.push([entry.dateFormatted, entry.odometer, entry.serviceNames.join('; '), entry.serviceCosts, entry.totalCost > 0 ? entry.totalCost : '', entry.notes || '']);
  });
  
  const ws = XLSX.utils.aoa_to_sheet(wsData);
  ws['!cols'] = [{ wch: 14 }, { wch: 14 }, { wch: 45 }, { wch: 35 }, { wch: 12 }, { wch: 40 }];
  XLSX.utils.book_append_sheet(wb, ws, 'Vehicle Report');
  XLSX.writeFile(wb, branding.appName.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-' + safeName + '-report.xlsx');
  showToast('Excel report exported');
}

function generateCleanPDF(rpt, safeName, logoData) {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'letter' });
  
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const margin = 15;
  const contentWidth = pageWidth - (margin * 2);
  
  const { branding, unit, vehicle: v, stats: s, costBreakdown: cost, options: opts } = rpt;
  
  // B&W Color palette
  const black = [0, 0, 0], darkGray = [51, 51, 51], mediumGray = [102, 102, 102];
  const lightGray = [153, 153, 153], veryLightGray = [220, 220, 220], tableBg = [245, 245, 245], white = [255, 255, 255];
  
  let y = 12;
  
  // HEADER
  if (logoData) { try { doc.addImage(logoData, 'PNG', margin, y - 3, 9, 9); } catch (e) {} }
  const logoOffset = logoData ? 12 : 0;
  doc.setFontSize(18); doc.setFont('helvetica', 'bold'); doc.setTextColor(...black);
  doc.text(branding.appName, margin + logoOffset, y + 2);
  doc.setFontSize(9); doc.setFont('helvetica', 'normal'); doc.setTextColor(...mediumGray);
  doc.text('Vehicle Maintenance Report', margin + logoOffset, y + 7);
  doc.setFontSize(8); doc.setTextColor(...darkGray);
  doc.text('Report Date: ' + rpt.reportDateFormatted, pageWidth - margin, y, { align: 'right' });
  doc.text(rpt.recordCount + ' Service Record' + (rpt.recordCount !== 1 ? 's' : ''), pageWidth - margin, y + 5, { align: 'right' });
  
  y = 24;
  doc.setDrawColor(...veryLightGray); doc.setLineWidth(0.5);
  doc.line(margin, y, pageWidth - margin, y);
  y = 30;
  
  // VEHICLE INFORMATION
  if (opts.includeVehicleInfo) {
    doc.setFillColor(...darkGray); doc.rect(margin, y, contentWidth, 7, 'F');
    doc.setTextColor(...white); doc.setFontSize(10); doc.setFont('helvetica', 'bold');
    doc.text('Vehicle Information', margin + 4, y + 5);
    y += 7;
    
    const boxStartY = y;
    doc.setDrawColor(...veryLightGray); doc.setLineWidth(0.3);
    y += 6;
    doc.setFontSize(14); doc.setFont('helvetica', 'bold'); doc.setTextColor(...black);
    doc.text(v.name, margin + 5, y);
    y += 8;
    
    const detailItems = [];
    if (opts.showVin && v.vin !== 'Not Recorded') detailItems.push({ label: 'VIN', value: v.vin });
    if (opts.showPlate && v.plate !== 'Not Recorded') detailItems.push({ label: 'LICENSE PLATE', value: v.plate });
    if (opts.showOdometer && v.currentOdo != null) detailItems.push({ label: 'CURRENT ODOMETER', value: v.currentOdo.toLocaleString() + ' ' + unit });
    if (opts.showYearMakeModel && v.year && v.make && v.model) detailItems.push({ label: 'YEAR/MAKE/MODEL', value: v.year + ' ' + v.make + ' ' + v.model });
    if (opts.showEngine && v.engine) detailItems.push({ label: 'ENGINE', value: v.engine });
    if (opts.showStats) {
      if (s.monthsTracked > 0) {
        const span = s.yearsTracked > 0 ? s.yearsTracked + ' year' + (s.yearsTracked > 1 ? 's' : '') : s.monthsTracked + ' month' + (s.monthsTracked > 1 ? 's' : '');
        detailItems.push({ label: 'HISTORY SPAN', value: span });
      }
      if (s.distanceTracked > 0) detailItems.push({ label: 'DISTANCE TRACKED', value: s.distanceTracked.toLocaleString() + ' ' + unit });
      if (s.avgPerYear > 0) detailItems.push({ label: 'AVG. ANNUAL', value: s.avgPerYear.toLocaleString() + ' ' + unit + '/yr' });
    }
    
    const colWidth = (contentWidth - 10) / 3;
    let col = 0, rowY = y;
    detailItems.forEach(item => {
      const x = margin + 5 + (col * colWidth);
      doc.setFontSize(6); doc.setTextColor(...lightGray); doc.setFont('helvetica', 'normal');
      doc.text(item.label, x, rowY);
      doc.setFontSize(9); doc.setTextColor(...darkGray); doc.setFont('helvetica', 'bold');
      const displayValue = item.value.length > 24 ? item.value.substring(0, 24) + '...' : item.value;
      doc.text(displayValue, x, rowY + 4);
      col++;
      if (col >= 3) { col = 0; rowY += 11; }
    });
    if (col > 0) rowY += 11;
    y = rowY + 2;
    doc.rect(margin, boxStartY, contentWidth, y - boxStartY, 'S');
    y += 6;
  }
  
  // RENEWAL DATES
  if (opts.includeRenewals && (v.insuranceExpiry || v.registrationExpiry)) {
    doc.setFillColor(...darkGray); doc.rect(margin, y, contentWidth, 7, 'F');
    doc.setTextColor(...white); doc.setFontSize(10); doc.setFont('helvetica', 'bold');
    doc.text('Renewal Dates', margin + 4, y + 5);
    y += 7;
    const boxStartY = y;
    y += 5;
    const renewals = [];
    if (v.insuranceExpiryFormatted) renewals.push({ label: 'Insurance Expiry', value: v.insuranceExpiryFormatted });
    if (v.registrationExpiryFormatted) renewals.push({ label: 'Registration Expiry', value: v.registrationExpiryFormatted });
    renewals.forEach((item, i) => {
      const x = margin + 5 + (i * (contentWidth / 2));
      doc.setFontSize(7); doc.setTextColor(...mediumGray); doc.setFont('helvetica', 'normal');
      doc.text(item.label, x, y);
      doc.setFontSize(10); doc.setTextColor(...darkGray); doc.setFont('helvetica', 'bold');
      doc.text(item.value, x, y + 5);
    });
    y += 10;
    doc.setDrawColor(...veryLightGray); doc.rect(margin, boxStartY, contentWidth, y - boxStartY, 'S');
    y += 6;
  }
  
  // COST SUMMARY (Optional)
  if (opts.includeCostSummary && cost.total > 0) {
    doc.setFillColor(...darkGray); doc.rect(margin, y, contentWidth, 7, 'F');
    doc.setTextColor(...white); doc.setFontSize(10); doc.setFont('helvetica', 'bold');
    doc.text('Cost Summary', margin + 4, y + 5);
    y += 7;
    const boxStartY = y;
    y += 4;
    const cardWidth = (contentWidth - 15) / 4;
    const costItems = [
      { label: 'Parts/Services', value: cost.partsFormatted },
      { label: 'Labor', value: cost.laborFormatted },
      { label: 'Misc/Fees', value: cost.miscFormatted },
      { label: 'TOTAL', value: cost.totalFormatted, bold: true }
    ];
    costItems.forEach((item, i) => {
      const x = margin + 3 + (i * (cardWidth + 3));
      doc.setDrawColor(...veryLightGray);
      if (item.bold) { doc.setFillColor(...tableBg); doc.rect(x, y, cardWidth, 14, 'FD'); }
      else { doc.rect(x, y, cardWidth, 14, 'S'); }
      doc.setFontSize(6); doc.setTextColor(...lightGray); doc.setFont('helvetica', 'normal');
      doc.text(item.label.toUpperCase(), x + cardWidth / 2, y + 4, { align: 'center' });
      doc.setFontSize(item.bold ? 11 : 9); doc.setTextColor(...(item.bold ? black : darkGray));
      doc.setFont('helvetica', item.bold ? 'bold' : 'normal');
      doc.text(item.value, x + cardWidth / 2, y + 10, { align: 'center' });
    });
    y += 18;
    const statItems = [
      { label: 'Year-to-Date', value: '$' + s.ytdCost.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}) },
      { label: 'Avg per Service', value: '$' + s.avgCostPerService.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}) },
      { label: 'Cost per ' + (unit === 'km' ? 'km' : 'mile'), value: '$' + s.costPerMile.toFixed(2) },
      { label: 'Service Types', value: s.uniqueServiceTypes.toString() }
    ];
    statItems.forEach((item, i) => {
      const x = margin + 3 + (i * (cardWidth + 3));
      doc.setFontSize(6); doc.setTextColor(...lightGray); doc.text(item.label, x + cardWidth / 2, y, { align: 'center' });
      doc.setFontSize(8); doc.setTextColor(...darkGray); doc.setFont('helvetica', 'bold');
      doc.text(item.value, x + cardWidth / 2, y + 4, { align: 'center' });
    });
    y += 8;
    doc.setDrawColor(...veryLightGray); doc.rect(margin, boxStartY, contentWidth, y - boxStartY, 'S');
    y += 6;
  }
  
  // UPCOMING MAINTENANCE
  if (opts.includeReminders && rpt.upcomingReminders.length > 0) {
    doc.setFillColor(...darkGray); doc.rect(margin, y, contentWidth, 7, 'F');
    doc.setTextColor(...white); doc.setFontSize(10); doc.setFont('helvetica', 'bold');
    doc.text('Upcoming Maintenance', margin + 4, y + 5);
    const statusText = s.overdueCount > 0 ? s.overdueCount + ' Overdue' : 'All OK';
    doc.setFontSize(7);
    const badgeWidth = doc.getTextWidth(statusText) + 8;
    doc.setFillColor(...(s.overdueCount > 0 ? mediumGray : lightGray));
    doc.rect(pageWidth - margin - badgeWidth - 3, y + 1.5, badgeWidth, 4, 'F');
    doc.setTextColor(...white);
    doc.text(statusText, pageWidth - margin - badgeWidth / 2 - 3, y + 4.2, { align: 'center' });
    y += 7;
    const boxStartY = y;
    y += 4;
    rpt.upcomingReminders.forEach((reminder, i) => {
      const rowY = y + (i * 6);
      const dotFill = reminder.status === 'overdue' ? black : (reminder.status === 'upcoming' ? mediumGray : lightGray);
      doc.setFillColor(...dotFill); doc.circle(margin + 5, rowY + 1.5, 1.5, 'F');
      doc.setFontSize(9); doc.setTextColor(...darkGray); doc.setFont('helvetica', 'normal');
      doc.text(reminder.serviceName, margin + 10, rowY + 2.5);
      doc.setFontSize(8); doc.setTextColor(...mediumGray);
      doc.text(formatReminderDue(reminder, unit), pageWidth - margin - 3, rowY + 2.5, { align: 'right' });
    });
    y += (rpt.upcomingReminders.length * 6) + 3;
    doc.setDrawColor(...veryLightGray); doc.rect(margin, boxStartY, contentWidth, y - boxStartY, 'S');
    y += 6;
  }
  
  // SERVICE HISTORY TABLE
  if (opts.includeHistory && rpt.timeline.length > 0) {
    doc.setFillColor(...darkGray); doc.rect(margin, y, contentWidth, 7, 'F');
    doc.setTextColor(...white); doc.setFontSize(10); doc.setFont('helvetica', 'bold');
    doc.text('Service History', margin + 4, y + 5);
    y += 8;
    
    const headers = ['Date', 'Odometer', 'Services', 'Cost', 'Notes'];
    const rows = rpt.timeline.slice().reverse().map(entry => [
      entry.dateFormatted, entry.odometerFormatted, entry.serviceNames.join(', ') || '–',
      entry.totalCostFormatted, entry.notes ? (entry.notes.length > 35 ? entry.notes.substring(0, 35) + '...' : entry.notes) : '–'
    ]);
    
    doc.autoTable({
      startY: y, head: [headers], body: rows,
      styles: { fontSize: 8, cellPadding: 3, textColor: darkGray, lineColor: veryLightGray, lineWidth: 0.3 },
      headStyles: { fillColor: tableBg, textColor: mediumGray, fontStyle: 'bold', fontSize: 7 },
      columnStyles: { 0: { cellWidth: 24 }, 1: { cellWidth: 24 }, 2: { cellWidth: 65 }, 3: { cellWidth: 22, halign: 'right', fontStyle: 'bold' }, 4: { cellWidth: 40 } },
      alternateRowStyles: { fillColor: [250, 250, 250] },
      margin: { left: margin, right: margin },
      didDrawPage: function(data) {
        doc.setFontSize(7); doc.setTextColor(...mediumGray);
        const pageNum = doc.internal.getCurrentPageInfo().pageNumber;
        doc.text(v.name + ' | VIN: ' + v.vin, margin, pageHeight - 8);
        doc.text('Page ' + pageNum, pageWidth / 2, pageHeight - 8, { align: 'center' });
        doc.text(branding.appName, pageWidth - margin, pageHeight - 8, { align: 'right' });
        if (pageNum > 1) {
          doc.setDrawColor(...veryLightGray); doc.setLineWidth(0.3);
          doc.line(margin, 10, pageWidth - margin, 10);
          doc.setFontSize(9); doc.setTextColor(...darkGray);
          doc.text(branding.appName + ' - ' + v.name, margin, 8);
        }
      }
    });
    y = doc.lastAutoTable.finalY + 6;
  }
  
  // SERVICE TYPE SUMMARY (Optional)
  if (opts.includeServiceSummary && rpt.serviceSummary && rpt.serviceSummary.length > 0 && y < pageHeight - 60) {
    doc.setFillColor(...darkGray); doc.rect(margin, y, contentWidth, 7, 'F');
    doc.setTextColor(...white); doc.setFontSize(10); doc.setFont('helvetica', 'bold');
    doc.text('Service Type Summary', margin + 4, y + 5);
    y += 8;
    const summaryHeaders = ['Service Type', 'Count', 'Total Cost', 'Last Performed'];
    const summaryRows = rpt.serviceSummary.slice(0, 10).map(svc => [svc.name, svc.count.toString(), '$' + svc.totalCost.toFixed(2), svc.lastPerformedFormatted || '–']);
    doc.autoTable({
      startY: y, head: [summaryHeaders], body: summaryRows,
      styles: { fontSize: 8, cellPadding: 2.5, textColor: darkGray, lineColor: veryLightGray, lineWidth: 0.3 },
      headStyles: { fillColor: tableBg, textColor: mediumGray, fontStyle: 'bold', fontSize: 7 },
      columnStyles: { 0: { cellWidth: 65 }, 1: { cellWidth: 20, halign: 'center' }, 2: { cellWidth: 30, halign: 'right', fontStyle: 'bold' }, 3: { cellWidth: 35 } },
      margin: { left: margin, right: margin }
    });
    y = doc.lastAutoTable.finalY + 6;
  }
  
  // FOOTER DISCLAIMER
  if (y < pageHeight - 28) {
    doc.setDrawColor(...veryLightGray); doc.setLineWidth(0.3);
    doc.rect(margin, y, contentWidth, 16, 'S');
    doc.setFontSize(7); doc.setTextColor(...mediumGray); doc.setFont('helvetica', 'normal');
    doc.text('This report is generated from records in ' + branding.appName + '. It may not include all maintenance performed on this vehicle.', margin + 4, y + 5);
    doc.text('For vehicle purchase decisions, always verify records and perform an independent inspection.', margin + 4, y + 9);
    doc.text('Report generated: ' + rpt.reportDateFormatted + ' at ' + rpt.reportTime, margin + 4, y + 13);
  }
  
  doc.save(branding.appName.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-' + safeName + '-report.pdf');
  showToast('PDF report exported');
}

// ========================================
// WORD EXPORT
// ========================================

function exportVehicleReportWord(options) {
  const rpt = buildVehicleReportData(options);
  if (!rpt) return;
  
  const { branding, unit, vehicle: v, stats: s, costBreakdown: cost, options: opts } = rpt;
  const safeName = v.name.replace(/[^\w]+/g, "_").toLowerCase();
  
  let html = `<!DOCTYPE html><html><head><meta charset="utf-8">
<title>${escapeHtml(branding.appName)} - Vehicle Report - ${escapeHtml(v.name)}</title>
<style>
@page { margin: 0.75in; size: letter; }
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.4; color: #333; margin: 0; padding: 0; }
.header { border-bottom: 1px solid #ccc; padding-bottom: 12px; margin-bottom: 20px; }
.header-row { display: table; width: 100%; }
.header-left { display: table-cell; vertical-align: top; }
.header-right { display: table-cell; vertical-align: top; text-align: right; }
.app-name { font-size: 18pt; font-weight: bold; color: #000; }
.app-tagline { font-size: 9pt; color: #666; margin-top: 2px; }
.report-meta { font-size: 8pt; color: #333; }
.section { margin: 15px 0; page-break-inside: avoid; }
.section-header { background: #333; color: #fff; padding: 6px 12px; font-size: 10pt; font-weight: bold; }
.section-body { border: 1px solid #dcdcdc; border-top: none; padding: 15px; }
.vehicle-name { font-size: 14pt; font-weight: bold; color: #000; margin-bottom: 12px; }
.info-grid { display: table; width: 100%; }
.info-row { display: table-row; }
.info-cell { display: table-cell; width: 33%; padding: 5px 10px 5px 0; vertical-align: top; }
.info-label { font-size: 7pt; color: #999; text-transform: uppercase; }
.info-value { font-size: 9pt; color: #333; font-weight: bold; }
.reminder-row { padding: 4px 0; border-bottom: 1px solid #eee; display: table; width: 100%; }
.reminder-row:last-child { border-bottom: none; }
.reminder-dot { display: table-cell; width: 20px; vertical-align: middle; }
.reminder-dot-inner { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.reminder-dot-inner.overdue { background: #000; }
.reminder-dot-inner.upcoming { background: #666; }
.reminder-dot-inner.ok { background: #ccc; }
.reminder-name { display: table-cell; font-size: 9pt; color: #333; vertical-align: middle; }
.reminder-due { display: table-cell; text-align: right; font-size: 8pt; color: #666; vertical-align: middle; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 9pt; }
table.data-table th { background: #f5f5f5; padding: 6px 8px; text-align: left; font-size: 8pt; text-transform: uppercase; color: #666; border-bottom: 1px solid #ccc; font-weight: bold; }
table.data-table td { padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: top; color: #333; }
table.data-table tr:nth-child(even) td { background: #fafafa; }
.cost-cell { font-weight: bold; text-align: right; }
.footer { margin-top: 20px; padding: 12px; border: 1px solid #dcdcdc; font-size: 8pt; color: #666; line-height: 1.5; }
.cost-grid { display: table; width: 100%; border-collapse: separate; border-spacing: 5px; }
.cost-card { display: table-cell; width: 25%; border: 1px solid #dcdcdc; padding: 8px; text-align: center; }
.cost-card.total { background: #f5f5f5; }
.cost-label { font-size: 7pt; color: #999; text-transform: uppercase; }
.cost-value { font-size: 11pt; color: #333; margin-top: 3px; }
.cost-card.total .cost-value { font-weight: bold; color: #000; }
</style></head><body>`;

  // Header
  html += `<div class="header"><div class="header-row">
    <div class="header-left"><div class="app-name">${escapeHtml(branding.appName)}</div><div class="app-tagline">Vehicle Maintenance Report</div></div>
    <div class="header-right"><div class="report-meta">Report Date: ${rpt.reportDateFormatted}<br>${rpt.recordCount} Service Record${rpt.recordCount !== 1 ? 's' : ''}</div></div>
  </div></div>`;

  // Vehicle Information
  if (opts.includeVehicleInfo) {
    html += `<div class="section"><div class="section-header">Vehicle Information</div><div class="section-body">
      <div class="vehicle-name">${escapeHtml(v.name)}</div><div class="info-grid">`;
    const detailItems = [];
    if (opts.showVin && v.vin !== 'Not Recorded') detailItems.push({ label: 'VIN', value: v.vin });
    if (opts.showPlate && v.plate !== 'Not Recorded') detailItems.push({ label: 'License Plate', value: v.plate });
    if (opts.showOdometer && v.currentOdo != null) detailItems.push({ label: 'Current Odometer', value: v.currentOdo.toLocaleString() + ' ' + unit });
    if (opts.showYearMakeModel && v.year && v.make && v.model) detailItems.push({ label: 'Year/Make/Model', value: v.year + ' ' + v.make + ' ' + v.model });
    if (opts.showEngine && v.engine) detailItems.push({ label: 'Engine', value: v.engine });
    if (opts.showStats) {
      if (s.monthsTracked > 0) detailItems.push({ label: 'History Span', value: s.yearsTracked > 0 ? s.yearsTracked + ' year' + (s.yearsTracked > 1 ? 's' : '') : s.monthsTracked + ' month' + (s.monthsTracked > 1 ? 's' : '') });
      if (s.distanceTracked > 0) detailItems.push({ label: 'Distance Tracked', value: s.distanceTracked.toLocaleString() + ' ' + unit });
      if (s.avgPerYear > 0) detailItems.push({ label: 'Avg. Annual', value: s.avgPerYear.toLocaleString() + ' ' + unit + '/yr' });
    }
    for (let i = 0; i < detailItems.length; i += 3) {
      html += `<div class="info-row">`;
      for (let j = i; j < i + 3 && j < detailItems.length; j++) {
        html += `<div class="info-cell"><div class="info-label">${detailItems[j].label.toUpperCase()}</div><div class="info-value">${escapeHtml(detailItems[j].value)}</div></div>`;
      }
      html += `</div>`;
    }
    html += `</div></div></div>`;
  }

  // Renewal Dates
  if (opts.includeRenewals && (v.insuranceExpiry || v.registrationExpiry)) {
    html += `<div class="section"><div class="section-header">Renewal Dates</div><div class="section-body"><div class="info-grid"><div class="info-row">`;
    if (v.insuranceExpiryFormatted) html += `<div class="info-cell"><div class="info-label">INSURANCE EXPIRY</div><div class="info-value">${v.insuranceExpiryFormatted}</div></div>`;
    if (v.registrationExpiryFormatted) html += `<div class="info-cell"><div class="info-label">REGISTRATION EXPIRY</div><div class="info-value">${v.registrationExpiryFormatted}</div></div>`;
    html += `</div></div></div></div>`;
  }

  // Cost Summary (optional)
  if (opts.includeCostSummary && cost.total > 0) {
    html += `<div class="section"><div class="section-header">Cost Summary</div><div class="section-body">
      <div class="cost-grid">
        <div class="cost-card"><div class="cost-label">Parts/Services</div><div class="cost-value">${cost.partsFormatted}</div></div>
        <div class="cost-card"><div class="cost-label">Labor</div><div class="cost-value">${cost.laborFormatted}</div></div>
        <div class="cost-card"><div class="cost-label">Misc/Fees</div><div class="cost-value">${cost.miscFormatted}</div></div>
        <div class="cost-card total"><div class="cost-label">Total</div><div class="cost-value">${cost.totalFormatted}</div></div>
      </div></div></div>`;
  }

  // Upcoming Maintenance
  if (opts.includeReminders && rpt.upcomingReminders.length > 0) {
    html += `<div class="section"><div class="section-header">Upcoming Maintenance</div><div class="section-body">`;
    rpt.upcomingReminders.forEach(reminder => {
      html += `<div class="reminder-row">
        <div class="reminder-dot"><span class="reminder-dot-inner ${reminder.status}"></span></div>
        <div class="reminder-name">${escapeHtml(reminder.serviceName)}</div>
        <div class="reminder-due">${formatReminderDue(reminder, unit)}</div>
      </div>`;
    });
    html += `</div></div>`;
  }

  // Service History
  if (opts.includeHistory && rpt.timeline.length > 0) {
    html += `<div class="section"><div class="section-header">Service History</div><div class="section-body" style="padding:0;">
      <table class="data-table"><thead><tr><th>Date</th><th>Odometer</th><th>Services</th><th style="text-align:right;">Cost</th><th>Notes</th></tr></thead><tbody>`;
    rpt.timeline.slice().reverse().forEach(entry => {
      html += `<tr><td>${entry.dateFormatted}</td><td>${entry.odometerFormatted}</td><td>${escapeHtml(entry.serviceNames.join(', ') || '–')}</td><td class="cost-cell">${entry.totalCostFormatted}</td><td>${escapeHtml(entry.notes ? (entry.notes.length > 40 ? entry.notes.substring(0, 40) + '...' : entry.notes) : '–')}</td></tr>`;
    });
    html += `</tbody></table></div></div>`;
  }

  // Service Type Summary (optional)
  if (opts.includeServiceSummary && rpt.serviceSummary && rpt.serviceSummary.length > 0) {
    html += `<div class="section"><div class="section-header">Service Type Summary</div><div class="section-body" style="padding:0;">
      <table class="data-table"><thead><tr><th>Service Type</th><th style="text-align:center;">Count</th><th style="text-align:right;">Total Cost</th><th>Last Performed</th></tr></thead><tbody>`;
    rpt.serviceSummary.slice(0, 10).forEach(svc => {
      html += `<tr><td>${escapeHtml(svc.name)}</td><td style="text-align:center;">${svc.count}</td><td class="cost-cell">$${svc.totalCost.toFixed(2)}</td><td>${svc.lastPerformedFormatted || '–'}</td></tr>`;
    });
    html += `</tbody></table></div></div>`;
  }

  // Footer
  html += `<div class="footer">
    This report is generated from records in ${escapeHtml(branding.appName)}. It may not include all maintenance performed on this vehicle.
    For vehicle purchase decisions, always verify records and perform an independent inspection.<br>
    <em>Report generated: ${rpt.reportDateFormatted} at ${rpt.reportTime}</em>
  </div></body></html>`;

  const blob = new Blob([html], { type: 'application/msword' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = branding.appName.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-' + safeName + '-report.doc';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  showToast('Word report exported');
}

// ========================================
// GLOBAL EXPORTS
// ========================================

window.openVehicleReportExportModal = openVehicleReportExportModal;
window.closeVehicleReportExportModal = closeVehicleReportExportModal;
window.executeVehicleReportExport = executeVehicleReportExport;
window.exportVehicleReportPDF = exportVehicleReportPDF;
window.exportVehicleReportWord = exportVehicleReportWord;
window.exportVehicleReportCSV = exportVehicleReportCSV;
window.exportVehicleReportXLSX = exportVehicleReportXLSX;