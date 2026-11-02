let globalClassExpiries = {};

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initColorPicker();
    loadAllSettings();
    loadHolidays();
    setupFormListeners();
});

window.addEventListener('liveSyncUpdate', () => {
    if (document.getElementById('settings-form')) {
        loadAllSettings();
        loadHolidays(true);
    }
});

function initTabs() {
    const links = document.querySelectorAll('.settings-menu a');
    const sections = document.querySelectorAll('.settings-section');

    function activateTab(hash) {
        links.forEach(l => l.classList.remove('active'));
        sections.forEach(s => s.classList.remove('active'));

        const link = document.querySelector(`.settings-menu a[href="${hash}"]`);
        if (link) {
            link.classList.add('active');
            const targetId = hash.substring(1);
            const targetSec = document.getElementById(targetId);
            if (targetSec) targetSec.classList.add('active');
        }
    }

    links.forEach(link => {
        link.addEventListener('click', (e) => {
            // Keep default behavior to update hash in URL
            const hash = link.getAttribute('href');
            activateTab(hash);
        });
    });

    // Check hash on load
    if (window.location.hash) {
        activateTab(window.location.hash);
    } else if (links.length > 0) {
        activateTab(links[0].getAttribute('href'));
    }
    
    // Listen for hash changes (e.g. clicking sidebar menu links from the same page)
    window.addEventListener('hashchange', () => {
        if (window.location.hash) {
            activateTab(window.location.hash);
        }
    });
}

function initColorPicker() {
    const swatches = document.querySelectorAll('.color-swatch');
    const input = document.getElementById('input-accent-color');
    swatches.forEach(swatch => {
        swatch.addEventListener('click', () => {
            swatches.forEach(s => s.classList.remove('active'));
            swatch.classList.add('active');
            input.value = swatch.dataset.color;
        });
    });
}

async function loadAllSettings() {
    try {
        const res = await fetchData('api/settings.php?action=get');
        if (res && res.status === 'success') {
            const data = res.data;
            
            // Populate standard inputs
            document.querySelectorAll('input[type="text"], input[type="email"], input[type="time"], input[type="number"], input[type="color"], select, textarea').forEach(input => {
                if (data[input.name] !== undefined) {
                    input.value = data[input.name];
                }
            });
            
            // Populate radio buttons
            document.querySelectorAll('input[type="radio"]').forEach(radio => {
                if (data[radio.name] !== undefined) {
                    if (radio.value === data[radio.name]) {
                        radio.checked = true;
                    }
                }
            });

            // Populate checkboxes
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                if (data[checkbox.name] !== undefined) {
                    checkbox.checked = data[checkbox.name] === 'true';
                }
            });

            // Populate Color Picker
            if (data.accent_color) {
                const swatch = document.querySelector(`.color-swatch[data-color="${data.accent_color}"]`);
                if (swatch) {
                    document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
                    swatch.classList.add('active');
                    document.getElementById('input-accent-color').value = data.accent_color;
                }
            }

            // Populate School Logo Preview
            if (data.school_logo) {
                const preview = document.getElementById('logo-preview');
                if (preview) {
                    preview.src = data.school_logo;
                    preview.style.display = 'block';
                }
            }

            // Populate Weekends
            const daysContainer = document.getElementById('days-container');
            if (daysContainer) {
                const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                let schoolDays = {};
                try {
                    if(data.school_days) schoolDays = JSON.parse(data.school_days);
                } catch(e){}

                daysContainer.innerHTML = '';
                days.forEach(day => {
                    const isChecked = schoolDays[day] !== false; // Default to true if not set
                    daysContainer.innerHTML += `
                        <div class="setting-row">
                            <div class="setting-info">
                                <h4>${day}</h4>
                                <p>Record attendance on this day.</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="day_${day}" ${isChecked ? 'checked' : ''}>
                                <span class="slider"></span>
                            </label>
                        </div>
                    `;
                });
            }

            // Trigger preview for ID Card Designer if function exists
            if (typeof previewIDCard === 'function') {
                setTimeout(previewIDCard, 100);
            }

            // Populate Classes & Expiries
            try {
                if(data.class_expiries) globalClassExpiries = JSON.parse(data.class_expiries);
            } catch(e) {}
            renderClassesTable();

        }
    } catch(e) {
        console.error(e);
        showNotification("Failed to load settings.", "error");
    }
}

async function loadHolidays(silent = false) {
    try {
        const res = await fetchData('api/holidays.php?action=list');
        const tbody = document.querySelector('#holidays-table tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (res && res.status === 'success') {
            res.data.forEach(h => {
                tbody.innerHTML += `
                    <tr>
                        <td>
                            <strong>${h.name}</strong>
                            <div style="font-size:0.8rem; color:var(--text-muted);">${h.description || ''}</div>
                        </td>
                        <td>${h.start_date} to ${h.end_date}</td>
                        <td>
                            <button type="button" class="btn btn-danger" style="padding:0.3rem 0.6rem; font-size:0.8rem;" onclick="deleteHoliday(${h.id})">Delete</button>
                        </td>
                    </tr>
                `;
            });
            if(res.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;">No holidays configured.</td></tr>';
            }
        }
    } catch(e) {
        console.error(e);
    }
}

async function deleteHoliday(id) {
    if(confirm("Delete this holiday?")) {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch(API_BASE_URL + 'api/holidays.php?action=delete', {
            method: 'POST', body: fd
        });
        const json = await res.json();
        if(json.status === 'success') {
            showNotification("Holiday deleted", "success");
            loadHolidays();
        } else {
            showNotification(json.message, "error");
        }
    }
}

function renderClassesTable() {
    const tbody = document.querySelector('#classes-table tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    
    const classes = Object.keys(globalClassExpiries).sort();
    if (classes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="2" style="text-align:center;">No classes configured.</td></tr>';
        return;
    }

    classes.forEach(c => {
        let classData = globalClassExpiries[c];
        
        // Handle legacy format (string expiry date)
        if (typeof classData === 'string') {
            classData = { school_fee: 0, bus_fee: 0, meals_fee: 0 };
            globalClassExpiries[c] = classData; // auto-upgrade
        }

        tbody.innerHTML += `
            <tr>
                <td><strong>${c}</strong></td>
                <td>
                    <button type="button" class="btn btn-danger" style="padding:0.3rem 0.6rem; font-size:0.8rem;" onclick="deleteClass('${c.replace(/'/g, "\\'")}')">Delete</button>
                </td>
            </tr>
        `;
    });
}

async function deleteClass(className) {
    if(confirm(`Delete expiry date for ${className}?`)) {
        delete globalClassExpiries[className];
        const fd = new FormData();
        fd.append('class_expiries', JSON.stringify(globalClassExpiries));
        
        try {
            const res = await fetch(API_BASE_URL + 'api/settings.php?action=update', {
                method: 'POST', body: fd
            });
            const json = await res.json();
            if(json.status === 'success') {
                showNotification("Class deleted", "success");
                renderClassesTable();
            } else {
                showNotification(json.message, "error");
            }
        } catch(e) {
            showNotification("Error", "error");
        }
    }
}

function setupFormListeners() {
    // School logo preview listener
    const logoInput = document.getElementById('school_logo');
    if(logoInput) {
        logoInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('logo-preview');
                    if (preview) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                }
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    // Generic settings forms
    const forms = ['form-school', 'form-schedule', 'form-theme', 'form-dashboard', 'form-notifications', 'form-qrcode', 'form-idcard', 'form-scanner-billing', 'form-sms-settings', 'form-sms-templates', 'form-sms-triggers'];
    forms.forEach(formId => {
        const form = document.getElementById(formId);
        if(form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await saveSettings(new FormData(form), form.querySelector('button[type="submit"]'));
            });
        }
    });

    // Weekends form (Custom logic because dynamic inputs)
    const formWeekends = document.getElementById('form-weekends');
    if(formWeekends) {
        formWeekends.addEventListener('submit', async (e) => {
            e.preventDefault();
            const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            const schoolDays = {};
            days.forEach(d => {
                const input = formWeekends.querySelector(`input[name="day_${d}"]`);
                schoolDays[d] = input ? input.checked : false;
            });
            const fd = new FormData();
            fd.append('school_days', JSON.stringify(schoolDays));
            await saveSettings(fd, formWeekends.querySelector('button[type="submit"]'));
        });
    }

    // Add holiday form
    const formHoliday = document.getElementById('form-add-holiday');
    if(formHoliday) {
        formHoliday.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = formHoliday.querySelector('button[type="submit"]');
            const ogText = btn.innerHTML;
            btn.innerHTML = 'Saving...'; btn.disabled = true;
            
            try {
                const res = await fetch(API_BASE_URL + 'api/holidays.php?action=add', {
                    method: 'POST', body: new FormData(formHoliday)
                });
                const json = await res.json();
                if(json.status === 'success') {
                    showNotification("Holiday added", "success");
                    formHoliday.reset();
                    loadHolidays();
                } else {
                    showNotification(json.message, "error");
                }
            } catch(e) {
                showNotification("Error", "error");
            } finally {
                btn.innerHTML = ogText; btn.disabled = false;
            }
        });
    }

    // Add class form
    const formClass = document.getElementById('form-add-class');
    if(formClass) {
        formClass.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = formClass.querySelector('button[type="submit"]');
            const ogText = btn.innerHTML;
            btn.innerHTML = 'Saving...'; btn.disabled = true;

            const fd = new FormData(formClass);
            const cname = fd.get('class_name');
            const sf = fd.get('school_fee') || 0;
            const bf = fd.get('bus_fee') || 0;
            const mf = fd.get('meals_fee') || 0;

            if (cname) {
                globalClassExpiries[cname] = {
                    school_fee: parseFloat(sf),
                    bus_fee: parseFloat(bf),
                    meals_fee: parseFloat(mf)
                };
                
                const saveFd = new FormData();
                saveFd.append('class_expiries', JSON.stringify(globalClassExpiries));
                
                try {
                    const res = await fetch(API_BASE_URL + 'api/settings.php?action=update', {
                        method: 'POST', body: saveFd
                    });
                    const json = await res.json();
                    if(json.status === 'success') {
                        showNotification("Class saved", "success");
                        formClass.reset();
                        renderClassesTable();
                    } else {
                        showNotification(json.message, "error");
                    }
                } catch(e) {
                    showNotification("Error", "error");
                }
            }
            
            btn.innerHTML = ogText; btn.disabled = false;
        });
    }
}

async function saveSettings(formData, btnElement) {
    const ogText = btnElement.innerHTML;
    btnElement.innerHTML = 'Saving...';
    btnElement.disabled = true;

    // Convert checkboxes to "true"/"false" explicitly
    for (let pair of formData.entries()) {
        const input = document.querySelector(`input[name="${pair[0]}"][type="checkbox"]`);
        if (input) {
            formData.set(pair[0], input.checked ? "true" : "false");
        }
    }
    
    // Check for unchecked checkboxes since FormData omits them
    document.querySelectorAll('.settings-section.active input[type="checkbox"]').forEach(cb => {
        if(!cb.checked && !cb.name.startsWith('day_')) { // ignore dynamic weekend checkboxes
            formData.set(cb.name, "false");
        }
    });

    try {
        const res = await fetch(API_BASE_URL + 'api/settings.php?action=update', {
            method: 'POST', body: formData
        });
        const json = await res.json();
        if(json.status === 'success') {
            showNotification("Settings saved successfully!", "success");
            // Reload global settings cache and theme without page reload
            loadSystemSettings();
        } else {
            showNotification(json.message, "error");
        }
    } catch(e) {
        showNotification("Failed to save settings.", "error");
    } finally {
        btnElement.innerHTML = ogText;
        btnElement.disabled = false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// BILLING CATEGORIES — Settings Tab
// ═══════════════════════════════════════════════════════════════════════════════

let _editingCategoryId = null;

async function loadBillingCategories() {
    const res = await fetchData('api/billing.php?action=get_categories');
    const tbody = document.querySelector('#billing-categories-table tbody');
    if (!tbody) return;
    if (!res || res.status !== 'success') {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2rem;">Could not load categories. Run billing_migrate.php first.</td></tr>';
        return;
    }
    const cats = res.data;
    if (!cats.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:1rem;">No categories yet. Add one above.</td></tr>';
        return;
    }
    tbody.innerHTML = '';
    cats.forEach(c => {
        const activeBtn = c.is_active == 1
            ? `<button onclick="toggleBillingCategory(${c.id},0)" class="btn" style="padding:0.3rem 0.7rem;font-size:0.78rem;background:#ecfdf5;color:#059669;border:none;">
                <i class="ph ph-check-circle"></i> Enabled</button>`
            : `<button onclick="toggleBillingCategory(${c.id},1)" class="btn" style="padding:0.3rem 0.7rem;font-size:0.78rem;background:#fef2f2;color:#dc2626;border:none;">
                <i class="ph ph-x-circle"></i> Disabled</button>`;

        const deleteBtn = c.usage_count > 0
            ? `<button disabled title="Cannot delete — ${c.usage_count} records exist" style="opacity:0.4;width:28px;height:28px;border-radius:6px;border:none;background:#fef2f2;color:#ef4444;cursor:not-allowed;display:flex;align-items:center;justify-content:center;">
                <i class="ph ph-trash"></i></button>`
            : `<button onclick="deleteBillingCategory(${c.id},'${c.name.replace(/'/g,"\\\'")}')" title="Delete" style="width:28px;height:28px;border-radius:6px;border:none;background:#fef2f2;color:#ef4444;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                <i class="ph ph-trash"></i></button>`;

        tbody.innerHTML += `<tr style="border-bottom:1px solid var(--border-color);">
            <td style="padding:0.75rem 1rem;font-weight:600;">${c.name}
                ${c.is_mandatory == 1 ? '<span style="margin-left:6px;background:#fef3c7;color:#b45309;padding:2px 7px;border-radius:10px;font-size:0.72rem;font-weight:700;">MANDATORY</span>' : ''}
            </td>
            <td style="padding:0.75rem 1rem;color:var(--text-muted);font-size:0.85rem;">${c.description || '—'}</td>
            <td style="padding:0.75rem 1rem;font-weight:700;">KES ${Number(c.default_amount).toLocaleString()}</td>
            <td style="padding:0.75rem 1rem;">${activeBtn}</td>
            <td style="padding:0.75rem 1rem;color:var(--text-muted);">${c.usage_count || 0} records</td>
            <td style="padding:0.75rem 1rem;">
                <button onclick="openBillingCategoryForm(${c.id})" style="width:28px;height:28px;border-radius:6px;border:none;background:#eff6ff;color:#3b82f6;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                    <i class="ph ph-pencil-simple"></i></button>
            </td>
            <td style="padding:0.75rem 1rem;">${deleteBtn}</td>
        </tr>`;
    });
}

function toggleBcClassSelect() {
    const isPerClass = document.getElementById('bc-assign-per-class').checked;
    document.getElementById('bc-class-select-group').style.display = isPerClass ? 'block' : 'none';
}

async function openBillingCategoryForm(id = null) {
    _editingCategoryId = id;
    const panel = document.getElementById('billing-category-form-panel');
    const title = document.getElementById('billing-cat-form-title');
    if (!panel) return;

    // Load classes for container
    const resClasses = await fetchData('api/settings.php?action=get_classes');
    let availableClasses = [];
    if (resClasses && resClasses.status === 'success') {
        availableClasses = resClasses.data;
    }
    if (id) {
        // Pre-fill from existing table row data
        title.textContent = 'Edit Category';
        fetchData(`api/billing.php?action=get_categories`).then(res => {
            if (!res || res.status !== 'success') return;
            const cat = res.data.find(c => c.id == id);
            if (!cat) return;
            document.getElementById('bc-name').value          = cat.name;
            document.getElementById('bc-description').value   = cat.description || '';
            document.getElementById('bc-amount').value        = cat.default_amount;
            document.getElementById('bc-mandatory').checked   = cat.is_mandatory == 1;
            document.getElementById('bc-active').checked      = cat.is_active    == 1;
            document.getElementById('bc-enforce-scanner').checked = cat.enforce_on_scanner == 1;
            document.getElementById('bc-sort').value          = cat.sort_order   || 0;
            document.getElementById('bc-due-date').value      = cat.due_date || '';
            document.getElementById('bc-grace-period').value  = cat.grace_period_days || 0;
            
            const container = document.getElementById('bc-assigned-classes-container');
            if (container) {
                container.innerHTML = '';
                let assignedClasses = [];
                let isAssoc = false;
                if (cat.assignment_type === 'Per Class' && cat.assigned_classes) {
                    assignedClasses = JSON.parse(cat.assigned_classes);
                    isAssoc = !Array.isArray(assignedClasses);
                }

                availableClasses.forEach(c => {
                    const cName = c.class_name;
                    let isChecked = false;
                    let amt = '';
                    
                    if (cat.assignment_type === 'Per Class') {
                        if (isAssoc && assignedClasses[cName] !== undefined) {
                            isChecked = true;
                            amt = assignedClasses[cName];
                        } else if (!isAssoc && assignedClasses.includes && assignedClasses.includes(cName)) {
                            isChecked = true;
                        }
                    }

                    const html = `
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; padding: 0.25rem 0;">
                            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; flex:1; margin:0; font-weight:normal;">
                                <input type="checkbox" class="bc-class-cb" value="${cName}" ${isChecked ? 'checked' : ''} onchange="this.closest('div').querySelector('.bc-class-amt').disabled = !this.checked">
                                ${cName}
                            </label>
                            <input type="number" step="0.01" class="form-control bc-class-amt" placeholder="Amount" value="${amt}" style="width: 120px; padding: 0.25rem 0.5rem;" ${isChecked ? '' : 'disabled'}>
                        </div>
                    `;
                    container.innerHTML += html;
                });
            }

            if (cat.assignment_type === 'Per Class') {
                document.getElementById('bc-assign-per-class').checked = true;
            } else {
                document.getElementById('bc-assign-global').checked = true;
            }
            toggleBcClassSelect();
        });
    } else {
        title.textContent = 'Add New Category';
        document.getElementById('billing-category-form').reset();
        document.getElementById('bc-assign-global').checked = true;
        
        const container = document.getElementById('bc-assigned-classes-container');
        if (container) {
            container.innerHTML = '';
            availableClasses.forEach(c => {
                const cName = c.class_name;
                const html = `
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; padding: 0.25rem 0;">
                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; flex:1; margin:0; font-weight:normal;">
                            <input type="checkbox" class="bc-class-cb" value="${cName}" onchange="this.closest('div').querySelector('.bc-class-amt').disabled = !this.checked">
                            ${cName}
                        </label>
                        <input type="number" step="0.01" class="form-control bc-class-amt" placeholder="Amount" style="width: 120px; padding: 0.25rem 0.5rem;" disabled>
                    </div>
                `;
                container.innerHTML += html;
            });
        }
        
        toggleBcClassSelect();
    }
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeBillingCategoryForm() {
    const panel = document.getElementById('billing-category-form-panel');
    if (panel) panel.style.display = 'none';
    _editingCategoryId = null;
}

async function toggleBillingCategory(id, newState) {
    const fd = new FormData();
    fd.append('id', id);
    fd.append('is_active', newState);
    const res = await fetchData('api/billing.php?action=toggle_category', { method: 'POST', body: fd });
    if (res && res.status === 'success') {
        showNotification(res.message, 'success');
        loadBillingCategories();
    } else {
        showNotification(res?.message || 'Error.', 'error');
    }
}

async function deleteBillingCategory(id, name) {
    if (!confirm(`Delete category "${name}"? This cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('id', id);
    const res = await fetchData('api/billing.php?action=delete_category', { method: 'POST', body: fd });
    if (res && res.status === 'success') {
        showNotification(res.message, 'success');
        loadBillingCategories();
    } else {
        showNotification(res?.message || 'Cannot delete.', 'error');
    }
}

// Hook into DOMContentLoaded to set up billing category form
document.addEventListener('DOMContentLoaded', () => {
    // Load billing categories when tab is clicked
    const billingTabLink = document.querySelector('a[href="#tab-billing"]');
    if (billingTabLink) {
        billingTabLink.addEventListener('click', () => loadBillingCategories());
    }

    // Billing category form submission
    const bcForm = document.getElementById('billing-category-form');
    if (bcForm) {
        bcForm.addEventListener('submit', async e => {
            e.preventDefault();
            const btn = bcForm.querySelector('button[type="submit"]');
            const og  = btn.innerHTML;
            btn.innerHTML = '⏳ Saving…'; btn.disabled = true;

            const fd = new FormData();
            if (_editingCategoryId) fd.append('id', _editingCategoryId);
            fd.append('name',           document.getElementById('bc-name').value);
            fd.append('description',    document.getElementById('bc-description').value);
            fd.append('default_amount', document.getElementById('bc-amount').value || 0);
            fd.append('is_mandatory',   document.getElementById('bc-mandatory').checked ? 1 : 0);
            fd.append('is_active',      document.getElementById('bc-active').checked    ? 1 : 0);
            fd.append('enforce_on_scanner', document.getElementById('bc-enforce-scanner').checked ? 1 : 0);
            fd.append('sort_order',     document.getElementById('bc-sort').value        || 0);
            fd.append('due_date',       document.getElementById('bc-due-date').value    || '');
            fd.append('grace_period_days', document.getElementById('bc-grace-period').value || 0);
            
            const assignmentType = document.getElementById('bc-assign-per-class').checked ? 'Per Class' : 'Global';
            fd.append('assignment_type', assignmentType);
            
            if (assignmentType === 'Per Class') {
                const classMap = {};
                const container = document.getElementById('bc-assigned-classes-container');
                if (container) {
                    const rows = container.querySelectorAll('div');
                    rows.forEach(row => {
                        const cb = row.querySelector('.bc-class-cb');
                        const amtInput = row.querySelector('.bc-class-amt');
                        if (cb && cb.checked) {
                            classMap[cb.value] = amtInput.value ? parseFloat(amtInput.value) : '';
                        }
                    });
                }
                fd.append('assigned_classes', JSON.stringify(classMap));
            } else {
                fd.append('assigned_classes', '[]');
            }

            const res = await fetchData('api/billing.php?action=save_category', { method: 'POST', body: fd });
            btn.innerHTML = og; btn.disabled = false;

            if (res && res.status === 'success') {
                showNotification(res.message, 'success');
                closeBillingCategoryForm();
                loadBillingCategories();
            } else {
                showNotification(res?.message || 'Error saving category.', 'error');
            }
        });
    }
});

// ═══════════════════════════════════════════════════════════════════════════════
// ACADEMIC TERMS & ROLLOVER
// ═══════════════════════════════════════════════════════════════════════════════

async function loadAcademicTerms() {
    const res = await fetchData('api/settings.php?action=get_terms');
    const tbody = document.querySelector('#terms-table tbody');
    if (!tbody) return;
    
    if (!res || res.status !== 'success' || !res.data) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Failed to load terms.</td></tr>';
        return;
    }

    if (res.data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No terms configured.</td></tr>';
        return;
    }

    tbody.innerHTML = '';
    res.data.forEach(t => {
        let statusBadge = '';
        if (t.status === 'Active') statusBadge = '<span style="background:#ecfdf5;color:#059669;padding:2px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">Active</span>';
        else if (t.status === 'Upcoming') statusBadge = '<span style="background:#eff6ff;color:#3b82f6;padding:2px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">Upcoming</span>';
        else statusBadge = '<span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">Completed</span>';

        const delBtn = t.status === 'Upcoming' 
            ? `<button onclick="deleteAcademicTerm(${t.id}, '${t.term_name}')" style="background:#fef2f2;color:#dc2626;border:none;border-radius:4px;padding:4px 8px;cursor:pointer;"><i class="ph ph-trash"></i> Delete</button>`
            : '';

        tbody.innerHTML += `
            <tr style="border-bottom:1px solid var(--border-color);">
                <td style="padding:0.75rem 1rem;font-weight:600;">${t.term_name}</td>
                <td style="padding:0.75rem 1rem;">${t.academic_year}</td>
                <td style="padding:0.75rem 1rem;">${t.start_date}</td>
                <td style="padding:0.75rem 1rem;">${t.end_date}</td>
                <td style="padding:0.75rem 1rem;">${statusBadge}</td>
                <td style="padding:0.75rem 1rem;">${delBtn}</td>
            </tr>
        `;
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const termsTabLink = document.querySelector('a[href="#tab-rollover"]');
    if (termsTabLink) {
        termsTabLink.addEventListener('click', () => loadAcademicTerms());
    }

    const addTermForm = document.getElementById('form-add-term');
    if (addTermForm) {
        addTermForm.addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(addTermForm);
            const res = await fetchData('api/settings.php?action=add_term', { method: 'POST', body: fd });
            if (res && res.status === 'success') {
                showNotification(res.message, 'success');
                addTermForm.reset();
                loadAcademicTerms();
                loadSystemSettings(); // refresh sidebar/globals if it changed active term
            } else {
                showNotification(res?.message || 'Error adding term.', 'error');
            }
        });
    }
});

async function deleteAcademicTerm(id, name) {
    if (!confirm(`Delete scheduled term "${name}"?`)) return;
    const fd = new FormData();
    fd.append('id', id);
    const res = await fetchData('api/settings.php?action=delete_term', { method: 'POST', body: fd });
    if (res && res.status === 'success') {
        showNotification(res.message, 'success');
        loadAcademicTerms();
    } else {
        showNotification(res?.message || 'Could not delete term.', 'error');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SMS SETTINGS & TEST SMS
// ═══════════════════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
    const btnTestSms = document.getElementById('btn-test-sms');
    if (btnTestSms) {
        btnTestSms.addEventListener('click', async () => {
            const phone = document.getElementById('test_sms_phone').value.trim();
            if (!phone) {
                showNotification('Please enter a test phone number.', 'error');
                return;
            }

            const og = btnTestSms.innerHTML;
            btnTestSms.innerHTML = '⏳ Sending...';
            btnTestSms.disabled = true;

            const fd = new FormData();
            fd.append('phone', phone);

            try {
                const res = await fetchData('api/sms.php?action=test_sms', { method: 'POST', body: fd });
                const resEl = document.getElementById('test-sms-result');
                if (res && res.status === 'success') {
                    showNotification(res.message, 'success');
                    resEl.innerHTML = `<span style="color:#059669"><i class="ph ph-check-circle"></i> SMS Sent Successfully!</span>`;
                } else {
                    showNotification(res?.message || 'Failed to send Test SMS.', 'error');
                    let detailsText = '';
                    if (res?.details) {
                        detailsText = '<br><small style="color:#666; font-family:monospace;">' + JSON.stringify(res.details) + '</small>';
                    }
                    resEl.innerHTML = `<span style="color:#dc2626"><i class="ph ph-warning-circle"></i> ${res?.message || 'Failed'}${detailsText}</span>`;
                    console.error("SMS Test Details:", JSON.stringify(res?.details));
                }
            } catch (e) {
                showNotification('Network error while testing SMS.', 'error');
            } finally {
                btnTestSms.innerHTML = og;
                btnTestSms.disabled = false;
            }
        });
    }
});




