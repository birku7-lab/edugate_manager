const state = {
    billing: { data: [], filtered: [], page: 1, limit: 50, sortCol: '', sortDir: 'asc' },
    students: { data: [], filtered: [], page: 1, limit: 50, sortCol: '', sortDir: 'asc' },
    attendance: { data: [], filtered: [], page: 1, limit: 50, sortCol: '', sortDir: 'asc' },
    meals: { data: [], filtered: [], page: 1, limit: 50, sortCol: '', sortDir: 'asc' },
    transport: { data: [], filtered: [], page: 1, limit: 50, sortCol: '', sortDir: 'asc' }
};

let currentTab = 'students';
window.reportTabs = ['billing', 'students', 'attendance', 'meals', 'transport'];

document.addEventListener('DOMContentLoaded', () => {
    setDefaultDates();
    checkReportPermissions();
    initFilters();
    initSortHeaders();
    initPaginationControls();
    
    // Initial fetch for the active tab
    populateDropdowns().then(() => {
        loadReportData(currentTab);
    });

    document.getElementById('btn-print-report')?.addEventListener('click', () => {
        window.print();
    });
});

function setDefaultDates() {
    const today = new Date().toISOString().split('T')[0];
    const sevenDaysAgo = new Date();
    sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
    const lastWeek = sevenDaysAgo.toISOString().split('T')[0];
    
    document.querySelectorAll('input[type="date"]').forEach(el => {
        if(el.id.includes('start')) el.value = lastWeek;
        if(el.id.includes('end')) el.value = today;
    });
}

async function populateDropdowns() {
    // Billing categories
    const catSel = document.getElementById('br-category');
    if (catSel) {
        const res = await fetchData('api/billing.php?action=get_categories');
        if (res && res.status === 'success') {
            res.data.forEach(c => {
                catSel.innerHTML += `<option value="${c.id}">${c.name}</option>`;
            });
        }
    }
    
    // Transport Buses
    const busSel = document.getElementById('tr-bus');
    if (busSel) {
        const res = await fetchData('api/buses.php?action=list');
        if (res && res.status === 'success') {
            res.data.forEach(b => {
                busSel.innerHTML += `<option value="${b.id}">${b.name} (${b.capacity || '-'})</option>`;
            });
        }
    }
}

function checkReportPermissions() {
    const permsRaw = localStorage.getItem('user_permissions') || '[]';
    let permissions = [];
    try { permissions = JSON.parse(permsRaw); } catch(e){}
    
    const role = localStorage.getItem('user_role');
    const isAll = permissions.includes('all') || role === 'Admin';
    
    document.querySelectorAll('.report-restricted-nav').forEach(btn => {
        const reportType = btn.getAttribute('data-report');
        if (isAll || permissions.includes('reports_' + reportType) || (reportType === 'billing' && (isAll || role === 'Admin'))) {
            btn.style.display = 'inline-block';
        } else {
            btn.style.display = 'none';
        }
    });
}

function switchReportTab(tab) {
    currentTab = tab;
    window.reportTabs.forEach(t => {
        const section = document.getElementById('section-' + t);
        const btn = document.getElementById('tab-' + t + '-btn');
        if (section) section.style.display = (t === tab) ? 'block' : 'none';
        if (btn) {
            if (t === tab) {
                btn.style.borderBottomColor = 'var(--primary)';
                btn.style.color = 'var(--primary)';
            } else {
                btn.style.borderBottomColor = 'transparent';
                btn.style.color = 'var(--text-muted)';
            }
        }
    });
    document.getElementById('print-title').textContent = document.getElementById('tab-' + tab + '-btn')?.textContent.trim() || 'Report';
    loadReportData(tab);
}

function initFilters() {
    window.reportTabs.forEach(tab => {
        const form = document.getElementById(`${tab === 'billing' ? 'billing' : (tab === 'students' ? 'student' : tab)}-report-form`);
        if (form) {
            // Prevent enter key submission
            form.addEventListener('submit', e => e.preventDefault());
            
            form.querySelectorAll('input, select').forEach(input => {
                if(input.type === 'text') {
                    // debounce search
                    let timeout;
                    input.addEventListener('input', () => {
                        clearTimeout(timeout);
                        timeout = setTimeout(() => {
                            if(input.id.includes('search')) {
                                applyLocalFilters(tab);
                            } else {
                                loadReportData(tab);
                            }
                        }, 500);
                    });
                } else {
                    input.addEventListener('change', () => {
                        loadReportData(tab);
                    });
                }
            });
        }
    });
}

async function loadReportData(type) {
    if (!state[type]) return;
    
    let url = '';
    let formId = '';
    
    if (type === 'billing') { url = 'api/billing.php?action=billing_report'; formId = 'billing-report-form'; }
    else if (type === 'students') { url = 'api/reports.php?action=students'; formId = 'student-report-form'; }
    else if (type === 'attendance') { url = 'api/reports.php?action=report_data'; formId = 'attendance-report-form'; }
    else if (type === 'meals') { url = 'api/reports.php?action=meals'; formId = 'meals-report-form'; }
    else if (type === 'transport') { url = 'api/reports.php?action=transport'; formId = 'transport-report-form'; }

    const form = document.getElementById(formId);
    if (!form) return;
    const formData = new FormData(form);
    const params = new URLSearchParams(formData).toString();

    const tbody = document.querySelector(`#${type}-report-table tbody`);
    if(tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:2rem;">Loading data...</td></tr>';

    const res = await fetchData(`${url}&${params}`);
    if (res && res.status === 'success') {
        state[type].data = res.data || [];
        applyLocalFilters(type);
    } else {
        if(tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:red;padding:2rem;">Error loading data</td></tr>';
    }
}

function applyLocalFilters(type) {
    const searchInput = document.getElementById(`${type === 'billing' ? 'br' : (type === 'students' ? 'sr' : (type === 'attendance' ? 'ar' : (type === 'meals' ? 'mr' : 'tr')))}-search`);
    const term = searchInput ? searchInput.value.toLowerCase() : '';
    
    let filtered = state[type].data;
    if (term) {
        filtered = filtered.filter(row => {
            return Object.values(row).some(val => String(val).toLowerCase().includes(term));
        });
    }

    const sCol = state[type].sortCol;
    if (sCol) {
        const dir = state[type].sortDir === 'asc' ? 1 : -1;
        filtered.sort((a,b) => {
            let valA = a[sCol] || '';
            let valB = b[sCol] || '';
            if (!isNaN(valA) && !isNaN(valB)) {
                return (Number(valA) - Number(valB)) * dir;
            }
            return String(valA).localeCompare(String(valB)) * dir;
        });
    }

    state[type].filtered = filtered;
    state[type].page = 1;
    
    updateSummaries(type);
    renderTable(type);
}

function updateSummaries(type) {
    const data = state[type].filtered;
    
    if (type === 'billing') {
        let expected = 0, collected = 0;
        data.forEach(r => { expected += Number(r.amount_due||0); collected += Number(r.amount_paid||0); });
        let balance = expected - collected;
        let mean = data.length ? (collected / data.length) : 0;
        
        document.getElementById('br-expected').textContent = expected.toLocaleString();
        document.getElementById('br-collected').textContent = collected.toLocaleString();
        document.getElementById('br-balance').textContent = balance.toLocaleString();
        document.getElementById('br-mean').textContent = mean.toLocaleString(undefined, {maximumFractionDigits:2});
    }
    else if (type === 'students') {
        const total = data.length;
        let male = data.filter(r => r.gender === 'Male').length;
        let female = data.filter(r => r.gender === 'Female').length;
        let classes = new Set(data.map(r => r.class)).size;
        let mean = classes > 0 ? total / classes : 0;

        document.getElementById('sr-total').textContent = total;
        document.getElementById('sr-male').textContent = male;
        document.getElementById('sr-female').textContent = female;
        document.getElementById('sr-mean').textContent = mean.toFixed(1);
    }
    else if (type === 'attendance') {
        const total = data.length;
        let present = data.filter(r => r.status === 'Present').length;
        let absent = data.filter(r => r.status === 'Absent').length;
        
        // Calculate mean daily attendance based on unique dates
        let dates = new Set(data.filter(r => r.attendance_date && r.attendance_date !== 'Range').map(r => r.attendance_date)).size;
        let mean = dates > 0 ? (present / dates) : present;

        document.getElementById('ar-total').textContent = total;
        document.getElementById('ar-present').textContent = present;
        document.getElementById('ar-absent').textContent = absent;
        document.getElementById('ar-mean').textContent = mean.toFixed(1);
    }
    else if (type === 'meals') {
        const total = data.length;
        let dates = new Set(data.map(r => r.meal_date)).size;
        let mean = dates > 0 ? total / dates : total;
        
        document.getElementById('mr-total').textContent = total;
        document.getElementById('mr-mean').textContent = mean.toFixed(1);
    }
    else if (type === 'transport') {
        const total = data.length;
        let dates = new Set(data.map(r => r.log_date)).size;
        let mean = dates > 0 ? total / dates : total;
        
        document.getElementById('tr-total').textContent = total;
        document.getElementById('tr-mean').textContent = mean.toFixed(1);
    }
}

function renderTable(type) {
    const s = state[type];
    const tbody = document.querySelector(`#${type}-report-table tbody`);
    if(!tbody) return;

    const start = (s.page - 1) * s.limit;
    const end = start + s.limit;
    const paginated = s.filtered.slice(start, end);

    if (paginated.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--text-muted)">No records found.</td></tr>';
    } else {
        let html = '';
        paginated.forEach(r => {
            if (type === 'billing') {
                const colors = { Paid: '#059669', Partial: '#b45309', Unpaid: '#dc2626', Exempt: '#0369a1' };
                html += `<tr>
                    <td><strong>${r.full_name}</strong></td>
                    <td>${r.admission_number}</td>
                    <td>${r.class}</td>
                    <td>${r.category_name}</td>
                    <td>${r.academic_year}/${r.school_term}</td>
                    <td style="text-align:right;">${Number(r.amount_due).toLocaleString()}</td>
                    <td style="text-align:right;color:#059669;">${Number(r.amount_paid).toLocaleString()}</td>
                    <td style="text-align:right;color:${parseFloat(r.balance) > 0 ? '#dc2626' : '#059669'};">${Number(r.balance).toLocaleString()}</td>
                    <td style="font-weight:bold;color:${colors[r.payment_status] || '#64748b'};">${r.payment_status}</td>
                </tr>`;
            } else if (type === 'students') {
                html += `<tr>
                    <td>${r.admission_number}</td>
                    <td><strong>${r.full_name}</strong></td>
                    <td>${r.class}</td>
                    <td>${r.gender || '-'}</td>
                    <td>${r.parent_phone || '-'}</td>
                </tr>`;
            } else if (type === 'attendance') {
                html += `<tr>
                    <td>${r.attendance_date || '-'}</td>
                    <td>${r.admission_number}</td>
                    <td><strong>${r.full_name}</strong></td>
                    <td>${r.class}</td>
                    <td>${r.arrival_time}</td>
                    <td style="font-weight:bold; color: ${r.status === 'Present' ? 'var(--success)' : 'var(--danger)'};">${r.status}</td>
                </tr>`;
            } else if (type === 'meals') {
                html += `<tr>
                    <td>${r.meal_date || '-'}</td>
                    <td>${r.admission_number}</td>
                    <td style="font-weight:600;color:var(--primary);">${r.full_name}</td>
                    <td>${r.class}</td>
                    <td><span class="badge" style="background:#f1f5f9;color:var(--text-main);">${r.meal_type}</span></td>
                    <td>${r.scanned_by_name || '-'}</td>
                </tr>`;
            } else if (type === 'transport') {
                html += `<tr>
                    <td>${r.log_date || '-'}</td>
                    <td>${r.admission_number}</td>
                    <td style="font-weight:600;color:var(--primary);">${r.full_name}</td>
                    <td><span class="badge" style="background:#e0e7ff;color:#4f46e5;">${r.bus_name}</span></td>
                    <td>${r.log_time}</td>
                    <td>${r.scanned_by_name || '-'}</td>
                </tr>`;
            }
        });
        tbody.innerHTML = html;
    }

    // Update pagination controls
    const prefix = type === 'billing' ? 'br' : (type === 'students' ? 'sr' : (type === 'attendance' ? 'ar' : (type === 'meals' ? 'mr' : 'tr')));
    const total = s.filtered.length;
    const info = document.getElementById(`${prefix}-page-info`);
    const btnPrev = document.getElementById(`${prefix}-prev`);
    const btnNext = document.getElementById(`${prefix}-next`);

    if(info) info.textContent = `Showing ${total === 0 ? 0 : start + 1}-${Math.min(end, total)} of ${total}`;
    if(btnPrev) btnPrev.disabled = s.page === 1;
    if(btnNext) btnNext.disabled = end >= total;
}

function initPaginationControls() {
    window.reportTabs.forEach(type => {
        const prefix = type === 'billing' ? 'br' : (type === 'students' ? 'sr' : (type === 'attendance' ? 'ar' : (type === 'meals' ? 'mr' : 'tr')));
        
        document.getElementById(`${prefix}-prev`)?.addEventListener('click', () => {
            if (state[type].page > 1) {
                state[type].page--;
                renderTable(type);
            }
        });
        document.getElementById(`${prefix}-next`)?.addEventListener('click', () => {
            const end = state[type].page * state[type].limit;
            if (end < state[type].filtered.length) {
                state[type].page++;
                renderTable(type);
            }
        });
    });
}

function initSortHeaders() {
    document.querySelectorAll('th[data-sort]').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            const type = th.closest('table').id.split('-')[0]; // billing, student, attendance, meals, transport
            const mappedType = type === 'student' ? 'students' : type;
            const sortCol = th.getAttribute('data-sort');
            
            if (state[mappedType].sortCol === sortCol) {
                state[mappedType].sortDir = state[mappedType].sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                state[mappedType].sortCol = sortCol;
                state[mappedType].sortDir = 'asc';
            }
            
            // update UI arrows
            th.closest('tr').querySelectorAll('th').forEach(h => {
                h.textContent = h.textContent.replace(' ↑', '').replace(' ↓', '');
            });
            th.textContent += state[mappedType].sortDir === 'asc' ? ' ↑' : ' ↓';
            
            applyLocalFilters(mappedType);
        });
    });
}

// ── Export Functions ──
document.getElementById('br-export-pdf')?.addEventListener('click', () => {
    const data = state.billing.filtered;
    if (!data.length) { showNotification('No data to export.', 'error'); return; }
    if (!window.jspdf) { showNotification('PDF library not loaded.', 'error'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    doc.setFontSize(16); doc.setFont('helvetica', 'bold');
    doc.text('EduGate — Financial Report', 14, 15);
    doc.setFontSize(9); doc.setFont('helvetica', 'normal');
    doc.text(`Generated: ${new Date().toLocaleString()}`, 14, 22);

    const rows = data.map(r => [
        r.full_name, r.admission_number, r.class, r.category_name,
        `${r.academic_year}/${r.school_term}`,
        Number(r.amount_due).toLocaleString(), Number(r.amount_paid).toLocaleString(),
        Number(r.balance).toLocaleString(), r.payment_status
    ]);
    doc.autoTable({
        head: [['Student','Adm No','Class','Category','Year/Term','Due','Paid','Balance','Status']],
        body: rows,
        startY: 28,
        styles: { fontSize: 7, cellPadding: 2 },
        headStyles: { fillColor: [79, 70, 229], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [248, 250, 252] },
    });
    doc.save(`financial-report-${new Date().toISOString().split('T')[0]}.pdf`);
});

document.getElementById('br-export-excel')?.addEventListener('click', () => {
    const data = state.billing.filtered;
    if (!data.length) { showNotification('No data to export.', 'error'); return; }
    if (!window.XLSX) { showNotification('Excel library not loaded.', 'error'); return; }
    const headers = ['Student','Adm No','Class','Category','Academic Year','Term','Due','Paid','Balance','Status'];
    const rows = data.map(r => [
        r.full_name, r.admission_number, r.class, r.category_name,
        r.academic_year, r.school_term,
        parseFloat(r.amount_due), parseFloat(r.amount_paid), parseFloat(r.balance), r.payment_status
    ]);
    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Financial Report');
    XLSX.writeFile(wb, `financial-report-${new Date().toISOString().split('T')[0]}.xlsx`);
});
