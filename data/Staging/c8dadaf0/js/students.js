function compressImage(file, maxWidth, maxHeight, quality) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = event => {
            const img = new Image();
            img.src = event.target.result;
            img.onload = () => {
                let width = img.width;
                let height = img.height;

                if (width > height) {
                    if (width > maxWidth) {
                        height = Math.round(height * (maxWidth / width));
                        width = maxWidth;
                    }
                } else {
                    if (height > maxHeight) {
                        width = Math.round(width * (maxHeight / height));
                        height = maxHeight;
                    }
                }

                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);
                
                // Fallback to jpeg if type is not supported
                const mimeType = file.type || 'image/jpeg';
                canvas.toBlob(blob => {
                    if (blob) {
                        resolve(blob);
                    } else {
                        reject(new Error('Canvas to Blob failed'));
                    }
                }, mimeType, quality);
            };
            img.onerror = error => reject(error);
        };
        reader.onerror = error => reject(error);
    });
}

// Used for both add_student.html and students.html
window.addEventListener('DOMContentLoaded', () => {
    loadClassExpiries();
    loadStudents();

    // Setup bulk select all
    document.getElementById('select-all-students')?.addEventListener('change', function(e) {
        const checkboxes = document.querySelectorAll('.student-checkbox');
        checkboxes.forEach(cb => cb.checked = e.target.checked);
        updateBulkActionVisibility();
    });

    // Fetch buses for edit modal if it exists
    if (document.getElementById('edit-assigned_bus_id')) {
        fetchData('api/buses.php?action=list').then(busRes => {
            if (busRes && busRes.status === 'success') {
                const busSelect = document.getElementById('edit-assigned_bus_id');
                let options = '<option value="">-- No Bus Assigned --</option>';
                busRes.data.forEach(bus => {
                    options += `<option value="${bus.id}">${bus.name} (${bus.registration_number})</option>`;
                });
                busSelect.innerHTML = options;
            }
        });
    }
});

window.addEventListener('liveSyncUpdate', () => {
    if (document.getElementById('students-tbody')) {
        loadStudents(true);
    }
});

/**
 * Shows/hides the "Please Specify Relationship" text field based on the
 * selected value of a Relationship-to-Student dropdown, and keeps its
 * `required` state in sync so validation never fires for a hidden field.
 * Reused by both the Add Student form and the Edit Student modal.
 */
function toggleRelationshipOtherField(selectId, groupId, inputId) {
    const select = document.getElementById(selectId);
    const group = document.getElementById(groupId);
    const input = document.getElementById(inputId);
    if (!select || !group || !input) return;

    const isOther = select.value === 'Other';
    group.style.display = isOther ? 'block' : 'none';
    input.required = isOther;
    if (!isOther) input.value = '';
}

document.addEventListener('DOMContentLoaded', () => {
    
    // Logic for Add Student page specifically
    if (document.getElementById('add-student-form')) {
        initAddStudentPage();
    }

    // Parent/Guardian "Other" relationship toggle — Add Student form
    const addRelationshipSelect = document.getElementById('parent_relationship');
    if (addRelationshipSelect) {
        addRelationshipSelect.addEventListener('change', () =>
            toggleRelationshipOtherField('parent_relationship', 'parent_relationship_other_group', 'parent_relationship_other')
        );
    }

    // Parent/Guardian "Other" relationship toggle — Edit Student modal
    const editRelationshipSelect = document.getElementById('edit-parent_relationship');
    if (editRelationshipSelect) {
        editRelationshipSelect.addEventListener('change', () =>
            toggleRelationshipOtherField('edit-parent_relationship', 'edit-parent_relationship_other_group', 'edit-parent_relationship_other')
        );
    }
    
    // Add Student Form Submission
    const addForm = document.getElementById('add-student-form');
    if (addForm) {
        addForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const submitBtn = addForm.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span>⏳</span> Saving...';
            submitBtn.disabled = true;

            const formData = new FormData(addForm);
            
            // Client-side image compression to bypass PHP size limits
            const photoInput = document.getElementById('photo');
            if (photoInput && photoInput.files[0]) {
                const file = photoInput.files[0];
                try {
                    const compressedBlob = await compressImage(file, 800, 800, 0.8);
                    // Override the photo field with the compressed blob
                    formData.set('photo', compressedBlob, file.name);
                } catch(e) {
                    console.error("Image compression failed, falling back to original file.", e);
                }
            }
            
            try {
                const response = await fetch(API_BASE_URL + 'api/students.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    showNotification(result.message, 'success');
                    addForm.reset();
                    // Optional: redirect to student profile or list
                    setTimeout(() => {
                        window.location.href = 'students.html';
                    }, 1500);
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                console.error(error);
                showNotification('An error occurred. Check console.', 'error');
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });
    }

    // Edit Student Form Submission
    const editForm = document.getElementById('edit-student-form');
    if (editForm) {
        editForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const submitBtn = editForm.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span>⏳</span> Saving...';
            submitBtn.disabled = true;

            const formData = new FormData(editForm);
            
            // Client-side image compression
            const photoInput = document.getElementById('edit-photo');
            if (photoInput && photoInput.files[0]) {
                const file = photoInput.files[0];
                try {
                    const compressedBlob = await compressImage(file, 800, 800, 0.8);
                    formData.set('photo', compressedBlob, file.name);
                } catch(e) {
                    console.error("Image compression failed.", e);
                }
            }
            
            try {
                const response = await fetch(API_BASE_URL + 'api/students.php?action=edit', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.status === 'success') {
                    showNotification(result.message, 'success');
                    closeEditModal();
                    loadStudents(); // Refresh table
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                console.error(error);
                showNotification('An error occurred. Check console.', 'error');
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });
    }

    // Load Students List (students.html)
    const studentsTableBody = document.getElementById('students-tbody');
    if (studentsTableBody) {
        loadStudents();

        // Search functionality
        const searchInput = document.getElementById('search-student');
        if (searchInput) {
            searchInput.addEventListener('input', () => applyFilters());
        }

        // Dropdown filters
        const filterClass = document.getElementById('filter-class');
        const filterGender = document.getElementById('filter-gender');
        const filterStatus = document.getElementById('filter-status');
        const filterBilling = document.getElementById('filter-billing');
        if (filterClass) filterClass.addEventListener('change', () => applyFilters());
        if (filterGender) filterGender.addEventListener('change', () => applyFilters());
        if (filterStatus) filterStatus.addEventListener('change', () => applyFilters());
        if (filterBilling) filterBilling.addEventListener('change', () => applyFilters());

        // Items per page
        const itemsPerPage = document.getElementById('items-per-page');
        if (itemsPerPage) {
            itemsPerPage.addEventListener('change', () => {
                window._studentsPage = 1;
                renderPage();
            });
        }
    }
});

async function initAddStudentPage() {
    try {
        // Fetch Buses
        fetchData('api/buses.php?action=list').then(busRes => {
            if (busRes && busRes.status === 'success') {
                const busSelect = document.getElementById('assigned_bus_id');
                if (busSelect) {
                    let options = '<option value="">-- No Bus Assigned --</option>';
                    busRes.data.forEach(bus => {
                        options += `<option value="${bus.id}">${bus.name} (${bus.registration_number})</option>`;
                    });
                    busSelect.innerHTML = options;
                }
            }
        });

        const res = await fetch(API_BASE_URL + 'api/settings.php?action=get');
        const json = await res.json();
        if (json.status === 'success') {
            const classExpiries = json.data.class_expiries ? JSON.parse(json.data.class_expiries) : {};
            const termEndDate = json.data.term_end_date || '';
            const classSelect = document.getElementById('class');
            const validUntilInput = document.getElementById('valid_until');


            if (classSelect) {
                classSelect.addEventListener('change', (e) => {
                    const selectedClass = e.target.value;
                    
                    // Auto-fill valid_until from global term end date
                    if (validUntilInput && termEndDate && !validUntilInput.value) {
                        validUntilInput.value = termEndDate;
                    }
                    

                });
            }
        }
    } catch(e) {
        console.error("Failed to load class expiries", e);
    }
}

// Global storage for all student data and filtered data
window._allStudents = [];
window._filteredStudents = [];
window._studentsPage = 1;

async function loadStudents(silent = false) {
    const tbody = document.getElementById('students-tbody');
    if (!tbody) return;
    if (!silent) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding: 2rem; color: #94a3b8;"><i class="ph ph-spinner" style="font-size:1.5rem;"></i><br>Loading students...</td></tr>`;
    }

    const res = await fetchData('api/students.php?action=list');

    if (res && res.status === 'success') {
        window._allStudents = res.data;

        if (res.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:2rem; color:#94a3b8;">No students found.</td></tr>`;
            return;
        }

        // Compute stats
        const total = res.data.length;
        const males = res.data.filter(s => s.gender === 'Male').length;
        const females = res.data.filter(s => s.gender === 'Female').length;
        const classes = new Set(res.data.map(s => s.class)).size;

        const statTotal = document.getElementById('stat-total');
        const statMale = document.getElementById('stat-male');
        const statMalePct = document.getElementById('stat-male-pct');
        const statFemale = document.getElementById('stat-female');
        const statFemalePct = document.getElementById('stat-female-pct');
        const statClasses = document.getElementById('stat-classes');

        if (statTotal) statTotal.textContent = total.toLocaleString();
        if (statMale) statMale.textContent = males.toLocaleString();
        if (statMalePct) statMalePct.textContent = total > 0 ? `${((males/total)*100).toFixed(1)}% of total` : '-';
        if (statFemale) statFemale.textContent = females.toLocaleString();
        if (statFemalePct) statFemalePct.textContent = total > 0 ? `${((females/total)*100).toFixed(1)}% of total` : '-';
        if (statClasses) statClasses.textContent = classes;

        // Populate class dropdown dynamically from student data
        const filterClass = document.getElementById('filter-class');
        if (filterClass) {
            const currentVal = filterClass.value; // preserve selection if re-loading
            const uniqueClasses = [...new Set(res.data.map(s => s.class).filter(Boolean))].sort();
            filterClass.innerHTML = '<option value="">All Classes</option>';
            uniqueClasses.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = c;
                filterClass.appendChild(opt);
            });
            // Restore previous selection
            if (currentVal) filterClass.value = currentVal;
        }

        applyFilters();
    } else {
        const tbody = document.getElementById('students-tbody');
        if (tbody) tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:2rem; color:#ef4444;">Failed to load students.</td></tr>`;
    }
}

function applyFilters() {
    const searchTerm = (document.getElementById('search-student')?.value || '').toLowerCase().trim();
    const filterClass = (document.getElementById('filter-class')?.value || '').trim();
    const filterGender = (document.getElementById('filter-gender')?.value || '').trim();
    // Status filter: 'Active' is the only real option; empty means show all
    // All students are treated as Active for now
    const filterStatus = (document.getElementById('filter-status')?.value || '').trim();
    const filterBilling = (document.getElementById('filter-billing')?.value || '').trim();

    window._filteredStudents = window._allStudents.filter(s => {
        const matchSearch = !searchTerm ||
            s.full_name.toLowerCase().includes(searchTerm) ||
            s.admission_number.toLowerCase().includes(searchTerm);
        const matchClass = !filterClass || s.class === filterClass;
        const matchGender = !filterGender || s.gender === filterGender;
        // All students are Active; if filter is empty or 'Active', match all
        const matchStatus = !filterStatus || filterStatus === 'Active';
        const matchBilling = !filterBilling || (s.billing_status || 'Unpaid') === filterBilling;
        return matchSearch && matchClass && matchGender && matchStatus && matchBilling;
    });

    window._studentsPage = 1;
    renderPage();
}

function clearFilters() {
    const search = document.getElementById('search-student');
    const fc = document.getElementById('filter-class');
    const fg = document.getElementById('filter-gender');
    const fs = document.getElementById('filter-status');
    const fb = document.getElementById('filter-billing');
    if (search) search.value = '';
    if (fc) fc.value = '';
    if (fg) fg.value = '';
    if (fs) fs.value = '';
    if (fb) fb.value = '';
    applyFilters();
}

function renderPage() {
    const tbody = document.getElementById('students-tbody');
    if (!tbody) return;

    const perPage = parseInt(document.getElementById('items-per-page')?.value || '10');
    const page = window._studentsPage;
    const data = window._filteredStudents;
    const total = data.length;
    const totalPages = Math.ceil(total / perPage);
    const start = (page - 1) * perPage;
    const pageData = data.slice(start, start + perPage);

    tbody.innerHTML = '';

    if (pageData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:2rem; color:#94a3b8;">No students match your filters.</td></tr>`;
    } else {
        pageData.forEach(s => {
            const photoSrc = s.photo
                ? (API_BASE_URL + s.photo + '?v=1.5')
                : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(s.full_name) + '&background=e0e7ff&color=4f46e5&size=40';


            // Format created_at date
            let dateAdded = '-';
            if (s.created_at) {
                const d = new Date(s.created_at);
                dateAdded = d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
            }

            // Billing Status badge
            let billingBadge = '-';
            const bStatus = s.billing_status || 'Unpaid';
            if (bStatus === 'Paid') {
                billingBadge = '<span class="billing-badge billing-badge-paid"><i class="ph ph-check-circle"></i> Paid</span>';
            } else if (bStatus === 'Partial') {
                billingBadge = '<span class="billing-badge billing-badge-partial"><i class="ph ph-warning-circle"></i> Partial</span>';
            } else if (bStatus === 'Exempt') {
                billingBadge = '<span class="billing-badge billing-badge-exempt"><i class="ph ph-shield-check"></i> Exempt</span>';
            } else {
                billingBadge = '<span class="billing-badge billing-badge-unpaid"><i class="ph ph-x-circle"></i> Unpaid</span>';
            }

            const genderIcon = s.gender === 'Male'
                ? `<i class="ph ph-gender-male" style="color: #3b82f6;"></i>`
                : `<i class="ph ph-gender-female" style="color: #ec4899;"></i>`;
            const genderColor = s.gender === 'Male' ? '#eff6ff' : '#fdf4ff';
            const genderTextColor = s.gender === 'Male' ? '#1d4ed8' : '#9333ea';

            const tr = document.createElement('tr');
            tr.style.cssText = 'background: white; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);';
            tr.innerHTML = `
                <td style="padding: 1rem 1rem; border: none; border-radius: 10px 0 0 10px;">
                    <img src="${photoSrc}" alt="${s.full_name}"
                        style="width:42px; height:42px; border-radius:50%; object-fit:cover; background:#e0e7ff; border: 2px solid #e2e8f0;"
                        onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(s.full_name)}&background=e0e7ff&color=4f46e5&size=40'">
                </td>
                <td style="padding: 1rem 0.5rem; border: none;">
                    <div style="flex: 1; text-align: left;">
                        <div style="font-weight: 600; color: var(--dark); font-size: 1rem;">${s.full_name}</div>
                    </div>
                </td>
                <td style="padding: 1rem 0.5rem; border: none; color: #3b82f6; font-weight: 600; font-size: 0.9rem;">${s.admission_number}</td>
                <td style="padding: 1rem 0.5rem; border: none; color: #475569; font-weight: 500; font-size: 0.9rem;">${s.class}</td>
                <td style="padding: 1rem 0.5rem; border: none;">
                    <span style="display: inline-flex; align-items: center; gap: 0.3rem; background: ${genderColor}; color: ${genderTextColor}; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.82rem; font-weight: 600;">
                        ${genderIcon} ${s.gender}
                    </span>
                </td>
                <td style="padding: 1rem 0.5rem; border: none;">
                    <span style="display: inline-flex; align-items: center; gap: 0.3rem; background: #ecfdf5; color: #059669; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.82rem; font-weight: 600;">
                        <span style="width:6px; height:6px; border-radius:50%; background: #10b981; display:inline-block;"></span> Active
                    </span>
                </td>
                <td class="admin-only-nav" style="padding: 1rem 0.5rem; border: none;">${billingBadge}</td>
                <td style="padding: 1rem 0.5rem; border: none; color: #64748b; font-size: 0.88rem;">${dateAdded}</td>
                <td style="padding: 1rem 1rem; border: none; border-radius: 0 10px 10px 0;">
                    <div style="display: flex; align-items: center; gap: 0.4rem; justify-content: center;">
                        <button title="View Profile" onclick="viewProfile(${s.id})"
                            style="width:32px; height:32px; border-radius:7px; border:none; background:#eff6ff; color:#3b82f6; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1rem;">
                            <i class="ph ph-eye"></i>
                        </button>
                        <button title="Edit Student" onclick="editStudent(${s.id})"
                            style="width:32px; height:32px; border-radius:7px; border:none; background:#fff7ed; color:#f97316; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1rem;">
                            <i class="ph ph-pencil"></i>
                        </button>
                        <button title="Delete Student" onclick="deleteStudent(${s.id})"
                            style="width:32px; height:32px; border-radius:7px; border:none; background:#fef2f2; color:#ef4444; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1rem;">
                            <i class="ph ph-trash"></i>
                        </button>
                        <button title="Assign QR Code" onclick="openAssignQRScanner(${s.id}, '${s.qr_code}')"
                            style="width:32px; height:32px; border-radius:7px; border:none; background:#f5f3ff; color:#8b5cf6; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1rem;">
                            <i class="ph ph-qr-code"></i>
                        </button>
                        <button title="More Options" onclick="showMoreOptions(event, '${s.qr_code}')"
                            style="width:32px; height:32px; border-radius:7px; border:none; background:#f8fafc; color:#64748b; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1rem;">
                            <i class="ph ph-dots-three-vertical"></i>
                        </button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    // Update pagination info
    const paginationInfo = document.getElementById('pagination-info');
    if (paginationInfo) {
        const from = total === 0 ? 0 : start + 1;
        const to = Math.min(start + perPage, total);
        paginationInfo.textContent = `Showing ${from} to ${to} of ${total.toLocaleString()} students`;
    }

    // Build pagination controls
    const paginationControls = document.getElementById('pagination-controls');
    if (paginationControls) {
        paginationControls.innerHTML = '';

        const btnStyle = (active) => `
            width: 36px; height: 36px; border-radius: 8px; border: 1px solid ${active ? '#3b82f6' : '#e2e8f0'};
            background: ${active ? '#3b82f6' : 'white'}; color: ${active ? 'white' : '#64748b'};
            font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
        `;

        // Prev button
        const prevBtn = document.createElement('button');
        prevBtn.innerHTML = '<i class="ph ph-caret-left"></i>';
        prevBtn.style.cssText = btnStyle(false);
        prevBtn.disabled = page === 1;
        prevBtn.style.opacity = page === 1 ? '0.4' : '1';
        prevBtn.onclick = () => { window._studentsPage--; renderPage(); };
        paginationControls.appendChild(prevBtn);

        // Page number buttons (max 5 visible)
        let startPage = Math.max(1, page - 2);
        let endPage = Math.min(totalPages, startPage + 4);
        if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

        for (let i = startPage; i <= endPage; i++) {
            const btn = document.createElement('button');
            btn.textContent = i;
            btn.style.cssText = btnStyle(i === page);
            btn.onclick = ((pageNum) => () => { window._studentsPage = pageNum; renderPage(); })(i);
            paginationControls.appendChild(btn);
        }

        if (endPage < totalPages) {
            const dots = document.createElement('span');
            dots.textContent = '...';
            dots.style.cssText = 'padding: 0 0.3rem; color: #94a3b8; line-height: 36px;';
            paginationControls.appendChild(dots);

            const lastBtn = document.createElement('button');
            lastBtn.textContent = totalPages;
            lastBtn.style.cssText = btnStyle(false);
            lastBtn.onclick = () => { window._studentsPage = totalPages; renderPage(); };
            paginationControls.appendChild(lastBtn);
        }

        // Next button
        const nextBtn = document.createElement('button');
        nextBtn.innerHTML = '<i class="ph ph-caret-right"></i>';
        nextBtn.style.cssText = btnStyle(false);
        nextBtn.disabled = page === totalPages || totalPages === 0;
        nextBtn.style.opacity = (page === totalPages || totalPages === 0) ? '0.4' : '1';
        nextBtn.onclick = () => { window._studentsPage++; renderPage(); };
        paginationControls.appendChild(nextBtn);
    }
}

function showMoreOptions(event, qrCode) {
    event.stopPropagation();
    // Simple mark present shortcut from the dots menu
    const existing = document.getElementById('more-options-dropdown');
    if (existing) existing.remove();

    const dropdown = document.createElement('div');
    dropdown.id = 'more-options-dropdown';
    dropdown.style.cssText = `
        position: fixed;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        z-index: 9999;
        min-width: 180px;
        padding: 0.5rem 0;
    `;
    dropdown.innerHTML = `
        <div onclick="markPresent('${qrCode}'); this.parentElement.remove();"
            style="padding: 0.6rem 1rem; cursor:pointer; display:flex; align-items:center; gap:0.5rem; color:#059669; font-weight:500; font-size:0.9rem;">
            <i class="ph ph-check-circle"></i> Mark Present
        </div>
    `;

    const btn = event.currentTarget;
    const rect = btn.getBoundingClientRect();
    dropdown.style.top = (rect.bottom + 5) + 'px';
    dropdown.style.left = (rect.left - 120) + 'px';
    document.body.appendChild(dropdown);
    document.addEventListener('click', () => dropdown.remove(), { once: true });
}

async function viewProfile(id) {
    // Show modal or redirect to profile page. Let's use a modal for premium feel.
    // For simplicity, we can dynamically create a modal
    let modal = document.getElementById('profile-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'profile-modal';
        modal.style.cssText = `
            position: fixed; top:0; left:0; width:100%; height:100%;
            background: rgba(0,0,0,0.5); backdrop-filter: blur(5px);
            z-index: 1000; display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.3s; pointer-events: none;
        `;
        
        modal.innerHTML = `
            <div class="glass-card" style="width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; position: relative;">
                <button onclick="closeProfile()" style="position: absolute; right: 15px; top: 15px; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--danger);">&times;</button>
                <div id="profile-content">Loading...</div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    modal.style.pointerEvents = 'auto';
    modal.style.opacity = '1';
    
    document.getElementById('profile-content').innerHTML = '<div style="text-align:center; padding: 2rem;">Loading profile...</div>';
    
    const res = await fetchData(`api/students.php?action=get&id=${id}`);
    if (res && res.status === 'success') {
        const student = res.data;
        const photoSrc = student.photo ? (API_BASE_URL + student.photo + '?v=1.5') : 'data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2280%22%20height%3D%2280%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23ccc%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22M20%2021v-2a4%204%200%200%200-4-4H8a4%204%200%200%200-4%204v2%22%3E%3C%2Fpath%3E%3Ccircle%20cx%3D%2212%22%20cy%3D%227%22%20r%3D%224%22%3E%3C%2Fcircle%3E%3C%2Fsvg%3E';
        
        const imgHtml = `<img src="${photoSrc}" alt="${student.full_name}" style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:3px solid var(--primary);background:#f0f0f0;">`;
        let infoHtml = `
        <div style="flex: 1; min-width: 250px;">
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Name:</strong> <span style="color:var(--text-muted);">${student.full_name}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Admission No:</strong> <span style="color:var(--text-muted);">${student.admission_number}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Class/Grade:</strong> <span style="color:var(--text-muted);">${student.class}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Gender:</strong> <span style="color:var(--text-muted);">${student.gender}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Date of Birth:</strong> <span style="color:var(--text-muted);">${student.dob || 'N/A'}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Admitted:</strong> <span style="color:var(--text-muted);">${student.admitted_date || 'N/A'}</span></p>
        </div>
        <div style="display:flex; flex-direction:column; align-items:center; gap: 1rem; border-left: 1px solid var(--border-color); padding-left: 2rem;">
            <div id="qrcode-container" style="display:inline-block; padding: 10px; background: white; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);"></div>
        </div>
        `;

        const relationshipDisplay = student.parent_relationship
            ? (student.parent_relationship === 'Other'
                ? (student.parent_relationship_other || 'Other')
                : student.parent_relationship)
            : 'Not Provided';

        const parentHtml = `
        <div style="margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
            <h4 style="margin: 0 0 0.75rem; color: var(--text-main); font-size: 1rem;">
                <i class="ph ph-users-three" style="color: var(--primary); vertical-align: middle;"></i> Parent/Guardian Information
            </h4>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Name:</strong> <span style="color:var(--text-muted);">${student.parent_name || 'Not Provided'}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Phone Number:</strong> <span style="color:var(--text-muted);">${student.parent_phone || 'Not Provided'}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Relationship:</strong> <span style="color:var(--text-muted);">${relationshipDisplay}</span></p>
        </div>
        `;

        const transportHtml = student.assigned_bus_id ? `
        <div style="margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
            <h4 style="margin: 0 0 0.75rem; color: var(--text-main); font-size: 1rem;">
                <i class="ph ph-bus" style="color: var(--primary); vertical-align: middle;"></i> Transport Information
            </h4>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Assigned Bus ID:</strong> <span style="color:var(--text-muted);">${student.assigned_bus_id}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Route Number:</strong> <span style="color:var(--text-muted);">${student.route_number || 'N/A'}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Pickup:</strong> <span style="color:var(--text-muted);">${student.pickup_location || 'N/A'}</span></p>
            <p style="margin: 0.5rem 0; font-size: 1.1rem;"><strong style="color:var(--text-main);">Dropoff:</strong> <span style="color:var(--text-muted);">${student.dropoff_location || 'N/A'}</span></p>
        </div>
        ` : '';

        document.getElementById('profile-content').innerHTML = `
            <!-- Tabs Header -->
            <div style="display: flex; gap: 1rem; border-bottom: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                <button onclick="switchProfileTab('info')" id="tab-btn-info" style="background:none; border:none; border-bottom: 2px solid var(--primary); color: var(--primary); font-weight: 600; padding: 0.5rem 1rem; cursor: pointer;">Profile Info</button>
                <button onclick="switchProfileTab('billing')" id="tab-btn-billing" class="admin-only-nav" style="background:none; border:none; border-bottom: 2px solid transparent; color: var(--text-muted); font-weight: 600; padding: 0.5rem 1rem; cursor: pointer;">Billing</button>
            </div>

            <div id="tab-content-info">
                <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                    ${imgHtml}
                    ${infoHtml}
                </div>
                ${parentHtml}
                ${transportHtml}
                <div style="margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="editStudent(${student.id})"><i class="ph ph-pencil-simple"></i> Edit Student</button>
                    <button class="btn" style="background:#10b981; color:white;" onclick="printIDCard(${student.id})"><i class="ph ph-printer"></i> Print ID Card</button>
                    <button class="btn btn-danger" onclick="deleteStudent(${student.id})"><i class="ph ph-trash"></i> Delete Student</button>
                </div>
            </div>

            <!-- Billing Tab Content -->
            <div id="tab-content-billing" class="admin-only-nav" style="display:none;">
                <div id="student-billing-loader" style="text-align:center; padding: 2rem; color: #94a3b8;"><i class="ph ph-spinner" style="font-size:1.5rem;"></i><br>Loading billing records...</div>
                <div id="student-billing-container" style="display:none;"></div>
            </div>
        `;

        // Generate QR Code
        new QRCode(document.getElementById("qrcode-container"), {
            text: student.qr_code,
            width: 150,
            height: 150,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });

        // Fetch billing if admin
        if (localStorage.getItem('user_role') === 'Admin') {
            loadStudentBillingProfile(student.id);
        }

    } else {
        document.getElementById('profile-content').innerHTML = `<div style="text-align:center;color:red;">Error loading profile.</div>`;
    }
}

function switchProfileTab(tab) {
    document.getElementById('tab-btn-info').style.borderBottomColor = tab === 'info' ? 'var(--primary)' : 'transparent';
    document.getElementById('tab-btn-info').style.color = tab === 'info' ? 'var(--primary)' : 'var(--text-muted)';
    
    document.getElementById('tab-btn-billing').style.borderBottomColor = tab === 'billing' ? 'var(--primary)' : 'transparent';
    document.getElementById('tab-btn-billing').style.color = tab === 'billing' ? 'var(--primary)' : 'var(--text-muted)';
    
    document.getElementById('tab-content-info').style.display = tab === 'info' ? 'block' : 'none';
    document.getElementById('tab-content-billing').style.display = tab === 'billing' ? 'block' : 'none';
}

async function loadStudentBillingProfile(studentId) {
    try {
        const res = await fetch(API_BASE_URL + 'api/billing.php?action=get_student_billing&student_id=' + studentId);
        const json = await res.json();
        
        document.getElementById('student-billing-loader').style.display = 'none';
        const container = document.getElementById('student-billing-container');
        container.style.display = 'block';

        if (json.status === 'success') {
            if (!json.data || json.data.length === 0) {
                container.innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--text-muted);">No billing records found for this student.</div>';
                return;
            }

            let totalDue = 0;
            let totalPaid = 0;
            
            let html = '<div style="display:flex; flex-direction:column; gap:0.75rem; max-height:400px; overflow-y:auto; padding-right:5px;">';
            json.data.forEach(b => {
                totalDue += parseFloat(b.amount_due);
                totalPaid += parseFloat(b.amount_paid);
                
                let badgeClass = 'billing-badge-unpaid';
                if (b.payment_status === 'Paid') badgeClass = 'billing-badge-paid';
                else if (b.payment_status === 'Partial') badgeClass = 'billing-badge-partial';
                else if (b.payment_status === 'Exempt') badgeClass = 'billing-badge-exempt';

                html += `
                <div class="billing-record-card" style="border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem; background: var(--bg-color);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                        <div>
                            <h4 style="margin:0 0 0.25rem 0; font-size:0.95rem; color:var(--dark);">${b.category_name}</h4>
                            <div style="font-size:0.8rem; color:var(--text-muted);">${b.academic_year} - ${b.school_term}</div>
                        </div>
                        <span class="billing-badge ${badgeClass}">${b.payment_status}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color);">
                        <div style="font-size:0.85rem;">
                            <span style="color:var(--text-muted);">Due:</span> <strong style="color:var(--dark);">KES ${parseFloat(b.amount_due).toLocaleString()}</strong>
                        </div>
                        <div style="font-size:0.85rem;">
                            <span style="color:var(--text-muted);">Paid:</span> <strong style="color:#059669;">KES ${parseFloat(b.amount_paid).toLocaleString()}</strong>
                        </div>
                        <div style="font-size:0.85rem;">
                            <span style="color:var(--text-muted);">Bal:</span> <strong style="color:#dc2626;">KES ${parseFloat(b.balance).toLocaleString()}</strong>
                        </div>
                    </div>
                </div>`;
            });
            html += '</div>';

            const totalBalance = totalDue - totalPaid;
            let summary = `
                <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                    <div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Total Outstanding</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: ${totalBalance > 0 ? '#dc2626' : '#059669'};">
                            KES ${totalBalance.toLocaleString()}
                        </div>
                    </div>
                    <a href="billing.html" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.9rem;"><i class="ph ph-arrow-square-out"></i> Manage Billing</a>
                </div>
            `;

            container.innerHTML = summary + html;
        } else {
            container.innerHTML = '<div style="color:red; text-align:center;">Failed to load billing records.</div>';
        }
    } catch(e) {
        console.error(e);
        document.getElementById('student-billing-container').innerHTML = '<div style="color:red; text-align:center;">Error loading billing data.</div>';
    }
}

function closeProfile() {
    const modal = document.getElementById('profile-modal');
    if (modal) {
        modal.style.opacity = '0';
        modal.style.pointerEvents = 'none';
    }
}

async function printIDCard(studentId) {
    const student = window._allStudents.find(s => s.id == studentId);
    if (!student) return;
    
    const qrContainer = document.getElementById('qrcode-container');
    const qrImg = qrContainer.querySelector('img').src;
    const photoSrc = student.photo ? (API_BASE_URL + student.photo + '?v=1.5') : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(student.full_name);
    
    // Open window immediately to prevent popup blocker
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    printWindow.document.write('Loading ID Card...');
    
    // Fetch settings
    let settings = {};
    try {
        const res = await fetch(API_BASE_URL + 'api/settings.php?action=get');
        const json = await res.json();
        if (json.status === 'success') {
            settings = json.data;
        }
    } catch(e) { console.error(e); }
    
    const schoolName = settings.school_name || "Sunrise High School";
    const schoolMotto = settings.school_motto || "Excellence • Discipline • Integrity";
    const schoolLogo = settings.school_logo ? (API_BASE_URL + settings.school_logo) : "";
    const schoolWebsite = settings.school_website || "www.yourschool.com";
    const schoolAddress = settings.school_address || "";
    const schoolEmail = settings.school_email || "";
    const accentColor = settings.accent_color || "#4f46e5";

    // ID Card Designer Settings
    const orientation = settings.id_card_orientation || 'vertical';
    const size = settings.id_card_size || 'medium';
    const headerColor = settings.id_card_header_color || accentColor;
    const headerTextColor = settings.id_card_header_text_color || '#ffffff';
    const bgColor = settings.id_card_bg_color || '#ffffff';
    const footerColor = settings.id_card_footer_color || '#0f172a';
    const backHeader = settings.id_card_back_header || 'STUDENT ID CARD';
    let terms = settings.id_card_terms || `This card is the property of ${schoolName}. It must be worn at all times while on school premises. If found, please return to the school administration.`;

    const isH = orientation === 'horizontal';
    
    // Base sizes (adjust for actual print resolution if needed, typically CSS pixels are 96dpi)
    const sizeMap = { 
        small: { w: 180, h: 288 }, // roughly CR80 aspect ratio
        medium: { w: 210, h: 330 }, 
        large: { w: 250, h: 380 } 
    };
    
    let w = sizeMap[size] ? sizeMap[size].w : 210;
    let h = sizeMap[size] ? sizeMap[size].h : 330;
    if (isH) {
        let tmp = w; w = h; h = tmp;
    }

    const logoHtml = schoolLogo ? `<img src="${schoolLogo}" style="height: 40px; margin-right: 10px; border-radius: 50%;">` : '';

    // Generate Layout based on orientation
    let cardFrontHtml = '';
    let cardBackHtml = '';

    if (isH) {
        // Horizontal Layout
        cardFrontHtml = `
            <div class="id-card horizontal">
                <div class="card-header" style="flex-direction:row; justify-content:flex-start; padding:10px 15px; height:auto;">
                    ${logoHtml}
                    <div style="text-align:left;">
                        <div class="school-name">${schoolName}</div>
                        <div class="school-motto">${schoolMotto}</div>
                    </div>
                </div>
                <div style="display:flex; flex:1; padding:10px 15px; gap:15px; align-items:center;">
                    <div class="photo-container" style="margin:0; width:80px; height:80px;">
                        <img src="${photoSrc}" class="student-photo" alt="Photo" style="width:80px; height:80px;">
                    </div>
                    <div style="flex:1;">
                        <div class="student-name" style="text-align:left; margin-top:0;">${student.full_name}</div>
                        <div class="student-class" style="text-align:left; margin-bottom:5px;">${student.class}</div>
                        
                        <div class="info-grid" style="padding:0; display:grid; grid-template-columns:1fr 1fr; gap:2px 10px;">
                            <div class="info-row"><span class="info-label" style="width:45px;">Adm No:</span><span class="info-value">${student.admission_number}</span></div>
                            <div class="info-row"><span class="info-label" style="width:45px;">D.O.B:</span><span class="info-value">${student.dob || '—'}</span></div>
                            <div class="info-row"><span class="info-label" style="width:45px;">Valid Until:</span><span class="info-value">${student.valid_until || '—'}</span></div>
                        </div>
                    </div>
                </div>
                <div class="footer">${schoolWebsite || schoolEmail}</div>
            </div>
        `;

        cardBackHtml = `
            <div class="id-card horizontal">
                <div class="back-header">${backHeader}</div>
                <div class="back-body" style="flex-direction:row; padding:10px; gap:10px;">
                    <div style="flex:1; display:flex; flex-direction:column; justify-content:center; text-align:left;">
                        <div class="terms" style="margin-bottom:5px;">${terms}</div>
                        <div class="school-info-block" style="text-align:left; border-top:none; border-left:1px solid #e2e8f0; padding-left:10px;">
                            <strong>${schoolName}</strong>
                            ${schoolAddress ? `<div><span class="school-info-icon">📍</span>${schoolAddress}</div>` : ''}
                            ${schoolEmail ? `<div><span class="school-info-icon">✉️</span>${schoolEmail}</div>` : ''}
                        </div>
                    </div>
                    <div class="qr-container" style="padding:0; align-items:center; flex:none;">
                        <img src="${qrImg}" alt="QR Code" style="width:90px; height:90px;">
                    </div>
                </div>
                <div class="footer">${schoolWebsite || schoolEmail}</div>
            </div>
        `;
    } else {
        // Vertical Layout (Standard)
        cardFrontHtml = `
            <div class="id-card">
                <div class="card-header">
                    <div class="school-header-row">
                        ${logoHtml}
                        <div class="school-name">${schoolName}</div>
                    </div>
                    <div class="school-motto">${schoolMotto}</div>
                </div>
                <div class="photo-container">
                    <img src="${photoSrc}" class="student-photo" alt="Photo">
                </div>
                <div class="student-name">${student.full_name}</div>
                <div class="student-class">${student.class}</div>
                
                <div class="info-grid">
                    <div class="info-row">
                        <span class="info-label">Adm No:</span>
                        <span class="info-value">${student.admission_number}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">D.O.B:</span>
                        <span class="info-value">${student.dob || '—'}</span>
                    </div>
                    ${student.admitted_date ? `<div class="info-row"><span class="info-label">Admitted:</span><span class="info-value">${student.admitted_date}</span></div>` : ''}
                    <div class="info-row">
                        <span class="info-label">Valid Until:</span>
                        <span class="info-value">${student.valid_until || '—'}</span>
                    </div>
                </div>
            </div>
        `;

        cardBackHtml = `
            <div class="id-card">
                <div class="back-header">${backHeader}</div>
                <div class="back-body">
                    <div class="terms">${terms}</div>
                    
                    <div class="qr-container">
                        <img src="${qrImg}" alt="QR Code">
                    </div>

                    <div class="school-info-block">
                        <strong>${schoolName}</strong>
                        ${schoolAddress ? `<div><span class="school-info-icon">📍</span>${schoolAddress}</div>` : ''}
                        ${schoolEmail ? `<div><span class="school-info-icon">✉️</span>${schoolEmail}</div>` : ''}
                        ${schoolWebsite ? `<div><span class="school-info-icon">🌐</span>${schoolWebsite}</div>` : ''}
                    </div>
                </div>

                <div class="footer">
                    ${schoolWebsite || schoolEmail}
                </div>
            </div>
        `;
    }

    const htmlContent = `
        <!DOCTYPE html>
        <html>
            <head>
                <title>Print ID Card - ${student.full_name}</title>
                <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
                <style>
                    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
                    body { 
                        font-family: 'Inter', sans-serif; 
                        margin: 0; 
                        padding: 20px; 
                        background: #f1f5f9;
                        display: flex;
                        gap: 20px;
                        justify-content: center;
                        flex-wrap: wrap;
                    }
                    .id-card {
                        width: ${w}px;
                        height: ${h}px;
                        background: ${bgColor};
                        border-radius: 10px;
                        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
                        overflow: hidden;
                        display: flex;
                        flex-direction: column;
                        position: relative;
                    }
                    /* FRONT CARD */
                    .card-header {
                        background: ${headerColor};
                        color: ${headerTextColor};
                        padding: 15px 10px;
                        text-align: center;
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                    }
                    .school-header-row {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        width: 100%;
                    }
                    .school-name {
                        font-weight: 700;
                        font-size: 13px;
                        line-height: 1.1;
                    }
                    .school-motto {
                        font-size: 8px;
                        opacity: 0.9;
                        margin-top: 3px;
                        letter-spacing: 0.5px;
                    }
                    .photo-container {
                        text-align: center;
                        margin-top: -30px;
                        z-index: 2;
                    }
                    .student-photo {
                        width: 80px;
                        height: 80px;
                        border-radius: 50%;
                        border: 3px solid white;
                        object-fit: cover;
                        background: white;
                        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                    }
                    .student-name {
                        text-align: center;
                        font-weight: 700;
                        font-size: 15px;
                        margin-top: 5px;
                        color: #1e293b;
                    }
                    .student-class {
                        text-align: center;
                        font-size: 11px;
                        color: ${headerColor};
                        font-weight: 600;
                        margin-bottom: 10px;
                    }
                    .info-grid {
                        padding: 0 15px;
                        font-size: 9px;
                        flex: 1;
                    }
                    .info-row {
                        display: flex;
                        margin-bottom: 5px;
                    }
                    .info-label {
                        color: #64748b;
                        width: 60px;
                        font-weight: 600;
                    }
                    .info-value {
                        color: #0f172a;
                        font-weight: 600;
                    }

                    /* BACK CARD */
                    .back-header {
                        background: ${headerColor};
                        color: ${headerTextColor};
                        text-align: center;
                        padding: 10px;
                        font-weight: 700;
                        font-size: 12px;
                        letter-spacing: 1px;
                    }
                    .back-body {
                        padding: 15px;
                        font-size: 9px;
                        color: #334155;
                        text-align: center;
                        flex: 1;
                        display: flex;
                        flex-direction: column;
                    }
                    .terms {
                        margin-bottom: 10px;
                        line-height: 1.4;
                    }
                    .emergency {
                        background: #fee2e2;
                        color: #b91c1c;
                        padding: 8px;
                        border-radius: 5px;
                        margin-bottom: 15px;
                        font-weight: 600;
                    }
                    .qr-container {
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        flex: 1;
                        padding: 10px;
                    }
                    .qr-container img {
                        width: 110px;
                        height: 110px;
                        border: 5px solid white;
                        border-radius: 8px;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                    }
                    .footer {
                        background: ${footerColor};
                        color: white;
                        text-align: center;
                        padding: 6px;
                        font-size: 8px;
                        position: absolute;
                        bottom: 0;
                        width: 100%;
                        box-sizing: border-box;
                    }

                    .school-info-block {
                        font-size: 8px;
                        color: #334155;
                        text-align: center;
                        padding: 8px 10px;
                        background: #f8fafc;
                        border-top: 1px solid #e2e8f0;
                        line-height: 1.8;
                    }
                    .school-info-block strong {
                        display: block;
                        font-size: 9px;
                        color: #1e293b;
                        margin-bottom: 3px;
                    }
                    .school-info-icon {
                        margin-right: 3px;
                    }

                    @media print {
                        body { 
                            background: white; 
                            padding: 0;
                            gap: 10px;
                        }
                        .id-card { box-shadow: none; border: 1px solid #ccc; }
                        button { display: none; }
                        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                    }
                </style>
            </head>
            <body>
                <!-- Front -->
                ${cardFrontHtml}

                <!-- Back -->
                ${cardBackHtml}

                <div style="position: fixed; top: 20px; right: 20px;">
                    <button onclick="window.print()" style="padding: 10px 20px; background: ${accentColor}; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">
                        <svg style="width:16px; height:16px; vertical-align:middle; margin-right:5px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                        Print ID Card
                    </button>
                </div>

                <script>
                    // Auto open print dialog after a slight delay for images/fonts to load
                    setTimeout(() => {
                        window.print();
                    }, 800);
                </script>
            </body>
        </html>
    `;
    printWindow.document.open();
    printWindow.document.write(htmlContent);
    printWindow.document.close();
}

// ============================================================================
// EXPORT STUDENT RECORDS (PDF) — administrative record-keeping only.
//
// This feature is intentionally kept separate from the QR Code / Student ID
// generation features. It must NEVER render QR codes, barcodes, student
// photos, or ID-card layouts. Those live exclusively in generateBlankQRPDF()
// (Generate QR Codes) and printIDCard() (Generate Student ID).
// ============================================================================

// Definition of every column the administrator can choose to print.
// `default: true` marks the columns that ship enabled out of the box, per
// the Default Configuration requirement (Admission Number, Student Name,
// Class, Gender, Parent/Guardian Name, Parent/Guardian Phone Number).
const STUDENT_PDF_FIELDS = [
    { key: 'admission_number', label: 'Adm. No.',              width: 26 },
    { key: 'full_name',        label: 'Student Name',          width: 44 },
    { key: 'class',            label: 'Class',                 width: 24 },
    { key: 'gender',           label: 'Gender',                width: 20 },
    { key: 'parent_name',      label: 'Parent/Guardian Name',  width: 40 },
    { key: 'parent_phone',     label: 'Parent/Guardian Phone', width: 34 },
    { key: 'fee_status',       label: 'Fee Status',            width: 24 },
    { key: 'admitted_date',    label: 'Date Registered',       width: 28 }
];

function openPdfModal() {
    const modal = document.getElementById('pdf-modal');
    if (!modal) return;

    // Reset the dialog to its default state every time it is opened, so a
    // previous selection never leaks into the next export.
    const scopeAll = modal.querySelector('input[name="pdf-scope"][value="all"]');
    if (scopeAll) scopeAll.checked = true;

    modal.querySelectorAll('.pdf-field-checkbox').forEach(cb => {
        const field = STUDENT_PDF_FIELDS.find(f => f.key === cb.dataset.field);
        cb.checked = !!(field && ['admission_number', 'full_name', 'class', 'gender', 'parent_name', 'parent_phone'].includes(field.key));
    });

    const errorEl = document.getElementById('pdf-field-error');
    if (errorEl) errorEl.style.display = 'none';

    resetGeneratePdfButton();

    modal.style.display = 'flex';
    setTimeout(() => { modal.style.opacity = '1'; }, 10);
}

function closePdfModal() {
    const modal = document.getElementById('pdf-modal');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => {
            modal.style.display = 'none';
            resetGeneratePdfButton();
        }, 300);
    }
}

function resetGeneratePdfButton() {
    const btn = document.getElementById('btn-generate-pdf');
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph ph-file-pdf" style="font-size: 1.2rem; margin-right: 0.4rem;"></i> Generate PDF';
    }
}

// Reads the administrator's Print Options selections from the dialog.
// Returns null (and shows an inline validation message) if nothing was
// selected, since an empty table would not be a usable record.
function getSelectedPdfOptions() {
    const scopeInput = document.querySelector('input[name="pdf-scope"]:checked');
    const scope = scopeInput ? scopeInput.value : 'all';

    const selectedKeys = Array.from(document.querySelectorAll('.pdf-field-checkbox:checked'))
        .map(cb => cb.dataset.field);

    const errorEl = document.getElementById('pdf-field-error');

    if (selectedKeys.length === 0) {
        if (errorEl) errorEl.style.display = 'block';
        return null;
    }
    if (errorEl) errorEl.style.display = 'none';

    // Preserve the canonical column order regardless of DOM order.
    const fields = STUDENT_PDF_FIELDS.filter(f => selectedKeys.includes(f.key));
    return { scope, fields };
}

// Loads a (same-origin) image URL as a base64 data URL for embedding into
// jsPDF. Resolves to null on any failure so a missing/unreachable school
// logo never blocks the export — the report is generated with a text-only
// header instead.
function loadImageAsDataURL(url) {
    return new Promise((resolve) => {
        if (!url) { resolve(null); return; }
        try {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = img.naturalWidth;
                    canvas.height = img.naturalHeight;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0);
                    resolve(canvas.toDataURL('image/png'));
                } catch (e) {
                    resolve(null);
                }
            };
            img.onerror = () => resolve(null);
            img.src = url;
        } catch (e) {
            resolve(null);
        }
    });
}

function formatPdfCellValue(field, student) {
    const raw = student[field.key];
    if (field.key === 'admitted_date') {
        if (!raw) return 'N/A';
        const d = new Date(raw);
        return isNaN(d.getTime()) ? raw : d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }
    if (field.key === 'fee_status') {
        return raw || 'N/A';
    }
    return (raw === undefined || raw === null || raw === '') ? '-' : raw;
}

// Helper function to generate QR Code as DataURL
function generateQRCodeBase64(text) {
    return new Promise((resolve, reject) => {
        try {
            const tempDiv = document.createElement('div');
            tempDiv.style.position = 'absolute';
            tempDiv.style.left = '-9999px';
            document.body.appendChild(tempDiv);
            
            // This initializes qrcodejs synchronously
            const qr = new QRCode(tempDiv, {
                text: text,
                width: 128,
                height: 128,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.L
            });
            
            // Sometimes the canvas takes a tick to be accessible in certain mobile browsers
            setTimeout(() => {
                try {
                    const canvas = tempDiv.querySelector('canvas');
                    const img = tempDiv.querySelector('img');
                    
                    let dataUrl = null;
                    if (canvas && canvas.width > 0) {
                        dataUrl = canvas.toDataURL("image/png");
                    } else if (img && img.src && img.src.startsWith('data:')) {
                        dataUrl = img.src;
                    }
                    
                    if (dataUrl) {
                        resolve(dataUrl);
                    } else {
                        reject(new Error("QR Canvas or Image not found"));
                    }
                } catch(err) {
                    reject(err);
                } finally {
                    if (tempDiv.parentNode) {
                        tempDiv.parentNode.removeChild(tempDiv);
                    }
                }
            }, 50); // 50ms delay to ensure DOM updates have flushed
        } catch(err) {
            reject(err);
        }
    });
}

// Generates the Student Records PDF for administrative record-keeping.
// STRICTLY excludes QR codes, barcodes, student photos, and ID-card
// graphics — only the columns chosen in Print Options are rendered.
async function downloadStudentsPDF() {
    const options = getSelectedPdfOptions();
    if (!options) return; // Validation message already shown inline.

    const { scope, fields } = options;

    const btn = document.getElementById('btn-generate-pdf');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="ph ph-spinner ph-spin" style="font-size: 1.2rem; margin-right: 0.4rem;"></i> Generating...';
    }

    try {
        if (typeof window.jspdf === 'undefined' || !window.jspdf.jsPDF) {
            throw new Error('PDF library is not available. Please check your internet connection.');
        }

        // Fetch all students (single request — no per-row QR/image generation,
        // so this stays fast and memory-light even for large registers).
        const resStudents = await fetchData('api/students.php?action=list');
        if (!resStudents || resStudents.status !== 'success' || !resStudents.data || resStudents.data.length === 0) {
            showNotification('No students found in the database.', 'error');
            resetGeneratePdfButton();
            return;
        }

        let finalData = resStudents.data;
        let reportSubtitle = 'All Registered Students';

        // Fetch attendance for filtering by scope
        if (scope !== 'all') {
            const today = new Date().toISOString().split('T')[0];
            const resAttendance = await fetchData(`api/attendance.php?action=records&date=${today}`);

            const presentStudentIds = new Set();
            if (resAttendance && resAttendance.status === 'success') {
                resAttendance.data.forEach(record => {
                    presentStudentIds.add(record.admission_number);
                });
            }

            if (scope === 'present') {
                finalData = resStudents.data.filter(s => presentStudentIds.has(s.admission_number));
                reportSubtitle = `Present Students - ${today}`;
            } else if (scope === 'absent') {
                finalData = resStudents.data.filter(s => !presentStudentIds.has(s.admission_number));
                reportSubtitle = `Absent Students - ${today}`;
            }
        }

        if (finalData.length === 0) {
            showNotification(`No students match the selected scope "${scope}".`, 'error');
            resetGeneratePdfButton();
            return;
        }

        // Fetch school branding for the report header (name + logo only —
        // no student identification graphics of any kind).
        let settings = {};
        try {
            const resSettings = await fetchData('api/settings.php?action=get');
            if (resSettings && resSettings.status === 'success') {
                settings = resSettings.data || {};
            }
        } catch (e) {
            // Branding is optional; proceed with sensible defaults.
        }
        const schoolName = settings.school_name || 'EduGate School System';
        const schoolLogoUrl = settings.school_logo ? (API_BASE_URL + settings.school_logo) : '';
        const logoDataUrl = schoolLogoUrl ? await loadImageAsDataURL(schoolLogoUrl) : null;

        // Choose orientation automatically: compact selections print cleanly
        // in portrait, wider selections switch to landscape so nothing gets
        // cramped or truncated.
        const orientation = fields.length > 5 ? 'landscape' : 'portrait';
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation, unit: 'mm', format: 'a4' });
        const pageWidth = doc.internal.pageSize.getWidth();
        const margin = 12;
        const headerHeight = 26;

        function drawHeader() {
            doc.setFillColor(79, 70, 229);
            doc.rect(0, 0, pageWidth, headerHeight, 'F');

            let textX = margin;
            if (logoDataUrl) {
                try {
                    doc.addImage(logoDataUrl, 'PNG', margin, 4, 18, 18);
                    textX = margin + 24;
                } catch (e) {
                    // Skip a logo that fails to embed rather than breaking the report.
                }
            }

            doc.setTextColor(255, 255, 255);
            doc.setFontSize(16);
            doc.setFont('helvetica', 'bold');
            doc.text(schoolName, textX, 12);

            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text(`Student Register - ${reportSubtitle}`, textX, 19);

            const now = new Date();
            const dateStr = now.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            doc.setFontSize(8.5);
            doc.text(`Generated: ${dateStr}, ${timeStr}`, pageWidth - margin, 19, { align: 'right' });
        }

        drawHeader();

        // Table head/body built strictly from the administrator-selected
        // columns — never includes QR/barcode/photo data.
        const head = [['#', ...fields.map(f => f.label)]];
        const body = finalData.map((s, i) => [
            i + 1,
            ...fields.map(f => formatPdfCellValue(f, s))
        ]);

        const columnStyles = { 0: { cellWidth: 10, halign: 'center' } };
        fields.forEach((f, idx) => {
            columnStyles[idx + 1] = { cellWidth: f.width };
        });

        doc.autoTable({
            startY: headerHeight + 6,
            head,
            body,
            styles: { fontSize: 8.5, cellPadding: 3, textColor: [40, 40, 40], valign: 'middle' },
            headStyles: { fillColor: [79, 70, 229], textColor: 255, fontStyle: 'bold', fontSize: 9 },
            alternateRowStyles: { fillColor: [248, 248, 255] },
            columnStyles,
            margin: { left: margin, right: margin, top: headerHeight + 6 },
            showHead: 'everyPage', // Repeat the header row on every new page.
            didDrawPage: function () {
                // Redraw the branded header on every page (not just the first).
                if (doc.internal.getCurrentPageInfo().pageNumber > 1) {
                    drawHeader();
                }
            }
        });

        // Footer with page numbers on every page.
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            const pageHeight = doc.internal.pageSize.getHeight();
            doc.setFillColor(79, 70, 229);
            doc.rect(0, pageHeight - 9, pageWidth, 9, 'F');
            doc.setFontSize(8);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(255, 255, 255);
            doc.text(`${schoolName} — Confidential Administrative Record`, margin, pageHeight - 3);
            doc.text(`Page ${i} of ${pageCount}`, pageWidth - margin, pageHeight - 3, { align: 'right' });
        }

        const now = new Date();
        doc.save(`Student_Register_${scope}_${now.toISOString().slice(0, 10)}.pdf`);
        showNotification(`PDF exported with ${finalData.length} students!`, 'success');
        closePdfModal();

    } catch (e) {
        console.error("PDF Error:", e);
        showNotification(e.message || "Failed to generate PDF.", 'error');
        resetGeneratePdfButton();
    }
}

// Edit and Delete functions
async function editStudent(id) {
    const res = await fetchData(`api/students.php?action=get&id=${id}`);
    if (res && res.status === 'success') {
        const s = res.data;
        document.getElementById('edit-id').value = s.id;
        document.getElementById('edit-full-name').value = s.full_name;
        document.getElementById('edit-admission-number').value = s.admission_number;
        document.getElementById('edit-class').value = s.class;
        
        if (document.getElementById('edit-gender')) document.getElementById('edit-gender').value = s.gender;
        if (document.getElementById('edit-dob')) document.getElementById('edit-dob').value = s.dob || '';
        if (document.getElementById('edit-blood_group')) document.getElementById('edit-blood_group').value = s.blood_group || '';
        if (document.getElementById('edit-emergency_contact')) document.getElementById('edit-emergency_contact').value = s.emergency_contact || '';
        if (document.getElementById('edit-admitted_date')) document.getElementById('edit-admitted_date').value = s.admitted_date || '';
        if (document.getElementById('edit-valid_until')) document.getElementById('edit-valid_until').value = s.valid_until || '';

        if (document.getElementById('edit-assigned_bus_id')) document.getElementById('edit-assigned_bus_id').value = s.assigned_bus_id || '';
        if (document.getElementById('edit-route_number')) document.getElementById('edit-route_number').value = s.route_number || '';
        if (document.getElementById('edit-pickup_location')) document.getElementById('edit-pickup_location').value = s.pickup_location || '';
        if (document.getElementById('edit-dropoff_location')) document.getElementById('edit-dropoff_location').value = s.dropoff_location || '';

        // Parent/Guardian Information
        if (document.getElementById('edit-parent_name')) document.getElementById('edit-parent_name').value = s.parent_name || '';
        if (document.getElementById('edit-parent_phone')) document.getElementById('edit-parent_phone').value = s.parent_phone || '';
        if (document.getElementById('edit-parent_relationship')) document.getElementById('edit-parent_relationship').value = s.parent_relationship || '';
        if (document.getElementById('edit-parent_relationship_other')) document.getElementById('edit-parent_relationship_other').value = s.parent_relationship_other || '';
        toggleRelationshipOtherField('edit-parent_relationship', 'edit-parent_relationship_other_group', 'edit-parent_relationship_other');

        document.getElementById('edit-photo').value = ''; // Reset file input
        
        const modal = document.getElementById('edit-modal');
        modal.style.display = 'flex';
        // Allow tiny delay for display:flex to apply before transition
        setTimeout(() => modal.style.pointerEvents = 'auto', 10);
    } else {
        showNotification("Failed to load student data.", "error");
    }
}

function closeEditModal() {
    const modal = document.getElementById('edit-modal');
    modal.style.pointerEvents = 'none';
    modal.style.display = 'none';
}

async function deleteStudent(id) {
    if (confirm("WARNING: Are you sure you want to completely delete this student? All their attendance records will be permanently erased. This cannot be undone!")) {
        try {
            const formData = new FormData();
            formData.append('id', id);
            const response = await fetch(API_BASE_URL + 'api/students.php?action=delete', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.status === 'success') {
                showNotification(result.message, 'success');
                loadStudents();
            } else {
                showNotification(result.message, 'error');
            }
        } catch (error) {
            console.error(error);
            showNotification('Failed to delete student.', 'error');
        }
    }
}

async function markPresent(qrCode) {
    try {
        const formData = new FormData();
        formData.append('qr_code', qrCode);
        const response = await fetch(API_BASE_URL + 'api/attendance.php?action=scan', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.status === 'success') {
            showNotification("Student marked as present!", 'success');
        } else if (result.status === 'duplicate') {
            showNotification(result.message, 'warning'); // "Student already scanned today"
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error(error);
        showNotification('Failed to mark attendance.', 'error');
    }
}

// ----------------------------------------------------------------------
// GENERATE QR CODES (PDF)
// ----------------------------------------------------------------------
function openGenerateQRModal() {
    const modal = document.getElementById('generate-qr-modal');
    if (modal) {
        modal.style.display = 'flex';
        setTimeout(() => modal.style.opacity = '1', 10);
    }
}

function closeGenerateQRModal() {
    const modal = document.getElementById('generate-qr-modal');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => modal.style.display = 'none', 300);
    }
}

document.getElementById('generate-qr-form')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const quantity = parseInt(document.getElementById('qr-quantity').value);
    const size = parseInt(document.getElementById('qr-size').value);
    const spacingInput = document.getElementById('qr-spacing');
    const spacing = spacingInput ? parseFloat(spacingInput.value) : 20.0;
    
    if (quantity < 1 || quantity > 100) {
        showNotification('Please enter a quantity between 1 and 100', 'error');
        return;
    }
    
    generateBlankQRPDF(quantity, size, spacing);
    closeGenerateQRModal();
});

async function generateBlankQRPDF(quantity, qrSizeMM, desiredSpacing = 20.0) {
    try {
        if (!window.jspdf) {
            throw new Error("jsPDF library is not loaded. Please check your internet connection.");
        }
        const { jsPDF } = window.jspdf;
        if (!jsPDF) {
            throw new Error("jsPDF class not found in window.jspdf");
        }
        
        const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
        
        // A4 size: 210 x 297 mm
        const margin = 10;
        const spacing = desiredSpacing;
        
        // Calculate how many fit per row/col exactly
        const cols = Math.floor((210 - margin*2 + spacing) / (qrSizeMM + spacing));
        const rows = Math.floor((297 - margin*2 + spacing) / (qrSizeMM + spacing));
        
        if (cols <= 0 || rows <= 0) {
            throw new Error("QR Size and Spacing are too large to fit on the page.");
        }
        
        // Use EXACT spacing as requested by user
        const actualSpacingX = spacing;
        const actualSpacingY = spacing;

        showNotification(`Generating PDF with ${quantity} QR codes...`, 'success');

        let currentItem = 0;
        
        while (currentItem < quantity) {
            if (currentItem > 0 && currentItem % (cols * rows) === 0) {
                doc.addPage();
            }
            
            const pageItem = currentItem % (cols * rows);
            const col = pageItem % cols;
            const row = Math.floor(pageItem / cols);
            
            const x = margin + col * (qrSizeMM + actualSpacingX);
            const y = margin + row * (qrSizeMM + actualSpacingY);
            
            // Generate a cryptographically secure random QR string
            const array = new Uint8Array(16);
            window.crypto.getRandomValues(array);
            const hex = Array.from(array).map(b => b.toString(16).padStart(2, '0')).join('').toUpperCase();
            const qrString = `QR-ID-${hex}`;
            
            // Generate QR Code Base64
            const qrBase64 = await generateQRCodeBase64(qrString);
            
            if (!qrBase64 || !qrBase64.startsWith('data:image')) {
                throw new Error("Failed to generate valid QR code image data");
            }

            // Draw Image
            doc.addImage(qrBase64, 'PNG', x, y, qrSizeMM, qrSizeMM);
            
            currentItem++;
        }
        
        doc.save(`Blank_QR_Codes_${quantity}.pdf`);
    } catch (e) {
        console.error("PDF Generation Error:", e);
        showNotification("PDF Error: " + e.message, "error");
        alert("PDF Error: " + e.message); // Added an alert to ensure the user sees it even if notifications fail
    }
}

// ----------------------------------------------------------------------
// ASSIGN QR CODE SCANNER
// ----------------------------------------------------------------------
let assignQrScanner = null;
let currentAssignStudentId = null;

function openAssignQRScanner(studentId, currentQrCode) {
    if (currentQrCode && currentQrCode.trim() !== '') {
        if (!confirm("This student already has a QR code assigned. Do you want to replace it with a new one?")) {
            return;
        }
    }
    
    currentAssignStudentId = studentId;
    const modal = document.getElementById('assign-qr-modal');
    if (modal) {
        modal.style.display = 'flex';
        setTimeout(() => modal.style.opacity = '1', 10);
    }
    
    document.getElementById('assign-qr-status').textContent = 'Looking for QR code...';
    document.getElementById('assign-qr-status').style.color = '#64748b';
    
    startAssignScanner();
}

function closeAssignQRScanner() {
    const modal = document.getElementById('assign-qr-modal');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => modal.style.display = 'none', 300);
    }
    
    if (assignQrScanner) {
        assignQrScanner.stop().then(() => {
            assignQrScanner.clear();
            assignQrScanner = null;
        }).catch(err => console.error("Failed to stop scanner", err));
    }
}

function startAssignScanner() {
    if (assignQrScanner) return;

    assignQrScanner = new Html5Qrcode("qr-reader");
    
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
    
    // Default to back camera
    assignQrScanner.start(
        { facingMode: "environment" }, 
        config,
        onAssignScanSuccess,
        onAssignScanFailure
    ).catch(err => {
        console.error("Error starting scanner:", err);
        document.getElementById('assign-qr-status').textContent = "Camera error: " + err;
        document.getElementById('assign-qr-status').style.color = '#ef4444';
    });
}

function onAssignScanFailure(error) {
    // ignore background errors while scanning
}

async function onAssignScanSuccess(decodedText, decodedResult) {
    if (!currentAssignStudentId) return;
    
    // Stop scanner to prevent multiple scans
    if (assignQrScanner) {
        await assignQrScanner.stop();
        assignQrScanner.clear();
        assignQrScanner = null;
    }
    
    document.getElementById('assign-qr-status').textContent = 'Processing: ' + decodedText;
    document.getElementById('assign-qr-status').style.color = '#3b82f6';
    
    try {
        const formData = new FormData();
        formData.append('id', currentAssignStudentId);
        formData.append('qr_code', decodedText);
        
        const response = await fetch(API_BASE_URL + 'api/students.php?action=assign_qr', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        if (result.status === 'success') {
            document.getElementById('assign-qr-status').textContent = 'Success! QR Code assigned.';
            document.getElementById('assign-qr-status').style.color = '#10b981';
            showNotification(result.message, 'success');
            
            // Reload the table data
            loadStudents();
            
            // Close after 1.5 seconds
            setTimeout(() => {
                closeAssignQRScanner();
            }, 1500);
        } else {
            document.getElementById('assign-qr-status').textContent = result.message;
            document.getElementById('assign-qr-status').style.color = '#ef4444';
            
            // Offer to retry
            const retryBtn = document.createElement('button');
            retryBtn.textContent = 'Retry Scan';
            retryBtn.className = 'btn btn-primary';
            retryBtn.style.marginTop = '10px';
            retryBtn.onclick = () => {
                document.getElementById('assign-qr-status').innerHTML = 'Looking for QR code...';
                document.getElementById('assign-qr-status').style.color = '#64748b';
                startAssignScanner();
            };
            document.getElementById('assign-qr-status').appendChild(document.createElement('br'));
            document.getElementById('assign-qr-status').appendChild(retryBtn);
        }
    } catch (error) {
        console.error(error);
        document.getElementById('assign-qr-status').textContent = 'Network error during assignment.';
        document.getElementById('assign-qr-status').style.color = '#ef4444';
    }
}
