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
      .html(`⚠ ${result.count} recall${result.count > 1 ? 's' : ''}`);
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
        <div class="no-recalls-icon">✓</div>
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


