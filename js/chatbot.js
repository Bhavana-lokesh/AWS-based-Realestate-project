(() => {
    const widget = document.querySelector('[data-chatbot]');
    if (!widget) return;

    const launch = widget.querySelector('.property-chatbot-launch');
    const panel = widget.querySelector('.property-chatbot-panel');
    const close = widget.querySelector('.property-chatbot-close');
    const messages = widget.querySelector('.property-chatbot-messages');
    const form = widget.querySelector('.property-chatbot-form');
    const input = form.querySelector('input');
    let activePropertyId = null;

    const appendMessage = (text, author, properties = [], loginRequired = false) => {
        const bubble = document.createElement('div');
        bubble.className = `property-chatbot-message ${author}`;
        if (author === 'assistant' && loginRequired) {
            const link = document.createElement('a');
            link.href = 'login.php';
            link.textContent = 'Sign in';
            bubble.append(document.createTextNode(`${text} `), link);
        } else {
            bubble.textContent = text;
        }
        messages.append(bubble);
        properties.forEach((property) => {
            const card = document.createElement('article');
            card.className = 'property-chatbot-card';
            if (property.image_url) {
                const image = document.createElement('img');
                image.src = property.image_url;
                image.alt = `Property photo: ${property.title}`;
                image.loading = 'lazy';
                card.append(image);
            }
            const body = document.createElement('div');
            body.className = 'property-chatbot-card-body';
            const title = document.createElement('strong');
            title.textContent = property.title || 'Property';
            const facts = document.createElement('p');
            const bedrooms = property.bedrooms === null ? 'BHK not listed' : `${property.bedrooms} BHK`;
            facts.textContent = `${property.location || 'Location not listed'} · ${bedrooms} · ₹${Number(property.price).toLocaleString('en-IN')}`;
            const actions = document.createElement('div');
            actions.className = 'property-chatbot-card-actions';
            const details = document.createElement('a');
            details.href = `propertydetail.php?pid=${encodeURIComponent(property.property_id)}`;
            details.textContent = 'View details';
            const visit = document.createElement('button');
            visit.type = 'button';
            visit.textContent = 'Request a visit';
            visit.addEventListener('click', () => send(`Book a visit for property ${property.title}`, property.property_id));
            const ask = document.createElement('button');
            ask.type = 'button';
            ask.textContent = 'Ask about it';
            ask.addEventListener('click', () => send('Tell me about this property', property.property_id));
            actions.append(details, visit, ask);
            body.append(title, facts, actions);
            card.append(body);
            messages.append(card);
        });
        messages.scrollTop = messages.scrollHeight;
    };

    const send = async (message, propertyId = activePropertyId) => {
        if (!message.trim()) return;
        appendMessage(message, 'user');
        input.value = '';
        const loading = document.createElement('div');
        loading.className = 'property-chatbot-message assistant property-chatbot-loading';
        loading.textContent = 'Searching the property listings…';
        messages.append(loading);
        messages.scrollTop = messages.scrollHeight;
        form.querySelector('button').disabled = true;
        try {
            const response = await fetch('chatbot_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message,
                    property_id: propertyId,
                    csrf_token: widget.dataset.csrf
                })
            });
            const result = await response.json();
            loading.remove();
            if (!response.ok) throw new Error(result.answer || 'The assistant could not complete that request.');
            appendMessage(result.answer || 'I could not find a response.', 'assistant', result.properties || [], result.login_required === true);
            if (result.properties && result.properties.length) activePropertyId = result.properties[0].property_id;
        } catch (error) {
            loading.remove();
            appendMessage(error.message || 'The assistant is temporarily unavailable. Please try again.', 'assistant');
        } finally {
            form.querySelector('button').disabled = false;
        }
    };

    launch.addEventListener('click', () => {
        panel.hidden = false;
        launch.setAttribute('aria-expanded', 'true');
        input.focus();
    });
    close.addEventListener('click', () => {
        panel.hidden = true;
        launch.setAttribute('aria-expanded', 'false');
        launch.focus();
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        send(input.value);
    });
    widget.querySelectorAll('.property-chatbot-quick-actions button').forEach((button) => {
        button.addEventListener('click', () => {
            const prompts = {
                '🔍 Find Properties': 'Find available properties',
                '🏠 Recommend Homes': 'Recommend homes based on my preferences',
                '📅 Book a Visit': 'I want to book a property visit',
                '💬 Ask About Properties': 'What can you tell me about the available properties?'
            };
            send(prompts[button.textContent.trim()] || button.textContent.trim());
        });
    });
})();
