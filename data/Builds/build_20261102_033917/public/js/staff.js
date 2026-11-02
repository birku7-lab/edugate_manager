document.addEventListener('DOMContentLoaded', () => {
    // Only Admin can view this page
    const role = localStorage.getItem('user_role');
    if (role !== 'Admin') {
        alert("Access Denied. You do not have permission to view this page.");
        window.location.href = 'index.html';
        return;
    }

    loadStaff();

    document.getElementById('add-staff-form').addEventListener('submit', handleAddStaff);

    loadBusOptions();

    // Auto-select all permissions if Admin role is chosen
    document.getElementById('role-select').addEventListener('change', (e) => {
        const permsContainer = document.getElementById('permissions-container');
        const checkboxes = document.querySelectorAll('.perm-check');
        const busContainer = document.getElementById('add-bus-container');
        if (e.target.value === 'Admin') {
            permsContainer.style.opacity = '0.5';
            permsContainer.style.pointerEvents = 'none';
            checkboxes.forEach(cb => cb.checked = true);
            busContainer.style.display = 'block';
            
        } else {
            permsContainer.style.opacity = '1';
            permsContainer.style.pointerEvents = 'auto';
            checkboxes.forEach(cb => cb.checked = false);
            busContainer.style.display = 'none';
            
        }
    });

    document.getElementById('perm-transport')?.addEventListener('change', (e) => {
        document.getElementById('bus-assignment-container').style.display = e.target.checked ? 'block' : 'none';
    });

    
    fetchDynamicPermissions();
});

async function fetchDynamicPermissions() {
    try {
        const res = await fetchData('api/billing.php?action=get_categories');
        if (res && res.status === 'success') {
            const addContainer = document.getElementById('dynamic-permissions-add');
            const editContainer = document.getElementById('dynamic-permissions-edit');
            
            if (!addContainer || !editContainer) return;
            
            let htmlAdd = '';
            let htmlEdit = '';
            
            res.data.forEach(cat => {
                if (cat.enforce_on_scanner == 1) {
                    // Create dynamic permissions for this custom scanner
                    htmlAdd += `<label><input type="checkbox" class="perm-check" value="scanner_cat_${cat.id}"> Scanner: ${cat.category_name}</label>`;
                    htmlEdit += `<label><input type="checkbox" class="edit-perm-check" value="scanner_cat_${cat.id}"> Scanner: ${cat.category_name}</label>`;
                }
            });
            
            if (htmlAdd !== '') {
                addContainer.innerHTML = htmlAdd;
                addContainer.style.display = 'grid';
                editContainer.innerHTML = htmlEdit;
                editContainer.style.display = 'grid';
            }
        }
    } catch(e) {}
}


window.addEventListener('liveSyncUpdate', () => {
    if (document.getElementById('staff-list')) {
        loadStaff(true);
    }
});

async function loadStaff(silent = false) {
    try {
        const res = await fetchData('api/staff.php?action=list');
        if (res && res.status === 'success') {
            const tbody = document.getElementById('staff-list');
            tbody.innerHTML = '';
            res.data.forEach(staff => {
                
                let permsHtml = '';
                if(staff.role === 'Admin') {
                    permsHtml = '<span class="badge" style="background:#ef4444;">Full Access</span>';
                } else {
                    try {
                        const p = JSON.parse(staff.permissions || '[]');
                        permsHtml = p.map(x => `<span class="badge" style="background:#3b82f6; margin:2px;">${x}</span>`).join('');
                        if(p.length === 0) permsHtml = '<span class="badge" style="background:#64748b;">No Access</span>';
                    } catch(e){}
                }

                tbody.innerHTML += `
                    <tr>
                        <td>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(staff.name)}&background=e2e8f0&color=475569" style="width:30px; height:30px; border-radius:50%;">
                                <strong>${staff.name}</strong>
                            </div>
                        </td>
                        <td>${staff.username}</td>
                        <td><strong>${staff.role}</strong></td>
                        <td><div style="display:flex; flex-wrap:wrap; max-width:250px;">${permsHtml}</div></td>
                        <td>
                            <button class="btn btn-primary" onclick="openEditModal(${staff.id})" style="padding: 0.3rem 0.6rem; font-size: 0.8rem; margin-right: 5px;">
                                <i class="ph ph-pencil-simple"></i> Edit
                            </button>
                            <button class="btn btn-danger" onclick="deleteStaff(${staff.id})" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;">
                                <i class="ph ph-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                `;
            });
        }
    } catch(e) {
        showNotification("Failed to load staff list", "error");
    }
}

async function handleAddStaff(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    
    // Gather permissions
    const role = form.querySelector('select[name="role"]').value;
    let permissions = [];
    if(role === 'Admin') {
        permissions = ['all'];
    } else {
        form.querySelectorAll('.perm-check:checked').forEach(cb => {
            permissions.push(cb.value);
        });
    }

    const fd = new FormData(form);
    fd.append('permissions', JSON.stringify(permissions));

    btn.disabled = true;
    btn.innerHTML = 'Creating...';

    try {
        const res = await fetch(API_BASE_URL + 'api/staff.php?action=add', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();

        if (json.status === 'success') {
            showNotification(json.message, "success");
            closeAddModal();
            form.reset();
            loadStaff();
        } else {
            showNotification(json.message, "error");
        }
    } catch(e) {
        showNotification("Error connecting to server", "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Create Account';
    }
}

async function deleteStaff(id) {
    if(confirm("Are you sure you want to completely delete this user?")) {
        try {
            const fd = new FormData();
            fd.append('id', id);
                    
            const res = await fetch(API_BASE_URL + 'api/staff.php?action=delete', {
                method: 'POST',
                body: fd
            });
            const json = await res.json();
            
            if (json.status === 'success') {
                showNotification(json.message, "success");
                loadStaff();
            } else {
                showNotification(json.message, "error");
            }
        } catch(e) {
            showNotification("Delete failed", "error");
        }
    }
}

function openAddModal() {
    document.getElementById('add-modal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('add-modal').style.display = 'none';
}

function openEditModal(id) {
    // Find staff details from the list or fetch it. We can re-fetch to be safe.
    fetchData('api/staff.php?action=list').then(res => {
        if(res && res.status === 'success') {
            const staff = res.data.find(s => s.id == id);
            if(staff) {
                document.getElementById('edit-id').value = staff.id;
                document.getElementById('edit-name').value = staff.name;
                document.getElementById('edit-username').value = staff.username;
                document.getElementById('edit-role-select').value = staff.role;
                document.getElementById('edit-password').value = ''; // Leave blank

                const permsContainer = document.getElementById('edit-permissions-container');
                const checkboxes = document.querySelectorAll('.edit-perm-check');
                checkboxes.forEach(cb => cb.checked = false); // Reset
                const busContainer = document.getElementById('edit-bus-container');
                const busSelect = document.getElementById('edit-assigned-bus');

                if (staff.role === 'Admin') {
                    permsContainer.style.opacity = '0.5';
                    permsContainer.style.pointerEvents = 'none';
                    checkboxes.forEach(cb => cb.checked = true);
                    busContainer.style.display = 'block';
                } else {
                    permsContainer.style.opacity = '1';
                    permsContainer.style.pointerEvents = 'auto';
                    let hasTransport = false;
                    try {
                        const p = JSON.parse(staff.permissions || '[]');
                        checkboxes.forEach(cb => {
                            if(p.includes(cb.value)) {
                                cb.checked = true;
                                if(cb.value === 'scanner_transport') hasTransport = true;
                            }
                        });
                    } catch(e){}
                    busContainer.style.display = hasTransport ? 'block' : 'none';
                }

                busSelect.value = staff.assigned_bus_id || '';

                document.getElementById('edit-modal').style.display = 'flex';
            }
        }
    });
}

function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
}

document.getElementById('perm-transport-edit')?.addEventListener('change', (e) => {
    document.getElementById('edit-bus-container').style.display = e.target.checked ? 'block' : 'none';
});

document.getElementById('perm-transport-add')?.addEventListener('change', (e) => {
    document.getElementById('add-bus-container').style.display = e.target.checked ? 'block' : 'none';
});

async function loadBusOptions() {
    try {
        const res = await fetchData('api/buses.php?action=list');
        if (res && res.status === 'success') {
            let html = '<option value="">-- No Bus Assigned --</option>';
            res.data.forEach(b => {
                if (b.status === 'Active') {
                    html += `<option value="${b.id}">${b.name} (${b.registration_number})</option>`;
                }
            });
            document.getElementById('add-assigned-bus').innerHTML = html;
            document.getElementById('edit-assigned-bus').innerHTML = html;
        }
    } catch(e) {
        console.error("Failed to load buses for dropdown", e);
    }
}

document.getElementById('edit-staff-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    
    const role = form.querySelector('select[name="role"]').value;
    let permissions = [];
    if(role === 'Admin') {
        permissions = ['all'];
    } else {
        form.querySelectorAll('.edit-perm-check:checked').forEach(cb => {
            permissions.push(cb.value);
        });
    }

    const fd = new FormData(form);
    fd.append('permissions', JSON.stringify(permissions));

    btn.disabled = true;
    btn.innerHTML = 'Saving...';

    try {
        const res = await fetch(API_BASE_URL + 'api/staff.php?action=edit', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();

        if (json.status === 'success') {
            showNotification(json.message, "success");
            closeEditModal();
            loadStaff();
        } else {
            showNotification(json.message, "error");
        }
    } catch(e) {
        showNotification("Error connecting to server", "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Save Changes';
    }
});

// Auto-select all permissions if Admin role is chosen (Edit Modal)
document.getElementById('edit-role-select')?.addEventListener('change', (e) => {
    const permsContainer = document.getElementById('edit-permissions-container');
    
    if(e.target.value === 'Admin') {
        permsContainer.style.opacity = '0.5';
        permsContainer.style.pointerEvents = 'none';
        document.querySelectorAll('.edit-perm-check').forEach(cb => cb.checked = true);
        busContainer.style.display = 'block';
    } else {
        permsContainer.style.opacity = '1';
        permsContainer.style.pointerEvents = 'auto';
        busContainer.style.display = document.getElementById('edit-perm-transport')?.checked ? 'block' : 'none';
    }
});


