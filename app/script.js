// Simple mock routing to switch between UI screens
function navigateTo(screenId) {
    // Hide all screens
    const screens = document.querySelectorAll('.screen');
    screens.forEach(screen => {
        screen.classList.remove('active');
    });

    // Show target screen
    const target = document.getElementById(screenId + '-screen');
    if (target) {
        target.classList.add('active');
    }
}

// Simulate login and reveal the sidebar navigation
function login() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.remove('hidden');
    navigateTo('intake'); // Default landing page after login
}

// Simulate logout
function logout() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.add('hidden');
    navigateTo('login');
    
    // Clear login inputs
    document.getElementById('username').value = '';
    document.getElementById('password').value = '';
}

// Tab switching logic for Active Batches
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', function() {
        // Remove active class from all tabs
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        // Add to clicked tab
        this.classList.add('active');
    });
});