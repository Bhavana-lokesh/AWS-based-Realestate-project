<?php
require_once __DIR__ . '/../auth.php';
$navigationRole = currentUserRole();
?>
<link rel="stylesheet" href="css/role-themes.css">
<link rel="stylesheet" href="css/marketplace.css">
<link rel="stylesheet" href="css/premium.css?v=2">
<link rel="stylesheet" href="css/font-awesome.min.css">
<script>
    document.body.dataset.role = <?php echo json_encode($navigationRole); ?> || document.body.dataset.role || 'buyer';
    document.body.classList.add('market-page');
</script>
<header id="header" class="transparent-header-modern fixed-header-bg-white w-100">
    <div class="main-nav secondary-nav site-navigation">
        <div class="container">
            <nav class="navbar navbar-light site-navbar" aria-label="Main navigation">
                <a class="navbar-brand estate-brand" href="index.php" aria-label="EstateScope home">
                    <span class="estate-brand-mark" aria-hidden="true">E</span>
                    <span class="estate-brand-name">Estate<span>Scope</span></span>
                </a>
                <div class="site-navigation-content" id="siteNavigation">
                    <ul class="navbar-nav me-auto">
                        <?php if ($navigationRole === 'agent' && isset($_SESSION['uid'])): ?>
                            <li class="nav-item"><a class="nav-link" href="agent_dashboard.php">Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="my_properties.php">My listings</a></li>
                            <li class="nav-item"><a class="nav-link" href="agent_dashboard.php#activity">Client activity</a></li>
                            <li class="nav-item"><a class="nav-link" href="property.php">Marketplace</a></li>
                            <li class="nav-item"><a class="nav-link" href="submitproperty.php">Add property</a></li>
                        <?php else: ?>
                            <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                            <li class="nav-item"><a class="nav-link" href="property.php">Properties</a></li>
                            <?php if (isset($_SESSION['uid']) && $navigationRole === 'super_admin'): ?>
                                <li class="nav-item"><a class="nav-link" href="admin/dashboard.php">Dashboard</a></li>
                            <?php endif; ?>
                            <?php if (isset($_SESSION['uid']) && $navigationRole === 'buyer'): ?>
                                <li class="nav-item"><a class="nav-link" href="favorites.php">Favorites</a></li>
                                <li class="nav-item"><a class="nav-link" href="my_offers.php">My offers</a></li>
                                <li class="nav-item"><a class="nav-link" href="Bought_Propertyes.php">Transactions</a></li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>

                    <?php if ($navigationRole !== 'agent'): ?>
                        <form class="site-nav-search" method="get" action="property.php" role="search">
                            <label class="sr-only" for="site-nav-city">Search location</label>
                            <input id="site-nav-city" type="search" name="city" placeholder="Search a city or area">
                            <button type="submit" aria-label="Search properties"><i class="fa fa-search" aria-hidden="true"></i></button>
                        </form>
                    <?php endif; ?>
                    <div class="site-nav-actions">
                        <?php if (isset($_SESSION['uid']) && isset($_SESSION['role'])): ?>
                            <a class="site-nav-signin" href="profile.php">My account</a>
                            <a class="btn site-nav-account" href="logout.php">Sign out</a>
                        <?php else: ?>
                            <a class="site-nav-signin" href="login.php">Sign in</a>
                            <a class="btn site-nav-primary" href="register.php">Create account</a>
                        <?php endif; ?>
                    </div>
                </div>
            </nav>
        </div>
    </div>
</header>
