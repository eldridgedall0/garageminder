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

          // ── Store subscription / tier-limit data ───────────────────────
          // Available to all feature modules as window.GM_SUBSCRIPTION.
          // When null (single-user mode) we set a full-access default so that
          // feature modules never have to special-case the absence of this key.
          if (resp.data.subscription) {
            window.GM_SUBSCRIPTION = resp.data.subscription;
          } else {
            window.GM_SUBSCRIPTION = {
              tier: 'free',
              tier_name: 'Free',
              is_active: true,
              limits: {},
              usage: {
                vehicles: { used: 0, max: -1, remaining: -1, unlimited: true },
                entries:  { used: 0, max: -1, remaining: -1, unlimited: true },
              },
              features: {
                recalls:               true,
                export:                true,
                export_level:          'bulk',
                attachments:           true,
                attachments_per_entry: 5,
                vehicle_photos:        true,
                local_upload:          true,
                gdrive:                true,
                templates:             true,
                max_templates:         -1,
              },
              upgrade_url: '/pricing/',
            };
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
          // Check for auth error (401)
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

          // Check for subscription / plan limit errors (403)
          if (xhr.status === 403) {
            try {
              const resp = JSON.parse(xhr.responseText);
              const limitErrors = [
                'vehicle_limit_reached',
                'entry_limit_reached',
                'template_limit_reached',
                'local_upload_not_allowed',
                'attachment_limit_reached',
              ];
              if (resp && limitErrors.includes(resp.error)) {
                // Show upgrade modal with the server message
                if (typeof showUpgradeModal === 'function') {
                  showUpgradeModal({
                    title: 'Plan Limit Reached',
                    message: resp.message || 'This action is not available on your current plan.',
                    feature: resp.error,
                  });
                } else {
                  alert(resp.message || 'Plan limit reached. Please upgrade.');
                }
                reject(new Error(resp.error));
                return;
              }
            } catch (e) {}
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

// ============================================================
// GM SUBSCRIPTION HELPERS
// Provides a clean API for all feature modules to read limits
// from window.GM_SUBSCRIPTION (populated in loadData above).
// ============================================================

const gmSub = {

  /** Return the full subscription object (or null if not yet loaded). */
  get: function() {
    return window.GM_SUBSCRIPTION || null;
  },

  /**
   * Check if a boolean/flag feature is available.
   * Feature keys mirror WP Admin tier limit keys:
   *   'recalls', 'export', 'attachments', 'vehicle_photos',
   *   'local_upload', 'gdrive', 'templates'
   */
  can: function(featureKey) {
    const sub = this.get();
    if (!sub || !sub.features) return true;  // default: allow when no data
    const val = sub.features[featureKey];
    if (val === undefined) return true;
    if (typeof val === 'boolean') return val;
    if (typeof val === 'number')  return val > 0 || val === -1;
    return val !== 'none' && val !== '0' && val !== '';
  },

  /** Return a numeric limit (-1 = unlimited).  Reads from limits{} block. */
  limit: function(key) {
    const sub = this.get();
    if (!sub || !sub.limits) return -1;
    const v = sub.limits[key];
    return v !== undefined ? Number(v) : -1;
  },

  /**
   * Check whether the user is AT or OVER a counted limit.
   * countType: 'vehicles' | 'entries'
   */
  atLimit: function(countType) {
    const sub = this.get();
    if (!sub || !sub.usage || !sub.usage[countType]) return false;
    const u = sub.usage[countType];
    if (u.unlimited) return false;
    return u.remaining <= 0;
  },

  /** Number remaining for a countType (-1 = unlimited). */
  remaining: function(countType) {
    const sub = this.get();
    if (!sub || !sub.usage || !sub.usage[countType]) return -1;
    const u = sub.usage[countType];
    if (u.unlimited) return -1;
    return u.remaining;
  },

  /** Current usage count for a countType. */
  used: function(countType) {
    const sub = this.get();
    if (!sub || !sub.usage || !sub.usage[countType]) return 0;
    return sub.usage[countType].used || 0;
  },

  /** Max allowed for a countType (-1 = unlimited). */
  max: function(countType) {
    const sub = this.get();
    if (!sub || !sub.usage || !sub.usage[countType]) return -1;
    const u = sub.usage[countType];
    return u.unlimited ? -1 : (u.max || -1);
  },

  /** Upgrade / pricing page URL. */
  upgradeUrl: function() {
    return this.get()?.upgrade_url || '/pricing/';
  },

  /** Human-readable tier name (e.g. "Free", "Pro", "Fleet"). */
  tierName: function() {
    return this.get()?.tier_name || 'Free';
  },

  /** Raw tier slug (e.g. "free", "paid", "fleet"). */
  tier: function() {
    return this.get()?.tier || 'free';
  },

  /** export_level string: 'none' | 'standard' | 'bulk' */
  exportLevel: function() {
    return this.get()?.features?.export_level || 'none';
  },

  /** Attachments per entry allowed by the subscription (0 = none). */
  attachmentsPerEntry: function() {
    const sub = this.get();
    return sub?.features?.attachments_per_entry ?? 0;
  },

  /** Max templates allowed (-1 = unlimited, 0 = none). */
  maxTemplates: function() {
    const sub = this.get();
    return sub?.features?.max_templates ?? -1;
  },
};

// Expose globally so all feature modules can use it
window.gmSub = gmSub;
