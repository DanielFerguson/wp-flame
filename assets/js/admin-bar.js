(function() {
    'use strict';
    var btn = document.getElementById('wp-admin-bar-wp-flame-trace');
    if (!btn) return;
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var nonce = window.wpFlameAdminBar && window.wpFlameAdminBar.forceTraceNonce;
        if (!nonce) return;
        document.cookie = 'wp_flame_force_trace=' + encodeURIComponent(nonce) + ';path=/;Max-Age=300;SameSite=Strict' + (location.protocol === 'https:' ? ';Secure' : '');
        location.reload();
    });
})();
