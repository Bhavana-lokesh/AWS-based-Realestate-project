<style>
/* === Global Sticky Footer & Layout Normalization === */
html {
    height: 100%;
}
body {
    min-height: 100%;
    min-height: 100vh;
    display: flex !important;
    flex-direction: column !important;
    margin: 0;
}
#page-wrapper {
    display: flex !important;
    flex-direction: column !important;
    min-height: 100vh !important;
    width: 100% !important;
    max-width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    box-sizing: border-box !important;
    flex: 1 0 auto !important;
}
#page-wrapper > .row {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 0 auto !important;
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}
#page-wrapper > .row > *:not(.property-chatbot) {
    width: 100% !important;
    max-width: 100% !important;
    flex-shrink: 0;
}
body > main,
body > .market-section,
body > .market-auth-layout,
body > .market-dashboard,
body > .market-detail-layout,
body > .full-row,
#page-wrapper > .row > .full-row,
#page-wrapper > .row > .profile-page-wrapper,
#page-wrapper > .row > main,
#page-wrapper > .full-row,
#page-wrapper > main {
    flex: 1 0 auto !important;
}

/* === Universal Market Footer Styling === */
footer.market-footer,
.market-footer {
    margin-top: auto !important;
    width: 100% !important;
    max-width: 100% !important;
    background: #172a29 !important;
    color: #dce6e2 !important;
    padding: 0 !important;
    border-top: none !important;
    box-sizing: border-box !important;
    position: relative !important;
    z-index: 99 !important;
    flex-shrink: 0 !important;
}
.market-footer-inner {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) auto !important;
    max-width: 1200px !important;
    gap: 22px 48px !important;
    margin: 0 auto !important;
    padding: 42px 24px 24px !important;
    box-sizing: border-box !important;
    width: 100% !important;
}
.market-footer-brand {
    color: #ffffff !important;
    font-family: 'Comfortaa', 'Muli', Georgia, sans-serif !important;
    font-size: 1.35rem !important;
    font-weight: 700 !important;
    text-decoration: none !important;
    display: inline-block !important;
    letter-spacing: 0.5px !important;
}
.market-footer-brand:hover {
    color: #b5dfd0 !important;
    text-decoration: none !important;
}
.market-footer-inner > div p {
    max-width: 500px !important;
    margin: 10px 0 0 !important;
    color: #c4d2cd !important;
    line-height: 1.6 !important;
    font-size: 0.92rem !important;
    font-family: 'Muli', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
.market-footer-links {
    display: flex !important;
    align-items: center !important;
    gap: 22px !important;
}
.market-footer-links a {
    color: #edf4f1 !important;
    text-decoration: none !important;
    font-size: 0.92rem !important;
    font-weight: 500 !important;
    font-family: 'Muli', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    transition: color 0.2s ease !important;
}
.market-footer-links a:hover {
    color: #b5dfd0 !important;
    text-decoration: none !important;
}
.market-footer-copyright {
    grid-column: 1 / -1 !important;
    margin: 0 !important;
    padding-top: 18px !important;
    border-top: 1px solid rgba(220, 230, 226, 0.2) !important;
    color: #b7c6c1 !important;
    font-size: 0.82rem !important;
    font-family: 'Muli', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
@media (max-width: 767px) {
    .market-footer-inner {
        grid-template-columns: 1fr !important;
        padding: 32px 20px 22px !important;
        gap: 20px !important;
    }
    .market-footer-links {
        flex-wrap: wrap !important;
        gap: 14px 20px !important;
    }
}
</style>

<footer class="market-footer">
    <div class="market-footer-inner">
        <div>
            <a class="market-footer-brand" href="index.php">EstateScope</a>
            <p>Browse available sale and rental listings, then review each property before contacting its owner.</p>
        </div>
        <nav class="market-footer-links" aria-label="Footer navigation">
            <a href="index.php">Home</a>
            <a href="property.php">Properties</a>
            <a href="about.php">About</a>
            <a href="contact.php">Contact</a>
        </nav>
        <p class="market-footer-copyright">&copy; <?= date('Y') ?> EstateScope</p>
    </div>
</footer>
<?php include __DIR__ . '/chatbot-widget.php'; ?>
