(function () {
    'use strict';

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
