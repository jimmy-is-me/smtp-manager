jQuery(function ($) {
    // 顯示/隱藏 Client Secret
    $('.gmail-oc-toggle-secret').on('click', function () {
        var $input = $('#client_secret');
        var isPass = $input.attr('type') === 'password';
        $input.attr('type', isPass ? 'text' : 'password');
        $(this).text(isPass ? '隱藏' : '顯示');
    });
});
