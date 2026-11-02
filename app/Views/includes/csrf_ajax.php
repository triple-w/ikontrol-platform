<script>

    var data = {};
    data[AppHelper.csrfTokenName] = AppHelper.csrfHash;
    $.ajaxSetup({
        data: data
    });
</script>
