/**
 * GarageMinder - Copy Reminder Modal with Dynamic Auto-Fill
 * 
 * Modal-based reminder copying with automatic recalculation
 * for the target vehicle based on its service history
 */

let currentCopyReminder = null;

/**
 * Open copy reminder modal
 */
// TEMPORARY DEBUG VERSION
// Replace the copyReminderFromCard function in gm.features.copy-reminder.js with this:

function copyReminderFromCard($card) {
  console.log("=== copyReminderFromCard START ===");
  console.log("1. $card:", $card);
  console.log("2. $card.length:", $card.length);
  
  const remId = $card.attr("data-id");
  console.log("3. remId:", remId);
  
  if (!remId) {
    console.log("ERROR: No remId - STOPPING");
    return;
  }
  
  console.log("4. Searching for reminder in data.reminders...");
  console.log("5. data.reminders exists:", typeof data !== 'undefined' && data.reminders);
  console.log("6. data.reminders length:", data.reminders ? data.reminders.length : 'N/A');
  
  const rem = data.reminders.find(r => r.id === remId);
  console.log("7. rem found:", rem);
  
  if (!rem) {
    console.log("ERROR: Reminder not found - STOPPING");
    return;
  }
  
  currentCopyReminder = rem;
  console.log("8. Set currentCopyReminder:", currentCopyReminder);
  
  // Get source vehicle name
  console.log("9. Looking for source vehicle...");
  const sourceVehicle = data.vehicles.find(v => v.id === rem.vehicleId);
  console.log("10. sourceVehicle:", sourceVehicle);
  const sourceVehicleName = sourceVehicle ? sourceVehicle.name : "Unknown Vehicle";
  console.log("11. sourceVehicleName:", sourceVehicleName);
  
  // Populate modal
  console.log("12. Populating modal fields...");
  $("#copy-source-vehicle").text(sourceVehicleName);
  $("#copy-source-service").text(rem.serviceName || "Reminder");
  
  // Populate target vehicle dropdown (exclude current vehicle)
  const $targetSelect = $("#copy-target-vehicle");
  console.log("13. $targetSelect found:", $targetSelect.length);
  
  $targetSelect.empty().append('<option value="">-- Select vehicle --</option>');
  
  data.vehicles.forEach(v => {
    if (v.id !== rem.vehicleId) {
      $targetSelect.append(
        $("<option>").val(v.id).text(v.name)
      );
    }
  });
  console.log("14. Target vehicle options added");
  
  // Set read-only fields
  $("#copy-service-name").val(rem.serviceName || "");
  $("#copy-interval-miles").val(rem.intervalMiles != null ? rem.intervalMiles : "");
  $("#copy-interval-months").val(rem.intervalMonths != null ? rem.intervalMonths : "");
  $("#copy-notes").val(rem.notes || "");
  console.log("15. Read-only fields populated");
  
  // Clear editable fields
  $("#copy-base-odo").val("");
  $("#copy-base-date").val("");
  $("#copy-next-odo").val("");
  $("#copy-next-date").val("");
  console.log("16. Editable fields cleared");
  
  // Hide fields section initially
  $("#copy-reminder-fields").hide();
  $("#copy-reminder-confirm").prop("disabled", true);
  console.log("17. Fields hidden, confirm button disabled");
  
  // Remove any existing auto-fill indicators
  $("#copy-reminder-modal .field").find(".reminder-autofill-indicator").remove();
  console.log("18. Auto-fill indicators removed");
  
  // Show modal
  console.log("19. About to show modal...");
  console.log("20. Modal exists:", $("#copy-reminder-modal").length);
  console.log("21. Modal display before:", $("#copy-reminder-modal").css("display"));
  
  $("#copy-reminder-modal").fadeIn(200);
  
  console.log("22. fadeIn called");
  
  setTimeout(function() {
    console.log("23. Modal display after (200ms):", $("#copy-reminder-modal").css("display"));
  }, 250);
  
  // Initialize date pickers for the modal fields
  if (typeof initDatePickers === "function") {
    initDatePickers($("#copy-reminder-modal"));
    console.log("24. Date pickers initialized");
  } else {
    console.log("24. initDatePickers not found - skipping");
  }
  
  console.log("=== copyReminderFromCard END ===");
}

/**
 * Close copy reminder modal
 */
function closeCopyReminderModal() {
  $("#copy-reminder-modal").fadeOut(200);
  currentCopyReminder = null;
  
  // Clear form
  $("#copy-target-vehicle").val("");
  $("#copy-base-odo").val("");
  $("#copy-base-date").val("");
  $("#copy-next-odo").val("");
  $("#copy-next-date").val("");
  $("#copy-notes").val("");
  
  // Remove auto-fill indicators
  $("#copy-reminder-modal .field").find(".reminder-autofill-indicator").remove();
}

/**
 * Auto-fill copy reminder fields when target vehicle is selected
 */
function autoFillCopyReminderFields() {
  const targetVehicleId = $("#copy-target-vehicle").val();
  
  if (!targetVehicleId || !currentCopyReminder) {
    $("#copy-reminder-fields").hide();
    $("#copy-reminder-confirm").prop("disabled", true);
    return;
  }
  
  // Show fields section
  $("#copy-reminder-fields").slideDown(200);
  $("#copy-reminder-confirm").prop("disabled", false);
  
  const targetVehicle = data.vehicles.find(v => v.id === targetVehicleId);
  if (!targetVehicle) return;
  
  const serviceName = currentCopyReminder.serviceName;
  const intervalMiles = currentCopyReminder.intervalMiles;
  const intervalMonths = currentCopyReminder.intervalMonths;
  
  // Find most recent entry for this service on target vehicle
  const mostRecentEntry = findMostRecentEntryForService(targetVehicleId, serviceName);
  
  // Determine base values
  let baseOdo = null;
  let baseDate = null;
  let entrySource = null;
  
  if (mostRecentEntry) {
    baseOdo = mostRecentEntry.odo;
    baseDate = mostRecentEntry.date;
    entrySource = {
      date: mostRecentEntry.date,
      odo: mostRecentEntry.odo
    };
  } else {
    // No entries - use current vehicle odometer and today
    baseOdo = targetVehicle.currentOdo != null ? targetVehicle.currentOdo : null;
    baseDate = getTodayIsoInSettingsTz();
    entrySource = null; // No entry source
  }
  
  // Calculate next due values
  let nextOdo = null;
  let nextDate = null;
  
  if (intervalMiles && baseOdo != null) {
    nextOdo = baseOdo + intervalMiles;
  }
  
  if (intervalMonths && baseDate) {
    nextDate = addMonthsToDate(baseDate, intervalMonths);
  }
  
  // Get field references
  const fields = {
    baseOdo: $("#copy-base-odo"),
    baseDate: $("#copy-base-date"),
    nextOdo: $("#copy-next-odo"),
    nextDate: $("#copy-next-date")
  };
  
  // Set values and add auto-fill indicators
  if (baseOdo != null) {
    fields.baseOdo.val(baseOdo);
    addAutoFillIndicator(fields.baseOdo, entrySource, "odo");
  } else {
    fields.baseOdo.val("");
    removeAutoFillIndicator(fields.baseOdo);
  }
  
  if (baseDate) {
    fields.baseDate.val(baseDate);
    addAutoFillIndicator(fields.baseDate, entrySource, "date");
  } else {
    fields.baseDate.val("");
    removeAutoFillIndicator(fields.baseDate);
  }
  
  if (nextOdo != null) {
    fields.nextOdo.val(nextOdo);
    addAutoFillIndicator(fields.nextOdo, null, "calculated");
  } else {
    fields.nextOdo.val("");
    removeAutoFillIndicator(fields.nextOdo);
  }
  
  if (nextDate) {
    fields.nextDate.val(nextDate);
    addAutoFillIndicator(fields.nextDate, null, "calculated");
  } else {
    fields.nextDate.val("");
    removeAutoFillIndicator(fields.nextDate);
  }
}

/**
 * Confirm copy reminder to target vehicle
 */
function confirmCopyReminder() {
  const targetVehicleId = $("#copy-target-vehicle").val();
  
  if (!targetVehicleId || !currentCopyReminder) {
    alert("Please select a target vehicle.");
    return;
  }
  
  const targetVehicle = data.vehicles.find(v => v.id === targetVehicleId);
  if (!targetVehicle) return;
  
  // Get values from modal (auto-filled or manually edited)
  const baseOdoStr = $("#copy-base-odo").val();
  const baseOdo = baseOdoStr ? parseInt(baseOdoStr, 10) : null;
  const baseDate = $("#copy-base-date").val() || null;
  
  const nextOdoStr = $("#copy-next-odo").val();
  const nextOdo = nextOdoStr ? parseInt(nextOdoStr, 10) : null;
  const nextDate = $("#copy-next-date").val() || null;
  
  const notes = $("#copy-notes").val().trim();
  
  // Create new reminder for target vehicle
  const now = new Date().toISOString();
  const newReminder = {
    id: "r_" + Date.now() + "_" + Math.random().toString(36).slice(2),
    vehicleId: targetVehicleId,
    serviceName: currentCopyReminder.serviceName,
    intervalMiles: currentCopyReminder.intervalMiles,
    intervalMonths: currentCopyReminder.intervalMonths,
    baseOdo: baseOdo,
    baseDate: baseDate,
    nextOdo: nextOdo,
    nextDate: nextDate,
    notes: notes,
    createdAt: now,
    updatedAt: now
  };
  
  // Add to data
  data.reminders.push(newReminder);
  
  // Save and re-render
  saveData();
  renderRemindersPage();
  renderDashboardRemindersSnippet();
  
  // Close modal
  closeCopyReminderModal();
  
  // Show success message
  showToast(`Reminder copied to ${targetVehicle.name}`);
}

/**
 * Initialize copy reminder modal event handlers
 */
function initCopyReminderModal() {
  // Close modal buttons
  $("#copy-reminder-close, #copy-reminder-cancel").on("click", function() {
    closeCopyReminderModal();
  });
  
  // Close modal when clicking overlay
  $("#copy-reminder-modal").on("click", function(e) {
    if ($(e.target).is("#copy-reminder-modal")) {
      closeCopyReminderModal();
    }
  });
  
  // Target vehicle selection change
  $("#copy-target-vehicle").on("change", function() {
    autoFillCopyReminderFields();
  });
  
  // Confirm button
  $("#copy-reminder-confirm").on("click", function() {
    confirmCopyReminder();
  });
  
  // Remove auto-fill indicator when user manually edits
  $("#copy-base-odo, #copy-base-date, #copy-next-odo, #copy-next-date").on("input", function() {
    const $field = $(this);
    if ($field.data("auto-filled")) {
      removeAutoFillIndicator($field);
    }
  });
  
  // ESC key to close modal
  $(document).on("keydown", function(e) {
    if (e.key === "Escape" && $("#copy-reminder-modal").is(":visible")) {
      closeCopyReminderModal();
    }
  });
}

// Initialize on document ready
$(document).ready(function() {
  initCopyReminderModal();
});
