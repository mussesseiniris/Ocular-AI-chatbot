(function () {
  const fab = document.getElementById('chatbot-fab');
  const panel = document.getElementById('chatbot-panel');
  const closeBtn = document.getElementById('chatbot-close');
  const input = document.getElementById('chatbot-input');
  const messages = document.getElementById('chatbot-messages');
  const sendBtn = document.getElementById('chatbot-send');
  if (!fab || !panel || !sendBtn) return;

  const url = sendBtn.dataset.url;
  const historyUrl = sendBtn.dataset.historyUrl;

  function setOpen(open) {
    panel.hidden = !open;
    fab.setAttribute('aria-label', open ? 'Close AI Assistant' : 'Open AI Assistant');
    if (open) {
      input.focus();        // Open: Focus moves to the input field
    } else {
      fab.focus();          // Close: Focus returns to the assistant button
    }
  }


  fab.addEventListener('click', () => {
    setOpen(panel.hidden);
  });

  panel.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') setOpen(false);
  });

  closeBtn.addEventListener('click', () => setOpen(false));

  function appendMessage(text, role) {
    const el = document.createElement('div');
    el.className = 'chatbot-msg chatbot-msg--' + role;
    el.textContent = text;
    messages.appendChild(el);
    messages.scrollTop = messages.scrollHeight;
    return el;
  }

  function getFreshToken() {
    return new Promise((resolve, reject) => {
      const container = document.getElementById('cf-turnstile-chatbot');
      if (!container || !window.turnstile) {
        console.log('[Turnstile] Skipping — resolving empty');
        resolve(''); // No Turnstile available (dev environment)
        return;
      }
      turnstile.reset(container);
      turnstile.execute(container, {
        callback: (token) => {
          console.log('[Turnstile] Token received:', token ? 'yes' : 'empty');
          resolve(token);
        },
        'error-callback': (err) => {
          console.log('[Turnstile] Error:', err);
          reject(new Error('Turnstile verification failed'));
        },
      });
    });
  }

  let isSending = false;

  async function send() {
    if (isSending) return;
    const question = input.value.trim();
    if (!question) return;
    isSending = true;
    sendBtn.disabled = true;
    try {
      appendMessage(question, 'user');
      input.value = '';
      const pending = appendMessage('Thinking', 'ai');
      pending.classList.add('chatbot-msg--pending');
      let token;
      try {
      token = await getFreshToken();
      } catch (e) {
        pending.classList.remove('chatbot-msg--pending');
        pending.textContent = 'Bot verification failed. Please refresh and try again.';
        return;
      }

      try {
        const res = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',    //include session cookie for server-side chat history
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            'tx_chatbot_chatbot[question]': question,
            'tx_chatbot_chatbot[turnstileToken]': token,
          })
        });

        const data = await res.json();
        const answer = data.answer || 'Sorry, something went wrong. Please try again.';
        pending.classList.remove('chatbot-msg--pending'); 
        pending.innerHTML = DOMPurify.sanitize(marked.parse(answer));

      } catch (e) {
        pending.classList.remove('chatbot-msg--pending');
        pending.textContent = 'Sorry, something went wrong. Please try again.';
      }
      messages.scrollTop = messages.scrollHeight;
    }
    finally {
      isSending = false;
      sendBtn.disabled = false;
    }
  }
  async function loadHistory() {
    if (!historyUrl) return;
    try {
      const res = await fetch(historyUrl, { credentials: 'same-origin' });
      const data = await res.json();
      (data.history || []).forEach(m => {
        const el = appendMessage('', m.role === 'assistant' ? 'ai' : 'user');
        if (m.role === 'assistant') {
          el.innerHTML = DOMPurify.sanitize(marked.parse(m.content));
        } else {
          el.textContent = m.content;
        }
      });
    } catch (e) {
    }
  }

  sendBtn.addEventListener('click', send);
  loadHistory();
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); send(); }
  });

})();