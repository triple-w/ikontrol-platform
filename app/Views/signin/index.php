<!DOCTYPE html>
<html lang="en">
    <head>
        <?php echo view('includes/head'); ?>
    </head>
    <body class="public-view signin-page">
        <?php if (config('Fiscal')->labMode) { ?>
            <div id="ikontrol-rc1-lab-banner" role="alert" style="position:fixed;z-index:20000;left:0;right:0;top:0;background:#8b0000;color:#fff;text-align:center;font-weight:700;padding:6px 12px;letter-spacing:.03em;">
                iKONTROL 1.1.6 RC1 — ENTORNO DE PRUEBAS: SIN VALIDEZ FISCAL
            </div>
            <style>body{padding-top:34px!important}</style>
        <?php } ?>
        <?php
        if (get_setting("show_background_image_in_signin_page") === "yes") {
            $background_url = get_file_from_setting("signin_page_background");
            ?>
            <style type="text/css">
                html, body {
                    background-image: url('<?php echo $background_url; ?>');
                    background-size:cover;
                }
            </style>
        <?php } ?>

        <div class="scrollable-page">
            <div class="form-signin">
                <?php
                if (isset($form_type) && $form_type == "request_reset_password") {
                    echo view("signin/reset_password_form");
                } else if (isset($form_type) && $form_type == "new_password") {
                    echo view('signin/new_password_form');
                } else {
                    echo view("signin/signin_form");
                }
                ?>
            </div>
        </div>

        <script>
            $(document).ready(function () {
                initScrollbar('.scrollable-page', {
                    setHeight: $(window).height() - 50
                });
            });
        </script>

        <?php echo view("includes/footer"); ?>
    </body>
</html>
