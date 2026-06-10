/* =============================================================================
   Lucky Copilot Vitrine - widget JavaScript
   A inclure sur les pages de guestlucky.com :
     <link rel="stylesheet" href="/chat/assets/widget.css">
     <script src="/chat/assets/widget.js" data-chat-url="/chat/chat.php"></script>
   ============================================================================= */

(function () {
    'use strict';

    var script = document.currentScript ||
        document.querySelector('script[src*="widget.js"]');
    var CHAT_URL = (script && script.getAttribute('data-chat-url')) || 'chat.php';

    var SUGGESTIONS = [
        "C'est quoi GuestLucky en 2 mots ?",
        "Combien ca coute ?",
        "Comment connecter mon annonce Airbnb ?",
        "C'est compatible avec Booking.com ?"
    ];

    var history = []; // [{role: 'user'|'assistant', text: '...'}]
    var sending = false;

    // -------- DOM --------

    function createWidget() {
        var root = document.createElement('div');
        root.id = 'glcv-root';

        root.innerHTML = ''
            + '<button id="glcv-bubble" aria-label="Ouvrir le chat Lucky Copilot"></button>'
            + '<div id="glcv-panel" role="dialog" aria-label="Lucky Copilot">'
            + '  <div id="glcv-header">'
            + '    <div id="glcv-header-avatar"></div>'
            + '    <div id="glcv-header-text">'
            + '      <div id="glcv-header-title">Lucky Copilot</div>'
            + '      <div id="glcv-header-status">En ligne</div>'
            + '    </div>'
            + '    <button id="glcv-close" aria-label="Fermer">×</button>'
            + '  </div>'
            + '  <div id="glcv-messages"></div>'
            + '  <div id="glcv-input-row">'
            + '    <textarea id="glcv-input" rows="1" placeholder="Pose ta question..."></textarea>'
            + '    <button id="glcv-send" aria-label="Envoyer">➤</button>'
            + '  </div>'
            + '  <div id="glcv-footer">Propulse par <a href="https://guestlucky.com" target="_blank" rel="noopener">GuestLucky</a></div>'
            + '</div>';

        document.body.appendChild(root);
        renderWelcome();
        bindEvents();
    }

    function renderWelcome() {
        var messages = document.getElementById('glcv-messages');
        var html = ''
            + '<div class="glcv-welcome">'
            + '  <strong>Hello !</strong>'
            + '  Je suis Lucky Copilot, l\'assistant IA de GuestLucky. Je suis la pour repondre a tes questions sur l\'outil, les fonctionnalites, les tarifs.'
            + '  <div class="glcv-suggestions">'
            + SUGGESTIONS.map(function (s) {
                return '<button class="glcv-suggestion" data-q="' +
                    s.replace(/"/g, '&quot;') + '">' + s + '</button>';
            }).join('')
            + '  </div>'
            + '</div>';
        messages.innerHTML = html;
    }

    function bindEvents() {
        document.getElementById('glcv-bubble').addEventListener('click', togglePanel);
        document.getElementById('glcv-close').addEventListener('click', closePanel);
        document.getElementById('glcv-send').addEventListener('click', onSend);

        var input = document.getElementById('glcv-input');
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                onSend();
            }
        });
        input.addEventListener('input', function () {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 100) + 'px';
        });

        document.addEventListener('click', function (e) {
            var t = e.target;
            if (t && t.classList && t.classList.contains('glcv-suggestion')) {
                var q = t.getAttribute('data-q');
                if (q) {
                    input.value = q;
                    onSend();
                }
            }
        });
    }

    function togglePanel() {
        var panel = document.getElementById('glcv-panel');
        if (panel.classList.contains('glcv-open')) {
            closePanel();
        } else {
            openPanel();
        }
    }

    function openPanel() {
        document.getElementById('glcv-panel').classList.add('glcv-open');
        setTimeout(function () {
            document.getElementById('glcv-input').focus();
        }, 200);
    }

    function closePanel() {
        document.getElementById('glcv-panel').classList.remove('glcv-open');
    }

    // -------- Messages --------

    function clearWelcome() {
        var welcome = document.querySelector('#glcv-messages .glcv-welcome');
        if (welcome) welcome.remove();
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderMarkdown(raw) {
        var s = escapeHtml(raw);
        // Inline code `code`
        s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        // Bold **text**
        s = s.replace(/\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>');
        // Italic *text* (single asterisk, not bold, must contain non-space)
        s = s.replace(/(^|[^*])\*([^*\s][^*\n]*?)\*(?!\*)/g, '$1<em>$2</em>');
        // Markdown links [text](url)
        s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g,
            '<a href="$2" target="_blank" rel="noopener">$1</a>');
        // Auto-link bare URLs
        s = s.replace(/(^|[\s])(https?:\/\/[^\s<)]+)/g,
            '$1<a href="$2" target="_blank" rel="noopener">$2</a>');

        var lines = s.split('\n');
        var out = [];
        var inUl = false, inOl = false;
        var paragraph = [];

        function flushP() {
            if (paragraph.length) {
                out.push('<p>' + paragraph.join('<br>') + '</p>');
                paragraph = [];
            }
        }
        function closeLists() {
            if (inUl) { out.push('</ul>'); inUl = false; }
            if (inOl) { out.push('</ol>'); inOl = false; }
        }
        function closeAll() { flushP(); closeLists(); }

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];

            if (line.trim() === '') { closeAll(); continue; }

            var h = line.match(/^\s*(#{1,6})\s+(.+)$/);
            if (h) { closeAll(); out.push('<div class="glcv-md-h">' + h[2] + '</div>'); continue; }

            var ul = line.match(/^\s*[-*]\s+(.+)$/);
            if (ul) {
                flushP();
                if (inOl) { out.push('</ol>'); inOl = false; }
                if (!inUl) { out.push('<ul>'); inUl = true; }
                out.push('<li>' + ul[1] + '</li>');
                continue;
            }

            var ol = line.match(/^\s*\d+\.\s+(.+)$/);
            if (ol) {
                flushP();
                if (inUl) { out.push('</ul>'); inUl = false; }
                if (!inOl) { out.push('<ol>'); inOl = true; }
                out.push('<li>' + ol[1] + '</li>');
                continue;
            }

            closeLists();
            paragraph.push(line);
        }
        closeAll();
        return out.join('');
    }

    function appendMessage(role, text) {
        clearWelcome();
        var messages = document.getElementById('glcv-messages');
        var bubble = document.createElement('div');
        bubble.className = 'glcv-msg glcv-msg-' + role;
        if (role === 'ai') {
            bubble.innerHTML = renderMarkdown(text);
        } else {
            bubble.textContent = text;
        }
        messages.appendChild(bubble);
        messages.scrollTop = messages.scrollHeight;
    }

    function appendError(text) {
        clearWelcome();
        var messages = document.getElementById('glcv-messages');
        var bubble = document.createElement('div');
        bubble.className = 'glcv-msg glcv-msg-error';
        bubble.textContent = text;
        messages.appendChild(bubble);
        messages.scrollTop = messages.scrollHeight;
    }

    function showTyping() {
        var messages = document.getElementById('glcv-messages');
        var typing = document.createElement('div');
        typing.className = 'glcv-typing';
        typing.id = 'glcv-typing';
        typing.innerHTML = '<span></span><span></span><span></span>';
        messages.appendChild(typing);
        messages.scrollTop = messages.scrollHeight;
    }

    function hideTyping() {
        var typing = document.getElementById('glcv-typing');
        if (typing) typing.remove();
    }

    // -------- Send --------

    function onSend() {
        if (sending) return;
        var input = document.getElementById('glcv-input');
        var q = input.value.trim();
        if (!q) return;

        appendMessage('user', q);
        history.push({ role: 'user', text: q });

        input.value = '';
        input.style.height = 'auto';
        sending = true;
        document.getElementById('glcv-send').disabled = true;
        showTyping();

        fetch(CHAT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                question: q,
                history: history.slice(0, -1) // l'historique SANS la question actuelle
            })
        })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                hideTyping();
                if (res.body && res.body.ok && res.body.answer) {
                    appendMessage('ai', res.body.answer);
                    history.push({ role: 'assistant', text: res.body.answer });
                } else {
                    var msg = (res.body && res.body.error) || 'Erreur inconnue.';
                    appendError(msg);
                }
            })
            .catch(function () {
                hideTyping();
                appendError("Le service est momentanement indisponible. Reessaye dans quelques instants.");
            })
            .finally(function () {
                sending = false;
                document.getElementById('glcv-send').disabled = false;
                document.getElementById('glcv-input').focus();
            });
    }

    // -------- Init --------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createWidget);
    } else {
        createWidget();
    }
})();
