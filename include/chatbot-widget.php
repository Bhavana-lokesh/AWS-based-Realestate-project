<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../auth.php';
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$chatbotRole = currentUserRole();
if (currentUserId() > 0 && $chatbotRole !== 'buyer') {
    return;
}
?>
<link rel="stylesheet" href="css/chatbot.css">
<div class="property-chatbot" data-chatbot data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <button class="property-chatbot-launch" type="button" aria-label="Open AI Property Assistant" aria-expanded="false">
        <span aria-hidden="true">✦</span><span class="property-chatbot-launch-label">Ask about homes</span>
    </button>
    <section class="property-chatbot-panel" aria-label="AI Property Assistant" hidden>
        <header class="property-chatbot-header">
            <div><strong>AI Property Assistant</strong><small>Property search and visit requests</small></div>
            <button type="button" class="property-chatbot-close" aria-label="Minimize chat">−</button>
        </header>
        <div class="property-chatbot-messages" aria-live="polite">
            <div class="property-chatbot-message assistant">Hi! 👋 I'm your AI Property Assistant. I can help you find properties, recommend homes, compare listings, and schedule property visits. How can I help you today?</div>
        </div>
        <div class="property-chatbot-quick-actions" aria-label="Suggested questions">
            <button type="button">🔍 Find Properties</button>
            <button type="button">🏠 Recommend Homes</button>
            <button type="button">📅 Book a Visit</button>
            <button type="button">💬 Ask About Properties</button>
        </div>
        <form class="property-chatbot-form">
            <label class="sr-only" for="property-chatbot-input">Message the property assistant</label>
            <input id="property-chatbot-input" maxlength="500" autocomplete="off" placeholder="Tell me what you're looking for…" required>
            <button type="submit">Send</button>
        </form>
    </section>
</div>
<script src="js/chatbot.js" defer></script>
