/**
 * Garage Maintenance - User Menu Component
 * Handles user authentication display and menu for multi-user mode
 * User menu is integrated into the main navigation bar after Settings
 */

(function() {
    'use strict';

    // User state
    let currentUser = null;
    let authUrls = null;
    let multiUserEnabled = false;

    /**
     * Initialize user menu from loaded data
     */
    function initUserMenu() {
        if (typeof data === 'undefined' || !data) {
            return;
        }

        multiUserEnabled = data.multiUserEnabled || false;
        
        if (!multiUserEnabled) {
            return;
        }

        currentUser = data.user || null;
        authUrls = data.authUrls || null;

        renderUserMenu();
    }

    /**
     * Render user menu in navigation bar (after Settings button)
     */
    function renderUserMenu() {
        if (!multiUserEnabled) {
            return;
        }

        // Remove existing menu if present
        const existingMenu = document.getElementById('user-menu');
        if (existingMenu) {
            existingMenu.remove();
        }

        // Find the nav bar
        const nav = document.querySelector('.nav');
        if (!nav) {
            console.warn('Navigation bar not found for user menu');
            return;
        }

        const menu = document.createElement('div');
        menu.id = 'user-menu';
        menu.className = 'user-menu';

        if (currentUser && currentUser.id) {
            // User is logged in
            const displayName = currentUser.display_name || currentUser.email || 'User';
            const initials = getInitials(displayName);
            const hasSubscription = currentUser.has_subscription;

            menu.innerHTML = `
                <button type="button" class="user-menu-trigger nav-btn" aria-haspopup="true" aria-expanded="false">
                    <span class="user-avatar">${escapeHtml(initials)}</span>
                    <span class="user-name">${escapeHtml(displayName)}</span>
                    ${hasSubscription 
                        ? '<span class="subscription-badge active">✓ Pro</span>'
                        : '<span class="subscription-badge inactive">Free</span>'
                    }
                    <span class="user-menu-arrow">▼</span>
                </button>
                <div class="user-menu-dropdown">
                    <div class="user-menu-header">
                        <div class="user-email">${escapeHtml(currentUser.email || '')}</div>
                        ${hasSubscription 
                            ? '<div class="user-subscription-status">✓ Pro Account</div>'
                            : '<div class="user-subscription-status free">Free Account</div>'
                        }
                    </div>
                    <div class="user-menu-items">
                        <a href="${authUrls?.profile_url || '/wp-admin/profile.php'}" class="user-menu-item">
                            👤 My Profile
                        </a>
                        ${!hasSubscription ? `
                        <a href="${authUrls?.subscribe_url || '/subscribe/'}" class="user-menu-item user-menu-upgrade">
                            ⭐ Upgrade to Pro
                        </a>
                        ` : ''}
                        <div class="user-menu-divider"></div>
                        <a href="${authUrls?.logout_url || '/wp-login.php?action=logout'}" class="user-menu-item user-menu-logout">
                            🚪 Log Out
                        </a>
                    </div>
                </div>
            `;

            // Toggle dropdown on click
            const trigger = menu.querySelector('.user-menu-trigger');
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = menu.classList.toggle('open');
                trigger.setAttribute('aria-expanded', isOpen);
            });

            // Close on outside click
            document.addEventListener('click', function(e) {
                if (!menu.contains(e.target)) {
                    menu.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                }
            });

            // Close on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && menu.classList.contains('open')) {
                    menu.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                }
            });

        } else {
            // User is not logged in (shouldn't happen if auth is required)
            menu.innerHTML = `
                <a href="${authUrls?.login_url || '/wp-login.php'}" class="user-menu-login-btn nav-btn">
                    🔐 Log In
                </a>
            `;
        }

        // Insert user menu at the end of nav (after Settings button)
        nav.appendChild(menu);
    }

    /**
     * Get initials from display name
     */
    function getInitials(name) {
        if (!name) return '?';
        const parts = name.trim().split(/\s+/);
        if (parts.length === 1) {
            return parts[0].charAt(0).toUpperCase();
        }
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Handle authentication errors from API responses
     */
    function handleAuthError(response) {
        if (!response) return false;

        if (response.error === 'authentication_required') {
            const loginUrl = response.login_url || authUrls?.login_url || '/wp-login.php';
            window.location.href = loginUrl;
            return true;
        }

        if (response.error === 'subscription_required') {
            const subscribeUrl = response.subscribe_url || authUrls?.subscribe_url || '/subscribe/';
            window.location.href = subscribeUrl;
            return true;
        }

        if (response.error === 'rate_limit_exceeded') {
            if (typeof showToast === 'function') {
                showToast('Too many requests. Please wait a moment.', 'warning');
            } else {
                alert('Too many requests. Please wait a moment.');
            }
            return true;
        }

        return false;
    }

    // Expose functions globally
    window.gmUser = {
        init: initUserMenu,
        render: renderUserMenu,
        handleAuthError: handleAuthError,
        getCurrentUser: function() { return currentUser; },
        isMultiUser: function() { return multiUserEnabled; }
    };

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            // Wait for data to be loaded
            setTimeout(initUserMenu, 100);
        });
    } else {
        setTimeout(initUserMenu, 100);
    }

})();