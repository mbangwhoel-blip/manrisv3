/**
 * AI Chatbot Assistant Widget — MANRIS v2
 * Floating widget for interactive risk management AI assistant.
 */
(function() {
  'use strict';

  // Cegah inisialisasi ganda
  if (document.getElementById('manris-ai-widget-container')) return;

  const CSS = `
    .manris-ai-btn {
      position: fixed;
      bottom: 24px;
      right: 24px;
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--accent, #3b82f6), var(--primary, #1e3a5f));
      color: #ffffff;
      border: none;
      box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      z-index: 9999;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .manris-ai-btn:hover {
      transform: scale(1.08) translateY(-2px);
      box-shadow: 0 8px 25px rgba(59, 130, 246, 0.5);
    }
    .manris-ai-badge {
      position: absolute;
      top: -2px;
      right: -2px;
      background: var(--danger, #dc2626);
      width: 14px;
      height: 14px;
      border-radius: 50%;
      border: 2px solid var(--surface, #ffffff);
    }
    .manris-ai-chatbox {
      position: fixed;
      bottom: 92px;
      right: 24px;
      width: 380px;
      max-width: calc(100vw - 32px);
      height: 520px;
      max-height: calc(100vh - 120px);
      background: var(--surface, #ffffff);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 16px;
      box-shadow: var(--shadow-lg, 0 10px 30px rgba(0,0,0,0.15));
      display: flex;
      flex-direction: column;
      overflow: hidden;
      z-index: 9999;
      opacity: 0;
      visibility: hidden;
      transform: translateY(20px) scale(0.95);
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .manris-ai-chatbox.open {
      opacity: 1;
      visibility: visible;
      transform: translateY(0) scale(1);
    }
    .manris-ai-header {
      background: linear-gradient(135deg, var(--primary, #1e3a5f), var(--primary-light, #2d5a8e));
      color: #ffffff;
      padding: 14px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      user-select: none;
    }
    .manris-ai-title-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .manris-ai-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: rgba(255,255,255,0.2);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
    }
    .manris-ai-title {
      font-weight: 700;
      font-size: 0.95rem;
      line-height: 1.2;
    }
    .manris-ai-status {
      font-size: 0.72rem;
      opacity: 0.85;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .manris-ai-status::before {
      content: '';
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #22c55e;
      display: inline-block;
    }
    .manris-ai-header-btn {
      background: transparent;
      border: none;
      color: rgba(255,255,255,0.75);
      font-size: 14px;
      cursor: pointer;
      padding: 5px 8px;
      border-radius: 6px;
      transition: color 0.15s, background 0.15s;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .manris-ai-header-btn:hover {
      color: #ffffff;
      background: rgba(255,255,255,0.15);
    }
    .manris-ai-close-btn {
      background: transparent;
      border: none;
      color: rgba(255,255,255,0.8);
      font-size: 18px;
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 6px;
      transition: color 0.15s, background 0.15s;
    }
    .manris-ai-close-btn:hover {
      color: #ffffff;
      background: rgba(255,255,255,0.15);
    }
    .manris-ai-messages {
      flex: 1;
      overflow-y: auto;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      background: var(--surface2, #f8fafc);
    }
    .manris-ai-msg {
      max-width: 86%;
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 0.88rem;
      line-height: 1.5;
      word-break: break-word;
    }
    .manris-ai-msg.bot {
      align-self: flex-start;
      background: var(--surface, #ffffff);
      color: var(--text, #1e293b);
      border: 1px solid var(--border, #e2e8f0);
      border-bottom-left-radius: 2px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .manris-ai-msg.user {
      align-self: flex-end;
      background: var(--accent, #3b82f6);
      color: #ffffff;
      border-bottom-right-radius: 2px;
    }
    .manris-ai-msg.bot p { margin: 0 0 6px 0; }
    .manris-ai-msg.bot p:last-child { margin-bottom: 0; }
    .manris-ai-msg.bot h4, .manris-ai-msg.bot h5 {
      margin: 8px 0 4px 0;
      font-size: 0.92rem;
      font-weight: 700;
      color: var(--primary, #1e3a5f);
    }
    .manris-ai-msg.bot code {
      background: rgba(0,0,0,0.06);
      padding: 1px 4px;
      border-radius: 4px;
      font-size: 0.82rem;
      font-family: monospace;
    }
    .manris-ai-msg.bot .ai-list-item {
      display: flex;
      gap: 6px;
      margin: 2px 0 2px 2px;
      line-height: 1.45;
    }
    .manris-ai-msg.bot .ai-bullet {
      color: var(--accent, #3b82f6);
      font-weight: bold;
    }
    .manris-ai-msg.bot .ai-num {
      color: var(--primary, #1e3a5f);
      font-weight: 600;
      min-width: 16px;
    }
    .manris-ai-msg.bot ul, .manris-ai-msg.bot ol {
      margin: 4px 0 6px 18px;
      padding: 0;
    }
    .manris-ai-msg.bot strong {
      color: var(--primary, #1e3a5f);
    }
    .manris-ai-suggestions {
      padding: 8px 12px;
      background: var(--surface, #ffffff);
      border-top: 1px solid var(--border, #e2e8f0);
      display: flex;
      flex-wrap: nowrap;
      overflow-x: auto;
      gap: 6px;
      scrollbar-width: thin;
    }
    .manris-ai-chip {
      background: var(--surface2, #f1f5f9);
      color: var(--text, #334155);
      border: 1px solid var(--border, #cbd5e1);
      border-radius: 20px;
      padding: 4px 10px;
      font-size: 0.75rem;
      white-space: nowrap;
      cursor: pointer;
      transition: all 0.15s;
    }
    .manris-ai-chip:hover {
      background: var(--accent, #3b82f6);
      color: #ffffff;
      border-color: var(--accent, #3b82f6);
    }
    .manris-ai-input-wrap {
      padding: 10px 12px;
      background: var(--surface, #ffffff);
      border-top: 1px solid var(--border, #e2e8f0);
      display: flex;
      gap: 8px;
      align-items: center;
    }
    .manris-ai-input {
      flex: 1;
      border: 1px solid var(--border, #cbd5e1);
      border-radius: 20px;
      padding: 8px 14px;
      font-size: 0.88rem;
      background: var(--surface2, #f8fafc);
      color: var(--text, #1e293b);
      outline: none;
      transition: border-color 0.2s;
    }
    .manris-ai-input:focus {
      border-color: var(--accent, #3b82f6);
      background: var(--surface, #ffffff);
    }
    .manris-ai-send {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      border: none;
      background: var(--accent, #3b82f6);
      color: #ffffff;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.15s, opacity 0.15s;
    }
    .manris-ai-send:hover:not(:disabled) {
      transform: scale(1.05);
    }
    .manris-ai-send:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .manris-ai-typing {
      display: flex;
      gap: 4px;
      padding: 8px 12px;
    }
    .manris-ai-typing span {
      width: 6px;
      height: 6px;
      background: var(--text-muted, #94a3b8);
      border-radius: 50%;
      animation: manrisBounce 1.2s infinite ease-in-out;
    }
    .manris-ai-typing span:nth-child(2) { animation-delay: 0.2s; }
    .manris-ai-typing span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes manrisBounce {
      0%, 80%, 100% { transform: translateY(0); }
      40% { transform: translateY(-6px); }
    }
  `;

  // Inject styles
  const styleEl = document.createElement('style');
  styleEl.textContent = CSS;
  document.head.appendChild(styleEl);

  // Template HTML
  const container = document.createElement('div');
  container.id = 'manris-ai-widget-container';
  container.innerHTML = `
    <button type="button" class="manris-ai-btn" id="manrisAiToggleBtn" title="Asisten Cerdas MANRIS" aria-label="Buka Asisten Cerdas">
      <i class="fas fa-robot"></i>
    </button>
    <div class="manris-ai-chatbox" id="manrisAiChatbox">
      <div class="manris-ai-header">
        <div class="manris-ai-title-wrap">
          <div class="manris-ai-avatar"><i class="fas fa-brain"></i></div>
          <div>
            <div class="manris-ai-title">Asisten AI MANRIS</div>
            <div class="manris-ai-status">Online • Konsultan Risiko</div>
          </div>
        </div>
        <div style="display:flex; align-items:center; gap:4px;">
          <button type="button" class="manris-ai-header-btn" id="manrisAiClearBtn" title="Bersihkan Percakapan (Mulai Baru)">
            <i class="fas fa-trash-alt"></i>
          </button>
          <button type="button" class="manris-ai-close-btn" id="manrisAiCloseBtn" title="Tutup">
            <i class="fas fa-times"></i>
          </button>
        </div>
      </div>
      <div class="manris-ai-messages" id="manrisAiMessages"></div>
      <div class="manris-ai-suggestions">
        <button type="button" class="manris-ai-chip" data-query="Apa perbedaan aksi mitigasi dengan saran mitigasi?">⚖️ Aksi vs Saran</button>
        <button type="button" class="manris-ai-chip" data-query="Berikan 5 saran mitigasi untuk identifikasi risiko keterlambatan reagen lab!">💡 5 Saran Mitigasi</button>
        <button type="button" class="manris-ai-chip" data-query="Bagaimana rumus penulisan pernyataan risiko yang baik?">💡 Rumus Risiko</button>
        <button type="button" class="manris-ai-chip" data-query="Bagaimana cara menentukan skor matriks 5x5?">📊 Level 5x5</button>
        <button type="button" class="manris-ai-chip" data-query="Apa saja strategi aksi mitigasi risiko?">🛡️ Strategi Mitigasi</button>
        <button type="button" class="manris-ai-chip" data-query="Apa saja 8 prinsip manajemen risiko ISO 31000?">🌐 Prinsip ISO 31000</button>
        <button type="button" class="manris-ai-chip" data-query="Apa perbedaan risiko inheren dan residual?">⚖️ Inheren vs Residual</button>
        <button type="button" class="manris-ai-chip" data-query="Jelaskan konsep Three Lines of Defense manajemen risiko!">🛡️ Tiga Lini Pertahanan</button>
        <button type="button" class="manris-ai-chip" data-query="Apa kaitan SPIP dengan manajemen risiko?">🏛️ SPIP & Risiko</button>
        <button type="button" class="manris-ai-chip" data-query="Bagaimana hierarki pengendalian risiko?">🪜 Hierarki Mitigasi</button>
        <button type="button" class="manris-ai-chip" data-query="Apa perbedaan form KKPR dan KKPMR?">📑 KKPR vs KKPMR</button>
      </div>
      <div class="manris-ai-input-wrap">
        <input type="text" class="manris-ai-input" id="manrisAiInput" placeholder="Ketik pertanyaan seputar risiko..." autocomplete="off">
        <button type="button" class="manris-ai-send" id="manrisAiSendBtn" title="Kirim">
          <i class="fas fa-paper-plane"></i>
        </button>
      </div>
    </div>
  `;
  document.body.appendChild(container);

  // Elements
  const toggleBtn = document.getElementById('manrisAiToggleBtn');
  const closeBtn  = document.getElementById('manrisAiCloseBtn');
  const clearBtn  = document.getElementById('manrisAiClearBtn');
  const chatbox   = document.getElementById('manrisAiChatbox');
  const msgList   = document.getElementById('manrisAiMessages');
  const inputEl   = document.getElementById('manrisAiInput');
  const sendBtn   = document.getElementById('manrisAiSendBtn');

  // Kunci storage unik terikat pada user ID & session ID aktif saat ini
  const activeUserId = window.MANRIS_USER_ID || '0';
  const activeSessionToken = window.MANRIS_SESSION_TOKEN || 'active';
  const STORAGE_KEY = 'manris_ai_chat_' + activeUserId + '_' + activeSessionToken;

  // Bersihkan seluruh riwayat obrolan dari sesi lampau / user lain / legacy key
  try {
    for (let i = sessionStorage.length - 1; i >= 0; i--) {
      const k = sessionStorage.key(i);
      if (k && k.indexOf('manris_ai_chat') !== -1 && k !== STORAGE_KEY) {
        sessionStorage.removeItem(k);
      }
    }
    for (let j = localStorage.length - 1; j >= 0; j--) {
      const lk = localStorage.key(j);
      if (lk && lk.indexOf('manris_ai_chat') !== -1) {
        localStorage.removeItem(lk);
      }
    }
  } catch(e) {}

  // State percakapan
  let chatHistory = [];
  try {
    const saved = sessionStorage.getItem(STORAGE_KEY);
    if (saved) chatHistory = JSON.parse(saved);
  } catch(e) {}

  // Format markdown ringan & terstruktur
  function formatMarkdown(text) {
    if (!text) return '';
    let escaped = text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');

    // Headings
    escaped = escaped.replace(/^###\s+(.+)$/gm, '<h5>$1</h5>');
    escaped = escaped.replace(/^##?\s+(.+)$/gm, '<h4>$1</h4>');

    // Inline code `code`
    escaped = escaped.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Bold & Italic
    escaped = escaped.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    escaped = escaped.replace(/\*([^\*\n]+?)\*/g, '<em>$1</em>');

    // Lists
    escaped = escaped.replace(/^[\*\-]\s+(.+)$/gm, '<div class="ai-list-item"><span class="ai-bullet">•</span><span>$1</span></div>');
    escaped = escaped.replace(/^(\d+)\.\s+(.+)$/gm, '<div class="ai-list-item"><span class="ai-num">$1.</span><span>$2</span></div>');

    // Handle line breaks cleanly without double spacing after headings or list items
    escaped = escaped.replace(/(<\/(?:h[45]|div)>)\n/g, '$1');
    escaped = escaped.replace(/\n{2,}/g, '<div style="height:6px"></div>');
    escaped = escaped.replace(/\n/g, '<br>');
    return escaped;
  }

  function appendMessage(role, text) {
    const div = document.createElement('div');
    div.className = 'manris-ai-msg ' + (role === 'user' ? 'user' : 'bot');
    if (role === 'user') {
      div.textContent = text;
    } else {
      div.innerHTML = formatMarkdown(text);
    }
    msgList.appendChild(div);
    msgList.scrollTop = msgList.scrollHeight;
  }

  function renderHistory() {
    msgList.innerHTML = '';
    if (chatHistory.length === 0) {
      appendMessage('bot', 'Halo! Saya **Asisten AI MANRIS**. Ada yang bisa saya bantu terkait 8 prinsip ISO 31000, perumusan risiko, atau strategi mitigasi?');
    } else {
      chatHistory.forEach(item => appendMessage(item.role, item.text));
    }
  }

  function toggleChat() {
    const isOpen = chatbox.classList.contains('open');
    if (isOpen) {
      chatbox.classList.remove('open');
    } else {
      chatbox.classList.add('open');
      renderHistory();
      setTimeout(() => inputEl.focus(), 150);
    }
  }

  toggleBtn.addEventListener('click', toggleChat);
  closeBtn.addEventListener('click', toggleChat);

  // Send Message
  async function sendMessage(text) {
    const query = text || inputEl.value.trim();
    if (!query) return;

    inputEl.value = '';
    appendMessage('user', query);
    chatHistory.push({ role: 'user', text: query });

    // Tampilkan typing indicator
    const typingDiv = document.createElement('div');
    typingDiv.className = 'manris-ai-msg bot manris-ai-typing';
    typingDiv.id = 'manrisAiTyping';
    typingDiv.innerHTML = '<span></span><span></span><span></span>';
    msgList.appendChild(typingDiv);
    msgList.scrollTop = msgList.scrollHeight;

    inputEl.disabled = true;
    sendBtn.disabled = true;

    try {
      const endpoint = (window.APP_URL || '') + '/api_ai_chat.php';
      const csrf = window.CSRF_TOKEN || '';

      const resp = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf
        },
        body: JSON.stringify({
          pesan: query,
          csrf_token: csrf,
          history: chatHistory.slice(-6)
        })
      });

      const typingEl = document.getElementById('manrisAiTyping');
      if (typingEl) typingEl.remove();

      if (resp.status === 401) {
        clearChat(false);
        appendMessage('bot', '⚠️ Sesi Anda telah berakhir atau kedaluwarsa. Mengalihkan ke halaman login...');
        setTimeout(() => {
          window.location.href = (window.APP_URL || '') + '/index.php?page=login&timeout=1';
        }, 1800);
        return;
      }

      const data = await resp.json();
      if (data && data.ok) {
        appendMessage('bot', data.reply);
        chatHistory.push({ role: 'model', text: data.reply });
      } else {
        appendMessage('bot', '⚠️ Maaf: ' + (data.error || 'Terjadi kesalahan sistem.'));
      }
    } catch(err) {
      const typingEl = document.getElementById('manrisAiTyping');
      if (typingEl) typingEl.remove();
      appendMessage('bot', '⚠️ Gagal terhubung ke layanan asisten. Pastikan koneksi server aktif.');
    } finally {
      inputEl.disabled = false;
      sendBtn.disabled = false;
      inputEl.focus();
      try {
        if (chatHistory.length > 0) {
          sessionStorage.setItem(STORAGE_KEY, JSON.stringify(chatHistory.slice(-20)));
        } else {
          sessionStorage.removeItem(STORAGE_KEY);
        }
      } catch(e) {}
    }
  }

  function clearChat(showNotice = false) {
    chatHistory = [];
    try {
      sessionStorage.removeItem(STORAGE_KEY);
      for (let i = sessionStorage.length - 1; i >= 0; i--) {
        const k = sessionStorage.key(i);
        if (k && k.indexOf('manris_ai_chat') !== -1) sessionStorage.removeItem(k);
      }
      for (let j = localStorage.length - 1; j >= 0; j--) {
        const lk = localStorage.key(j);
        if (lk && lk.indexOf('manris_ai_chat') !== -1) localStorage.removeItem(lk);
      }
    } catch(e) {}
    renderHistory();
    if (showNotice) {
      appendMessage('bot', '🧹 *Percakapan telah dibersihkan.* Silakan mulai topik diskusi baru.');
    }
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (chatHistory.length === 0) return;
      if (confirm('Bersihkan seluruh obrolan dengan asisten AI dan mulai topik baru?')) {
        clearChat(true);
      }
    });
  }

  // Bersihkan chat saat form logout di-submit
  document.addEventListener('submit', (e) => {
    const target = e.target;
    if (target && target.querySelector && target.querySelector('input[name="action"][value="logout"]')) {
      clearChat(false);
    }
  });

  sendBtn.addEventListener('click', () => sendMessage());
  inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  // Suggestion chips click
  document.querySelectorAll('.manris-ai-chip').forEach(chip => {
    chip.addEventListener('click', function() {
      const q = this.getAttribute('data-query');
      if (q) sendMessage(q);
    });
  });

})();
