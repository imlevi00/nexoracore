<!-- Units Management Modal -->
<div class="modal fade" id="unitsManagementModal" tabindex="-1" aria-labelledby="unitsManagementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="unitsManagementModalLabel">
                    <i class="bi bi-gear"></i> بەڕێوەبردنی یەکەکان
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Default unit for new products -->
                <div class="card border-primary mb-3 shadow-sm">
                    <div class="card-body py-3">
                        <h6 class="card-title text-primary mb-2">
                            <i class="bi bi-star-fill"></i> یەکەی بنەڕەتی بۆ کاڵای نوێ
                        </h6>
                        <p class="small text-muted mb-2">
                            ئەم یەکەیە ڕاستەوخۆ لە فۆرمی زیادکردنی کاڵا دەردەکەوێت وەک یەکەی بنەڕەت (نرخ و مۆڵەت لەسەری دادەنێیت).
                        </p>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <label for="modal_default_unit_select" class="visually-hidden">یەکەی بنەڕەتی</label>
                            <select class="form-select form-select-sm" id="modal_default_unit_select" style="max-width: 280px;" aria-label="یەکەی بنەڕەتی">
                            </select>
                            <button type="button" class="btn btn-sm btn-primary" id="modal_save_default_unit_btn">
                                <i class="bi bi-check2-circle"></i> پاشەکەوت
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Add New Unit Section -->
                <div class="card mb-3">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0">
                            <i class="bi bi-plus-circle"></i> زیادکردنی یەکەی نوێ
                        </h6>
                    </div>
                    <div class="card-body">
                        <form id="addUnitForm">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="modal_new_unit_name" class="form-label">
                                        ناوی یەکە <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="modal_new_unit_name" 
                                           placeholder="بۆ نموونە: کارتۆن، دانە، کیلۆ..." required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="modal_new_unit_symbol" class="form-label">
                                        هێما (دڵخواز)
                                    </label>
                                    <input type="text" class="form-control" id="modal_new_unit_symbol" 
                                           placeholder="بۆ نموونە: kg, pc...">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label d-block">&nbsp;</label>
                                    <button type="submit" class="btn btn-success btn-sm w-100">
                                        <i class="bi bi-plus-circle"></i> زیادکردن
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Units List Section -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">
                            <i class="bi bi-list-ul"></i> لیستی یەکەکان
                        </h6>
                    </div>
                    <div class="card-body">
                        <div id="unitsListContainer">
                            <div class="text-center py-3">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> داخستن
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Units Management JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const unitsModal = document.getElementById('unitsManagementModal');
    
    // Load units when modal is opened
    unitsModal.addEventListener('show.bs.modal', function () {
        loadUnitsListModal();
    });
    
    // Add new unit form submission
    document.getElementById('addUnitForm').addEventListener('submit', function(e) {
        e.preventDefault();
        addNewUnitModal();
    });

    const saveDefBtn = document.getElementById('modal_save_default_unit_btn');
    if (saveDefBtn) {
        saveDefBtn.addEventListener('click', function() {
            setDefaultUnitFromModal();
        });
    }
});

function populateDefaultUnitSelect(unitList) {
    const sel = document.getElementById('modal_default_unit_select');
    if (!sel) return;
    sel.innerHTML = '';
    unitList.forEach(function(u) {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.name + (u.symbol ? ' (' + u.symbol + ')' : '');
        if (u.is_default == 1) opt.selected = true;
        sel.appendChild(opt);
    });
}

function syncDefaultUnitEverywhere(newDefaultId) {
    if (typeof units !== 'undefined') {
        units.forEach(function(u) {
            u.is_default = (String(u.id) === String(newDefaultId)) ? 1 : 0;
        });
    }
    const hint = document.getElementById('default_unit_hint');
    if (hint && typeof units !== 'undefined') {
        const u = units.find(function(x) { return String(x.id) === String(newDefaultId); });
        if (u) {
            let sym = u.symbol ? ' <span class="text-muted">(' + escapeHtml(u.symbol) + ')</span>' : '';
            hint.innerHTML = 'یەکەی بنەڕەتی کاڵای نوێ: <strong>' + escapeHtml(u.name) + '</strong>' + sym;
        }
    }
    if (typeof window.onDefaultUnitChanged === 'function') {
        window.onDefaultUnitChanged(newDefaultId);
    }
}

function setDefaultUnitById(unitId) {
    const sel = document.getElementById('modal_default_unit_select');
    if (sel) sel.value = String(unitId);
    setDefaultUnitFromModal();
}

function setDefaultUnitFromModal() {
    const sel = document.getElementById('modal_default_unit_select');
    if (!sel || !sel.value) {
        showModalMessage('تکایە یەکەیەک هەڵبژێرە', 'warning');
        return;
    }
    const btn = document.getElementById('modal_save_default_unit_btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> پاشەکەوت...';
    }
    const formData = new FormData();
    formData.append('unit_id', sel.value);
    formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');

    fetch('<?php echo url('user/api/set_default_unit.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            syncDefaultUnitEverywhere(parseInt(sel.value, 10));
            loadUnitsListModal();
            showModalMessage(data.message || 'یەکەی بنەڕەتی نوێکرایەوە', 'success');
        } else {
            showModalMessage(data.message || 'هەڵەیەک ڕوویدا', 'danger');
        }
    })
    .catch(function(error) {
        console.error('Error setting default unit:', error);
        showModalMessage('هەڵەیەک ڕوویدا لە پاشەکەوتکردن', 'danger');
    })
    .finally(function() {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle"></i> پاشەکەوت';
        }
    });
}

// Load units list in modal
function loadUnitsListModal() {
    const container = document.getElementById('unitsListContainer');
    
    fetch('<?php echo url('user/api/get_units.php'); ?>')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.units.length === 0) {
                    populateDefaultUnitSelect([]);
                    container.innerHTML = `
                        <div class="alert alert-info text-center">
                            <i class="bi bi-info-circle"></i> هیچ یەکەیەک نییە
                        </div>
                    `;
                } else {
                    let html = '<div class="list-group">';
                    populateDefaultUnitSelect(data.units);

                    data.units.forEach(unit => {
                        const isDefault = unit.is_default == 1;
                        const inUse = parseInt(unit.product_count, 10) > 0;
                        const deleteDisabled = isDefault || inUse;
                        let deleteTitle = '';
                        if (isDefault) {
                            deleteTitle = 'ناتوانیت یەکەی بنەڕەتی بسڕیتەوە';
                        } else if (inUse) {
                            deleteTitle = 'ئەم یەکەیە لە یەک یان زیاتر کاڵادا بەکارهاتووە؛ ناتوانیت بسڕیتەوە';
                        }
                        const badge = isDefault ? '<span class="badge bg-primary ms-2">یەکەی بنەڕەتی کاڵای نوێ</span>' : '';
                        const inUseHint = inUse && !isDefault
                            ? '<br><small class="text-warning"><i class="bi bi-link-45deg"></i> لە کاڵادا بەکارهاتووە</small>'
                            : '';
                        
                        html += `
                            <div class="list-group-item" id="unit-item-${unit.id}">
                                <div class="row align-items-center">
                                    <div class="col-md-5">
                                        <strong>${escapeHtml(unit.name)}</strong> ${badge}${inUseHint}
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted">
                                            هێما: ${unit.symbol ? escapeHtml(unit.symbol) : '-'}
                                        </small>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <button type="button" class="btn btn-sm btn-outline-secondary mb-1" 
                                                onclick="setDefaultUnitById(${unit.id})"
                                                ${isDefault ? 'disabled' : ''}
                                                title="وەک یەکەی بنەڕەتی بۆ کاڵای نوێ دابنێ">
                                            <i class="bi bi-star"></i> بنەڕەتی بکە
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary mb-1" 
                                                onclick="editUnitModal(${unit.id}, '${escapeHtml(unit.name)}', '${unit.symbol || ''}')">
                                            <i class="bi bi-pencil"></i> دەستکاری
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger mb-1" 
                                                ${deleteDisabled ? 'disabled' : ''}
                                                ${deleteTitle ? 'title="' + escapeHtml(deleteTitle) + '"' : ''}
                                                onclick="deleteUnitModal(${unit.id}, '${escapeHtml(unit.name)}')">
                                            <i class="bi bi-trash"></i> سڕینەوە
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                }
            } else {
                container.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> ${escapeHtml(data.message || 'هەڵەیەک ڕوویدا')}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading units:', error);
            container.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> هەڵەیەک ڕوویدا لە بارکردنی یەکەکان
                </div>
            `;
        });
}

// Add new unit from modal
function addNewUnitModal() {
    const name = document.getElementById('modal_new_unit_name').value.trim();
    const symbol = document.getElementById('modal_new_unit_symbol').value.trim();
    const submitBtn = document.querySelector('#addUnitForm button[type="submit"]');
    
    if (!name) {
        showModalMessage('تکایە ناوی یەکە داخڵ بکە', 'warning');
        return;
    }
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> زیادکردن...';
    
    const formData = new FormData();
    formData.append('name', name);
    formData.append('symbol', symbol);
    formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
    
    fetch('<?php echo url('user/api/add_unit.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Clear form
            document.getElementById('modal_new_unit_name').value = '';
            document.getElementById('modal_new_unit_symbol').value = '';
            
            // Reload units list
            loadUnitsListModal();
            
            // Update units array in parent page
            if (typeof units !== 'undefined') {
                units.push(data.unit);
                
                // Update dropdown in parent page
                const dropdown = document.getElementById('unit_selection_dropdown');
                if (dropdown) {
                    const option = document.createElement('option');
                    option.value = data.unit.id;
                    option.textContent = data.unit.name + (data.unit.symbol ? ' (' + data.unit.symbol + ')' : '');
                    dropdown.appendChild(option);
                }
            }
            
            showModalMessage('یەکەی نوێ بە سەرکەوتوویی زیادکرا', 'success');
        } else {
            showModalMessage(data.message || 'هەڵەیەک ڕوویدا', 'danger');
        }
    })
    .catch(error => {
        console.error('Error adding unit:', error);
        showModalMessage('هەڵەیەک ڕوویدا لە زیادکردنی یەکە', 'danger');
    })
    .finally(() => {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-plus-circle"></i> زیادکردن';
    });
}

// Edit unit from modal
function editUnitModal(unitId, currentName, currentSymbol) {
    // Create inline edit form
    const unitItem = document.getElementById(`unit-item-${unitId}`);
    
    unitItem.innerHTML = `
        <form id="editUnitForm-${unitId}" onsubmit="saveEditUnitModal(event, ${unitId})">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <input type="text" class="form-control form-control-sm" 
                           id="edit_unit_name_${unitId}" value="${currentName}" required>
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" 
                           id="edit_unit_symbol_${unitId}" value="${currentSymbol}" 
                           placeholder="هێما">
                </div>
                <div class="col-md-4 text-end">
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check"></i> پاشەکەوت
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" 
                            onclick="loadUnitsListModal()">
                        <i class="bi bi-x"></i> هەڵوەشاندنەوە
                    </button>
                </div>
            </div>
        </form>
    `;
    
    // Focus on name input
    document.getElementById(`edit_unit_name_${unitId}`).focus();
}

// Save edited unit
function saveEditUnitModal(event, unitId) {
    event.preventDefault();
    
    const name = document.getElementById(`edit_unit_name_${unitId}`).value.trim();
    const symbol = document.getElementById(`edit_unit_symbol_${unitId}`).value.trim();
    
    if (!name) {
        showModalMessage('تکایە ناوی یەکە داخڵ بکە', 'warning');
        return;
    }
    
    const formData = new FormData();
    formData.append('unit_id', unitId);
    formData.append('name', name);
    formData.append('symbol', symbol);
    formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
    
    fetch('<?php echo url('user/api/update_unit.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload units list
            loadUnitsListModal();
            
            // Update units array in parent page
            if (typeof units !== 'undefined') {
                const unitIndex = units.findIndex(u => u.id == unitId);
                if (unitIndex !== -1) {
                    units[unitIndex] = data.unit;
                }
                
                // Update dropdown in parent page
                const dropdown = document.getElementById('unit_selection_dropdown');
                if (dropdown) {
                    const option = dropdown.querySelector(`option[value="${unitId}"]`);
                    if (option) {
                        option.textContent = data.unit.name + (data.unit.symbol ? ' (' + data.unit.symbol + ')' : '');
                    }
                }
            }
            
            showModalMessage('یەکە بە سەرکەوتوویی نوێکرایەوە', 'success');
        } else {
            showModalMessage(data.message || 'هەڵەیەک ڕوویدا', 'danger');
            loadUnitsListModal();
        }
    })
    .catch(error => {
        console.error('Error updating unit:', error);
        showModalMessage('هەڵەیەک ڕوویدا لە نوێکردنەوەی یەکە', 'danger');
        loadUnitsListModal();
    });
}

// Delete unit from modal
function deleteUnitModal(unitId, unitName) {
    if (!confirm(`ئایا دڵنیایت لە سڕینەوەی یەکەی "${unitName}"؟`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('unit_id', unitId);
    formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
    
    fetch('<?php echo url('user/api/delete_unit.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload units list
            loadUnitsListModal();
            
            // Update units array in parent page
            if (typeof units !== 'undefined') {
                const unitIndex = units.findIndex(u => u.id == unitId);
                if (unitIndex !== -1) {
                    units.splice(unitIndex, 1);
                }
                
                // Update dropdown in parent page
                const dropdown = document.getElementById('unit_selection_dropdown');
                if (dropdown) {
                    const option = dropdown.querySelector(`option[value="${unitId}"]`);
                    if (option) {
                        option.remove();
                    }
                }
            }
            
            showModalMessage('یەکە بە سەرکەوتوویی سڕایەوە', 'success');
        } else {
            showModalMessage(data.message || 'هەڵەیەک ڕوویدا', 'danger');
        }
    })
    .catch(error => {
        console.error('Error deleting unit:', error);
        showModalMessage('هەڵەیەک ڕوویدا لە سڕینەوەی یەکە', 'danger');
    });
}

// Show message in modal
function showModalMessage(message, type = 'info') {
    const container = document.querySelector('#unitsManagementModal .modal-body');
    
    // Remove existing alerts
    const existingAlerts = container.querySelectorAll('.alert-modal-message');
    existingAlerts.forEach(alert => alert.remove());
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show alert-modal-message`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    container.insertBefore(alertDiv, container.firstChild);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 3000);
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.toString().replace(/[&<>"']/g, m => map[m]);
}
</script>

