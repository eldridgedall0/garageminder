/**
 * GarageMinder - Entry Management Functions
 * 
 * This file contains the missing entry management functions that are called
 * but not defined in the GitHub repository.
 * 
 * Add this to index.php BEFORE gm.handlers.js:
 * <script src="assets/js/gm.entry-management.js"></script>
 */

/**
 * Delete an entry and recalculate all affected reminders
 */
function deleteEntryByCard($card) {
  const entryId = $card.attr("data-id");
  if (!entryId) return;
  
  const entry = data.entries.find(e => e.id === entryId);
  if (!entry) return;
  
  if (!confirm("Delete this service entry?\n\nReminders will be recalculated based on remaining entries.")) {
    return;
  }
  
  const vehicleId = entry.vehicleId;
  const serviceNames = getServiceNames(entry.services || []);
  
  // Remove entry from data
  data.entries = data.entries.filter(e => e.id !== entryId);
  
  // For each service in the deleted entry, recalculate reminders
  serviceNames.forEach(serviceName => {
    if (!serviceName) return;
    
    // Find all reminders for this vehicle and service
    const rems = data.reminders.filter(
      r => r.vehicleId === vehicleId && r.serviceName === serviceName
    );
    
    // Find the NEW most recent entry (after deletion)
    const mostRecentEntry = findMostRecentEntryForService(vehicleId, serviceName);
    
    // Recalculate each reminder
    rems.forEach(r => {
      const intervalMiles = r.intervalMiles != null ? r.intervalMiles : null;
      const intervalMonths = r.intervalMonths != null ? r.intervalMonths : null;
      const vehicle = data.vehicles.find(v => v.id === vehicleId) || null;
      const currentOdo = vehicle && vehicle.currentOdo != null ? vehicle.currentOdo : null;
      const nowIso = new Date().toISOString();
      
      let baseOdo = null;
      let baseDate = null;
      
      if (mostRecentEntry) {
        // There's still at least one entry for this service - use it
        baseOdo = mostRecentEntry.odo != null ? mostRecentEntry.odo
                 : (currentOdo != null ? currentOdo
                    : (r.baseOdo != null ? r.baseOdo : null));
        baseDate = mostRecentEntry.date || r.baseDate || null;
      } else {
        // No entries left for this service - reset to vehicle current state or original baseline
        baseOdo = currentOdo != null ? currentOdo : (r.baseOdo != null ? r.baseOdo : null);
        baseDate = r.baseDate || null;
      }
      
      let nextOdo = null;
      let nextDate = null;
      
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
  
  // Save and re-render
  saveData();
  renderDashboard();
  renderRemindersPage();
  renderDashboardRemindersSnippet();
  
  showToast("Entry deleted, reminders updated");
}

/**
 * Save changes to an entry from the accordion edit mode
 */
async function saveEntryFromAccordion($card) {
  const entryId = $card.attr("data-id");
  if (!entryId) return;
  
  const entry = data.entries.find(e => e.id === entryId);
  if (!entry) return;
  
  // Get values from edit fields
  const newDate = $card.find(".entry-edit-date").val().trim();
  const newOdoStr = $card.find(".entry-edit-odo").val().trim();
  const newOdo = newOdoStr ? parseInt(newOdoStr, 10) : null;
  const newNotes = $card.find(".entry-edit-notes").val().trim();
  const newOtherServices = $card.find(".entry-edit-services-other").val().trim();
  
  // Get checked services
  const checkedServices = [];
  $card.find(".entry-edit-services-wrapper input[type=checkbox]:checked").each(function() {
    const svcName = $(this).attr("name");
    if (svcName) {
      const costInput = $(this).closest("label").find(".service-cost-input");
      const cost = costInput.length ? parseFloat(costInput.val()) || 0 : 0;
      const notesInput = $(this).closest("label").find(".service-notes-input");
      const notes = notesInput.length ? notesInput.val().trim() : "";
      
      checkedServices.push({
        name: svcName,
        cost: cost,
        notes: notes
      });
    }
  });
  
  // Parse other services (comma or semicolon separated)
  if (newOtherServices) {
    const otherNames = newOtherServices.split(/[,;]+/).map(s => s.trim()).filter(s => s);
    otherNames.forEach(name => {
      if (!checkedServices.find(s => s.name === name)) {
        checkedServices.push({ name: name, cost: 0, notes: "" });
      }
    });
  }
  
  if (checkedServices.length === 0) {
    alert("Please select at least one service.");
    return;
  }
  
  // Store old service names for reminder cleanup
  const oldServiceNames = getServiceNames(entry.services || []);
  
  // Update entry
  entry.date = newDate || entry.date;
  entry.odo = newOdo;
  entry.notes = newNotes;
  entry.services = checkedServices;
  entry.updatedAt = new Date().toISOString();
  
  // Get new service names
  const newServiceNames = getServiceNames(entry.services);
  
  // Find services that were removed
  const removedServices = oldServiceNames.filter(name => !newServiceNames.includes(name));
  
  // For removed services, recalculate reminders (similar to delete logic)
  removedServices.forEach(serviceName => {
    const rems = data.reminders.filter(
      r => r.vehicleId === entry.vehicleId && r.serviceName === serviceName
    );
    
    const mostRecentEntry = findMostRecentEntryForService(entry.vehicleId, serviceName);
    
    rems.forEach(r => {
      const intervalMiles = r.intervalMiles != null ? r.intervalMiles : null;
      const intervalMonths = r.intervalMonths != null ? r.intervalMonths : null;
      const vehicle = data.vehicles.find(v => v.id === entry.vehicleId) || null;
      const currentOdo = vehicle && vehicle.currentOdo != null ? vehicle.currentOdo : null;
      const nowIso = new Date().toISOString();
      
      let baseOdo = null;
      let baseDate = null;
      
      if (mostRecentEntry) {
        baseOdo = mostRecentEntry.odo != null ? mostRecentEntry.odo : (currentOdo != null ? currentOdo : r.baseOdo);
        baseDate = mostRecentEntry.date || r.baseDate || null;
      } else {
        baseOdo = currentOdo != null ? currentOdo : r.baseOdo;
        baseDate = r.baseDate || null;
      }
      
      let nextOdo = null;
      let nextDate = null;
      
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
  
  // For services that remain (or were added), recalculate using the UPDATED entry
  // This ensures date/odometer changes are reflected
  resetRemindersForEntry(entry);
  
  // Save and re-render
  await saveData();
  renderDashboard();
  renderRemindersPage();
  renderDashboardRemindersSnippet();
  
  showToast("Entry updated, reminders recalculated");
}
