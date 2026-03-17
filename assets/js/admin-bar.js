(function() {
    'use strict';
    var btn = document.getElementById('wp-admin-bar-wp-flame-trace');
    if (!btn) return;
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        document.cookie = 'wp_flame_force_trace=1;path=/;SameSite=Strict' + (location.protocol === 'https:' ? ';Secure' : '');
        location.reload();
    });
})();
