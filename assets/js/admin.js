(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.getAttribute) {
            return;
        }

        var message = target.getAttribute('data-wp-flame-confirm');
        if (!message) {
            return;
        }

        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    var notices = document.querySelectorAll('.notice.notice-success.is-dismissible');
    for (var i = 0; i < notices.length; i++) {
        (function (notice) {
            setTimeout(function () {
                var btn = notice.querySelector('.notice-dismiss');
                if (btn) btn.click();
            }, 3000);
        })(notices[i]);
    }
})();
