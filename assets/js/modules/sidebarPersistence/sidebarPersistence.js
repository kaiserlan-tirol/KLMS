/**
 * Sidebar Persistence Module
 * Persists the collapsed/expanded state of Bootstrap collapse elements in the admin sidebar
 */

const $ = require('jquery');

class SidebarPersistence {
    constructor() {
        this.storageKey = 'admin_sidebar_state';
        this.init();
    }

    init() {
        // Load saved state on page load
        this.loadSidebarState();
        
        // Listen for collapse events to save state
        this.bindCollapseEvents();
    }

    /**
     * Get the current sidebar state from localStorage
     */
    getSavedState() {
        try {
            const saved = localStorage.getItem(this.storageKey);
            return saved ? JSON.parse(saved) : {};
        } catch (e) {
            console.warn('Failed to parse sidebar state from localStorage:', e);
            return {};
        }
    }

    /**
     * Save the sidebar state to localStorage
     */
    saveSidebarState() {
        const state = {};
        
        // Find all collapsible elements in the sidebar
        $('[data-toggle="collapse"]').each(function() {
            const $toggle = $(this);
            const targetId = $toggle.attr('href');
            
            if (targetId && targetId.startsWith('#')) {
                const $target = $(targetId);
                const isCollapsed = $toggle.hasClass('collapsed');
                const isVisible = $target.hasClass('show');
                
                state[targetId] = {
                    collapsed: isCollapsed,
                    show: isVisible
                };
            }
        });
        
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(state));
        } catch (e) {
            console.warn('Failed to save sidebar state to localStorage:', e);
        }
    }

    /**
     * Load and apply the saved sidebar state
     */
    loadSidebarState() {
        const savedState = this.getSavedState();
        
        // Apply saved state to each collapsible element
        Object.keys(savedState).forEach(targetId => {
            const state = savedState[targetId];
            const $toggle = $(`[data-toggle="collapse"][href="${targetId}"]`);
            const $target = $(targetId);
            
            if ($toggle.length && $target.length) {
                // Apply collapsed class to toggle button
                if (state.collapsed) {
                    $toggle.addClass('collapsed');
                } else {
                    $toggle.removeClass('collapsed');
                }
                
                // Apply show class to target element
                if (state.show) {
                    $target.addClass('show');
                } else {
                    $target.removeClass('show');
                }
                
                // Update aria-expanded attribute for accessibility
                $toggle.attr('aria-expanded', state.show ? 'true' : 'false');
            }
        });
    }

    /**
     * Bind event listeners to collapse elements
     */
    bindCollapseEvents() {
        // Listen for Bootstrap collapse events
        $('[data-toggle="collapse"]').on('click', () => {
            // Use a small delay to ensure Bootstrap has updated the classes
            setTimeout(() => {
                this.saveSidebarState();
            }, 50);
        });
        
        // Also listen for the Bootstrap collapse events directly
        $('.collapse').on('shown.bs.collapse hidden.bs.collapse', () => {
            this.saveSidebarState();
        });
    }

    /**
     * Clear saved sidebar state (useful for debugging or reset functionality)
     */
    clearSavedState() {
        try {
            localStorage.removeItem(this.storageKey);
        } catch (e) {
            console.warn('Failed to clear sidebar state from localStorage:', e);
        }
    }
}

// Export for use in other modules
module.exports = SidebarPersistence;

// Auto-initialize if jQuery is available
$(document).ready(() => {
    if ($('[data-toggle="collapse"]').length > 0) {
        new SidebarPersistence();
    }
});
