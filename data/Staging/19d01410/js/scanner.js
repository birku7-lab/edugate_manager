let html5QrCode;
let isProcessing = false;
let isPausedByUser = false;

// Offline Sync Loop
setInterval(syncOfflineScans, 3000);

async function syncOfflineScans() {
    if (!navigator.onLine) return;
    const scans = JSON.parse(localStorage.getItem('offline_scans') || '[]');
    if (scans.length === 0) return;

    // We have scans to sync
    const scan = scans[0];
    const formData = new FormData();
    formData.append('qr_code', scan.qr_code);
    formData.append('timestamp', scan.timestamp);

    try {
        const res = await fetchData('api/attendance.php?action=scan', {
            method: 'POST',
            body: formData
        });
        
        if (res && res.status !== 'error' || (res && res.status === 'error' && !res.message.includes('Failed to fetch'))) {
            // Successfully reached server (even if it's an API error like Invalid QR, we remove it from queue)
            scans.shift();
            localStorage.setItem('offline_scans', JSON.stringify(scans));
            
            // Trigger UI update if it was the last one
            if(scans.length === 0) showNotification('Offline scans synchronized!', 'success');
        }
    } catch(e) {
        // Still offline or server down
    }
}

function togglePauseResume() {
    const btn = document.getElementById('pause-resume-btn');
    if (!html5QrCode) return;

    if (isPausedByUser) {
        // Resume
        if(html5QrCode.getState() === 3 /* PAUSED */) {
            html5QrCode.resume();
        }
        isPausedByUser = false;
        btn.innerHTML = '<i class="ph ph-pause"></i> Pause Scanner';
        btn.className = 'btn btn-warning';
        document.getElementById('scan-status').textContent = 'Ready to Scan...';
        document.getElementById('scan-status').style.color = 'var(--primary)';
    } else {
        // Pause
        if(html5QrCode.getState() === 2 /* SCANNING */) {
            html5QrCode.pause(true);
        }
        isPausedByUser = true;
        btn.innerHTML = '<i class="ph ph-play"></i> Resume Scanner';
        btn.className = 'btn btn-success';
        document.getElementById('scan-status').textContent = 'Scanner Paused by User';
        document.getElementById('scan-status').style.color = 'var(--warning)';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Determine Scanner Mode
    const urlParams = new URLSearchParams(window.location.search);
    let currentMode = urlParams.get('mode') || 'attendance';
    window.scannerMode = currentMode;

    // Update UI based on mode
    const titleEl = document.querySelector('.header-left h1');
    const descEl = document.querySelector('.header-left p');
    if (currentMode === 'meals') {
        if (titleEl) titleEl.innerHTML = '<i class="ph ph-bowl-food"></i> Meals Scanner';
        if (descEl) descEl.textContent = 'Scan student QR codes to record meals.';
    } else if (currentMode === 'transport') {
        if (titleEl) titleEl.innerHTML = '<i class="ph ph-bus"></i> Transport Scanner';
        if (descEl) descEl.textContent = 'Scan student QR codes to record bus boarding.';
    }

    // Initialize Webcam Scanner to automatically ask for permissions and start
    html5QrCode = new Html5Qrcode("reader");
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
    
    html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess, onScanError)
    .catch(err => {
        document.getElementById('reader').innerHTML = `
            <div style="padding: 2rem; text-align: center; color: var(--danger);">
                <i class="ph ph-warning" style="font-size: 2rem;"></i><br>
                <strong>Camera Access Denied</strong><br>
                <span style="font-size: 0.9rem;">Please allow camera permissions in your browser settings and refresh the page.</span>
            </div>
        `;
    });

    // Initialize USB Scanner listener
    // Keep focus on the hidden input
    const usbInput = document.getElementById('usb-scanner-input');
    
    document.addEventListener('click', () => {
        // Only focus if it's not a touch device to prevent mobile keyboard from popping up
        if(usbInput && !('ontouchstart' in window)) usbInput.focus();
    });

    if (usbInput) {
        usbInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const code = this.value.trim();
                this.value = ''; // clear for next scan
                if (code) {
                    processScan(code);
                }
            }
        });
        usbInput.focus();
    }
});

function onScanSuccess(decodedText, decodedResult) {
    if(isProcessing) return;
    
    if(html5QrCode && html5QrCode.getState() === 2 /* SCANNING */) {
        html5QrCode.pause(true);
    }
    processScan(decodedText);
}

function onScanError(errorMessage) {
    // Ignore routine errors
}

async function processScan(qrCode) {
    if (isProcessing) return;
    isProcessing = true;
    
    document.getElementById('scan-status').textContent = 'Processing...';
    document.getElementById('scan-status').style.color = 'var(--warning)';
    
    // --- OFFLINE QUEUE CHECK ---
    if (!navigator.onLine) {
        saveScanOffline(qrCode);
        return;
    }

    const formData = new FormData();
    formData.append('qr_code', qrCode);

    let res;
    try {
        const mode = window.scannerMode || 'attendance';
        res = await fetchData(`api/attendance.php?action=scan&mode=${mode}`, {
            method: 'POST',
            body: formData
        });
        
        // If fetch completely fails, fallback to offline
        if (res && res.status === 'error' && res.message && (res.message.includes('Failed to fetch') || res.message.includes('NetworkError'))) {
            saveScanOffline(qrCode);
            return;
        }
    } catch(e) {
        saveScanOffline(qrCode);
        return;
    }

    document.getElementById('idle-card').style.display = 'none';
    const resultCard = document.getElementById('result-card');
    
    // Reset classes
    resultCard.className = 'scan-result-card';

    if (res && (res.status === 'success' || res.status === 'duplicate' || res.status === 'warning')) {
        const s = res.data;
        const photoSrc = s.photo ? (API_BASE_URL + s.photo + '?v=1.5') : 'data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22120%22%20height%3D%22120%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23ccc%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22M20%2021v-2a4%204%200%200%200-4-4H8a4%204%200%200%200-4%204v2%22%3E%3C%2Fpath%3E%3Ccircle%20cx%3D%2212%22%20cy%3D%227%22%20r%3D%224%22%3E%3C%2Fcircle%3E%3C%2Fsvg%3E';
        
        document.getElementById('result-photo').src = photoSrc;
        document.getElementById('result-name').textContent = s.full_name;
        document.getElementById('result-adm').textContent = s.admission_number;
        document.getElementById('result-class').textContent = s.class;
        document.getElementById('result-gender').textContent = s.gender;

        if (res.status === 'success') {
            resultCard.classList.add('success');
            document.getElementById('result-icon').textContent = '✅';
            document.getElementById('result-message').textContent = res.message || 'Recorded Successfully';
            document.getElementById('result-message').style.color = 'var(--success)';
            document.getElementById('result-time').textContent = s.arrival_time || s.scan_time || '-';
            
            playAudio('beep-success');
        } else if (res.status === 'warning') {
            resultCard.classList.add('duplicate');
            document.getElementById('result-icon').textContent = '⚠️';
            document.getElementById('result-message').textContent = res.message;
            document.getElementById('result-message').style.color = 'var(--warning)';
            document.getElementById('result-time').textContent = s.arrival_time;
            
            playAudio('beep-warning');
        } else {
            resultCard.classList.add('duplicate');
            document.getElementById('result-icon').textContent = '⚠️';
            document.getElementById('result-message').textContent = res.message || 'Already Checked In Today';
            document.getElementById('result-message').style.color = 'var(--warning)';
            document.getElementById('result-time').textContent = (res.first_scan_time || '-') + ' (First Scan)';
            
            playAudio('beep-warning');
        }
    } else {
        // Error
        resultCard.classList.add('error');
        document.getElementById('result-icon').textContent = '❌';
        document.getElementById('result-message').textContent = res?.message || 'Invalid QR Code';
        document.getElementById('result-message').style.color = 'var(--danger)';
        
        if (res && res.data) {
            const s = res.data;
            const photoSrc = s.photo ? (API_BASE_URL + s.photo + '?v=1.5') : 'data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22120%22%20height%3D%22120%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23ccc%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22M20%2021v-2a4%204%200%200%200-4-4H8a4%204%200%200%200-4%204v2%22%3E%3C%2Fpath%3E%3Ccircle%20cx%3D%2212%22%20cy%3D%227%22%20r%3D%224%22%3E%3C%2Fcircle%3E%3C%2Fsvg%3E';
            document.getElementById('result-photo').src = photoSrc;
            document.getElementById('result-name').textContent = s.full_name;
            document.getElementById('result-adm').textContent = s.admission_number;
            document.getElementById('result-class').textContent = s.class;
            document.getElementById('result-gender').textContent = s.gender;
            document.getElementById('result-time').textContent = '-';
        } else {
            document.getElementById('result-photo').src = 'data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22120%22%20height%3D%22120%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23ccc%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Ccircle%20cx%3D%2212%22%20cy%3D%2212%22%20r%3D%2210%22%3E%3C%2Fcircle%3E%3Cline%20x1%3D%2215%22%20y1%3D%229%22%20x2%3D%229%22%20y2%3D%2215%22%3E%3C%2Fline%3E%3Cline%20x1%3D%229%22%20y1%3D%229%22%20x2%3D%2215%22%20y2%3D%2215%22%3E%3C%2Fline%3E%3C%2Fsvg%3E';
            document.getElementById('result-name').textContent = 'Unknown Student';
            document.getElementById('result-adm').textContent = '-';
            document.getElementById('result-class').textContent = '-';
            document.getElementById('result-gender').textContent = '-';
            document.getElementById('result-time').textContent = '-';
        }
        
        playAudio('beep-error');
    }

    setTimeout(() => {
        isProcessing = false;
        document.getElementById('scan-status').textContent = 'Ready to Scan';
        document.getElementById('scan-status').style.color = 'var(--text-muted)';
        
        if(html5QrCode && html5QrCode.getState() === 3 /* PAUSED */ && !isPausedByUser) {
            html5QrCode.resume();
        }
        
        const usbInput = document.getElementById('usb-scanner-input');
        if(usbInput) usbInput.focus();
    }, 3000);
}

function saveScanOffline(qrCode) {
    const scans = JSON.parse(localStorage.getItem('offline_scans') || '[]');
    // Use local ISO string to approximate YYYY-MM-DD HH:MM:SS format
    const now = new Date();
    const tzoffset = (new Date()).getTimezoneOffset() * 60000; 
    const localISOTime = (new Date(Date.now() - tzoffset)).toISOString().slice(0, 19).replace('T', ' ');
    
    scans.push({ qr_code: qrCode, timestamp: localISOTime });
    localStorage.setItem('offline_scans', JSON.stringify(scans));

    document.getElementById('idle-card').style.display = 'none';
    const resultCard = document.getElementById('result-card');
    resultCard.className = 'scan-result-card success';
    
    document.getElementById('result-icon').textContent = '💾';
    document.getElementById('result-message').textContent = 'Saved Offline (Pending Sync)';
    document.getElementById('result-message').style.color = '#3b82f6';
    document.getElementById('result-name').textContent = 'Unknown (Offline)';
    document.getElementById('result-adm').textContent = qrCode;
    document.getElementById('result-class').textContent = 'Pending';
    document.getElementById('result-gender').textContent = 'Pending';
    document.getElementById('result-time').textContent = localISOTime;
    
    document.getElementById('result-photo').src = 'data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22120%22%20height%3D%22120%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23ccc%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22M20%2021v-2a4%204%200%200%200-4-4H8a4%204%200%200%200-4%204v2%22%3E%3C%2Fpath%3E%3Ccircle%20cx%3D%2212%22%20cy%3D%227%22%20r%3D%224%22%3E%3C%2Fcircle%3E%3C%2Fsvg%3E';
    
    playAudio('beep-success');

    setTimeout(() => {
        isProcessing = false;
        document.getElementById('scan-status').textContent = 'Ready to Scan';
        document.getElementById('scan-status').style.color = 'var(--text-muted)';
        
        if(html5QrCode && html5QrCode.getState() === 3 /* PAUSED */ && !isPausedByUser) {
            html5QrCode.resume();
        }
        
        const usbInput = document.getElementById('usb-scanner-input');
        if(usbInput) usbInput.focus();
    }, 2000);
}

function playAudio(type) {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gainNode = ctx.createGain();
    
    osc.connect(gainNode);
    gainNode.connect(ctx.destination);
    
    if (type === 'beep-success') {
        osc.frequency.value = 800;
        osc.type = "sine";
        gainNode.gain.setValueAtTime(0.1, ctx.currentTime);
        osc.start();
        osc.stop(ctx.currentTime + 0.1);
    } else if (type === 'beep-warning') {
        osc.frequency.value = 400;
        osc.type = "square";
        gainNode.gain.setValueAtTime(0.05, ctx.currentTime);
        osc.start();
        osc.stop(ctx.currentTime + 0.3);
    } else {
        osc.frequency.value = 200;
        osc.type = "sawtooth";
        gainNode.gain.setValueAtTime(0.1, ctx.currentTime);
        osc.start();
        osc.stop(ctx.currentTime + 0.3);
    }
}
