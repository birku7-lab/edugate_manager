/**
 * sms_logs.js
 * Handles fetching, filtering, deleting, and displaying SMS logs from the database.
 */

let allSmsLogs = [];

document.addEventListener('DOMContentLoaded', () => {
    loadSMSLogs();

    const searchInput = document.getElementById('sms-search');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase();
            const filtered = allSmsLogs.filter(log => {
                const sName = (log.student_name || '').toLowerCase();
                const phone = (log.phone_number || '').toLowerCase();
                const msg = (log.message || '').toLowerCase();
                const status = (log.status || '').toLowerCase();
                return sName.includes(query) || phone.includes(query) || msg.includes(query) || status.includes(query);
            });
            renderSmsLogs(filtered);
        });
    }
});

async function loadSMSLogs() {
    const tbody = document.getElementById('sms-logs-tbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;">Loading logs...</td></tr>';

    try {
        const res = await fetchData('api/sms.php?action=get_logs');
        if (res && res.status === 'success') {
            allSmsLogs = res.data;
            renderSmsLogs(allSmsLogs);
        } else {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:2rem;color:#dc2626;">Error: ${res.message || 'Failed to load logs'}</td></tr>`;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:2rem;color:#dc2626;">Failed to connect to the server.</td></tr>`;
    }
}

function renderSmsLogs(data) {
    const tbody = document.getElementById('sms-logs-tbody');
    if (!tbody) return;

    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;">No SMS logs found.</td></tr>';
        return;
    }

    let html = '';
    data.forEach(log => {
        const dateObj = new Date(log.sent_at);
        const dateStr = dateObj.toLocaleDateString() + ' ' + dateObj.toLocaleTimeString();
        
        const statusBadge = log.status === 'Sent' 
            ? `<span style="background:#ecfdf5;color:#059669;padding:2px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">Sent</span>`
            : `<span style="background:#fef2f2;color:#dc2626;padding:2px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">Failed</span>`;

        const encodedResponse = encodeURIComponent(log.api_response || '');
        const encodedError = encodeURIComponent(log.error_message || '');

        html += `
            <tr style="border-bottom:1px solid var(--border-color);">
                <td style="padding:0.75rem 1rem;">${dateStr}</td>
                <td style="padding:0.75rem 1rem;">${log.student_name || 'N/A'}</td>
                <td style="padding:0.75rem 1rem;">${log.phone_number}</td>
                <td style="padding:0.75rem 1rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${log.message}">${log.message}</td>
                <td style="padding:0.75rem 1rem;">${statusBadge}</td>
                <td style="padding:0.75rem 1rem;">
                    <button class="btn" style="padding:4px 8px;font-size:0.8rem;background:#eff6ff;color:#3b82f6;border:none;" onclick="showLogDetails('${encodedResponse}', '${encodedError}')">
                        <i class="ph ph-info"></i> View
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function showLogDetails(encodedResponse, encodedError) {
    const apiResponse = decodeURIComponent(encodedResponse);
    const errorMsg = decodeURIComponent(encodedError);

    document.getElementById('modal-error-message').textContent = errorMsg || 'None';
    
    try {
        if (apiResponse) {
            const parsed = JSON.parse(apiResponse);
            document.getElementById('modal-api-response').textContent = JSON.stringify(parsed, null, 2);
        } else {
            document.getElementById('modal-api-response').textContent = 'No response logged.';
        }
    } catch (e) {
        document.getElementById('modal-api-response').textContent = apiResponse;
    }

    document.getElementById('sms-details-modal').style.display = 'flex';
}

async function deleteAllSMSLogs() {
    if (!confirm('Are you sure you want to delete ALL SMS logs? This action cannot be undone.')) {
        return;
    }
    
    try {
        const res = await fetchData('api/sms.php?action=delete_all_logs', { method: 'POST' });
        if (res && res.status === 'success') {
            alert(res.message);
            loadSMSLogs();
        } else {
            alert('Error: ' + (res.message || 'Failed to delete logs.'));
        }
    } catch (e) {
        alert('Failed to connect to the server.');
    }
}
