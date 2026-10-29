<?php 
ini_set('session.cache_limiter','public');
session_cache_limiter(false);
session_start();
include("config.php");
								
?>
<!DOCTYPE html>
<html lang="en">

<head>
<!-- FOR MORE PROJECTS visit: codeastro.com -->
<!-- Required meta tags -->
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

<!-- Meta Tags -->
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<link rel="shortcut icon" href="images/favicon.ico">

<!--	Fonts
	========================================================-->
<link href="https://fonts.googleapis.com/css?family=Muli:400,400i,500,600,700&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css?family=Comfortaa:400,700" rel="stylesheet">

<!--	Css Link
	========================================================-->
<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
<link rel="stylesheet" type="text/css" href="css/bootstrap-slider.css">
<link rel="stylesheet" type="text/css" href="css/jquery-ui.css">
<link rel="stylesheet" type="text/css" href="css/layerslider.css">
<link rel="stylesheet" type="text/css" href="css/color.css" id="color-change">
<link rel="stylesheet" type="text/css" href="css/owl.carousel.min.css">
<link rel="stylesheet" type="text/css" href="css/font-awesome.min.css">
<link rel="stylesheet" type="text/css" href="fonts/flaticon/flaticon.css">
<link rel="stylesheet" type="text/css" href="css/style.css">
<link rel="stylesheet" type="text/css" href="css/marketplace.css">

<!--	Title
	=========================================================-->
<title>EstateScope | Property listings</title>
</head>
<body class="market-page">

<!--	Page Loader  -->
<!--<div class="page-loader position-fixed z-index-9999 w-100 bg-white vh-100">
	<div class="d-flex justify-content-center y-middle position-relative">
	  <div class="spinner-border" role="status">
		<span class="sr-only">Loading...</span>
	  </div>
	</div>
</div>  -->
<!--	Page Loader  -->

<div id="page-wrapper">
        <!--	Header start  -->
		<?php include("include/header.php");?>
        <!--	Header end  -->
		
        <?php
        $listingCounts = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) AS total, SUM(property_type = 'Sale') AS sales, SUM(property_type = 'Rental') AS rentals FROM property_listings p WHERE p.status = 'available' AND p.review_status = 'approved' AND EXISTS (SELECT 1 FROM user owner WHERE owner.user_id = p.seller_id AND LOWER(owner.role) IN ('agent', 'seller') AND owner.is_active = 1)"));
        $popularLocations = mysqli_query($con, "SELECT p.location, COUNT(*) AS listing_count FROM property_listings p WHERE p.status = 'available' AND p.review_status = 'approved' AND p.location IS NOT NULL AND p.location <> '' AND EXISTS (SELECT 1 FROM user owner WHERE owner.user_id = p.seller_id AND LOWER(owner.role) IN ('agent', 'seller') AND owner.is_active = 1) GROUP BY p.location ORDER BY listing_count DESC, p.location ASC LIMIT 6");
        ?>
        <section class="market-hero">
            <img class="market-hero-image" src="images/banner/rshmpg.jpg" alt="Contemporary homes at dusk">
            <div class="market-hero-shade" aria-hidden="true"></div>
            <div class="market-hero-content">
                <p class="market-eyebrow">Homes for sale and rent</p>
                <h1>Find a place that feels like yours.</h1>
                <p class="market-hero-copy">Explore available properties, compare the details, and contact the listing owner when you are ready.</p>
                <form class="market-search" method="get" action="property.php">
                    <div>
                        <label for="search-type">Listing type</label>
                        <select id="search-type" class="form-control" name="type">
                            <option value="">All types</option>
                            <option value="Sale">For sale</option>
                            <option value="Rental">For rent</option>
                        </select>
                    </div>
                    <div>
                        <label for="search-city">City or area</label>
                        <input id="search-city" type="text" class="form-control" name="city" placeholder="Enter a location" required>
                    </div>
                    <div>
                        <label for="search-min-price">Minimum budget</label>
                        <input id="search-min-price" type="number" class="form-control" name="min_price" min="0" step="100000" placeholder="₹ Any">
                    </div>
                    <div>
                        <label for="search-max-price">Maximum budget</label>
                        <input id="search-max-price" type="number" class="form-control" name="max_price" min="0" step="100000" placeholder="₹ Any">
                    </div>
                    <button class="btn" type="submit" name="filter">Search properties</button>
                </form>
                <p class="market-hero-count"><?= (int)($listingCounts['total'] ?? 0) ?> available listings in the database</p>
            </div>
        </section>
        
		<section class="market-explore-band">
            <div class="market-section">
                <div class="market-section-heading">
                    <div>
                        <h2>Explore the current inventory</h2>
                        <p>Counts are calculated from available property records.</p>
                    </div>
                    <a class="market-text-link" href="property.php">Browse all listings</a>
                </div>
                <div class="market-explore-links">
                    <a class="market-explore-link" href="property.php?type=Sale">Explore homes for sale <strong><?= (int)($listingCounts['sales'] ?? 0) ?></strong></a>
                    <a class="market-explore-link" href="property.php?type=Rental">Find a rental <strong><?= (int)($listingCounts['rentals'] ?? 0) ?></strong></a>
                    <a class="market-explore-link" href="property.php">Browse all listings <strong><?= (int)($listingCounts['total'] ?? 0) ?></strong></a>
                </div>
            </div>
        </section>

        <?php if ($popularLocations && mysqli_num_rows($popularLocations) > 0): ?>
        <section class="market-section market-localities">
            <div class="market-section-heading">
                <div>
                    <p class="market-eyebrow">Start somewhere familiar</p>
                    <h2>Explore by location</h2>
                    <p>Popular areas from the currently available listings.</p>
                </div>
            </div>
            <div class="market-locality-grid">
                <?php while ($location = mysqli_fetch_assoc($popularLocations)): ?>
                    <a class="market-locality-link" href="property.php?<?= htmlspecialchars(http_build_query(['city' => $location['location']]), ENT_QUOTES, 'UTF-8') ?>">
                        <span><i class="fa fa-map-marker" aria-hidden="true"></i></span>
                        <span><strong><?= htmlspecialchars($location['location'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= (int)$location['listing_count'] ?> available <?= (int)$location['listing_count'] === 1 ? 'property' : 'properties' ?></small></span>
                        <i class="fa fa-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php endwhile; ?>
            </div>
        </section>
        <?php endif; ?>
		
        <!--	Recent Properties  -->
<div class="full-row">
    <div class="container">
        <div class="row">
            <div class="col-md-12">
                <div class="market-section-heading">
                    <div>
                        <h2>Recently listed</h2>
                        <p>Latest available properties from the listings database.</p>
                    </div>
                    <a class="market-text-link" href="property.php">View all properties</a>
                </div>
            </div>
            <div class="col-md-12">
                <div class="tab-content mt-4">
                    <div class="tab-pane fade show active">
                        <?php
                        $query = mysqli_query($con, "
                            SELECT pl.property_id, pl.title, pl.price, pl.location, pl.property_type,
                                   pl.bedrooms, pl.bathrooms, pl.size_sqft, pl.created_at,
                                   (SELECT image_url FROM property_image WHERE property_id = pl.property_id LIMIT 1) AS image
                            FROM property_listings pl
                            JOIN user owner ON owner.user_id = pl.seller_id
                            WHERE pl.status = 'available' AND pl.review_status = 'approved'
                                AND LOWER(owner.role) IN ('agent', 'seller') AND owner.is_active = 1
                            ORDER BY pl.created_at DESC
                            LIMIT 9
                        ");
                        ?>
                        <div class="market-listing-grid">
                            <?php if ($query && mysqli_num_rows($query) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($query)): ?>
                                    <article class="market-listing-card">
                                        <?php if (!empty($row['image'])): ?>
                                            <img class="market-card-image" src="admin/property/<?= htmlspecialchars(basename($row['image']), ENT_QUOTES, 'UTF-8') ?>" alt="Property photo for <?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?php else: ?>
                                            <div class="market-no-image">No photos uploaded</div>
                                        <?php endif; ?>
                                        <div class="market-card-body">
                                            <span class="market-card-type"><?= htmlspecialchars($row['property_type'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <h3 class="market-card-title"><a href="propertydetail.php?pid=<?= (int)$row['property_id'] ?>"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></a></h3>
                                            <p class="market-card-location"><?= htmlspecialchars($row['location'] ?? 'Location not listed', ENT_QUOTES, 'UTF-8') ?></p>
                                            <div class="market-card-price">₹<?= number_format((float)$row['price']) ?></div>
                                            <div class="market-card-specs">
                                                <?php if ((int)$row['bedrooms'] > 0): ?><span><?= (int)$row['bedrooms'] ?> beds</span><?php endif; ?>
                                                <?php if ((int)$row['bathrooms'] > 0): ?><span><?= (int)$row['bathrooms'] ?> baths</span><?php endif; ?>
                                                <span><?= number_format((int)$row['size_sqft']) ?> sqft</span>
                                            </div>
                                            <a class="market-text-link" href="propertydetail.php?pid=<?= (int)$row['property_id'] ?>">View Details</a>
                                        </div>
                                    </article>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="market-empty-state">No available properties are listed right now.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div><!-- Recent Properties -->

        
        <section class="market-how-it-works">
            <div class="market-section">
                <div class="market-section-heading">
                    <div>
                        <p class="market-eyebrow">A simpler way to move</p>
                        <h2>From first look to next steps</h2>
                        <p>Explore live listings and use the platform's existing tools to keep your search moving.</p>
                    </div>
                    <a class="market-text-link" href="property.php">Explore properties</a>
                </div>
                <div class="market-steps-grid">
                    <article><span>01</span><h3>Find your shortlist</h3><p>Search by location, budget, and sale or rental availability.</p></article>
                    <article><span>02</span><h3>Compare the details</h3><p>Review property photos, specifications, and available features.</p></article>
                    <article><span>03</span><h3>Connect directly</h3><p>Sign in to save a property, send an inquiry, or request a visit.</p></article>
                </div>
            </div>
        </section>
        
      
        <!--	Footer   start-->
		<?php include("include/footer.php");?>
		<!--	Footer   start-->
    </div>
<!-- Wrapper End --> 


</body>
</html>