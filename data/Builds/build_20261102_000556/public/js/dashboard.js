document.addEventListener('DOMContentLoaded', () => {
    // Live clock
    const dateElement = document.getElementById('current-date-widget');
    const timeElement = document.getElementById('current-time-widget');

    function updateDateTime() {
        const now = new Date();
        if (dateElement) {
            dateElement.textContent = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }
        if (timeElement) {
            timeElement.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }
    }
    updateDateTime();
    setInterval(updateDateTime, 1000); // update every second

    // Initial load
    loadDashboardStats();
    loadDashboardBillingCards();

    // The global liveSyncUpdate event will trigger refreshes more efficiently
    window.addEventListener('liveSyncUpdate', () => {
        loadDashboardStats();
        loadDashboardBillingCards();
    });
});

async function loadDashboardBillingCards() {
    const container = document.getElementById('dashboard-billing-cards');
    if (!container) return;
    // Only show for admin
    const role = localStorage.getItem('user_role');
    if (role !== 'Admin') {
        const section = document.getElementById('billing-overview-section');
        if (section) section.style.display = 'none';
        return;
    }
    try {
        const res = await fetchData('api/billing.php?action=billing_dashboard');
        if (!res || res.status !== 'success' || !res.data.length) {
            container.innerHTML = `<div class="card" style="padding:1.25rem;color:var(--text-muted);font-size:0.9rem;">
                No billing data. <a href="api/billing_migrate.php" target="_blank">Run migration first</a>, then configure categories in <a href="settings.html#tab-billing">Settings → Billing</a>.
            </div>`;
            return;
        }
        const colorMap = [
            'linear-gradient(135deg,#4f46e5,#7c3aed)',
            'linear-gradient(135deg,#059669,#10b981)',
            'linear-gradient(135deg,#0284c7,#0ea5e9)',
            'linear-gradient(135deg,#b45309,#f59e0b)',
        ];
        container.innerHTML = res.data.map((cat, i) => {
            const pct = cat.expected > 0 ? Math.round((cat.collected / cat.expected) * 100) : 0;
            const color = colorMap[i % colorMap.length];
            return `<div class="card" style="padding:1.25rem;position:relative;overflow:hidden;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:0.75rem;">
                    <div>
                        <p style="font-size:0.72rem;font-weight:700;color:var(--text-muted);letter-spacing:0.5px;text-transform:uppercase;margin:0 0 0.25rem;">${cat.name}</p>
                        <div style="font-size:1.4rem;font-weight:800;color:var(--text-main);">KES ${Number(cat.collected).toLocaleString()}</div>
                        <p style="font-size:0.75rem;color:var(--text-muted);margin:0.15rem 0 0;">of KES ${Number(cat.expected).toLocaleString()} expected</p>
                    </div>
                    <div style="width:40px;height:40px;border-radius:10px;background:${color};display:flex;align-items:center;justify-content:center;color:white;font-size:1.2rem;flex-shrink:0;">
                        <i class="ph ph-currency-circle-dollar"></i>
                    </div>
                </div>
                <div style="background:var(--border-color);border-radius:10px;height:5px;margin-bottom:0.75rem;">
                    <div style="height:5px;border-radius:10px;background:${color};width:${pct}%;transition:width 0.8s ease;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:0.78rem;">
                    <span style="color:#059669;font-weight:600;">${cat.paid_count ?? 0} Paid</span>
                    <span style="color:#f59e0b;font-weight:600;">${cat.partial_count ?? 0} Partial</span>
                    <span style="color:#ef4444;font-weight:600;">${cat.unpaid_count ?? 0} Unpaid</span>
                </div>
            </div>`;
        }).join('');
    } catch(e) {
        console.log('Billing dashboard not available:', e.message);
        const section = document.getElementById('billing-overview-section');
        if (section) section.style.display = 'none';
    }
}

let lineChartInstance = null;
let donutChartInstance = null;
let _lastPresentCount = null; // track changes to animate

function animateValue(elementId, newVal) {
    const el = document.getElementById(elementId);
    if (!el) return;

    const oldVal = el.textContent;
    if (oldVal !== String(newVal)) {
        el.style.transition = 'all 0.3s ease';
        el.style.transform = 'scale(1.15)';
        el.style.color = '#4f46e5';
        el.textContent = newVal;
        setTimeout(() => {
            el.style.transform = 'scale(1)';
            el.style.color = '';
        }, 400);
    }
}

async function loadDashboardStats() {
    if (window.appSettings) {
        if (window.appSettings.dash_cards === 'false') {
            const grid = document.querySelector('.dashboard-grid');
            if (grid) grid.style.display = 'none';
        }
        const scansContainer = document.getElementById('live-scans-container');
        if (window.appSettings.dash_live_scan === 'false' && scansContainer) {
            scansContainer.closest('.card').style.display = 'none';
        }
        const lineChart = document.getElementById('attendanceLineChart');
        if (window.appSettings.dash_charts === 'false' && lineChart) {
            lineChart.closest('.card').style.display = 'none';
        }
        const quickActions = document.querySelector('.quick-actions-grid');
        if (window.appSettings.dash_quick_actions === 'false' && quickActions) {
            quickActions.closest('.card').style.display = 'none';
        }
    }

    const res = await fetchData('api/dashboard.php');
    if (res && res.status === 'success') {
        const data = res.data;

        // Animate stat cards on change
        animateValue('stat-total', data.total_students);
        animateValue('stat-present', data.present_today);
        animateValue('stat-absent', data.absent_today);
        animateValue('stat-percentage', data.attendance_percentage + '%');
        if (data.meals_today !== undefined) animateValue('stat-meals', data.meals_today);
        if (data.transport_today !== undefined) animateValue('stat-transport', data.transport_today);

        const presentPerc = data.total_students > 0
            ? ((data.present_today / data.total_students) * 100).toFixed(1)
            : 0;
        const absentPerc = data.total_students > 0
            ? ((data.absent_today / data.total_students) * 100).toFixed(1)
            : 0;

        const presentSub = document.getElementById('stat-present-subtitle');
        const absentSub = document.getElementById('stat-absent-subtitle');
        if (presentSub) presentSub.textContent = presentPerc + '% of total students';
        if (absentSub) absentSub.textContent = absentPerc + '% of total students';

        // Update "Live Scans" panel
        const scansContainer = document.getElementById('live-scans-container');
        if (scansContainer) {
            // Only re-render if count changed to avoid flicker
            if (_lastPresentCount !== data.present_today) {
                _lastPresentCount = data.present_today;
                scansContainer.innerHTML = '';

                if (data.recent_scans && data.recent_scans.length > 0) {
                    document.getElementById('summary-total-scans').textContent = data.present_today;
                    document.getElementById('summary-last-scan').textContent = formatTime12h(data.recent_scans[0].arrival_time);

                    data.recent_scans.slice(0, 5).forEach(scan => {
                        const photoSrc = scan.photo
                            ? (API_BASE_URL + scan.photo + '?v=1.5')
                            : `https://ui-avatars.com/api/?name=${encodeURIComponent(scan.full_name)}&background=e0e7ff&color=4f46e5`;

                        const statusColor = scan.attendance_status === 'Present' ? '#10b981' : '#f59e0b';
                        const statusIcon = scan.attendance_status === 'Present'
                            ? '<i class="ph-fill ph-check-circle" style="color:#10b981;"></i>'
                            : '<i class="ph-fill ph-clock" style="color:#f59e0b;"></i>';

                        const div = document.createElement('div');
                        div.className = 'scan-item';
                        div.style.animation = 'slideInScan 0.3s ease';
                        div.innerHTML = `
                            <div class="scan-user">
                                <img src="${photoSrc}" alt="${scan.full_name}"
                                    onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(scan.full_name)}&background=e0e7ff&color=4f46e5'">
                                <div class="scan-user-info">
                                    <h4>${scan.full_name}</h4>
                                    <p>${scan.admission_number || '-'}</p>
                                </div>
                            </div>
                            <div class="scan-class">${scan.class}</div>
                            <div class="scan-time">
                                ${formatTime12h(scan.arrival_time)}
                                ${statusIcon}
                            </div>
                        `;
                        scansContainer.appendChild(div);
                    });
                } else {
                    scansContainer.innerHTML = '<div style="text-align:center; padding: 2rem; color: var(--text-muted);">No scans yet today.</div>';
                    const totalScans = document.getElementById('summary-total-scans');
                    const lastScan = document.getElementById('summary-last-scan');
                    if (totalScans) totalScans.textContent = '0';
                    if (lastScan) lastScan.textContent = '-';
                }
            }
        }

        // Update notification badge with today's scan count
        const badge = document.querySelector('.notification-btn .badge');
        if (badge) badge.textContent = data.present_today;

        // Update charts
        updateLineChart(data.weekly_data);
        updateDonutChart(data.class_distribution, data.total_students);
    }
}

function formatTime12h(timeStr) {
    if (!timeStr) return '-';
    const parts = timeStr.split(':');
    if (parts.length < 2) return timeStr;
    let hour = parseInt(parts[0]);
    const min = parts[1];
    const ampm = hour >= 12 ? 'PM' : 'AM';
    hour = hour % 12;
    hour = hour ? hour : 12;
    return `${hour.toString().padStart(2, '0')}:${min} ${ampm}`;
}

function updateLineChart(weeklyData) {
    const ctx = document.getElementById('attendanceLineChart');
    if (!ctx) return;

    const actualData = weeklyData || [0, 0, 0, 0, 0, 0];

    if (lineChartInstance) {
        // Just update data in place (smooth transition, no flicker)
        lineChartInstance.data.datasets[0].data = actualData;
        lineChartInstance.update('active');
        return;
    }

    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, 'rgba(79, 70, 229, 0.4)');
    gradient.addColorStop(1, 'rgba(79, 70, 229, 0.0)');

    lineChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            datasets: [{
                label: 'Attendance %',
                data: actualData,
                borderColor: '#4f46e5',
                backgroundColor: gradient,
                borderWidth: 3,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#4f46e5',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 600 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    padding: 10,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: function (context) { return context.parsed.y + '%'; }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    grid: { color: '#f1f5f9', drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 11 }, stepSize: 25 }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 12 } }
                }
            }
        }
    });
}

function updateDonutChart(classDistribution, totalStudents) {
    const ctx = document.getElementById('classDonutChart');
    if (!ctx) return;

    const donutTotal = document.getElementById('donut-total');
    if (donutTotal) donutTotal.textContent = totalStudents;

    if (!classDistribution || classDistribution.length === 0) return;

    const colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#ec4899', '#14b8a6', '#f97316'];
    const labels = classDistribution.map(c => c.class);
    const data = classDistribution.map(c => c.count);
    const bgColors = classDistribution.map((_, i) => colors[i % colors.length]);

    if (donutChartInstance) {
        // Update in place
        donutChartInstance.data.labels = labels;
        donutChartInstance.data.datasets[0].data = data;
        donutChartInstance.data.datasets[0].backgroundColor = bgColors;
        donutChartInstance.update('active');
    } else {
        donutChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: bgColors,
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                animation: { duration: 600 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (context) { return ` ${context.label}: ${context.raw} students`; }
                        }
                    }
                }
            }
        });
    }

    // Build real legend
    const legendContainer = document.getElementById('donut-legend');
    if (legendContainer) {
        legendContainer.innerHTML = '';
        classDistribution.forEach((item, i) => {
            const perc = totalStudents > 0 ? ((item.count / totalStudents) * 100).toFixed(1) : 0;
            const div = document.createElement('div');
            div.style.cssText = 'display: flex; align-items: center; justify-content: space-between;';
            div.innerHTML = `
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: ${bgColors[i]};"></span>
                    <span style="color: var(--text-main); font-weight: 500;">${item.class}</span>
                </div>
                <div style="color: var(--text-muted);">
                    <span>${item.count}</span> <span style="font-size: 0.7rem; margin-left: 5px;">(${perc}%)</span>
                </div>
            `;
            legendContainer.appendChild(div);
        });
    }
}

// Add slide-in animation for scan items
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInScan {
        from { opacity: 0; transform: translateY(-8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
`;
document.head.appendChild(style);
