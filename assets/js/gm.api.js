/**
 * Garage Maintenance - API Functions
 * Handles loading and saving data to backend
 * Multi-user ready with authentication error handling
 */

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
        
        // Handle authentication errors
        if (resp && resp.error === 'authentication_required') {
          if (resp.login_url) {
            window.location.href = resp.login_url;
          } else {
            window.location.reload();
          }
          return;
        }
        
        if (resp && resp.success && resp.data) {
          data = resp.data;
          
          // Store user info if provided (multi-user mode)
          if (resp.data.user) {
            window.gmUser = resp.data.user;
          }
          if (resp.data.authUrls) {
            window.gmAuthUrls = resp.data.authUrls;
          }
        }
      },
      error: function(xhr, status, err) {
        // Check for auth error
        if (xhr.status === 401) {
          try {
            const resp = JSON.parse(xhr.responseText);
            if (resp.login_url) {
              window.location.href = resp.login_url;
              return;
            }
          } catch (e) {}
          window.location.reload();
          return;
        }
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


/**
 * Save data to backend - returns a Promise for async usage
 */
function saveData() {
  return new Promise((resolve, reject) => {
    try {
      if (data && typeof data === "object") {
        // FIXED: Preserve "all" as a valid value, don't convert to null
        // Only use null if activeVehicleId is truly empty/undefined
        if (activeVehicleId === "all") {
          data.activeVehicleId = "all";
        } else if (activeVehicleId) {
          data.activeVehicleId = activeVehicleId;
        } else {
          data.activeVehicleId = "all"; // Default to "all" if nothing set
        }
      }

      $.ajax({
        url: BACKEND_URL + "?action=save",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify({ data: data || {} }),
        success: function(resp) {
          if (resp && resp.success) {
            showToast("Changes saved");
            resolve(resp);
          } else if (resp && resp.db_error) {
            const banner = document.getElementById("db-error-banner");
            if (banner) banner.style.display = "block";
            reject(new Error("Database error"));
          } else if (resp && resp.error === 'authentication_required') {
            // Handle auth error
            if (resp.login_url) {
              window.location.href = resp.login_url;
            } else {
              window.location.reload();
            }
            reject(new Error("Authentication required"));
          } else {
            resolve(resp);
          }
        },
        error: function(xhr, status, err) {
          // Check for auth error
          if (xhr.status === 401) {
            try {
              const resp = JSON.parse(xhr.responseText);
              if (resp.login_url) {
                window.location.href = resp.login_url;
                return;
              }
            } catch (e) {}
            window.location.reload();
            return;
          }
          console.error("Error saving data:", err);
          reject(err);
        }
      });
    } catch (e) {
      console.error("Error in saveData:", e);
      reject(e);
    }
  });
}

/**
 * Helper function for backward compatibility - fire and forget save
 */
function saveDataSync() {
  saveData().catch(err => console.error("Save failed:", err));
}