(function() {
    'use strict';
    function bind(id, mode) {
        var btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var nonce = window.wpFlameAdminBar && window.wpFlameAdminBar.forceTraceNonce;
            if (!nonce) return;
            var attributes = ';path=/;Max-Age=300;SameSite=Strict' + (location.protocol === 'https:' ? ';Secure' : '');
            document.cookie = 'wp_flame_force_trace=' + encodeURIComponent(nonce) + attributes;
            document.cookie = 'wp_flame_force_mode=' + encodeURIComponent(mode) + attributes;
            location.reload();
        });
    }

    bind('wp-admin-bar-wp-flame-trace', 'standard');
    bind('wp-admin-bar-wp-flame-trace-deep', 'deep');
})();
