document.addEventListener('DOMContentLoaded', () => {
    loadRecords(); // Load today by default
    
    const filterForm = document.getElementById('filter-form');
    if (filterForm) {
        filterForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const formData = new FormData(filterForm);
            
            // Clean up empty params
            const params = new URLSearchParams();
            for (const [key, value] of formData) {
                if (value.trim() !== '') {
                    params.append(key, value);
                }
            }
            loadRecords(params.toString());
        });
    }
});

window.addEventListener('liveSyncUpdate', () => {
    if (document.querySelector('#records-table tbody')) {
        // We need to re-fetch with the current filter params
        const filterForm = document.getElementById('filter-form');
        let currentParams = '';
        if (filterForm) {
            const formData = new FormData(filterForm);
            const params = new URLSearchParams();
            for (const [key, value] of formData) {
                if (value.trim() !== '') params.append(key, value);
            }
            currentParams = params.toString();
        }
        loadRecords(currentParams, true);
    }
});

async function loadRecords(params = '', silent = false) {
    const tbody = document.querySelector('#records-table tbody');
    if (!silent) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center">Loading...</td></tr>';
    }
    
    // By default load today's records if no params
    let url = 'api/attendance.php?action=records';
    if (params) {
        url += '&' + params;
    } else {
        const today = new Date().toISOString().split('T')[0];
        url += '&date=' + today;
    }
    
    const res = await fetchData(url);
    if (res && res.status === 'success') {
        tbody.innerHTML = '';
        if (res.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;">No attendance records found for this criteria.</td></tr>';
            return;
        }
        
        res.data.forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${r.attendance_date}</td>
                <td>${r.arrival_time}</td>
                <td>${r.admission_number}</td>
                <td><strong>${r.full_name}</strong></td>
                <td>${r.class}</td>
                <td><span class="badge ${r.attendance_status === 'Present' ? 'badge-success' : 'badge-danger'}">${r.attendance_status}</span></td>
            `;
            tbody.appendChild(tr);
        });
    } else {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:red;">Error loading records</td></tr>`;
    }
}
