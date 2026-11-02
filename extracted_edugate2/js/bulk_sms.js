/**
 * Bulk SMS Logic
 */

document.addEventListener('DOMContentLoaded', () => {
    // Populate classes dropdown dynamically
    if (document.getElementById('bulk_target_classes')) {
        populateClassesDropdown();
    }
    
    // Character counter
    const msgInput = document.getElementById('bulk_message');
    if (msgInput) {
        msgInput.addEventListener('input', updateBulkCounters);
    }
});

async function populateClassesDropdown() {
    try {
        const res = await fetchData('api/settings.php?action=get_classes');
        if (res && res.status === 'success' && res.data) {
            const select = document.getElementById('bulk_target_classes');
            select.innerHTML = '';
            res.data.forEach(c => {
                select.innerHTML += `<option value="${c.class_name}">${c.class_name}</option>`;
            });
        }
    } catch (e) {
        console.error("Failed to load classes for bulk SMS", e);
    }
}

function toggleBulkFilters() {
    const type = document.getElementById('bulk_target_type').value;
    const classFilter = document.getElementById('bulk_filter_classes');
    
    if (type === 'classes') {
        classFilter.style.display = 'block';
    } else {
        classFilter.style.display = 'none';
    }
    
    // Hide stats when filter changes
    document.getElementById('bulk_recipient_stats').style.display = 'none';
}

function getBulkFiltersPayload() {
    const type = document.getElementById('bulk_target_type').value;
    let filters = { type: type };
    
    if (type === 'classes') {
        const selected = Array.from(document.getElementById('bulk_target_classes').selectedOptions).map(o => o.value);
        if (selected.length === 0) {
            showNotification("Please select at least one class", "error");
            return null;
        }
        filters.classes = selected;
    }
    
    return filters;
}

async function calculateRecipients() {
    const filters = getBulkFiltersPayload();
    if (!filters) return;
    
    const fd = new FormData();
    fd.append('filters', JSON.stringify(filters));
    
    try {
        const res = await fetchData('api/sms.php?action=get_recipients', {
            method: 'POST',
            body: fd
        });
        
        if (res && res.status === 'success') {
            document.getElementById('bulk_count').innerText = res.count;
            document.getElementById('bulk_recipient_stats').style.display = 'block';
            updateBulkCounters(); // Update cost based on new count
        } else {
            showNotification(res.message || "Failed to calculate recipients", "error");
        }
    } catch (e) {
        showNotification("Error calculating recipients", "error");
    }
}

function updateBulkCounters() {
    const msg = document.getElementById('bulk_message').value;
    const chars = msg.length;
    document.getElementById('bulk_char_count').innerText = chars;
    
    // SMS Cost logic (1 SMS = 160 chars)
    const smsParts = Math.ceil(chars / 160) || 1;
    const count = parseInt(document.getElementById('bulk_count').innerText) || 0;
    
    const totalCost = smsParts * count;
    document.getElementById('bulk_cost').innerText = totalCost;
}

function previewBulkMessage() {
    const msg = document.getElementById('bulk_message').value;
    if (!msg.trim()) {
        showNotification("Please compose a message first", "error");
        return;
    }
    
    const count = parseInt(document.getElementById('bulk_count').innerText) || 0;
    if (count === 0) {
        showNotification("No recipients selected. Calculate recipients first.", "error");
        return;
    }
    
    // Replace placeholders with mock data
    let preview = msg
        .replace(/{Student Name}/g, "John Doe")
        .replace(/{Parent Name}/g, "Jane Doe")
        .replace(/{Admission Number}/g, "ADM-1234")
        .replace(/{Class}/g, "Form 1")
        .replace(/{School Name}/g, "Your School")
        .replace(/{Amount Paid}/g, "KES 5,000")
        .replace(/{Balance}/g, "KES 2,000")
        .replace(/{Payment Date}/g, new Date().toISOString().split('T')[0])
        .replace(/{Current Term}/g, "Term 1")
        .replace(/{Academic Year}/g, "2026/2027");
        
    alert("PREVIEW MESSAGE:\n\n" + preview + "\n\nTotal Recipients: " + count + "\nEstimated Cost: " + document.getElementById('bulk_cost').innerText + " Units");
}

async function sendBulkSMSAction() {
    const filters = getBulkFiltersPayload();
    if (!filters) return;
    
    const msg = document.getElementById('bulk_message').value;
    if (!msg.trim()) {
        showNotification("Message cannot be empty", "error");
        return;
    }
    
    const count = parseInt(document.getElementById('bulk_count').innerText) || 0;
    if (count === 0) {
        showNotification("Calculate recipients first. Count is 0.", "error");
        return;
    }
    
    const scheduleTime = document.getElementById('bulk_schedule_time').value;
    const isSchedule = scheduleTime !== '';
    
    if (!confirm(`Are you sure you want to ${isSchedule ? 'schedule' : 'send'} this bulk SMS to ${count} recipients?`)) {
        return;
    }
    
    const fd = new FormData();
    fd.append('filters', JSON.stringify(filters));
    fd.append('message', msg);
    
    let action = 'send_bulk';
    if (isSchedule) {
        action = 'schedule_bulk';
        fd.append('schedule_time', scheduleTime);
    }
    
    try {
        const res = await fetchData(`api/sms.php?action=${action}`, {
            method: 'POST',
            body: fd
        });
        
        if (res && res.status === 'success') {
            showNotification(res.message, "success");
            if (!isSchedule) {
                document.getElementById('bulk_message').value = '';
                updateBulkCounters();
            }
        } else {
            showNotification(res.message || "Failed to process bulk SMS", "error");
        }
    } catch (e) {
        showNotification("An error occurred", "error");
    }
}

async function runScheduledSMS() {
    try {
        const res = await fetch(API_BASE_URL + 'api/cron_sms.php');
        const text = await res.text();
        try {
            const json = JSON.parse(text);
            if (json.status === 'success') {
                showNotification(json.message, "success");
            } else {
                showNotification(json.message || "Error processing scheduled SMS", "error");
            }
        } catch(e) {
            console.log(text);
            showNotification("Scheduled SMS run completed. Check console if issues.", "success");
        }
    } catch (e) {
        showNotification("Failed to trigger scheduled SMS", "error");
    }
}
