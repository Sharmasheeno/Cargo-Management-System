/**
 * Premium UI Logic for CURDUB SMART CARGO
 * Handles Bulk Actions, Enhanced Tables, and Action Menus
 */

document.addEventListener('DOMContentLoaded', function() {
    initPremiumUI();
});

function initPremiumUI() {
    // 1. Bulk Selection Logic
    initBulkSelection();
    
    // 2. Action Menu Tooltips (if any)
    // 3. Table Hover Effects (CSS handles most)
}

function initBulkSelection() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.customer-checkbox, .row-checkbox');
    const bulkBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');

    if (!selectAll || !bulkBar) return;

    function updateBulkBar() {
        const checked = document.querySelectorAll('.customer-checkbox:checked, .row-checkbox:checked');
        if (checked.length > 0) {
            bulkBar.style.display = 'flex';
            if (selectedCount) selectedCount.textContent = checked.length + ' la doortay';
        } else {
            bulkBar.style.display = 'none';
        }
    }

    selectAll.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.customer-checkbox, .row-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = this.checked;
            const row = cb.closest('tr');
            if (row) {
                if (this.checked) row.classList.add('selected-row');
                else row.classList.remove('selected-row');
            }
        });
        updateBulkBar();
    });

    // Delegate checkbox changes
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('customer-checkbox') || e.target.classList.contains('row-checkbox')) {
            const row = e.target.closest('tr');
            if (row) {
                if (e.target.checked) row.classList.add('selected-row');
                else row.classList.remove('selected-row');
            }
            updateBulkBar();
            
            // Update selectAll status
            const allCbs = document.querySelectorAll('.customer-checkbox, .row-checkbox');
            const allChecked = Array.from(allCbs).every(cb => cb.checked);
            selectAll.checked = allChecked && allCbs.length > 0;
        }
    });

    // Handle Bulk Delete
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function() {
            const checked = document.querySelectorAll('.customer-checkbox:checked, .row-checkbox:checked');
            const ids = Array.from(checked).map(cb => cb.value);
            
            if (ids.length === 0) return;

            if (confirm(`Ma hubtaa inaad tirtirto ${ids.length} shay oo la doortay?`)) {
                // This function should be defined in the specific page or handled globally
                if (typeof window.handleBulkDelete === 'function') {
                    window.handleBulkDelete(ids);
                } else {
                    console.error('handleBulkDelete function not defined on this page');
                }
            }
        });
    }
}

// Global Export Functionality
function exportData(type, format) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('ajax_action', 'export_' + type);
    urlParams.set('format', format);
    window.location.href = '?' + urlParams.toString();
}

// Global Import Trigger
function triggerImport(inputId) {
    document.getElementById(inputId).click();
}

// Handle Row Click for Selection (Optional UX)
document.addEventListener('click', function(e) {
    const row = e.target.closest('tr');
    if (row && !e.target.closest('a, button, .action-menu, input')) {
        const cb = row.querySelector('.customer-checkbox, .row-checkbox');
        if (cb) {
            cb.checked = !cb.checked;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
});
