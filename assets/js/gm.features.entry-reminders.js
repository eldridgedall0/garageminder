/**
 * GarageMinder - Entry Save with Smart Reminder Integration
 * 
 * Handles saving entries and showing smart notifications about reminders
 * Includes "Create Reminder" button for services without reminders
 */

/**
 * Add or update entry from the main entry form
 */
async function addOrUpdateEntryFromForm() {
  if (!activeVehicleId || activeVehicleId === "all") {
    alert("Please select a specific vehicle first.");
    return;
  }
  
  const vehicle = data.vehicles.find(v => v.id === activeVehicleId);
  if (!vehicle) return;
  
  // Get form values
  const date = $("#entry-date").val();
  const odoStr = $("#entry-odo").val();
  const odo = odoStr ? parseInt(odoStr, 10) : null;
  const miscCost = parseFloat($("#entry-cost").val()) || 0;
  const notes = $("#entry-notes").val().trim();
  
  // Validate
  if (!date) {
    alert("Please enter a date.");
    $("#entry-date").focus();
    return;
  }
  if (odo == null || odo < 0) {
    alert("Please enter a valid odometer reading.");
    $("#entry-odo").focus();
    return;
  }
  
  // Get services
  const services = getServicesFromChecklist(
    $("#service-checklist-container"),
    $("#entry-services-other").val()
  );
  
  if (!services || services.length === 0) {
    alert("Please select at least one service.");
    return;
  }
  
  // Handle attachments
  const attachments = [];
  const $selectedFiles = $("#selected-files-preview .selected-file-item");
  
  $selectedFiles.each(function() {
    const $item = $(this);
    const type = $item.data("attach-type");
    
    if (type === "gdrive") {
      attachments.push({
        type: "gdrive",
        fileId: $item.data("file-id"),
        fileName: $item.data("file-name"),
        mimeType: $item.data("mime-type"),
        webViewLink: $item.data("web-view-link")
      });
    } else if (type === "local") {
      const fileObj = $item.data("file-object");
      if (fileObj) {
        attachments.push({
          type: "local_pending",
          file: fileObj,
          fileName: fileObj.name
        });
      }
    }
  });
  
  // Create entry object
  const now = new Date().toISOString();
  const entry = {
    id: "e_" + Date.now() + "_" + Math.random().toString(36).slice(2),
    vehicleId: activeVehicleId,
    date: date,
    odo: odo,
    services: services,
    miscCost: miscCost,
    notes: notes,
    attachments: attachments.filter(a => a.type === "gdrive"), // Only save GDrive initially
    createdAt: now,
    updatedAt: now
  };
  
  // Add to data
  data.entries.push(entry);
  
  // Update vehicle odometer if this is the most recent entry
  const allEntries = data.entries.filter(e => e.vehicleId === activeVehicleId);
  const sortedByDate = allEntries.slice().sort((a, b) => new Date(b.date) - new Date(a.date));
  if (sortedByDate[0] && sortedByDate[0].id === entry.id) {
    vehicle.currentOdo = odo;
  }
  
  // Update reminders for each service
  services.forEach(service => {
    resetRemindersForEntry({
      vehicleId: activeVehicleId,
      date: date,
      odo: odo,
      services: [service]
    });
  });
  
  // Handle local file uploads
  const localFiles = attachments.filter(a => a.type === "local_pending");
  if (localFiles.length > 0) {
    // Upload local files
    for (const fileData of localFiles) {
      try {
        const uploadResult = await uploadLocalAttachment(entry.id, fileData.file);
        if (uploadResult && uploadResult.id) {
          entry.attachments.push({
            id: uploadResult.id,
            type: "local",
            fileName: uploadResult.fileName,
            filePath: uploadResult.filePath,
            mimeType: uploadResult.mimeType
          });
        }
      } catch (err) {
        console.error("Upload failed:", err);
        showToast(`Failed to upload ${fileData.fileName}`);
      }
    }
  }
  
  // Save data
  await saveData();
  
  // Show success notification with reminder info
  showEntrySuccessWithReminderInfo(entry, services);
  
  // Re-render
  renderDashboard();
  renderRemindersPage();
  renderDashboardRemindersSnippet();
  
  // Reset form
  renderNewEntryFormDefaults();
  initDatePickers($(document));
  $("#selected-files-preview").empty();
}

/**
 * Check if a service has an active reminder
 */
function serviceHasReminder(vehicleId, serviceName) {
  if (!vehicleId || !serviceName) return false;
  
  return data.reminders.some(r => 
    r.vehicleId === vehicleId && 
    r.serviceName.toLowerCase() === serviceName.trim().toLowerCase()
  );
}

/**
 * Get services from entry that don't have reminders
 */
function getServicesWithoutReminders(vehicleId, services) {
  return services.filter(service => {
    const serviceName = typeof service === 'string' ? service : service.name;
    return !serviceHasReminder(vehicleId, serviceName);
  });
}

/**
 * Show success notification with smart reminder info
 */
function showEntrySuccessWithReminderInfo(entry, services) {
  const vehicle = data.vehicles.find(v => v.id === entry.vehicleId);
  if (!vehicle) {
    showToast("Entry saved successfully");
    return;
  }
  
  const unit = getUnitShort();
  const servicesWithReminders = [];
  const servicesWithoutReminders = [];
  
  // Categorize services
  services.forEach(service => {
    const serviceName = typeof service === 'string' ? service : service.name;
    
    if (serviceHasReminder(entry.vehicleId, serviceName)) {
      // Find the reminder
      const reminder = data.reminders.find(r => 
        r.vehicleId === entry.vehicleId && 
        r.serviceName.toLowerCase() === serviceName.toLowerCase()
      );
      
      if (reminder) {
        const derived = computeReminderDerived(reminder, vehicle.currentOdo);
        servicesWithReminders.push({
          name: serviceName,
          reminder: reminder,
          derived: derived
        });
      }
    } else {
      servicesWithoutReminders.push(serviceName);
    }
  });
  
  // Build notification HTML
  let html = `<div class="entry-success-notification">`;
  html += `<div class="entry-success-header">`;
  html += `<i class="bi bi-check-circle-fill" style="color: var(--gm-success); font-size: 1.2rem;"></i>`;
  html += `<strong>Entry saved at ${entry.odo.toLocaleString()} ${unit}</strong>`;
  html += `</div>`;
  
  // Show updated reminders
  if (servicesWithReminders.length > 0) {
    html += `<div class="entry-success-reminders">`;
    servicesWithReminders.forEach(item => {
      html += `<div class="entry-success-reminder-item">`;
      html += `<span class="reminder-service-name">${item.name}</span>`;
      
      const nextInfo = [];
      if (item.derived.nextOdo) {
        nextInfo.push(`${item.derived.nextOdo.toLocaleString()} ${unit}`);
      }
      if (item.derived.nextDate) {
        nextInfo.push(formatDateNice(item.derived.nextDate));
      }
      
      if (nextInfo.length > 0) {
        html += `<span class="reminder-next-info">Next: ${nextInfo.join(" • ")}</span>`;
      }
      html += `</div>`;
    });
    html += `</div>`;
  }
  
  // Show "Create Reminder" buttons for services without reminders
  if (servicesWithoutReminders.length > 0) {
    html += `<div class="entry-success-no-reminders">`;
    html += `<div class="no-reminder-label">No reminders set for:</div>`;
    servicesWithoutReminders.forEach(serviceName => {
      html += `<button class="btn-create-reminder btn-ghost btn-small" data-vehicle-id="${entry.vehicleId}" data-service-name="${serviceName}">`;
      html += `<i class="bi bi-bell"></i> Create "${serviceName}" Reminder`;
      html += `</button>`;
    });
    html += `</div>`;
  }
  
  html += `</div>`;
  
  // Show custom notification with longer duration
  showCustomNotification(html, 8000); // 8 seconds
}

/**
 * Show custom HTML notification (enhanced toast)
 */
function showCustomNotification(html, duration = 5000) {
  // Remove any existing notification
  $(".custom-notification").remove();
  
  const $notification = $("<div>")
    .addClass("custom-notification")
    .html(html)
    .appendTo("body");
  
  // Fade in
  setTimeout(() => {
    $notification.addClass("show");
  }, 10);
  
  // Auto-dismiss
  setTimeout(() => {
    $notification.removeClass("show");
    setTimeout(() => {
      $notification.remove();
    }, 300);
  }, duration);
  
  // Click to dismiss
  $notification.on("click", function(e) {
    // Don't dismiss if clicking a button
    if (!$(e.target).is("button") && !$(e.target).closest("button").length) {
      $notification.removeClass("show");
      setTimeout(() => {
        $notification.remove();
      }, 300);
    }
  });
}

/**
 * Handle "Create Reminder" button click from notification
 */
$(document).on("click", ".btn-create-reminder", function() {
  const vehicleId = $(this).data("vehicle-id");
  const serviceName = $(this).data("service-name");
  
  if (!vehicleId || !serviceName) return;
  
  // Close notification
  $(".custom-notification").remove();
  
  // Navigate to reminders page
  showView("reminders");
  setActiveVehicle(vehicleId);
  
  // Pre-fill reminder form
  setTimeout(() => {
    const $serviceSelect = $("#rem-new-service");
    const $serviceCustom = $("#rem-new-service-custom");
    
    // Check if service exists in dropdown
    const optionExists = $serviceSelect.find(`option[value="${serviceName}"]`).length > 0;
    
    if (optionExists) {
      $serviceSelect.val(serviceName).trigger("change");
    } else {
      $serviceCustom.val(serviceName).trigger("blur");
    }
    
    // Scroll to form
    $("#reminder-form")[0].scrollIntoView({ behavior: "smooth", block: "center" });
    
    // Highlight the form briefly
    $("#reminder-form").addClass("highlight-form");
    setTimeout(() => {
      $("#reminder-form").removeClass("highlight-form");
    }, 2000);
  }, 300);
});
