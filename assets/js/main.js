/**
 * MAIN JS — Sistem Manajemen Risiko Premium
 * Vanilla JS: Modal, Toast, Sidebar, Animasi
 */

'use strict';

// ── Modal ─────────────────────────────────────────────────────
/**
 * Buka modal dengan animasi
 */
function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  // Trigger animation
  requestAnimationFrame(() => el.classList.add('visible'));
}

/**
 * Tutup modal
 */
function closeModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.style.display = 'none';
  document.body.style.overflow = '';
}

// Tutup modal klik backdrop (mencegah tertutup saat drag text)
let modalMousedownTarget = null;
document.addEventListener('mousedown', (e) => {
  modalMousedownTarget = e.target;
});
document.addEventListener('click', (e) => {
  if (e.target.classList.contains('modal-overlay') && modalMousedownTarget === e.target) {
    e.target.style.display = 'none';
    document.body.style.overflow = '';
  }
});

// Tutup modal dengan ESC
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay').forEach(m => {
      if (m.style.display === 'flex') {
        m.style.display = 'none';
        document.body.style.overflow = '';
      }
    });
  }
});

// ── Sidebar Toggle ────────────────────────────────────────────
function toggleSidebar() {
  const sidebar  = document.getElementById('sidebar');
  const overlay  = document.getElementById('sidebarOverlay');
  const wrapper  = document.getElementById('mainWrapper');
  if (!sidebar || !overlay || !wrapper) return;
  const isMobile = window.innerWidth <= 900;

  if (isMobile) {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('show');
  } else {
    const collapsed = sidebar.classList.toggle('collapsed');
    if (collapsed) {
      sidebar.style.width = '68px';
      wrapper.style.marginLeft = '68px';
      sidebar.querySelectorAll('.brand-text, .brand-sub, span, .nav-section-title, .sidebar-footer')
        .forEach(el => el.style.display = 'none');
    } else {
      sidebar.style.width = '';
      wrapper.style.marginLeft = '';
      sidebar.querySelectorAll('.brand-text, .brand-sub, span, .nav-section-title, .sidebar-footer')
        .forEach(el => el.style.display = '');
    }
  }
}

// ── Toast Notification ────────────────────────────────────────
/**
 * Tampilkan toast dengan animasi
 * @param {string} msg   - Pesan yang ditampilkan
 * @param {string} type  - success | error | warning | info
 * @param {number} duration - Durasi ms (default 4000)
 */
function showToast(msg, type = 'info', duration = 4000) {
  const icons = {
    success: 'check-circle', error: 'times-circle',
    warning: 'exclamation-triangle', info: 'info-circle'
  };
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `
    <i class="fas fa-${icons[type] || 'info-circle'}"></i>
    <span>${escHtml(msg)}</span>
    <button class="toast-remove" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
  `;
  container.appendChild(toast);

  // Auto remove
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(20px)';
    toast.style.transition = 'all .3s ease';
    setTimeout(() => toast.remove(), 320);
  }, duration);
}

// ── XSS safe escape ──────────────────────────────────────────
function escHtml(str) {
  const div = document.createElement('div');
  div.appendChild(document.createTextNode(str));
  return div.innerHTML;
}

// ── Button loading state & Logout cleanup ──────────────────────
// Gunakan setTimeout agar button di-disable SETELAH form terkirim.
// Jika di-disable sinkron, browser membatalkan submission.
document.querySelectorAll('form').forEach(form => {
  // Bersihkan riwayat chat AI jika form adalah form logout
  if (form.querySelector('input[name="action"][value="logout"]')) {
    form.addEventListener('submit', function () {
      try {
        for (let i = sessionStorage.length - 1; i >= 0; i--) {
          const k = sessionStorage.key(i);
          if (k && k.indexOf('manris_ai_chat') !== -1) sessionStorage.removeItem(k);
        }
        for (let j = localStorage.length - 1; j >= 0; j--) {
          const lk = localStorage.key(j);
          if (lk && lk.indexOf('manris_ai_chat') !== -1) localStorage.removeItem(lk);
        }
      } catch(e) {}
    });
  }

  form.addEventListener('submit', function () {
    const btn = this.querySelector('[type="submit"]');
    if (btn) {
      const orig = btn.innerHTML;
      setTimeout(() => {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
      }, 0);
      setTimeout(() => { btn.disabled = false; btn.innerHTML = orig; }, 5000);
    }
  });
});

// ── Card hover ripple ─────────────────────────────────────────
document.querySelectorAll('.stat-card').forEach(card => {
  card.addEventListener('mouseenter', function () {
    this.style.transform = 'translateY(-3px)';
  });
  card.addEventListener('mouseleave', function () {
    this.style.transform = '';
  });
});

// ── Animate numbers (stat values) ────────────────────────────
function animateCount(el, target, duration = 600) {
  const start = 0;
  const startTime = performance.now();
  function update(now) {
    const elapsed = now - startTime;
    const progress = Math.min(elapsed / duration, 1);
    const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
    el.textContent = Math.round(start + (target - start) * eased);
    if (progress < 1) requestAnimationFrame(update);
  }
  requestAnimationFrame(update);
}

// Jalankan animasi angka saat halaman load
document.querySelectorAll('.stat-value').forEach(el => {
  const target = parseInt(el.textContent) || 0;
  if (target > 0) animateCount(el, target);
});

// ── Confirm delete with custom dialog ────────────────────────
document.querySelectorAll('[data-confirm]').forEach(btn => {
  btn.addEventListener('click', function (e) {
    if (!confirm(this.dataset.confirm || 'Yakin?')) {
      e.preventDefault();
      e.stopPropagation();
    }
  });
});

// ── Tooltip simple ────────────────────────────────────────────
document.querySelectorAll('[title]').forEach(el => {
  el.setAttribute('data-title', el.getAttribute('title'));
});

// ── Responsive table: add data-label for mobile ───────────────
document.querySelectorAll('.data-table').forEach(table => {
  const headers = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
  table.querySelectorAll('tbody tr').forEach(row => {
    row.querySelectorAll('td').forEach((td, i) => {
      if (headers[i]) td.setAttribute('data-label', headers[i]);
    });
  });
});

// ── Auto-dismiss alerts after 5s ─────────────────────────────
setTimeout(() => {
  document.querySelectorAll('.alert-auto').forEach(el => {
    el.style.opacity = '0';
    el.style.transition = 'opacity .4s';
    setTimeout(() => el.remove(), 420);
  });
}, 5000);

// ── Live Clock (Global) ───────────────────────────────────────
function updateGlobalClock() {
    const el = document.getElementById('liveClock');
    if(!el) return;
    const now = new Date();
    const days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    
    const d = days[now.getDay()];
    const date = String(now.getDate()).padStart(2, '0');
    const m = months[now.getMonth()];
    const y = now.getFullYear();
    const h = String(now.getHours()).padStart(2, '0');
    const min = String(now.getMinutes()).padStart(2, '0');
    const sec = String(now.getSeconds()).padStart(2, '0');
    
    el.innerHTML = `<span class="clock-date">${d}, ${date} ${m} ${y}</span><span class="clock-time">${h}:${min}:${sec}</span>`;
}
setInterval(updateGlobalClock, 1000);
updateGlobalClock();

// ── Skeleton Loading ────────────────────────────────────────
function showSkeleton(containerId, count) {
  const el = document.getElementById(containerId);
  if (!el) return;
  let html = '';
  for (let i = 0; i < count; i++) {
    html += '<div class="skeleton-card" style="margin-bottom:12px">' +
      '<div class="skeleton skeleton-line"></div>' +
      '<div class="skeleton skeleton-line short"></div>' +
      '<div style="display:flex;gap:12px;align-items:center;margin-top:12px">' +
        '<div class="skeleton skeleton-avatar"></div>' +
        '<div style="flex:1"><div class="skeleton skeleton-line short"></div></div>' +
      '</div>' +
    '</div>';
  }
  el.innerHTML = html;
}

// ── Page Enter Animation ────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  const main = document.querySelector('.main-content') || document.querySelector('.page-header');
  if (main) main.classList.add('page-enter');
});

// ── Empty State Helper ──────────────────────────────────────
function renderEmptyState(containerId, icon, title, desc, actionHtml) {
  const el = document.getElementById(containerId);
  if (!el) return;
  el.innerHTML = '<div class="empty-state">' +
    '<div class="empty-state-icon"><i class="' + icon + '"></i></div>' +
    '<div class="empty-state-title">' + title + '</div>' +
    '<div class="empty-state-desc">' + desc + '</div>' +
    (actionHtml ? '<div class="empty-state-action">' + actionHtml + '</div>' : '') +
  '</div>';
}

// ── Saved Filters (localStorage) ──────────────────────────────
function saveFilter(page, filterData) {
  let saved = JSON.parse(localStorage.getItem('manris_saved_filters') || '{}');
  const key = page + '_' + Date.now();
  saved[key] = { page: page, data: filterData, label: filterData.label || 'Filter', ts: Date.now() };
  localStorage.setItem('manris_saved_filters', JSON.stringify(saved));
}
function getSavedFilters(page) {
  let saved = JSON.parse(localStorage.getItem('manris_saved_filters') || '{}');
  return Object.values(saved).filter(f => f.page === page);
}
function applySavedFilter(page, key) {
  let saved = JSON.parse(localStorage.getItem('manris_saved_filters') || '{}');
  const filter = saved[key];
  if (!filter) return;
  const params = new URLSearchParams(filter.data);
  params.set('page', page);
  window.location.href = '?' + params.toString();
}
function deleteSavedFilter(key) {
  let saved = JSON.parse(localStorage.getItem('manris_saved_filters') || '{}');
  delete saved[key];
  localStorage.setItem('manris_saved_filters', JSON.stringify(saved));
}

// ── Keyboard Shortcuts ──────────────────────────────────────
document.addEventListener('keydown', function(e) {
  // Ctrl+K = command palette (future)
  // N = new (when not typing)
  // Esc = close modal
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay').forEach(m => {
      if (m.style.display !== 'none') closeModal(m.id);
    });
  }
});


// ── Theme Toggle ──────────────────────────────────────────────
const themeToggle = document.getElementById('themeToggle');
const darkSheet   = document.getElementById('dark-stylesheet');
const html        = document.documentElement;

themeToggle?.addEventListener('click', () => {
  const isDark = html.getAttribute('data-theme') === 'dark';
  const newTheme = isDark ? 'light' : 'dark';
  html.setAttribute('data-theme', newTheme);
  document.body.classList.toggle('dark-mode', !isDark);
  themeToggle.querySelector('i').className = 'fas ' + (isDark ? 'fa-moon' : 'fa-sun');
  darkSheet.disabled = isDark;

  // Simpan preference via cookie & server
  document.cookie = `theme=${newTheme};path=/;max-age=31536000`;
  fetch((window.APP_URL || '') + '/api.php/set_theme', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      'X-CSRF-Token': window.CSRF_TOKEN || ''
    },
    body: new URLSearchParams({theme: newTheme})
  });

  // Update Chart.js secara dinamis tanpa reload halaman
  if (typeof Chart !== 'undefined') {
      Chart.defaults.color = newTheme === 'dark' ? '#94a3b8' : '#64748b';
      Chart.defaults.borderColor = newTheme === 'dark' ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';
      for (let id in Chart.instances) {
          if (Chart.instances[id].config.type === 'bar') {
              Chart.instances[id].options.scales.x.grid.color = newTheme === 'dark' ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';
          }
          if (Chart.instances[id].config.type === 'doughnut') {
              if (Chart.instances[id].data.datasets[0]) {
                  Chart.instances[id].data.datasets[0].borderColor = newTheme === 'dark' ? '#1e293b' : '#ffffff';
              }
          }
          Chart.instances[id].update();
      }
  }
});

// ── Sidebar Toggle ────────────────────────────────────────────
const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
if (sidebarToggleBtn) {
  sidebarToggleBtn.addEventListener('click', () => {
    if (typeof toggleSidebar === 'function') toggleSidebar();
  });
}

// ── Dropdown User ─────────────────────────────────────────────
const userDropdownBtn = document.getElementById('userDropdownBtn');
if (userDropdownBtn) {
  userDropdownBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    document.getElementById('dropdownMenu')?.classList.toggle('show');
  });
}

document.addEventListener('click', (e) => {
  const dropdown = document.getElementById('userDropdown');
  if (dropdown && !dropdown.contains(e.target)) {
    document.getElementById('dropdownMenu')?.classList.remove('show');
  }
  // Close notif saat klik di luar
  const notifDropdown = document.getElementById('notifDropdown');
  if (notifDropdown && !notifDropdown.contains(e.target)) {
    document.getElementById('notifMenu')?.style.setProperty('display', 'none');
  }
  // Close action dropdown saat klik di luar
  if (!e.target.closest('.action-dropdown')) {
    closeAllActionMenus();
  }
});

// ── Nav Group Toggle ──────────────────────────────────────────
document.querySelectorAll('.js-nav-group-toggle').forEach(btn => {
  btn.addEventListener('click', function() {
    this.classList.toggle('expanded');
    this.setAttribute('aria-expanded', this.classList.contains('expanded') ? 'true' : 'false');
    if (this.nextElementSibling) {
      this.nextElementSibling.classList.toggle('show');
    }
  });
});

// ── Action Dropdown (untuk kolom Aksi di tabel) ──────────────
function toggleActionMenu(e, id) {
  e.stopPropagation();
  closeAllActionMenus();
  document.getElementById('actionMenu-' + id)?.classList.toggle('show');
}
function closeAllActionMenus() {
  document.querySelectorAll('.action-menu.show').forEach(m => m.classList.remove('show'));
}

// ── Notifikasi ────────────────────────────────────────────────
const notifToggleBtn = document.getElementById('notifToggleBtn');
if (notifToggleBtn) {
  notifToggleBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const menu = document.getElementById('notifMenu');
    if (menu) menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    document.getElementById('dropdownMenu')?.classList.remove('show');
  });
}

const markAllReadBtn = document.getElementById('markAllReadBtn');
if (markAllReadBtn) {
  markAllReadBtn.addEventListener('click', () => {
    fetch((window.APP_URL || '') + '/api.php/notif_read_all', {method: 'POST', headers:{'X-CSRF-Token': window.CSRF_TOKEN || ''}, credentials:'same-origin'}).then(()=>{
      const badge = document.getElementById('notifBadge');
      if (badge) badge.remove();
      document.querySelectorAll('#notifList a').forEach(a => a.style.opacity = '.6');
    }).catch(()=>{});
  });
}

function markRead(id) {
  fetch((window.APP_URL || '') + '/api.php/notif_read', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
    body: 'id=' + encodeURIComponent(id),
    keepalive: true,
    credentials: 'same-origin'
  }).catch(()=>{});
}


// ── Inisialisasi DataTables ──────────────────────────────────────


// ── Inisialisasi DataTables ──────────────────────────────────────
// Tabel ringkasan pada dashboard sengaja dibuat statis agar tetap ringkas dan
// tidak memunculkan kontrol pencarian/paginasi pada ruang yang terbatas.
const dataTables = document.querySelectorAll('.data-table:not(.no-datatable)');
if (window.simpleDatatables?.DataTable) {
  dataTables.forEach(table => {
    const isSearchable = table.getAttribute('data-searchable') !== 'false';
    const isSortable = table.getAttribute('data-sortable') !== 'false';
    const dt = new window.simpleDatatables.DataTable(table, {
      searchable: isSearchable,
      sortable: isSortable,
      fixedHeight: false,
      perPage: 7,
      labels: {
          placeholder: "Cari data...",
          perPage: "Data",
          noRows: "Tidak ada data yang ditemukan",
          info: "Menampilkan {start} - {end} dari {rows} data"
      }
    });

      const wrapper = table.closest('.datatable-wrapper');
      if (wrapper) {
        const top = wrapper.querySelector('.datatable-top');
        const card = wrapper.closest('.card');
        if (top && card) {
          const header = card.querySelector('.card-header');
          if (header) {
            header.style.display = 'flex';
            header.style.flexWrap = 'wrap';
            header.style.alignItems = 'center';
            header.style.justifyContent = 'space-between';
            header.style.gap = '12px';
            
            top.style.padding = '0';
            top.style.margin = '0';
            top.style.flex = '1';
            top.style.display = 'flex';
            top.style.justifyContent = 'flex-end';
            top.style.alignItems = 'center';
            top.style.gap = '8px';
            top.style.minWidth = '250px';

            const dtSearch = top.querySelector('.datatable-search');
            if (dtSearch) {
                dtSearch.style.marginLeft = '0';
            }
            
            header.appendChild(top);
          }
        }
        
        const container = wrapper.querySelector('.datatable-container');
        if (container) {
          container.classList.add('table-responsive');
        }
        const outerResponsive = wrapper.parentElement;
        if (outerResponsive && outerResponsive.classList.contains('table-responsive')) {
          outerResponsive.classList.remove('table-responsive');
        }
      }
  });
}

// ── Form Validation ────────────────────────────────────────
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', function(e) {
    if (!this.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
      const firstInvalid = this.querySelector(':invalid');
      if (firstInvalid) {
        firstInvalid.focus();
        showToast('Mohon lengkapi form dengan benar.', 'error');
      }
    }
    this.classList.add('was-validated');
  });
});

// Tutup sidebar mobile setelah memilih menu.
document.querySelectorAll('.sidebar .nav-item').forEach(link => link.addEventListener('click', () => {
  document.getElementById('sidebar')?.classList.remove('open');
  document.getElementById('sidebarOverlay')?.classList.remove('show');
}));

// Jangan kehilangan input panjang secara tidak sengaja.
document.querySelectorAll('form').forEach(form => {
  let dirty = false;
  form.querySelectorAll('input, select, textarea').forEach(field => {
    field.addEventListener('input', () => { dirty = true; });
  });
  form.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', e => {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });
});

// ── Cegah Enter-submit di input single-line dalam modal/form input ──
// Browser auto-submit form saat Enter ditekan di <input type="text">.
// Textarea & field dengan class "allow-enter" tetap boleh Enter.
document.addEventListener('keydown', function(e) {
  if (e.key !== 'Enter') return;
  const el = e.target;
  if (!el || el.tagName !== 'INPUT') return;
  // Hanya untuk input single-line (bukan textarea, bukan button)
  if (['text','number','date','email','tel','url','password','search'].indexOf(el.type) === -1) return;
  // Skip filter bar / search form (punya handler sendiri)
  const form = el.closest('form');
  if (!form) return;
  // Form filter (method GET dengan class filter-bar) tetap boleh enter
  if (form.classList.contains('filter-bar') || form.classList.contains('search-bar')) return;
  // Cegah submit
  e.preventDefault();
  // Pindah focus ke field berikutnya (UX lebih baik)
  const focusable = form.querySelectorAll('input, select, textarea, button[type="submit"]');
  const idx = Array.prototype.indexOf.call(focusable, el);
  if (idx > -1 && idx < focusable.length - 1) {
    focusable[idx + 1].focus();
  }
});


function autofillNip(inputEl, nipFieldName, scopeSelector = '') {
    const list = document.getElementById('listUsersWithNip');
    if(!list) return;
    let foundNip = '';
    const options = list.options;
    for(let i=0; i<options.length; i++){
        if(options[i].value === inputEl.value) {
            foundNip = options[i].getAttribute('data-nip');
            break;
        }
    }
    if(foundNip) {
        const scope = scopeSelector ? document.querySelector(scopeSelector) : inputEl.closest('form') || inputEl.closest('.modal-content') || document;
        const nipEl = scope.querySelector('input[name="'+nipFieldName+'"]');
        if(nipEl) nipEl.value = foundNip;
    }
}

