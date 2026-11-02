document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('login-form');
    const errorMsg = document.getElementById('error-message');
    const submitBtn = document.getElementById('submit-btn');

    // Always ping verify to check if cookie is valid
    fetch(API_BASE_URL + 'api/auth.php?action=verify', {
        method: 'POST'
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            window.location.href = 'index.html';
        } else {
            localStorage.removeItem('user_role');
            localStorage.removeItem('user_permissions');
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;
        
        errorMsg.style.display = 'none';
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = 'Signing in...';
        submitBtn.disabled = true;

        try {
            const fd = new FormData();
            fd.append('username', username);
            fd.append('password', password);

            const res = await fetch(API_BASE_URL + 'api/auth.php?action=login', {
                method: 'POST',
                body: fd
            });

            const data = await res.json();

            if (data.status === 'success') {
                // Save auth data
                // Token is now stored in HttpOnly cookie by the server
                localStorage.setItem('user_role', data.user.role);
                localStorage.setItem('user_permissions', data.user.permissions);
                
                // Redirect to dashboard
                window.location.href = 'index.html';
            } else {
                errorMsg.textContent = data.message || 'Invalid credentials';
                errorMsg.style.display = 'block';
            }
        } catch (err) {
            errorMsg.textContent = 'Connection error. Please try again.';
            errorMsg.style.display = 'block';
            console.error(err);
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    });
});
