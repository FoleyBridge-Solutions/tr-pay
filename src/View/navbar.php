<?php
$menuItems = [

];

// Render Nav menu
function renderMenu($menuItems)
{
    $html = '<ul class="navbar-nav me-auto mb-2 mb-lg-0">';
    foreach ($menuItems as $item) {
        if (isset($item['children'])) {
            $html .= '<li class="nav-item dropdown">';
            $html .= '<a class="nav-link dropdown-toggle" href="#" id="navbarDropdown' . $item['title'] . '" role="button" data-bs-toggle="dropdown" aria-expanded="false">' . $item['title'] . '</a>';
            $html .= renderSubMenu($item['children'], 'dropdown-menu');
            $html .= '</li>';
        } else {
            $html .= '<li class="nav-item">';
            $html .= '<a class="nav-link" href="?page=' . $item['link'] . '">' . $item['title'] . '</a>';
            $html .= '</li>';
        }
    }
    $html .= '</ul>';
    echo $html;
}

function renderSubMenu($menuItems, $class)
{
    $html = '<ul class="' . $class . '" aria-labelledby="navbarDropdown">';
    foreach ($menuItems as $item) {
        if (isset($item['children'])) {
            $html .= '<li class="dropdown-submenu">';
            $html .= '<a class="dropdown-item dropdown-toggle" href="#">' . $item['title'] . '</a>';
            $html .= renderSubMenu($item['children'], 'dropdown-menu');
            $html .= '</li>';
        } else {
            $html .= '<li><a class="dropdown-item" href="?page=' . $item['link'] . '">' . $item['title'] . '</a></li>';
        }
    }
    $html .= '</ul>';
    return $html;
}
?>

<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <div class="container-fluid">
        <a class="navbar-brand" href="/">Burkhart Peterson Payment Gateway</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <?php renderMenu($menuItems); ?>
        </div>
    </div>
</nav>

<?php if (isset($_SESSION['alert_message'])): ?>
    <div class="alert alert-<?= $_SESSION['alert_type'] ?> alert-dismissible fade show" role="alert">
        <?= $_SESSION['alert_message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['alert_message'], $_SESSION['alert_type']); ?>
<?php endif; ?>