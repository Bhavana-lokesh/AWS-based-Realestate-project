<?php
/**
 * Copy this file to secrets.php and fill in your own values.
 * secrets.php is git-ignored and must NEVER be committed.
 */

// Stripe (https://dashboard.stripe.com/apikeys)
define('STRIPE_SECRET_KEY', 'sk_test_your_stripe_secret_key_here');

// Google Gemini (https://aistudio.google.com/app/apikey)
define('GEMINI_API_KEY', 'your_gemini_api_key_here');

// Gmail SMTP (use a Gmail "App Password", not your real password)
define('MAIL_USERNAME', 'your_email@gmail.com');
define('MAIL_APP_PASSWORD', 'your_16_char_app_password');
