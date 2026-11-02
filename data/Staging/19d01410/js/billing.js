/**
 * js/billing.js — Billing Management Page Logic
 * Handles: summary cards, student billing table, bulk actions, modals, PDF/Excel export.
 */

'use strict';

// ── Module State ──────────────────────────────────────────────────────────────
let _billingData      = [];
let _categories       = [];
let _filteredData     = [];
let _selectedIds      = new Set();
let _currentSettings  = {};
let _currentBillingRecords = [];
let _lastMpesaCheck   = ''; // Will be populated on first fetch

// ── Init ──────────────────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
    // Admin-only page guard
    const role = localStorage.getItem('user_role');
    if (role !== 'Admin') {
        document.querySelector('.main-content').innerHTML = `
            <div style="display:flex;align-items:center;justify-content:center;min-height:60vh;flex-direction:column;gap:1rem;">
                <i class="ph ph-lock" style="font-size:4rem;color:var(--text-muted);"></i>
                <h2 style="color:var(--text-main);">Access Restricted</h2>
                <p style="color:var(--text-muted);">The Billing section is for Administrators only.</p>
                <a href="index.html" class="btn btn-primary">← Go to Dashboard</a>
            </div>`;
        return;
    }

    await loadSettings();
    await loadCategories();
    await loadBillingData();

    // Pre-fill year/term from settings
    const yearInput = document.getElementById('filter-billing-year');
    if (yearInput && _currentSettings.academic_year) yearInput.value = _currentSettings.academic_year;

    setupEventListeners();
    renderSummaryCards();
    loadUnallocatedPayments();
    
    // Poll for unallocated payments and new M-Pesa notifications every 10 seconds
    setInterval(() => {
        loadUnallocatedPayments();
        pollMpesaNotifications();
    }, 10000);
});

async function loadSettings() {
    const res = await fetchData('api/settings.php?action=get');
    if (res && res.status === 'success') _currentSettings = res.data;
}

async function loadCategories() {
    const res = await fetchData('api/billing.php?action=get_categories');
    if (res && res.status === 'success') {
        _categories = res.data;
        populateCategoryFilters();
    }
}

async function loadBillingData() {
    const params = buildFilterParams();
    const res = await fetchData('api/billing.php?action=students_billing_list&' + params);
    if (res && res.status === 'success') {
        _billingData = res.data;
        _filteredData = [..._billingData];
        applyClientSearch();
        renderTable();
    } else {
        document.getElementById('billing-tbody').innerHTML =
            `<tr><td colspan="6" style="text-align:center;padding:3rem;color:var(--text-muted);">No billing data found. Ensure students are registered and billing_migrate.php has been run.</td></tr>`;
    }
}

function buildFilterParams() {
    const cat    = document.getElementById('filter-billing-category')?.value || '';
    const status = document.getElementById('filter-billing-status')?.value || '';
    const cls    = document.getElementById('filter-billing-class')?.value || '';
    const year   = document.getElementById('filter-billing-year')?.value || '';
    const term   = document.getElementById('filter-billing-term')?.value || '';
    const p = new URLSearchParams();
    if (cat)    p.append('category_id',    cat);
    if (status) p.append('payment_status', status);
    if (cls)    p.append('class',          cls);
    if (year)   p.append('academic_year',  year);
    if (term)   p.append('school_term',    term);
    return p.toString();
}

// ── Category Filters ──────────────────────────────────────────────────────────
function populateCategoryFilters() {
    const catSel  = document.getElementById('filter-billing-category');
    const bulkSel = document.getElementById('bulk-category-select');
    const assignSel = document.getElementById('assign-category');

    const activeCats = _categories.filter(c => c.is_active == 1);

    if (catSel) {
        catSel.innerHTML = '<option value="">All Categories</option>';
        activeCats.forEach(c => {
            catSel.innerHTML += `<option value="${c.id}">${c.name}</option>`;
        });
    }
    if (bulkSel) {
        bulkSel.innerHTML = '<option value="">All Categories</option>';
        activeCats.forEach(c => {
            bulkSel.innerHTML += `<option value="${c.id}">${c.name}</option>`;
        });
    }
    if (assignSel) {
        assignSel.innerHTML = '<option value="">Select category…</option>';
        activeCats.forEach(c => {
            assignSel.innerHTML += `<option value="${c.id}" data-amount="${c.default_amount}">${c.name}${c.is_mandatory?' (Mandatory)':''}</option>`;
        });
    }

    // Populate class filter
    const classSel = document.getElementById('filter-billing-class');
    if (classSel && _billingData.length > 0) {
        const classes = [...new Set(_billingData.map(s => s.class).filter(Boolean))].sort();
        classSel.innerHTML = '<option value="">All Classes</option>';
        classes.forEach(c => { classSel.innerHTML += `<option value="${c}">${c}</option>`; });
    }
}

// ── Summary Cards ─────────────────────────────────────────────────────────────
async function renderSummaryCards() {
    const res = await fetchData('api/billing.php?action=billing_dashboard');
    if (!res || res.status !== 'success') return;

    const container = document.getElementById('billing-summary-cards');
    container.innerHTML = '';

    const colorMap = {
        0: { bg: 'linear-gradient(135deg,#4f46e5,#7c3aed)', icon: 'ph-graduation-cap' },
        1: { bg: 'linear-gradient(135deg,#059669,#10b981)', icon: 'ph-fork-knife'  },
        2: { bg: 'linear-gradient(135deg,#0284c7,#0ea5e9)', icon: 'ph-bus'         },
    };

    res.data.forEach((cat, idx) => {
        const c = colorMap[idx] || { bg: 'linear-gradient(135deg,#7c3aed,#a855f7)', icon: 'ph-tag' };
        const pct = cat.expected > 0 ? Math.round((cat.collected / cat.expected) * 100) : 0;
        container.innerHTML += `
        <div class="card billing-stat-card" style="padding:1.5rem;position:relative;overflow:hidden;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem;">
                <div>
                    <p style="font-size:0.78rem;font-weight:700;color:var(--text-muted);letter-spacing:0.5px;text-transform:uppercase;margin-bottom:0.3rem;">${cat.name}</p>
                    <div style="font-size:1.6rem;font-weight:800;color:var(--text-main);">KES ${Number(cat.collected).toLocaleString()}</div>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.2rem;">collected of KES ${Number(cat.expected).toLocaleString()}</p>
                </div>
                <div style="width:48px;height:48px;border-radius:12px;background:${c.bg};display:flex;align-items:center;justify-content:center;color:white;font-size:1.5rem;flex-shrink:0;">
                    <i class="ph ${c.icon}"></i>
                </div>
            </div>
            <div style="background:var(--border-color);border-radius:10px;height:6px;margin-bottom:1rem;">
                <div style="height:6px;border-radius:10px;background:${c.bg};width:${pct}%;transition:width 0.8s ease;"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.5rem;font-size:0.8rem;">
                <div style="text-align:center;padding:0.5rem;background:var(--bg-color);border-radius:8px;">
                    <div style="font-weight:700;color:#059669;">${cat.paid_count ?? '—'}</div>
                    <div style="color:var(--text-muted);">Paid</div>
                </div>
                <div style="text-align:center;padding:0.5rem;background:var(--bg-color);border-radius:8px;">
                    <div style="font-weight:700;color:#f59e0b;">${cat.partial_count ?? '—'}</div>
                    <div style="color:var(--text-muted);">Partially Paid</div>
                </div>
                <div style="text-align:center;padding:0.5rem;background:var(--bg-color);border-radius:8px;">
                    <div style="font-weight:700;color:#ef4444;">${cat.unpaid_count ?? '—'}</div>
                    <div style="color:var(--text-muted);">Unpaid</div>
                </div>
            </div>
            <div style="margin-top:0.75rem;display:flex;justify-content:space-between;align-items:center;padding-top:0.75rem;border-top:1px solid var(--border-color);">
                <span style="font-size:0.8rem;color:var(--text-muted);">Outstanding</span>
                <span style="font-size:0.9rem;font-weight:700;color:#ef4444;">KES ${Number(cat.outstanding).toLocaleString()}</span>
            </div>
        </div>`;
    });
}

// ── Table Rendering ───────────────────────────────────────────────────────────
function applyClientSearch() {
    const q = (document.getElementById('billing-search')?.value || '').toLowerCase();
    if (!q) { _filteredData = [..._billingData]; return; }
    _filteredData = _billingData.filter(s =>
        s.full_name?.toLowerCase().includes(q) ||
        s.admission_number?.toLowerCase().includes(q)
    );
}

function billingStatusBadge(status, percentage = null) {
    const map = {
        'Paid':    { bg: '#ecfdf5', color: '#059669', dot: '#10b981' },
        'Partial': { bg: '#fffbeb', color: '#b45309', dot: '#f59e0b' },
        'Unpaid':  { bg: '#fef2f2', color: '#dc2626', dot: '#ef4444' },
        'Exempt':  { bg: '#f0f9ff', color: '#0369a1', dot: '#0ea5e9' },
    };
    const s = map[status] || map['Unpaid'];
    let displayStatus = status;
    if (status === 'Partial') {
        displayStatus = `Partially Paid`;
    }
    return `<span style="display:inline-flex;align-items:center;gap:5px;background:${s.bg};color:${s.color};padding:0.25rem 0.6rem;border-radius:20px;font-size:0.78rem;font-weight:600;white-space:nowrap;">
        <span style="width:6px;height:6px;border-radius:50%;background:${s.dot};display:inline-block;"></span>${displayStatus}
    </span>`;
}

function renderTable() {
    const tbody = document.getElementById('billing-tbody');
    const countEl = document.getElementById('billing-record-count');
    if (countEl) countEl.textContent = `${_filteredData.length} student(s)`;

    if (!_filteredData.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:3rem;color:var(--text-muted);">
            <i class="ph ph-users" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>No students found.</td></tr>`;
        return;
    }

    tbody.innerHTML = '';
    _filteredData.forEach(student => {
        const photoSrc = student.photo
            ? API_BASE_URL + student.photo
            : `https://ui-avatars.com/api/?name=${encodeURIComponent(student.full_name)}&background=e0e7ff&color=4f46e5&size=40`;

        // Parse concatenated category data
        const catNames    = student.cat_names    ? student.cat_names.split('||')    : [];
        const catStatuses = student.cat_statuses ? student.cat_statuses.split('||') : [];
        const catIds      = student.cat_ids      ? student.cat_ids.split('||')      : [];
        const billingIds  = student.billing_ids  ? student.billing_ids.split('||')  : [];

        let categoryCells = '';
        if (catNames.length === 0) {
            categoryCells = `<span style="color:var(--text-muted);font-size:0.82rem;">No billing assigned</span>`;
        } else {
            const catDues = student.cat_dues ? student.cat_dues.split('||') : [];
            const catPaids = student.cat_paids ? student.cat_paids.split('||') : [];
            
            categoryCells = catNames.map((name, i) => {
                const status = catStatuses[i] || 'Unpaid';
                let pct = null;
                if (status === 'Partial' && catDues[i] && catPaids[i]) {
                    const due = parseFloat(catDues[i]);
                    const paid = parseFloat(catPaids[i]);
                    if (due > 0) pct = Math.round((paid / due) * 100);
                }
                return `<div style="display:inline-flex;flex-direction:column;align-items:flex-start;margin-right:0.75rem;margin-bottom:0.25rem;">
                    <span style="font-size:0.72rem;color:var(--text-muted);margin-bottom:2px;">${name}</span>
                    <div style="display:flex;align-items:center;gap:0.4rem;">
                        ${billingStatusBadge(status, pct)}
                        ${(status !== 'Paid' && status !== 'Exempt') ? `<button onclick="openPaymentModal(${billingIds[i]}, ${student.id})" style="background:#10b981;color:white;border:none;border-radius:4px;padding:3px 8px;cursor:pointer;font-size:0.75rem;font-weight:600;display:flex;align-items:center;gap:3px;"><i class="ph ph-currency-circle-dollar"></i> Pay</button>` : ''}
                    </div>
                </div>`;
            }).join('');
        }

        const isSelected = _selectedIds.has(student.id);
        const tr = document.createElement('tr');
        tr.setAttribute('data-id', student.id);
        tr.style.cssText = 'border-bottom:1px solid var(--border-color);transition:background 0.15s;';
        tr.onmouseover = () => tr.style.background = 'var(--bg-color)';
        tr.onmouseout  = () => tr.style.background = '';
        tr.innerHTML = `
            <td style="padding:0.875rem 1rem;">
                <input type="checkbox" class="billing-student-checkbox" data-id="${student.id}" ${isSelected ? 'checked' : ''}>
            </td>
            <td style="padding:0.875rem 0.5rem;">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <img src="${photoSrc}" alt="${student.full_name}"
                        style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color);"
                        onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(student.full_name)}&background=e0e7ff&color=4f46e5&size=40'">
                    <div>
                        <div style="font-weight:600;color:var(--text-main);font-size:0.9rem;">${student.full_name}</div>
                    </div>
                </div>
            </td>
            <td style="padding:0.875rem 0.5rem;color:var(--text-muted);font-size:0.88rem;">${student.class || '—'}</td>
            <td style="padding:0.875rem 0.5rem;color:#3b82f6;font-weight:600;font-size:0.88rem;">${student.admission_number}</td>
            <td style="padding:0.875rem 0.5rem;">${categoryCells}</td>
            <td style="padding:0.875rem 0.5rem;font-weight:700;color:${(student.total_due - student.total_paid) > 0 ? '#ef4444' : '#059669'};">KES ${Number((student.total_due || 0) - (student.total_paid || 0)).toLocaleString()}</td>
            <td style="padding:0.875rem 1rem;">
                <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                    <button title="View/Edit Billing" onclick="viewStudentBilling(${student.id},'${escapeHtml(student.full_name)}')"
                        style="width:32px;height:32px;border-radius:7px;border:none;background:#eff6ff;color:#3b82f6;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                        <i class="ph ph-eye"></i>
                    </button>
                    <button title="Assign Category" onclick="openAssignModal(${student.id})"
                        style="width:32px;height:32px;border-radius:7px;border:none;background:#ecfdf5;color:#059669;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                        <i class="ph ph-plus-circle"></i>
                    </button>
                    <button title="Payment History" onclick="viewPaymentHistory(${student.id},'${escapeHtml(student.full_name)}')"
                        style="width:32px;height:32px;border-radius:7px;border:none;background:#fdf4ff;color:#9333ea;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                        <i class="ph ph-clock-counter-clockwise"></i>
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });

    // Re-attach checkbox listeners
    document.querySelectorAll('.billing-student-checkbox').forEach(cb => {
        cb.addEventListener('change', e => {
            const id = parseInt(e.target.getAttribute('data-id'));
            if (e.target.checked) _selectedIds.add(id); else _selectedIds.delete(id);
            updateBulkBar();
        });
    });

    // Select-all
    const selAll = document.getElementById('select-all-billing');
    if (selAll) {
        selAll.checked = false;
        selAll.onchange = e => {
            document.querySelectorAll('.billing-student-checkbox').forEach(cb => {
                cb.checked = e.target.checked;
                const id = parseInt(cb.getAttribute('data-id'));
                if (e.target.checked) _selectedIds.add(id); else _selectedIds.delete(id);
            });
            updateBulkBar();
        };
    }
}

function escapeHtml(str) {
    return String(str).replace(/['"<>&]/g, c => ({'\'':'&#39;','"':'&quot;','<':'&lt;','>':'&gt;','&':'&amp;'}[c]));
}

// ── Bulk Actions ──────────────────────────────────────────────────────────────
function updateBulkBar() {
    const bar = document.getElementById('bulk-action-bar');
    const countEl = document.getElementById('bulk-selected-count');
    if (_selectedIds.size > 0) {
        bar.style.display = 'block';
        countEl.textContent = _selectedIds.size;
    } else {
        bar.style.display = 'none';
    }
}

// ── View Student Billing (detail modal) ───────────────────────────────────────
async function viewStudentBilling(studentId, studentName) {
    const modal = document.getElementById('billing-modal');
    document.getElementById('billing-modal-title').textContent = studentName;
    document.getElementById('billing-modal-subtitle').textContent = 'Billing Overview';
    document.getElementById('billing-modal-content').innerHTML = '<div style="text-align:center;padding:2rem;"><i class="ph ph-spinner" style="font-size:2rem;animation:spin 1s linear infinite;"></i></div>';
    openModal(modal);

    const res = await fetchData(`api/billing.php?action=get_student_billing&student_id=${studentId}`);
    if (!res || res.status !== 'success') {
        document.getElementById('billing-modal-content').innerHTML = '<p style="color:var(--danger);">Error loading billing data.</p>';
        return;
    }

    _currentBillingRecords = res.data;
    const records = res.data;
    if (!records.length) {
        document.getElementById('billing-modal-content').innerHTML = `
            <div style="text-align:center;padding:2rem;color:var(--text-muted);">
                <i class="ph ph-currency-circle-dollar" style="font-size:3rem;display:block;margin-bottom:1rem;"></i>
                No billing records found for this student.

            </div>`;
        return;
    }

    let html = `<div style="display:flex;flex-direction:column;gap:1rem;">`;
    records.forEach(r => {
        const balance = (parseFloat(r.amount_due) - parseFloat(r.amount_paid)).toFixed(2);
        const pct = r.payment_percentage || 0;
        
        html += `
        <div class="billing-record-card" style="border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
            <div style="padding:1rem 1.25rem;background:var(--bg-color);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
                <div>
                    <span style="font-weight:700;color:var(--text-main);font-size:0.95rem;">${r.category_name}</span>
                    <span style="margin-left:0.5rem;font-size:0.78rem;color:var(--text-muted);">${r.academic_year} · ${r.school_term}</span>
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    ${billingStatusBadge(r.payment_status, pct)}
                    ${r.payment_status !== 'Paid' && r.payment_status !== 'Exempt' ? `
                    <button onclick="openPaymentModal(${r.id}, ${studentId})" title="Record Payment"
                        style="padding:0.4rem 0.8rem;border-radius:6px;border:none;background:#10b981;color:white;cursor:pointer;display:flex;align-items:center;gap:0.4rem;font-size:0.85rem;font-weight:600;">
                        <i class="ph ph-currency-circle-dollar"></i> Pay
                    </button>` : ''}
                    <button onclick="removeBillingRecord(${r.id}, '${escapeHtml(r.category_name)}')" title="Remove"
                        style="width:28px;height:28px;border-radius:6px;border:none;background:#fef2f2;color:#ef4444;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.9rem;">
                        <i class="ph ph-trash"></i>
                    </button>
                </div>
            </div>
            
            <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border-color);">
                <div style="display:flex;justify-content:space-between;margin-bottom:0.4rem;">
                    <span style="font-size:0.8rem;color:var(--text-muted);font-weight:600;">Payment Progress (${pct}%)</span>
                    <span style="font-size:0.8rem;font-weight:600;color:var(--text-main);">KES ${Number(r.amount_paid).toLocaleString()} / KES ${Number(r.amount_due).toLocaleString()}</span>
                </div>
                <div style="background:var(--border-color);border-radius:10px;height:8px;overflow:hidden;">
                    <div style="height:8px;border-radius:10px;background:${pct === 100 ? '#10b981' : (pct > 0 ? '#f59e0b' : '#ef4444')};width:${pct}%;transition:width 0.8s ease;"></div>
                </div>
            </div>
            
            <div style="padding:1rem 1.25rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:0.75rem;">
                <div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Amount Due</div>
                    <div style="font-weight:700;color:var(--text-main);">KES ${Number(r.amount_due).toLocaleString()}</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Amount Paid</div>
                    <div style="font-weight:700;color:#059669;">KES ${Number(r.amount_paid).toLocaleString()}</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Balance</div>
                    <div style="font-weight:700;color:${parseFloat(balance) > 0 ? '#ef4444' : '#059669'};">KES ${Number(balance).toLocaleString()}</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Due Date</div>
                    <div style="font-weight:600;color:var(--text-main);">${r.due_date || '—'}</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Last Payment</div>
                    <div style="font-weight:600;color:var(--text-main);">${r.payment_date || '—'}</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Updated By</div>
                    <div style="font-weight:600;color:var(--text-muted);">${r.updated_by_name || '—'}</div>
                </div>
            </div>
            ${r.notes ? `<div style="padding:0 1.25rem 1rem;font-size:0.82rem;color:var(--text-muted);font-style:italic;">📝 ${r.notes}</div>` : ''}
        </div>`;
    });
    html += `</div>
    <div style="margin-top:1.25rem;display:flex;gap:0.75rem;flex-wrap:wrap;">

        <button onclick="viewPaymentHistory(${studentId},'${escapeHtml(records[0]?.category_name || '')}');closeBillingModal();" class="btn" style="background:var(--bg-color);color:var(--text-muted);font-size:0.85rem;">
            <i class="ph ph-clock-counter-clockwise"></i> View Full History
        </button>
    </div>`;

    document.getElementById('billing-modal-content').innerHTML = html;
}

// ── Payment History Modal ─────────────────────────────────────────────────────
async function viewPaymentHistory(studentId, studentName) {
    const modal = document.getElementById('billing-modal');
    document.getElementById('billing-modal-title').textContent = studentName || 'Payment History';
    document.getElementById('billing-modal-subtitle').textContent = 'Complete Payment History';
    document.getElementById('billing-modal-content').innerHTML = '<div style="text-align:center;padding:2rem;"><i class="ph ph-spinner" style="font-size:2rem;animation:spin 1s linear infinite;"></i></div>';
    openModal(modal);

    const res = await fetchData(`api/billing.php?action=payment_history&student_id=${studentId}`);
    if (!res || res.status !== 'success' || !res.data.length) {
        document.getElementById('billing-modal-content').innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:2rem;">No payment history found.</p>';
        return;
    }

    let html = `<div class="table-responsive" style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead><tr style="background:var(--bg-color);">
            <th style="padding:0.6rem 0.8rem;text-align:left;color:var(--text-muted);font-weight:600;">Date</th>
            <th style="padding:0.6rem 0.8rem;text-align:left;color:var(--text-muted);font-weight:600;">Category</th>
            <th style="padding:0.6rem 0.8rem;text-align:right;color:var(--text-muted);font-weight:600;">Previous Bal</th>
            <th style="padding:0.6rem 0.8rem;text-align:right;color:var(--text-muted);font-weight:600;">Paid</th>
            <th style="padding:0.6rem 0.8rem;text-align:right;color:var(--text-muted);font-weight:600;">New Bal</th>
            <th style="padding:0.6rem 0.8rem;text-align:left;color:var(--text-muted);font-weight:600;">Method / Ref</th>
            <th style="padding:0.6rem 0.8rem;text-align:left;color:var(--text-muted);font-weight:600;">Status</th>
            <th style="padding:0.6rem 0.8rem;text-align:left;color:var(--text-muted);font-weight:600;">By</th>
            <th style="padding:0.6rem 0.8rem;text-align:center;color:var(--text-muted);font-weight:600;">Action</th>
        </tr></thead><tbody>`;

    res.data.forEach(h => {
        const transAmt = h.transaction_amount ? Number(h.transaction_amount) : Number(h.amount_paid);
        const prevBal = h.previous_balance ? Number(h.previous_balance) : Number(h.amount_due);
        
        html += `<tr style="border-bottom:1px solid var(--border-color);">
            <td style="padding:0.6rem 0.8rem;">${h.payment_date || h.performed_at?.split('T')[0] || '—'}</td>
            <td style="padding:0.6rem 0.8rem;font-weight:600;">${h.category_name}</td>
            <td style="padding:0.6rem 0.8rem;text-align:right;">KES ${prevBal.toLocaleString()}</td>
            <td style="padding:0.6rem 0.8rem;text-align:right;color:#059669;font-weight:700;">+ KES ${transAmt.toLocaleString()}</td>
            <td style="padding:0.6rem 0.8rem;text-align:right;color:#ef4444;">KES ${Number(h.balance).toLocaleString()}</td>
            <td style="padding:0.6rem 0.8rem;">${h.payment_method || '—'}<br><span style="font-size:0.7rem;color:var(--text-muted);">${h.reference_number || ''}</span></td>
            <td style="padding:0.6rem 0.8rem;">${billingStatusBadge(h.payment_status, h.payment_percentage)}</td>
            <td style="padding:0.6rem 0.8rem;color:var(--text-muted);">${h.performed_by_name || '—'}</td>
            <td style="padding:0.6rem 0.8rem;text-align:center;">
                <button onclick="printReceipt('${encodeURIComponent(JSON.stringify(h))}', '${escapeHtml(studentName)}')" class="btn" style="background:#e0e7ff;color:#4f46e5;padding:0.4rem 0.6rem;font-size:0.75rem;border-radius:4px;">
                    <i class="ph ph-printer"></i> Print
                </button>
            </td>
        </tr>`;
    });

    html += `</tbody></table></div>`;
    document.getElementById('billing-modal-content').innerHTML = html;
}

// ── Print Receipt ─────────────────────────────────────────────────────────────
function printReceipt(hEncoded, studentName) {
    const h = JSON.parse(decodeURIComponent(hEncoded));
    const transAmt = h.transaction_amount ? Number(h.transaction_amount) : Number(h.amount_paid);
    const prevBal = h.previous_balance ? Number(h.previous_balance) : Number(h.amount_due);
    const newBal = Number(h.balance);
    const date = h.payment_date || h.performed_at?.split('T')[0] || new Date().toISOString().split('T')[0];
    
    const receiptHtml = `
        <html>
        <head>
            <title>Receipt - ${studentName}</title>
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 20px; color: #333; }
                .receipt-box { max-width: 500px; margin: 0 auto; border: 1px solid #eee; padding: 30px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.15); }
                .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
                .header h1 { margin: 0; font-size: 24px; color: #333; }
                .header p { margin: 5px 0 0; color: #666; font-size: 14px; }
                .details { margin-bottom: 20px; line-height: 1.6; }
                .details strong { width: 150px; display: inline-block; }
                .amounts { background: #f9fafb; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                .amounts .row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
                .amounts .total { font-weight: bold; font-size: 18px; border-top: 1px solid #ddd; padding-top: 8px; margin-top: 8px; color: #059669; }
                .footer { text-align: center; color: #888; font-size: 12px; border-top: 1px solid #eee; padding-top: 15px; }
                @media print { .receipt-box { box-shadow: none; border: none; } }
            </style>
        </head>
        <body>
            <div class="receipt-box">
                <div class="header">
                    <h1>Payment Receipt</h1>
                    <p>Transaction Reference: ${h.reference_number || 'N/A'}</p>
                    <p>Date: ${date}</p>
                </div>
                <div class="details">
                    <div><strong>Student Name:</strong> ${studentName}</div>
                    <div><strong>Category:</strong> ${h.category_name}</div>
                    <div><strong>Term / Year:</strong> ${h.school_term} ${h.academic_year}</div>
                    <div><strong>Payment Method:</strong> ${h.payment_method || 'Manual'}</div>
                    <div><strong>Status:</strong> ${h.payment_status}</div>
                </div>
                <div class="amounts">
                    <div class="row"><span>Previous Balance:</span> <span>KES ${prevBal.toLocaleString()}</span></div>
                    <div class="row total"><span>Amount Paid:</span> <span>KES ${transAmt.toLocaleString()}</span></div>
                    <div class="row" style="margin-top:10px; color:${newBal > 0 ? '#ef4444' : '#333'};"><span>New Balance:</span> <span>KES ${newBal.toLocaleString()}</span></div>
                </div>
                <div class="footer">
                    <p>Processed by: ${h.performed_by_name || 'System'}</p>
                    <p>Thank you for your payment!</p>
                </div>
            </div>
            <script>
                window.onload = function() { window.print(); }
            </script>
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(receiptHtml);
    printWindow.document.close();
}




// ── Payment Modal ─────────────────────────────────────────────────────────────
async function openPaymentModal(billingId, studentId = null) {
    if (studentId) {
        // Fetch student billing records first so _currentBillingRecords is populated with latest data
        const res = await fetchData(`api/billing.php?action=get_student_billing&student_id=${studentId}`);
        if (res && res.status === 'success') {
            _currentBillingRecords = res.data;
        }
    }

    document.getElementById('payment-billing-id').value = billingId;
    document.getElementById('payment-form').reset();
    document.getElementById('payment-date').value = new Date().toISOString().split('T')[0];

    // Find record in the loaded details
    const rec = (_currentBillingRecords || []).find(r => r.id == billingId);
    if (rec) {
        document.getElementById('payment-category-display').textContent = rec.category_name;
        document.getElementById('payment-due-display').textContent = `KES ${Number(rec.amount_due).toLocaleString()}`;
        document.getElementById('payment-paid-display').textContent = `KES ${Number(rec.amount_paid).toLocaleString()}`;
        
        const bal = (parseFloat(rec.amount_due) - parseFloat(rec.amount_paid)).toFixed(2);
        document.getElementById('payment-balance-display').textContent = `KES ${Number(bal).toLocaleString()}`;
        document.getElementById('payment-balance-display').style.color = bal > 0 ? '#ef4444' : '#059669';
        
        // Disable amount input if fully paid
        const amountInput = document.getElementById('payment-amount-being-paid');
        if (bal <= 0) {
            amountInput.value = 0;
            amountInput.disabled = true;
            document.getElementById('btn-save-payment').disabled = true;
        } else {
            amountInput.value = '';
            amountInput.max = bal; // basic client-side validation
            amountInput.disabled = false;
            document.getElementById('btn-save-payment').disabled = false;
        }
    }

    openModal(document.getElementById('payment-modal'));
}

function closePaymentModal() { closeModal(document.getElementById('payment-modal')); }

document.addEventListener('DOMContentLoaded', () => {
    const payForm = document.getElementById('payment-form');
    if (payForm) {
        payForm.addEventListener('submit', async e => {
            e.preventDefault();
            const btn = payForm.querySelector('button[type="submit"]');
            btn.disabled = true; btn.innerHTML = '⏳ Saving…';
            const fd = new FormData();
            fd.append('billing_id', document.getElementById('payment-billing-id').value);
            fd.append('amount_being_paid', document.getElementById('payment-amount-being-paid').value);
            fd.append('payment_date', document.getElementById('payment-date').value);
            fd.append('payment_method', document.getElementById('payment-method').value);
            fd.append('reference_number', document.getElementById('payment-reference').value);
            fd.append('notes', document.getElementById('payment-notes').value);

            const res = await fetchData('api/billing.php?action=record_payment', { method: 'POST', body: fd });
            btn.disabled = false; btn.innerHTML = '<i class="ph ph-floppy-disk"></i> Record Payment';
            if (res && res.status === 'success') {
                showNotification(res.message, 'success');
                closePaymentModal();
                await loadBillingData();
                renderSummaryCards();
                
                // Refresh the open billing details modal
                const studentId = (_currentBillingRecords && _currentBillingRecords.length > 0) ? _currentBillingRecords[0].student_id : null;
                const studentName = document.getElementById('billing-modal-title').textContent;
                if (studentId) viewStudentBilling(studentId, studentName);
            } else {
                showNotification(res?.message || 'Error recording payment.', 'error');
            }
        });
    }
});

// ── Remove Billing Record ─────────────────────────────────────────────────────
async function removeBillingRecord(billingId, categoryName) {
    if (!confirm(`Remove "${categoryName}" billing record? This cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('billing_id', billingId);
    const res = await fetchData('api/billing.php?action=remove_billing', { method: 'POST', body: fd });
    if (res && res.status === 'success') {
        showNotification(res.message, 'success');
        closeBillingModal();
        await loadBillingData();
        renderSummaryCards();
    } else {
        showNotification(res?.message || 'Error removing record.', 'error');
    }
}

// ── Bulk Apply ────────────────────────────────────────────────────────────────
async function applyBulkAction() {
    const action  = document.getElementById('bulk-action-select').value;
    const catId   = document.getElementById('bulk-category-select')?.value || 0;
    if (!action) { showNotification('Please select an action.', 'error'); return; }
    if (_selectedIds.size === 0) { showNotification('No students selected.', 'error'); return; }

    const confirmed = confirm(`Apply "${action}" to ${_selectedIds.size} student(s)?`);
    if (!confirmed) return;

    const fd = new FormData();
    fd.append('student_ids', JSON.stringify([..._selectedIds]));
    fd.append('bulk_action',  action);
    fd.append('category_id',  catId);

    const res = await fetchData('api/billing.php?action=bulk_billing', { method: 'POST', body: fd });
    if (res && res.status === 'success') {
        showNotification(res.message, 'success');
        _selectedIds.clear();
        updateBulkBar();
        await loadBillingData();
        renderSummaryCards();
    } else {
        showNotification(res?.message || 'Bulk action failed.', 'error');
    }
}

// ── Modal Helpers ─────────────────────────────────────────────────────────────
function openModal(el) {
    el.style.display = 'flex';
    setTimeout(() => el.style.opacity = '1', 10);
}
function closeModal(el) {
    el.style.opacity = '0';
    setTimeout(() => el.style.display = 'none', 300);
}
function closeBillingModal() { closeModal(document.getElementById('billing-modal')); }

// ── Export Functions ──────────────────────────────────────────────────────────
async function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const params = buildFilterParams();
    const res = await fetchData('api/billing.php?action=billing_report&' + params);
    if (!res || res.status !== 'success') { showNotification('Failed to load report data.', 'error'); return; }

    doc.setFontSize(16); doc.setFont('helvetica', 'bold');
    doc.text('EduGate — Billing Report', 14, 15);
    doc.setFontSize(9); doc.setFont('helvetica', 'normal');
    doc.text(`Generated: ${new Date().toLocaleString()}`, 14, 22);

    const rows = res.data.map(r => [
        r.full_name, r.admission_number, r.class,
        r.category_name, r.academic_year, r.school_term,
        `KES ${Number(r.amount_due).toLocaleString()}`,
        `KES ${Number(r.amount_paid).toLocaleString()}`,
        `KES ${Number(r.balance).toLocaleString()}`,
        r.payment_status, r.due_date || '—', r.payment_date || '—',
    ]);

    doc.autoTable({
        head: [['Student','Adm No','Class','Category','Year','Term','Due','Paid','Balance','Status','Due Date','Pay Date']],
        body: rows,
        startY: 28,
        styles: { fontSize: 7, cellPadding: 2 },
        headStyles: { fillColor: [79, 70, 229], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [248, 250, 252] },
    });

    doc.save(`billing-report-${new Date().toISOString().split('T')[0]}.pdf`);
}

async function exportExcel() {
    const params = buildFilterParams();
    const res = await fetchData('api/billing.php?action=billing_report&' + params);
    if (!res || res.status !== 'success') { showNotification('Failed to load report data.', 'error'); return; }

    const headers = ['Student','Admission No','Class','Category','Academic Year','Term','Amount Due','Amount Paid','Balance','Status','Due Date','Payment Date','Notes','Updated By'];
    const rows = res.data.map(r => [
        r.full_name, r.admission_number, r.class,
        r.category_name, r.academic_year, r.school_term,
        parseFloat(r.amount_due), parseFloat(r.amount_paid), parseFloat(r.balance),
        r.payment_status, r.due_date || '', r.payment_date || '', r.notes || '', r.updated_by_name || ''
    ]);

    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Billing Report');
    XLSX.writeFile(wb, `billing-report-${new Date().toISOString().split('T')[0]}.xlsx`);
}

// ── Event Listeners ───────────────────────────────────────────────────────────
function setupEventListeners() {
    document.getElementById('btn-apply-filters')?.addEventListener('click', async () => {
        _selectedIds.clear();
        updateBulkBar();
        await loadBillingData();
    });
    document.getElementById('btn-clear-filters')?.addEventListener('click', () => {
        ['filter-billing-category','filter-billing-status','filter-billing-class',
         'filter-billing-year','filter-billing-term'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        document.getElementById('billing-search').value = '';
        if (_currentSettings.academic_year) {
            document.getElementById('filter-billing-year').value = _currentSettings.academic_year;
        }
        loadBillingData();
    });
    document.getElementById('billing-search')?.addEventListener('input', () => {
        applyClientSearch();
        renderTable();
    });
    document.getElementById('btn-export-pdf')?.addEventListener('click', exportPDF);
    document.getElementById('btn-export-excel')?.addEventListener('click', exportExcel);
    document.getElementById('btn-bulk-apply')?.addEventListener('click', applyBulkAction);
    document.getElementById('btn-bulk-cancel')?.addEventListener('click', () => {
        _selectedIds.clear();
        document.querySelectorAll('.billing-student-checkbox').forEach(cb => cb.checked = false);
        const selAll = document.getElementById('select-all-billing');
        if (selAll) selAll.checked = false;
        updateBulkBar();
    });

    // Show category select for status-change bulk actions
    const bulkActionSel = document.getElementById('bulk-action-select');
    const bulkCatSel    = document.getElementById('bulk-category-select');
    if (bulkActionSel && bulkCatSel) {
        bulkActionSel.addEventListener('change', () => {
            const statusActions = ['mark_paid','mark_partial','mark_unpaid','mark_exempt'];
            bulkCatSel.style.display = statusActions.includes(bulkActionSel.value) ? 'block' : 'none';
        });
    }

    // Close modals on backdrop click
    ['billing-modal','assign-modal','payment-modal','unallocated-modal'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', e => { if (e.target === el) {
            if (id === 'unallocated-modal') closeUnallocatedModal();
            else closeModal(el);
        }});
    });
}

// Re-populate class filter after data loads
window.addEventListener('liveSyncUpdate', () => loadBillingData());

// ── Unallocated Payments ─────────────────────────────────────────────────────

async function pollMpesaNotifications() {
    const res = await fetchData(`api/billing.php?action=poll_mpesa_notifications&last_time=${encodeURIComponent(_lastMpesaCheck)}`);
    if (res && res.status === 'success') {
        if (_lastMpesaCheck !== '' && res.data && res.data.length > 0) {
            res.data.forEach(n => {
                showNotification(n.message, 'success');
                if (n.type === 'allocated') {
                    loadBillingData(); // refresh table to show new payment
                }
            });
        }
        _lastMpesaCheck = res.server_time;
    }
}

async function loadUnallocatedPayments() {
    const res = await fetchData('api/billing.php?action=get_unallocated');
    if (res && res.status === 'success') {
        const count = res.data.length;
        const btn = document.getElementById('btn-view-unallocated');
        const badge = document.getElementById('unallocated-count');
        
        if (btn && badge) {
            if (count > 0) {
                btn.style.display = 'inline-block';
                badge.textContent = count;
            } else {
                btn.style.display = 'none';
            }
        }
        
        const tbody = document.getElementById('unallocated-tbody');
        if (tbody) {
            if (count === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-muted);">No unallocated payments! 🎉</td></tr>';
                return;
            }
            
            tbody.innerHTML = '';
            res.data.forEach(p => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid var(--border-color)';
                tr.innerHTML = `
                    <td style="padding:0.75rem;font-size:0.85rem;">${p.payment_date}</td>
                    <td style="padding:0.75rem;font-size:0.85rem;font-weight:600;">${p.sender_name}<br><small style="color:var(--text-muted);">${p.sender_phone}</small></td>
                    <td style="padding:0.75rem;font-size:0.85rem;">${p.receipt_number}</td>
                    <td style="padding:0.75rem;font-size:0.9rem;font-weight:700;color:#059669;">KES ${Number(p.amount).toLocaleString()}</td>
                    <td style="padding:0.75rem;text-align:center;">
                        <button onclick="openAssignModal(${p.id}, ${p.amount}, '${p.receipt_number}', '${p.sender_name.replace(/'/g, "\\'")}', '${p.sender_phone.replace(/'/g, "\\'")}')" class="btn btn-primary" style="padding:0.4rem 0.8rem;font-size:0.8rem;">Assign</button>
                        <button onclick="deleteUnallocated(${p.id})" class="btn" style="padding:0.4rem 0.8rem;font-size:0.8rem;background:var(--bg-color);color:var(--text-muted);"><i class="ph ph-trash"></i></button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
    }
}

function openUnallocatedModal() {
    const modal = document.getElementById('unallocated-modal');
    modal.style.display = 'flex';
    requestAnimationFrame(() => modal.style.opacity = '1');
    loadUnallocatedPayments();
}

function closeUnallocatedModal() {
    const modal = document.getElementById('unallocated-modal');
    modal.style.opacity = '0';
    setTimeout(() => modal.style.display = 'none', 300);
}

let _assignState = {
    unallocatedId: null,
    studentId: null,
    amount: 0
};

async function openAssignModal(id, amount, receipt, senderName, senderPhone) {
    _assignState = { unallocatedId: id, studentId: null, amount: amount };
    
    // Populate info
    document.getElementById('assign-sender-name').textContent = senderName || 'Unknown Sender';
    document.getElementById('assign-sender-phone').textContent = senderPhone || 'No Phone';
    document.getElementById('assign-amount').textContent = `KES ${Number(amount).toLocaleString()}`;
    document.getElementById('assign-receipt').textContent = receipt || 'No Receipt';
    
    // Reset selections
    clearSelectedStudent();
    document.getElementById('assign-student-search').value = '';
    
    // Reset purpose
    document.querySelector('input[name="payment_purpose"][value="auto"]').checked = true;
    toggleSpecificServices(false);
    
    // Show modal
    const modal = document.getElementById('assign-unallocated-modal');
    modal.style.display = 'flex';
    requestAnimationFrame(() => modal.style.opacity = '1');
    
    // Fetch Smart Suggestions
    const suggList = document.getElementById('smart-suggestions-list');
    const suggContainer = document.getElementById('smart-suggestions-container');
    suggList.innerHTML = '<div style="font-size:0.85rem;color:var(--text-muted);"><i class="ph ph-spinner ph-spin"></i> Finding matches...</div>';
    suggContainer.style.display = 'block';
    
    const sRes = await fetchData(`api/billing.php?action=get_student_suggestions&sender_name=${encodeURIComponent(senderName)}&sender_phone=${encodeURIComponent(senderPhone)}`);
    if (sRes && sRes.status === 'success' && sRes.data.length > 0) {
        suggList.innerHTML = '';
        sRes.data.forEach(s => {
            const div = document.createElement('div');
            div.style.cssText = 'padding:0.75rem;background:var(--card-bg);border:1px solid var(--border-color);border-radius:8px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;transition:all 0.2s;';
            div.innerHTML = `
                <div>
                    <div style="font-weight:700;font-size:0.9rem;color:var(--text-color);">${s.full_name} <span style="font-size:0.75rem;color:var(--text-muted);font-weight:400;">(${s.class})</span></div>
                    <div style="font-size:0.8rem;color:var(--text-muted);"><i class="ph ph-user"></i> ${s.parent_name || 'N/A'} &bull; <i class="ph ph-phone"></i> ${s.parent_phone || 'N/A'}</div>
                    <div style="font-size:0.75rem;color:#059669;margin-top:0.25rem;font-weight:600;"><i class="ph ph-check"></i> ${s.match_reason}</div>
                </div>
                <button class="btn" style="padding:0.4rem 0.8rem;font-size:0.8rem;background:var(--bg-color);color:var(--text-color);">Select</button>
            `;
            div.onclick = () => selectStudentForAssignment(s.id, s.full_name, s.class);
            suggList.appendChild(div);
        });
    } else {
        suggList.innerHTML = '<div style="font-size:0.85rem;color:var(--text-muted);">No smart matches found. Please search manually below.</div>';
    }
    
    // Fetch Active Categories
    const cRes = await fetchData('api/billing.php?action=get_categories');
    if (cRes && cRes.status === 'success') {
        const catList = document.getElementById('assign-categories-list');
        catList.innerHTML = '';
        cRes.data.filter(c => c.is_active == 1).forEach(c => {
            catList.innerHTML += `
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;color:var(--text-color);cursor:pointer;">
                    <input type="checkbox" name="assign_category" value="${c.id}" style="accent-color:#059669;"> ${c.name}
                </label>
            `;
        });
    }
}

function closeAssignModal() {
    const modal = document.getElementById('assign-unallocated-modal');
    modal.style.opacity = '0';
    setTimeout(() => modal.style.display = 'none', 300);
}

function selectStudentForAssignment(id, name, cls) {
    _assignState.studentId = id;
    document.getElementById('selected-student-name').textContent = `${name} (${cls})`;
    document.getElementById('selected-student-display').style.display = 'flex';
    document.getElementById('smart-suggestions-container').style.display = 'none';
}

function clearSelectedStudent() {
    _assignState.studentId = null;
    document.getElementById('selected-student-name').textContent = '';
    document.getElementById('selected-student-display').style.display = 'none';
    document.getElementById('smart-suggestions-container').style.display = 'block';
}

function toggleSpecificServices(show) {
    document.getElementById('specific-services-container').style.display = show ? 'block' : 'none';
    document.getElementById('purpose-label-specific').style.background = show ? 'rgba(5,150,105,0.05)' : 'var(--bg-color)';
    document.getElementById('purpose-label-specific').style.borderColor = show ? '#059669' : 'var(--border-color)';
    
    document.getElementById('purpose-label-auto').style.background = !show ? 'rgba(5,150,105,0.05)' : 'var(--bg-color)';
    document.getElementById('purpose-label-auto').style.borderColor = !show ? '#059669' : 'var(--border-color)';
}

async function submitAssignUnallocated() {
    if (!_assignState.studentId) {
        alert('Please select a student first.');
        return;
    }
    
    const formData = new FormData();
    formData.append('unallocated_id', _assignState.unallocatedId);
    formData.append('student_id', _assignState.studentId);
    
    const isSpecific = document.querySelector('input[name="payment_purpose"]:checked').value === 'specific';
    if (isSpecific) {
        const checked = Array.from(document.querySelectorAll('input[name="assign_category"]:checked')).map(cb => cb.value);
        if (checked.length === 0) {
            alert('Please select at least one specific service/category.');
            return;
        }
        formData.append('category_ids', JSON.stringify(checked));
    }
    
    const res = await fetchData('api/billing.php?action=assign_unallocated', {
        method: 'POST',
        body: formData
    });
    
    if (res && res.status === 'success') {
        showNotification(res.message, 'success');
        closeAssignModal();
        loadUnallocatedPayments();
        loadBillingData();
    } else {
        showNotification(res?.message || 'Error assigning payment.', 'error');
    }
}

// Attach Search Event Listener
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('assign-student-search');
    const dropdown = document.getElementById('assign-student-dropdown');
    let searchTimeout;

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            const query = e.target.value.trim();
            
            if (query.length < 2) {
                dropdown.style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(async () => {
                const res = await fetchData(`api/billing.php?action=get_student_suggestions&sender_name=${encodeURIComponent(query)}`);
                if (res && res.status === 'success') {
                    dropdown.innerHTML = '';
                    if (res.data.length === 0) {
                        dropdown.innerHTML = '<div style="padding:1rem;color:var(--text-muted);font-size:0.85rem;text-align:center;">No students found matching that name.</div>';
                    } else {
                        res.data.forEach(s => {
                            const div = document.createElement('div');
                            div.style.cssText = 'padding:0.75rem 1rem;border-bottom:1px solid var(--border-color);cursor:pointer;font-size:0.9rem;transition:background 0.2s;';
                            div.onmouseover = () => div.style.background = 'var(--bg-color)';
                            div.onmouseout = () => div.style.background = 'transparent';
                            div.innerHTML = `
                                <div style="font-weight:600;color:var(--text-color);">${s.full_name} <span style="font-size:0.75rem;color:var(--text-muted);font-weight:400;">(${s.class})</span></div>
                                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.2rem;"><i class="ph ph-user"></i> ${s.parent_name || 'N/A'} &bull; <i class="ph ph-phone"></i> ${s.parent_phone || 'N/A'}</div>
                            `;
                            div.onclick = () => {
                                selectStudentForAssignment(s.id, s.full_name, s.class);
                                searchInput.value = '';
                                dropdown.style.display = 'none';
                            };
                            dropdown.appendChild(div);
                        });
                    }
                    dropdown.style.display = 'block';
                }
            }, 300);
        });
        
        // Hide dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (e.target !== searchInput && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }
});

async function deleteUnallocated(id) {
    if (!confirm('Are you sure you want to delete this unallocated payment? This cannot be undone.')) return;
    
    const formData = new FormData();
    formData.append('id', id);
    
    const res = await fetchData('api/billing.php?action=delete_unallocated', {
        method: 'POST',
        body: formData
    });
    
    if (res && res.status === 'success') {
        showNotification(res.message, 'success');
        loadUnallocatedPayments();
    } else {
        showNotification(res?.message || 'Error deleting payment.', 'error');
    }
}
