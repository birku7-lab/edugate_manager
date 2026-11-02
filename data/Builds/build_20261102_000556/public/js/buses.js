document.addEventListener('DOMContentLoaded', () => {
    // Only Admin can view this page
    const role = localStorage.getItem('user_role');
    if (role !== 'Admin') {
        alert("Access Denied. You do not have permission to view this page.");
        window.location.href = 'index.html';
        return;
    }

    loadBuses();

    document.getElementById('add-bus-form').addEventListener('submit', handleAddBus);
    document.getElementById('edit-bus-form').addEventListener('submit', handleEditBus);
    document.getElementById('assign-students-form').addEventListener('submit', handleAssignStudents);
});

async function loadBuses(silent = false) {
    try {
        const res = await fetchData('api/buses.php?action=list');
        if (res && res.status === 'success') {
            const tbody = document.getElementById('buses-list');
            tbody.innerHTML = '';
            res.data.forEach(bus => {
                const statusBadge = bus.status === 'Active' 
                    ? '<span class="badge" style="background:#10b981;">Active</span>'
                    : '<span class="badge" style="background:#ef4444;">Inactive</span>';

                tbody.innerHTML += `
                    <tr>
                        <td><strong>${bus.name}</strong></td>
                        <td>${bus.registration_number}</td>
                        <td>
                            <div>${bus.driver_name || '-'}</div>
                            <div style="font-size:0.8rem; color:var(--text-muted);">${bus.driver_phone || ''}</div>
                        </td>
                        <td>${bus.route || '-'}</td>
                        <td>${bus.capacity || 0} seats</td>
                        <td>${statusBadge}</td>
                        <td>
                            <button class="btn" onclick="openAssignModal(${bus.id}, '${bus.name.replace(/'/g, "\\'")}')" style="padding: 0.3rem 0.6rem; font-size: 0.8rem; margin-right: 5px; background:var(--primary); color:white; border:none;">
                                <i class="ph ph-users"></i> Assign
                            </button>
                            <button class="btn btn-primary" onclick="openEditModal(${bus.id})" style="padding: 0.3rem 0.6rem; font-size: 0.8rem; margin-right: 5px;">
                                <i class="ph ph-pencil-simple"></i> Edit
                            </button>
                            <button class="btn btn-danger" onclick="deleteBus(${bus.id})" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;">
                                <i class="ph ph-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                `;
            });
        }
    } catch(e) {
        showNotification("Failed to load buses", "error");
    }
}

async function handleAddBus(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    
    const fd = new FormData(form);

    btn.disabled = true;
    btn.innerHTML = 'Saving...';

    try {
        const res = await fetch(API_BASE_URL + 'api/buses.php?action=add', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();

        if (json.status === 'success') {
            showNotification(json.message, "success");
            closeAddModal();
            form.reset();
            loadBuses();
        } else {
            showNotification(json.message, "error");
        }
    } catch(e) {
        showNotification("Error connecting to server", "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Save Bus';
    }
}

async function handleEditBus(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    
    const fd = new FormData(form);

    btn.disabled = true;
    btn.innerHTML = 'Saving...';

    try {
        const res = await fetch(API_BASE_URL + 'api/buses.php?action=edit', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();

        if (json.status === 'success') {
            showNotification(json.message, "success");
            closeEditModal();
            loadBuses();
        } else {
            showNotification(json.message, "error");
        }
    } catch(e) {
        showNotification("Error connecting to server", "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Save Changes';
    }
}

async function deleteBus(id) {
    if (confirm("Are you sure you want to delete this bus?")) {
        const fd = new FormData();
        fd.append('id', id);

        try {
            const res = await fetch(API_BASE_URL + 'api/buses.php?action=delete', {
                method: 'POST',
                body: fd
            });
            const json = await res.json();
            if (json.status === 'success') {
                showNotification(json.message, "success");
                loadBuses();
            } else {
                showNotification(json.message, "error");
            }
        } catch(e) {
            showNotification("Error connecting to server", "error");
        }
    }
}

// Assign Students Modal Logic
let assignStudentsList = [];

async function openAssignModal(busId, busName) {
    document.getElementById('assign-bus-id').value = busId;
    document.getElementById('assign-bus-name').innerText = busName;
    document.getElementById('assign-search').value = '';
    const listContainer = document.getElementById('assign-students-list');
    
    listContainer.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--text-muted);">Loading eligible students...</div>';
    document.getElementById('assign-modal').style.display = 'block';

    try {
        const res = await fetchData(`api/buses.php?action=get_assignable_students&bus_id=${busId}`);
        if (res && res.status === 'success') {
            assignStudentsList = res.data;
            renderAssignStudents();
        } else {
            listContainer.innerHTML = `<div style="text-align:center; padding:2rem; color:var(--danger);">${res.message || 'Failed to load'}</div>`;
        }
    } catch(e) {
        listContainer.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--danger);">Error connecting to server</div>';
    }
}

function closeAssignModal() {
    document.getElementById('assign-modal').style.display = 'none';
}

function renderAssignStudents() {
    const listContainer = document.getElementById('assign-students-list');
    const searchTerm = document.getElementById('assign-search').value.toLowerCase();
    const busId = parseInt(document.getElementById('assign-bus-id').value);
    
    let html = '';
    
    const filtered = assignStudentsList.filter(s => 
        s.full_name.toLowerCase().includes(searchTerm) || 
        s.admission_number.toLowerCase().includes(searchTerm)
    );

    if (filtered.length === 0) {
        html = '<div style="text-align:center; padding:2rem; color:var(--text-muted);">No eligible students found.</div>';
    } else {
        filtered.forEach(s => {
            const isAssigned = s.assigned_bus_id == busId;
            html += `
                <div style="display:flex; align-items:center; justify-content:space-between; padding:0.8rem; border-bottom:1px solid var(--border-color);">
                    <div>
                        <strong>${s.full_name}</strong> (${s.admission_number})
                        <div style="font-size:0.8rem; color:var(--text-muted);">Class: ${s.class}</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="student_ids[]" value="${s.id}" class="assign-checkbox" ${isAssigned ? 'checked' : ''} onchange="updateLocalList(${s.id}, this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>
            `;
        });
    }
    
    listContainer.innerHTML = html;
}

function updateLocalList(studentId, isChecked) {
    const student = assignStudentsList.find(s => s.id == studentId);
    if (student) {
        student.assigned_bus_id = isChecked ? document.getElementById('assign-bus-id').value : null;
    }
}

function filterAssignStudents() {
    renderAssignStudents();
}

async function handleAssignStudents(e) {
    e.preventDefault();
    const busId = document.getElementById('assign-bus-id').value;
    const btn = e.target.querySelector('button[type="submit"]');
    
    const studentIds = assignStudentsList
        .filter(s => s.assigned_bus_id == busId)
        .map(s => s.id);

    btn.disabled = true;
    btn.innerHTML = 'Saving...';

    const fd = new FormData();
    fd.append('bus_id', busId);
    fd.append('student_ids', JSON.stringify(studentIds));

    try {
        const res = await fetch(API_BASE_URL + 'api/buses.php?action=assign_students', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();

        if (json.status === 'success') {
            showNotification(json.message, "success");
            closeAssignModal();
        } else {
            showNotification(json.message, "error");
        }
    } catch(e) {
        showNotification("Error connecting to server", "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Save Assignments';
    }
}

function openAddModal() {
    document.getElementById('add-modal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('add-modal').style.display = 'none';
}

function openEditModal(id) {
    fetchData('api/buses.php?action=list').then(res => {
        if(res && res.status === 'success') {
            const bus = res.data.find(b => b.id == id);
            if(bus) {
                document.getElementById('edit-id').value = bus.id;
                document.getElementById('edit-name').value = bus.name;
                document.getElementById('edit-registration_number').value = bus.registration_number;
                document.getElementById('edit-driver_name').value = bus.driver_name || '';
                document.getElementById('edit-driver_phone').value = bus.driver_phone || '';
                document.getElementById('edit-route').value = bus.route || '';
                document.getElementById('edit-capacity').value = bus.capacity || 0;
                document.getElementById('edit-status').value = bus.status;

                document.getElementById('edit-modal').style.display = 'flex';
            }
        }
    });
}

function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
}
