/**
 * Prooriente WMS - Auth Logic (PIN Login)
 */

document.addEventListener('DOMContentLoaded', () => {
    
    const ui = {
        loginScreen: document.getElementById('login-screen'),
        mainApp: document.getElementById('main-app'),
        docInput: document.getElementById('doc-input'),
        loginBtn: document.getElementById('btn-login'),
        pinDots: document.querySelectorAll('.pin-dot'),
        btnText: document.getElementById('btn-text'),
        spinner: document.getElementById('btn-spinner'),
        userAvatarInitials: document.getElementById('dash-user-initials'),
        userNameDisplay: document.getElementById('dash-user-name'),
        userRolDisplay: document.getElementById('dash-user-rol')
    };

    let currentPin = '';
    const PIN_LENGTH = 4;

    // ----- Check if already logged in -----
    checkSession();

    // ----- NumPad Logic -----
    document.querySelectorAll('.num-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const val = e.currentTarget.dataset.val;
            
            if (val === 'del') {
                currentPin = currentPin.slice(0, -1);
            } else if (currentPin.length < PIN_LENGTH) {
                currentPin += val;
                // Vibrate on interaction if supported (Haptic feedback)
                if (navigator.vibrate) navigator.vibrate(20);
            }

            updatePinUI();
        });
    });

    function updatePinUI() {
        ui.pinDots.forEach((dot, index) => {
            if (index < currentPin.length) {
                dot.classList.add('filled');
            } else {
                dot.classList.remove('filled');
            }
        });

        // Auto login if PIN is 4 digits
        if (currentPin.length === PIN_LENGTH) {
            handleLogin();
        }
    }

    // ----- Login Action -----
    async function handleLogin() {
        const documento = ui.docInput.value.trim();
        if(!documento) {
            showToast('Ingrese su documento de identidad', 'error');
            ui.docInput.focus();
            currentPin = '';
            updatePinUI();
            return;
        }

        setLoading(true);

        try {
            const result = await window.api.post('/auth/login', {
                documento: documento,
                pin: currentPin,
                nit: '900000001' // Prooriente default
            });

            // Success
            window.api.setToken(result.token);
            localStorage.setItem('user_data', JSON.stringify(result.user));
            localStorage.setItem('user_permissions', JSON.stringify(result.permisos));
            
            showToast(`Bienvenido ${result.user.nombre}`, 'success');
            setTimeout(() => {
                transitionToApp(result.user);
            }, 600);

        } catch (err) {
            showToast(err.message, 'error');
            // Reset PIN
            currentPin = '';
            updatePinUI();
            if (navigator.vibrate) navigator.vibrate([50, 50, 50]); // Error haptic error pattern
        } finally {
            setLoading(false);
        }
    }

    ui.loginBtn.addEventListener('click', handleLogin);

    // ----- Helpers -----
    function setLoading(isLoading) {
        ui.loginBtn.disabled = isLoading;
        if (isLoading) {
            ui.btnText.style.display = 'none';
            ui.spinner.style.display = 'block';
        } else {
            ui.btnText.style.display = 'block';
            ui.spinner.style.display = 'none';
        }
    }

    async function checkSession() {
        const token = window.api.getToken();
        if (!token) return;

        try {
            const res = await window.api.get('/auth/me');
            localStorage.setItem('user_data', JSON.stringify(res.user));
            localStorage.setItem('user_permissions', JSON.stringify(res.permisos));
            transitionToApp(res.user);
        } catch (e) {
            // Token invalid or expired, stay on login screen
            console.log('Session expired, showing login screen');
        }
    }

    function transitionToApp(user) {
        // Hydrate App Shell Data conditionally (depending on current theme structure)
        if (ui.userNameDisplay) ui.userNameDisplay.textContent = user.nombre;
        if (ui.userRolDisplay) ui.userRolDisplay.textContent = user.rol + (user.sucursal ? ` - ${user.sucursal}` : '');
        if (ui.userAvatarInitials) ui.userAvatarInitials.textContent = user.nombre.substring(0,2).toUpperCase();
        
        // Animations
        ui.loginScreen.classList.remove('slide-up');
        ui.loginScreen.style.opacity = '0';
        setTimeout(() => {
            ui.loginScreen.style.display = 'none';
            ui.mainApp.style.display = 'flex';
            ui.mainApp.classList.add('fade-in');
            
            // Dispatch general app init event
            window.dispatchEvent(new Event('app:ready'));
        }, 300);
    }
});

// Global Toast logic
window.showToast = function(msg, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = msg;
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-20px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
