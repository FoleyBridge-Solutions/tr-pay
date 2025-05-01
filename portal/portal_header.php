<!doctype html>

<html lang="en" class="light-style layout-menu-fixed layout-compact" dir="ltr" data-theme="theme-default" data-assets-path="/includes/assets/" data-template="horizontal-menu-template">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />

    <title>TWE Tech</title>

    <meta name="description" content="" />

    <link rel="manifest" href="/manifest.json">

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/includes/assets/img/favicon/favicon.ico" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet" />

    <!-- Icons -->
    <link rel="stylesheet" href="/includes/assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="/includes/assets/vendor/fonts/fontawesome.css" />
    <link rel="stylesheet" href="/includes/assets/vendor/fonts/flag-icons.css" />

    <!-- Core CSS -->
    <link rel="stylesheet" href="/includes/assets/vendor/css/rtl/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="/includes/assets/vendor/css/rtl/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="/includes/assets/css/demo.css" />

    <!-- Vendors CSS -->
    <link rel="stylesheet" href="/includes/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="/includes/assets/vendor/libs/typeahead-js/typeahead.css" />
    <link rel="stylesheet" href="/includes/assets/vendor/libs/apex-charts/apex-charts.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.3/css/dataTables.bootstrap5.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/3.0.1/css/responsive.bootstrap5.css" />
    <link rel="stylesheet" href="/includes/assets/vendor/libs/spinkit/spinkit.css" />
    <link rel="stylesheet" href="/includes/assets/vendor/libs/toastr/toastr.css" />
    <link rel="stylesheet" href="/includes/assets/vendor/libs/apex-charts/apex-charts.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.css" integrity="sha512-3pIirOrwegjM6erE5gPSwkUzO+3cTjpnV9lexlNZqvupR64iZBnOOTiiLPb9M36zpMScbmUNIcHUqKD47M719g==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js" integrity="sha512-VEd+nq25CkR676O+pLBnDW09R7VQX9Mdiij052gVCp5yVH3jGtH70Ho/UUv4mJDsEdTvqRCFZg0NKGiojGnUCw==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>


    <!-- Helpers -->
    <script src="/includes/assets/vendor/js/helpers.js"></script>
    <!--! Template customizer & Theme config files MUST be included after core stylesheets and helpers.js in the <head> section -->
    <!--? Template customizer: To hide customizer set displayCustomizer value false in config.js.  -->
    <script src="/includes/assets/vendor/js/template-customizer.js"></script>
    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->
    <script src="/includes/assets/js/config.js"></script>

    <!-- TinyMCE -->
    <script src="https://cdn.tiny.cloud/1/aq84ecg358zq9b4i9ea6hjaxqpx4mirbbtm7h5khkwevpqac/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script></head>

</head>

<!-- Navbar -->

<nav class="navbar navbar-expand-lg navbar-dark bg-dark d-print-none">
    <div class="container">
        <a class="navbar-brand" href="index.php"><?= nullable_htmlentities($company_name); ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav mr-auto">
                <li class="nav-item <?php if (basename($_SERVER['PHP_SELF']) == "index.php") {echo "active";} ?>">
                    <a class="nav-link" href="index.php"><i class="fas fa-fw fa-home mr-2"></i>Home</a>
                </li>
                <li class="nav-item <?php if (basename($_SERVER['PHP_SELF']) == "store.php") {echo "active";} ?>">
                    <a class="nav-link" href="store.php"><i class="fas fa-fw fa-store mr-2"></i>Store</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-fw fa-life-ring mr-2"></i>
                        Support
                    </a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="tickets.php"><i class="fas fa-fw fa-ticket-alt mr-2"></i>Tickets</a>
                            <?php if ($contact_primary == 1 || $contact_is_technical_contact) { ?>
                                <a class="dropdown-item" href="documents.php"><i class="fas fa-fw fa-file-alt mr-2"></i>Documents</a>
                                <a class="dropdown-item" href="assets.php"><i class="fas fa-fw fa-cogs mr-2"></i>Assets</a>
                        <?php } ?>
                    </div>
                </li>

                
                <?php if (($contact_primary == 1 || $contact_is_billing_contact) && $config_module_enable_accounting == 1) { ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-fw fa-dollar-sign mr-2"></i>
                            Accounting
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="guest_view_statement.php"><i class="fas fa-fw fa-file-alt mr-2"></i>Statement</a>
                            <a class="dropdown-item" href="subscriptions.php"><i class="fas fa-fw fa-cogs mr-2"></i>Subscriptions</a>
                            <a class="dropdown-item" href="invoices.php"><i class="fas fa-fw fa-file-invoice mr-2"></i>Invoices</a>
                            <a class="dropdown-item" href="quotes.php"><i class="fas fa-fw fa-file-alt mr-2"></i>Quotes</a>
                <?php } ?>
            </ul>

            <ul class="nav navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="referrals.php"><i class="fas fa-fw fa-users mr-2"></i> Referrals</a>
                </li>
            </ul>

            <ul class="nav navbar-nav pull-right">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <?= stripslashes(nullable_htmlentities($contact_name)); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="profile.php"><i class="fas fa-fw fa-user mr-2"></i>Account</a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="portal_post.php?logout"><i class="fas fa-fw fa-sign-out-alt mr-2"></i>Sign out</a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>

<br>

<!-- Page content container -->
<div class="container">
