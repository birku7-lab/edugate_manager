// Global Auth Check
(function checkAuth() {
    const isLoginPage = window.location.pathname.endsWith('login.html') || window.location.pathname.endsWith('login');
    
    // Server-side auth handles actual security. This is just for offline/SW fallback UI state
    if (!isLoginPage && !localStorage.getItem('user_role')) {
        window.location.href = 'login.html';
        return; // Stop execution
    }
    
    if (!isLoginPage) {
        // Enforce Permissions on Sidebar
        window.addEventListener('DOMContentLoaded', () => {
            
            // PWA & Offline Setup
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('sw.js').catch(err => console.log('SW error', err));
            }

            const networkInd = document.createElement('div');
            networkInd.style.cssText = 'position:fixed;bottom:20px;left:20px;padding:8px 15px;border-radius:20px;font-weight:bold;font-size:12px;z-index:9999;display:none;';
            document.body.appendChild(networkInd);

            const updateOnlineStatus = () => {
                if (navigator.onLine) {
                    networkInd.style.display = 'block';
                    networkInd.style.backgroundColor = '#10b981';
                    networkInd.style.color = '#fff';
                    networkInd.innerHTML = '🟢 Online';
                    setTimeout(() => { networkInd.style.display = 'none'; }, 3000);
                } else {
                    networkInd.style.display = 'block';
                    networkInd.style.backgroundColor = '#ef4444';
                    networkInd.style.color = '#fff';
                    networkInd.innerHTML = '🔴 Offline (Working Locally)';
                }
            };
            window.addEventListener('online', updateOnlineStatus);
            window.addEventListener('offline', updateOnlineStatus);
            if (!navigator.onLine) updateOnlineStatus();

            const permsRaw = localStorage.getItem('user_permissions') || '[]';
            let permissions = [];
            try { permissions = JSON.parse(permsRaw); } catch(e){}
            
            const role = localStorage.getItem('user_role');
            
            // Render user profile in footer
            const footerInfo = document.querySelector('.sidebar-footer-info');
            if (footerInfo) {
                const userName = localStorage.getItem('user_name') || (role === 'Admin' ? 'Admin' : 'Staff');
                footerInfo.innerHTML = `<h4>${userName}</h4><p>${role}</p>`;
            }

            // If not "all", hide unauthorized links and enforce page access
            if (!permissions.includes('all')) {
                const menuLinks = document.querySelectorAll('.sidebar-menu a');
                menuLinks.forEach(link => {
                    const href = link.getAttribute('href');
                    if (href && href !== '#') {
                        const pageName = href.split('?')[0].replace('.html', '');
                        let allowed = permissions.includes(pageName);
                        
                        // Special case for scanner specific links
                        if (pageName === 'scanner') {
                            const modeMatch = href.match(/mode=([a-zA-Z0-9_]+)/);
                            if (modeMatch) {
                                allowed = permissions.includes('scanner_' + modeMatch[1]);
                            } else {
                                allowed = permissions.some(p => p.startsWith('scanner_')) || permissions.includes('all');
                            }
                        }

                        if (!allowed) {
                            link.parentElement.style.display = 'none';
                        }
                    }
                });

                // Also hide the entire Management/System section if all children are hidden
                document.querySelectorAll('.nav-section').forEach(section => {
                    const visibleLinks = section.querySelectorAll('li:not([style*="display: none"])');
                    if (visibleLinks.length === 0) {
                        section.style.display = 'none';
                    }
                });

                // Page-level protection: check if current page is allowed
                const currentPathFull = window.location.pathname.split('/').pop() || 'index.html';
                const currentPath = currentPathFull.split('?')[0];
                const currentPageName = currentPath.replace('.html', '');
                
                // Allow login.html and empty paths
                if (currentPath !== 'login.html' && currentPath !== '') {
                    let pageAllowed = permissions.includes(currentPageName);
                    
                    if (currentPageName === 'scanner') {
                        const urlParams = new URLSearchParams(window.location.search);
                        const mode = urlParams.get('mode');
                        if (mode) {
                            pageAllowed = permissions.includes('scanner_' + mode) || permissions.includes('all');
                        } else {
                            pageAllowed = permissions.some(p => p.startsWith('scanner_')) || permissions.includes('all');
                        }
                    }

                    if (!pageAllowed && !permissions.includes('all')) {
                        // Redirect to the first allowed page, or login if none
                        if (permissions.length > 0) {
                            let redirect = permissions[0];
                            if (redirect.startsWith('scanner_')) redirect = 'scanner';
                            if (redirect.startsWith('reports_')) redirect = 'reports';
                            window.location.href = redirect + '.html';
                        } else {
                            window.location.href = 'login.html';
                        }
                    }
                }
            }

            // Inject dynamic scanner links into the sidebar if user has permission
            const managementSection = document.querySelector('.nav-section:nth-child(1) .sidebar-menu');
            if (managementSection) {
                // Add hardcoded missing ones
                if (permissions.includes('all') || permissions.includes('scanner_meals')) {
                    managementSection.innerHTML += `<li><a href="scanner.html?mode=meals"><i class="ph ph-bowl-food"></i> Meals Scanner</a></li>`;
                }
                if (permissions.includes('all') || permissions.includes('scanner_transport')) {
                    managementSection.innerHTML += `<li><a href="scanner.html?mode=transport"><i class="ph ph-bus"></i> Transport Scanner</a></li>`;
                }
                
                // Fetch dynamic ones
                try {
                    fetch(API_BASE_URL + 'api/billing.php?action=get_categories').then(r => r.json()).then(res => {
                        if (res && res.status === 'success') {
                            res.data.forEach(cat => {
                                if (cat.enforce_on_scanner == 1) {
                                    if (permissions.includes('all') || permissions.includes('scanner_cat_' + cat.id)) {
                                        managementSection.innerHTML += `<li><a href="scanner.html?mode=cat_${cat.id}"><i class="ph ph-scan"></i> ${cat.category_name} Scanner</a></li>`;
                                    }
                                }
                            });
                        }
                    }).catch(e => {});
                } catch(e) {}
            }

            // ── Admin-Only Nav Sections (Finance / Billing) ──────────────────
            // Hide .admin-only-nav sections for non-Admin users globally
            if (role !== 'Admin') {
                const style = document.createElement('style');
                style.innerHTML = '.admin-only-nav { display: none !important; }';
                document.head.appendChild(style);
            }

            // Set up the header notification bell (payment alerts badge)
            ensureNotificationBell();
            
            // Add Logout Button
            const sidebarScroll = document.querySelector('.sidebar-scroll');
            if(sidebarScroll) {
                const logoutSection = document.createElement('div');
                logoutSection.className = 'nav-section';
                logoutSection.innerHTML = `<ul class="sidebar-menu"><li><a href="#" id="logout-btn" style="color:#ef4444;"><i class="ph ph-sign-out"></i> Logout</a></li></ul>`;
                sidebarScroll.appendChild(logoutSection);
                
                document.getElementById('logout-btn').addEventListener('click', (e) => {
                    e.preventDefault();
                    fetch(API_BASE_URL + 'api/auth.php?action=logout', { method: 'POST' }).then(() => {
                        localStorage.removeItem('user_role');
                        localStorage.removeItem('user_permissions');
                        window.location.href = 'login.html';
                    });
                });
            }
        });
    }
})();

// Global Fetch Interceptor
const originalFetch = window.fetch;
window.fetch = async function() {
    let [resource, config] = arguments;
    
    if (config && ['POST', 'PUT', 'DELETE'].includes(config.method?.toUpperCase())) {
        const match = document.cookie.match(/(?:^|;\s*)csrf_token=([^;]+)/);
        if (match) {
            config.headers = { ...config.headers, 'X-CSRF-Token': match[1] };
        }
    }

    const response = await originalFetch(resource, config);
    
    // Auto-logout if unauthorized (e.g. token expired/invalid)
    if (response.status === 401 && !window.location.pathname.includes('login.html')) {
        localStorage.clear();
        window.location.href = 'login.html';
    }
    
    return response;
};

document.addEventListener('DOMContentLoaded', () => {
    // Mobile Sidebar Toggle
    const toggleBtn = document.querySelector('.mobile-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });
    }

    // Set active menu item
    function updateSidebarActive() {
        const currentPath = window.location.pathname.split('/').pop() || 'index.html';
        const currentHash = window.location.hash;
        const fullPath = currentPath + currentHash;
        
        const menuLinks = document.querySelectorAll('.sidebar-menu a');
        
        // Clear all
        menuLinks.forEach(link => link.parentElement.classList.remove('active'));
        
        let matched = false;
        
        // Try to match full path with hash first
        menuLinks.forEach(link => {
            const linkPath = link.getAttribute('href');
            if (linkPath === fullPath) {
                link.parentElement.classList.add('active');
                matched = true;
            }
        });
        
        // Fallback: If no exact hash match, match just the path
        if (!matched) {
            menuLinks.forEach(link => {
                const linkPath = link.getAttribute('href');
                if (linkPath === currentPath || linkPath.split('#')[0] === currentPath) {
                    link.parentElement.classList.add('active');
                }
            });
        }
    }
    
    updateSidebarActive();
    window.addEventListener('hashchange', updateSidebarActive);

    loadSystemSettings();
});

async function loadSystemSettings() {
    try {
        const res = await fetchData('api/settings.php?action=get');
        if (res && res.status === 'success') {
            const settings = res.data;
            window.appSettings = settings;

            // Apply Theme
            if (settings.theme === 'dark' || (settings.theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.body.classList.add('dark-theme');
            } else {
                document.body.classList.remove('dark-theme');
            }

            // Apply Accent Color
            const root = document.documentElement;
            const accents = {
                'blue': '#3b82f6',
                'purple': '#8b5cf6',
                'green': '#10b981',
                'orange': '#f59e0b',
                'red': '#ef4444',
                'pink': '#ec4899',
                'teal': '#14b8a6',
                'indigo': '#4f46e5'
            };
            if (settings.accent_color && accents[settings.accent_color]) {
                root.style.setProperty('--primary', accents[settings.accent_color]);
            }
            
            // Populate Class Dropdowns Globally
            populateClassDropdowns(settings.class_expiries);
        }
    } catch(e) {
        console.error("Failed to load settings:", e);
    }
}

function populateClassDropdowns(classExpiriesStr) {
    if (!classExpiriesStr) return;
    try {
        const classData = JSON.parse(classExpiriesStr);
        const classes = Object.keys(classData).sort();
        
        // Find all selects that are meant for classes
        const classSelects = document.querySelectorAll('select[name="class"], select#edit-class, select#filter-class, select#report-class, select#br-class, select#filter-billing-class, select#class');
        
        classSelects.forEach(select => {
            // Keep the first option if it's a placeholder (value="")
            let firstOption = null;
            if (select.options.length > 0 && select.options[0].value === "") {
                firstOption = select.options[0].cloneNode(true);
            }
            
            select.innerHTML = '';
            if (firstOption) select.appendChild(firstOption);
            else {
                // Default placeholder if none existed
                const opt = document.createElement('option');
                opt.value = "";
                opt.textContent = "Select Class";
                select.appendChild(opt);
            }

            classes.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = c;
                select.appendChild(opt);
            });
        });
    } catch(e) {
        console.error("Error populating class dropdowns:", e);
    }
}

// Global Notification System
function showNotification(message, type = 'success') {
    if (window.appSettings) {
        if (type === 'success' && window.appSettings.notifications_success === 'false') return;
        if (type === 'error' && window.appSettings.notifications_error === 'false') return;
    }

    let container = document.getElementById('notification-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'notification-container';
        document.body.appendChild(container);
    }

    const notif = document.createElement('div');
    notif.className = `notification ${type}`;
    
    const iconHtml = type === 'success' ? '✅' : (type === 'error' ? '❌' : '⚠️');

    notif.innerHTML = `
        <div class="icon">${iconHtml}</div>
        <div class="content">
            <h4 style="margin: 0; font-size: 14px;">${type.charAt(0).toUpperCase() + type.slice(1)}</h4>
            <p style="margin: 0; font-size: 13px; color: #555;">${message}</p>
        </div>
    `;

    container.appendChild(notif);

    setTimeout(() => {
        notif.classList.add('hide');
        setTimeout(() => {
            if(notif.parentNode) notif.parentNode.removeChild(notif);
        }, 300);
    }, 4000);
}

// Helper to fetch data
async function fetchData(url, options = {}) {
    try {
        const fullUrl = url.startsWith('http') ? url : API_BASE_URL + url;
        options.cache = 'no-store';

        let finalUrl = fullUrl;
        try {
            const urlObj = new URL(fullUrl);
            urlObj.searchParams.append('_t', Date.now());
            finalUrl = urlObj.toString();
        } catch(e){}

        // Ensure cookies are sent with every fetch request
        options.credentials = 'include';

        const response = await fetch(finalUrl, options);
        
        // Let the global fetch interceptor handle 401 redirects silently
        if (response.status === 401) {
            return { status: 'error', message: 'Session expired' };
        }
        
        if(!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        
        const json = await response.json();
        
        // Auto-logout if token is dead/unauthorized
        if (json && json.message && json.message.toLowerCase().includes('unauthorized')) {
            localStorage.clear();
            window.location.href = 'login.html';
            return { status: 'error', message: 'Session expired. Please log in again.' };
        }
        
        return json;
    } catch (e) {
        console.error('Fetch error:', e);
        showNotification(e.message, 'error');
        return { status: 'error', message: e.message };
    }
}

// ==========================================
// REAL-TIME DEVICE SYNC (SHORT POLLING)
// ==========================================
window.lastSyncId = null;
window.enableLiveSync = true;

async function checkLiveSync() {
    if (!window.enableLiveSync) return;
    
    // Only poll if not on login page
    if (window.location.pathname.endsWith('login.html') || window.location.pathname.endsWith('login')) return;
    if (!localStorage.getItem('user_role')) return; // use localStorage instead since auth_token is HttpOnly

    try {
        const response = await fetch(API_BASE_URL + 'api/sync.php?_t=' + Date.now(), {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' },
            credentials: 'include'
        });
        
        if (response.ok) {
            const data = await response.json();
            if (data && data.status === 'success') {
                if (window.lastSyncId === null) {
                    // First load, just record the latest ID
                    window.lastSyncId = data.latest_id;
                } else if (data.latest_id > window.lastSyncId) {
                    // Changes detected!
                    window.lastSyncId = data.latest_id;
                    // Dispatch global event so pages can silently reload their data
                    window.dispatchEvent(new CustomEvent('liveSyncUpdate'));
                    
                    // Show a visual indicator to the user
                    showNotification('<i class="ph ph-arrows-clockwise" style="animation: spin 1s linear infinite;"></i> Live Sync: Changes applied', 'success');
                }
            } else {
                // Server responded but with an error payload (e.g. missing audit_log table)
                console.error('Live Sync: server returned an error', data);
            }
        } else {
            // Non-2xx response (404 = old/missing sync.php on server, 401/403 = auth issue, 500 = DB error)
            console.error('Live Sync: request failed with status', response.status);
        }
    } catch (e) {
        // Network-level failure (offline, DNS, etc.) - log but don't spam notifications
        console.error('Live Sync: fetch threw an error', e);
    }
}

// Start polling every 3 seconds
setInterval(checkLiveSync, 3000);

// ==========================================
// NOTIFICATION BELL (badge count + dropdown log for payment alerts)
// ==========================================
function ensureNotificationBell() {
    if (window.location.pathname.endsWith('login.html') || window.location.pathname.endsWith('login')) return;

    let btn = document.querySelector('.notification-btn');
    const headerRight = document.querySelector('.header-right');

    if (!btn && headerRight) {
        // Page has no bell yet (only the dashboard ships with one) - add it so alerts are visible everywhere
        btn = document.createElement('button');
        btn.className = 'notification-btn';
        btn.innerHTML = `<i class="ph ph-bell"></i><span class="badge" id="global-notification-badge" style="display:none;">0</span>`;
        headerRight.appendChild(btn);
    } else if (btn) {
        // Bell already exists (e.g. dashboard) - make its badge dynamic instead of a hardcoded number
        let badge = btn.querySelector('.badge');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'badge';
            btn.appendChild(badge);
        }
        badge.id = 'global-notification-badge';
    }

    if (btn) {
        btn.id = 'global-notification-btn';
        btn.style.position = btn.style.position || 'relative';
        btn.onclick = (e) => {
            e.stopPropagation();
            showNotificationDropdown();
        };
    }

    updateNotificationBadge();
}

function updateNotificationBadge() {
    const badge = document.getElementById('global-notification-badge');
    if (!badge) return;
    const count = parseInt(localStorage.getItem('notif_unread_count') || '0');
    if (count > 0) {
        badge.textContent = count > 9 ? '9+' : String(count);
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

// Records a payment notification (matched or unallocated) so it increments the bell badge
// and shows up in the dropdown log, independent of the big popup.
function addNotification(message, type) {
    const count = parseInt(localStorage.getItem('notif_unread_count') || '0') + 1;
    localStorage.setItem('notif_unread_count', String(count));
    updateNotificationBadge();

    let log = [];
    try { log = JSON.parse(localStorage.getItem('notif_log') || '[]'); } catch (e) {}
    log.unshift({ message, type, time: Date.now() });
    log = log.slice(0, 20); // keep a rolling log of the most recent 20
    localStorage.setItem('notif_log', JSON.stringify(log));

    renderNotificationDropdown();
}

function showNotificationDropdown() {
    let dropdown = document.getElementById('global-notification-dropdown');
    if (dropdown) {
        dropdown.remove();
        return;
    }

    // Opening the dropdown marks everything as read
    localStorage.setItem('notif_unread_count', '0');
    updateNotificationBadge();

    dropdown = document.createElement('div');
    dropdown.id = 'global-notification-dropdown';
    dropdown.style.cssText = `
        position: absolute; top: 60px; right: 20px; width: 320px; max-height: 420px;
        overflow-y: auto; background: #fff; border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 999998; padding: 8px;
    `;
    document.body.appendChild(dropdown);
    renderNotificationDropdown();

    setTimeout(() => {
        document.addEventListener('click', function handler(e) {
            const dd = document.getElementById('global-notification-dropdown');
            const btn = document.getElementById('global-notification-btn');
            if (dd && !dd.contains(e.target) && e.target !== btn && !btn?.contains(e.target)) {
                dd.remove();
                document.removeEventListener('click', handler);
            }
        });
    }, 50);
}

function renderNotificationDropdown() {
    const dropdown = document.getElementById('global-notification-dropdown');
    if (!dropdown) return;

    let log = [];
    try { log = JSON.parse(localStorage.getItem('notif_log') || '[]'); } catch (e) {}

    if (!log.length) {
        dropdown.innerHTML = `<div style="padding:24px;text-align:center;color:#999;font-size:13px;">No notifications yet</div>`;
        return;
    }

    dropdown.innerHTML = log.map(n => `
        <div onclick="window.location.href='billing.html'" style="padding:10px 12px;border-bottom:1px solid #f0f0f0;font-size:13px;color:#333;cursor:pointer;" onmouseover="this.style.backgroundColor='#f9fafb'" onmouseout="this.style.backgroundColor='transparent'">
            <div style="color:${n.type === 'success' ? '#10b981' : '#f59e0b'};font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">
                ${n.type === 'success' ? '✅ Payment Matched' : '💸 Unallocated Payment'}
            </div>
            <div>${escapeHtml(n.message)}</div>
            <div style="color:#aaa;font-size:11px;margin-top:3px;">${new Date(n.time).toLocaleString()}</div>
        </div>
    `).join('');
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}


window._mpesaPopupQueue = [];
window._mpesaPopupShowing = false;
window._closeMpesaPopupFn = null;

// Public entry point: adds a notification to the queue instead of showing it immediately.
// This guarantees every payment notification is eventually seen, even if several
// M-Pesa payments arrive within the same few seconds.
function queueMpesaPopup(htmlContent, isSuccess) {
    window._mpesaPopupQueue.push({ htmlContent, isSuccess });
    processMpesaPopupQueue();
}

function processMpesaPopupQueue() {
    if (window._mpesaPopupShowing) return; // wait for current popup's 5s to finish
    const next = window._mpesaPopupQueue.shift();
    if (!next) return;
    window._mpesaPopupShowing = true;
    showBigMpesaPopup(next.htmlContent, next.isSuccess, () => {
        window._mpesaPopupShowing = false;
        processMpesaPopupQueue(); // show the next one, if any, right after this one closes
    });
}

// Called by the popup's "Close Now" button
function closeMpesaPopupNow() {
    if (window._closeMpesaPopupFn) window._closeMpesaPopupFn();
}

function showBigMpesaPopup(htmlContent, isSuccess, onClose) {
    // Only one popup shown at a time now (queue handles the rest), so clear any stale leftover
    let existing = document.getElementById('mpesa-big-popup');
    if (existing) existing.remove();

    let overlay = document.createElement('div');
    overlay.id = 'mpesa-big-popup';
    overlay.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.7); backdrop-filter: blur(5px);
        z-index: 999999; display: flex; align-items: center; justify-content: center;
        opacity: 0; transition: opacity 0.3s ease;
    `;

    const modal = document.createElement('div');
    modal.id = 'mpesa-big-modal';
    modal.style.cssText = `
        background: #fff; padding: 40px; border-radius: 16px;
        text-align: center; max-width: 500px; width: 90%;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        transform: scale(0.8); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    // Set colors based on success or warning
    const color = isSuccess ? '#10b981' : '#f59e0b';
    const icon = isSuccess ? '✅' : '💸';
    const title = isSuccess ? 'PAYMENT MATCHED!' : 'NEW UNALLOCATED PAYMENT';
    
    modal.innerHTML = `
        <div style="font-size: 60px; margin-bottom: 10px; animation: bounce 1s ease infinite;">${icon}</div>
        <h2 style="color: ${color}; margin-top: 0; font-size: 24px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;">${title}</h2>
        <div style="font-size: 18px; color: #333; line-height: 1.6; margin-bottom: 20px;">
            ${htmlContent}
        </div>
        <div style="width: 100%; height: 4px; background: #eee; border-radius: 2px; overflow: hidden; margin-top: 20px;">
            <div id="mpesa-progress-bar" style="width: 100%; height: 100%; background: ${color}; transition: width 5s linear;"></div>
        </div>
        <button onclick="closeMpesaPopupNow()" style="margin-top: 20px; padding: 8px 20px; background: #eee; border: none; border-radius: 6px; cursor: pointer; color: #666; font-weight: bold;">Close Now</button>
    `;
    
    // Animate in
    setTimeout(() => {
        overlay.style.opacity = '1';
        modal.style.transform = 'scale(1)';
        
        // Start progress bar shrink
        setTimeout(() => {
            const bar = document.getElementById('mpesa-progress-bar');
            if(bar) bar.style.width = '0%';
        }, 50);
    }, 10);
    
    const doClose = () => {
        const el = document.getElementById('mpesa-big-popup');
        if (!el) return;
        overlay.style.opacity = '0';
        modal.style.transform = 'scale(0.8)';
        setTimeout(() => {
            const el2 = document.getElementById('mpesa-big-popup');
            if (el2) el2.remove();
            window._closeMpesaPopupFn = null;
            if (onClose) onClose();
        }, 300);
    };

    window._closeMpesaPopupFn = doClose;

    // Auto-close after 5 seconds, then (via onClose) the queue shows the next notification, if any
    setTimeout(doClose, 5000);
}

// Add bounce animation style if not exists
if (!document.getElementById('mpesa-bounce-style')) {
    const style = document.createElement('style');
    style.id = 'mpesa-bounce-style';
    style.innerHTML = `
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
    `;
    document.head.appendChild(style);
}

// ==========================================
// M-PESA REAL-TIME PAYMENT POLLING
// ==========================================
window.lastUnallocId = null;
window.lastHistoryId = null;

async function checkMpesaPayments() {
    if (window.location.pathname.endsWith('login.html') || window.location.pathname.endsWith('login')) return;
    if (!localStorage.getItem('user_role')) return;
    
    // Check if the user disabled the popup in settings
    if (window.appSettings && window.appSettings.notifications_mpesa === 'false') return;

    // Initialization: if null, we just need to hit the endpoint to get the current baseline
    // But since our endpoint only returns items > id, if we send 0, we'll get EVERYTHING.
    // To prevent a flood of popups on first load, we initialize them from localStorage if they exist,
    // otherwise we need a way to just get the 'current max' without returning rows.
    // For simplicity, we'll use a trick: store the IDs in localStorage. If missing, we'll start at a very high number or rely on a new init endpoint.
    // Since we don't have an init endpoint, if it's the very first time, we'll set them to a huge number to avoid historical spam, 
    // or better yet, the server should return the max IDs when we pass a special flag.
    // Wait, let's just send `init=1` if we don't know the IDs.
    
    let isInit = false;
    if (window.lastUnallocId === null || window.lastHistoryId === null) {
        let storedU = localStorage.getItem('last_unalloc_id');
        let storedH = localStorage.getItem('last_history_id');
        if (storedU && storedH) {
            window.lastUnallocId = parseInt(storedU);
            window.lastHistoryId = parseInt(storedH);
        } else {
            // First load ever for this browser
            isInit = true;
            window.lastUnallocId = 0;
            window.lastHistoryId = 0;
        }
    }

    try {
        const response = await fetch(`${API_BASE_URL}api/billing.php?action=poll_payments&last_unalloc_id=${window.lastUnallocId}&last_history_id=${window.lastHistoryId}&_t=${Date.now()}`, {
            credentials: 'include'
        });
        
        if (response.ok) {
            const data = await response.json();
            if (data && data.status === 'success') {
                if (isInit) {
                    // Just set the max IDs, don't show popups for historical data
                    if (data.new_unallocated.length > 0) {
                        window.lastUnallocId = Math.max(...data.new_unallocated.map(i => parseInt(i.id)));
                    }
                    if (data.new_assigned.length > 0) {
                        window.lastHistoryId = Math.max(...data.new_assigned.map(i => parseInt(i.id)));
                    }
                    localStorage.setItem('last_unalloc_id', window.lastUnallocId);
                    localStorage.setItem('last_history_id', window.lastHistoryId);
                } else {
                    // Queue popups for new items so every payment gets seen, one after another
                    data.new_unallocated.forEach(payment => {
                        window.lastUnallocId = Math.max(window.lastUnallocId, parseInt(payment.id));
                        const msg = `Amount: KES ${payment.amount} — Sender: ${payment.sender_name}. Please assign this payment in the dashboard.`;
                        addNotification(msg, 'warning');
                        queueMpesaPopup(`<strong>Amount:</strong> KES ${payment.amount}<br><strong>Sender:</strong> ${payment.sender_name}<br><br><span style="font-size:14px; color:#666;">Please assign this payment in the dashboard.</span>`, false);
                    });
                    
                    data.new_assigned.forEach(payment => {
                        window.lastHistoryId = Math.max(window.lastHistoryId, parseInt(payment.id));
                        
                        const popupHtml = `
                            <strong>Student:</strong> ${payment.student_name} (${payment.class})<br>
                            <strong>Parent:</strong> ${payment.parent_name || 'N/A'}<br>
                            <strong>Paid:</strong> KES ${payment.amount}<br>
                            <button onclick="printReceipt('${payment.receipt_number}', '${payment.student_name.replace(/'/g, "\\'")}', '${payment.amount}', '${payment.class.replace(/'/g, "\\'")}', '${(payment.parent_name || '').replace(/'/g, "\\'")}')" class="btn btn-sm" style="margin-top: 15px; background: #10b981; color: white; border: none; border-radius: 8px; padding: 10px 20px; cursor: pointer; font-weight: bold; font-size: 16px; box-shadow: 0 4px 6px rgba(16,185,129,0.3);">
                                🖨️ Print Receipt
                            </button>
                        `;
                        const msg = `Payment matched — ${payment.student_name} (${payment.class}) paid KES ${payment.amount}.`;
                        addNotification(msg, 'success');
                        queueMpesaPopup(popupHtml, true);
                    });
                    
                    if (data.new_unallocated.length > 0 || data.new_assigned.length > 0) {
                        localStorage.setItem('last_unalloc_id', window.lastUnallocId);
                        localStorage.setItem('last_history_id', window.lastHistoryId);
                        // Trigger UI update if on billing page
                        window.dispatchEvent(new CustomEvent('mpesaPaymentArrived'));
                    }
                }
            }
        }
    } catch (e) {
        console.error('M-Pesa poll error', e);
    }
}

// Start M-Pesa polling every 5 seconds
setInterval(checkMpesaPayments, 5000);

// ==========================================
// RECEIPT PRINTING
// ==========================================
window.printReceipt = function(receiptNo, studentName, amount, className, parentName) {
    const printWindow = window.open('', '_blank', 'width=600,height=800');
    if (!printWindow) {
        alert("Popup blocker prevented opening the receipt. Please allow popups for this site.");
        return;
    }
    
    const dateStr = new Date().toLocaleString();
    
    printWindow.document.write(`
        <html>
        <head>
            <title>Receipt - ${receiptNo}</title>
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 40px; color: #333; }
                .receipt-box { border: 1px solid #ddd; padding: 30px; border-radius: 8px; max-width: 500px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
                .header { text-align: center; border-bottom: 2px dashed #eee; padding-bottom: 20px; margin-bottom: 20px; }
                .header h1 { margin: 0; color: #10b981; font-size: 24px; }
                .header p { margin: 5px 0 0; color: #666; font-size: 14px; }
                .row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 15px; }
                .row strong { color: #555; }
                .amount { font-size: 20px; font-weight: bold; color: #10b981; text-align: right; margin-top: 20px; border-top: 2px dashed #eee; padding-top: 20px; }
                .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #999; }
                @media print {
                    body { -webkit-print-color-adjust: exact; padding: 0; }
                    .receipt-box { border: none; box-shadow: none; }
                }
            </style>
        </head>
        <body>
            <div class="receipt-box">
                <div class="header">
                    <h1>OFFICIAL RECEIPT</h1>
                    <p>Receipt No: <strong>${receiptNo}</strong></p>
                    <p>Date: ${dateStr}</p>
                </div>
                
                <div class="row">
                    <span><strong>Student:</strong></span>
                    <span>${studentName}</span>
                </div>
                <div class="row">
                    <span><strong>Class:</strong></span>
                    <span>${className}</span>
                </div>
                <div class="row">
                    <span><strong>Received From:</strong></span>
                    <span>${parentName || 'N/A'}</span>
                </div>
                <div class="row">
                    <span><strong>Payment Method:</strong></span>
                    <span>M-Pesa</span>
                </div>
                
                <div class="amount">
                    Amount Paid: KES ${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2})}
                </div>
                
                <div class="footer">
                    <p>Thank you for your payment.</p>
                    <p>Generated by EduGate SMS System</p>
                </div>
            </div>
            <script>
                setTimeout(() => {
                    window.print();
                    window.onafterprint = function() { window.close(); }
                }, 500);
            </script>
        </body>
        </html>
    `);
    printWindow.document.close();
};
