document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.toast-container .toast').forEach((toast) => {
    const delay = toast.classList.contains('text-bg-danger') || toast.classList.contains('text-bg-warning') ? 5000 : 2000;
    window.setTimeout(() => {
      toast.style.transition = 'opacity .25s ease';
      toast.style.opacity = '0';
      window.setTimeout(() => toast.remove(), 250);
    }, delay);
  });

  document.querySelectorAll('[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
      if (!confirm(button.dataset.confirm)) event.preventDefault();
    });
  });

  const chart = document.getElementById('statusChart');
  if (chart && window.Chart) {
    new Chart(chart, {
      type: 'doughnut',
      data: {
        labels: ['Pending', 'Approved', 'Rejected'],
        datasets: [{ data: chart.dataset.values.split(',').map(Number), backgroundColor: ['#f6c343', '#2f9e44', '#d9480f'] }]
      },
      options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
  }
});

document.addEventListener('DOMContentLoaded', () => {
  const preference = (key, fallback) => { try { return localStorage.getItem(key) || fallback; } catch { return fallback; } };
  const remember = (key, value) => { try { localStorage.setItem(key, value); } catch {} };
  const language = document.getElementById('interface-language');
  const larger = document.getElementById('larger-text');
  const words = {
    'My dashboard':'Akong dashboard', 'Your recent requests':'Bag-ong mga hangyo',
    'Community announcements':'Mga anunsyo sa barangay', 'Your next schedule':'Imong sunod nga eskedyul',
    'Your updates':'Imong mga pahibalo', 'Your requests':'Imong mga hangyo',
    'In review':'Gisusi pa', 'Completed':'Nahuman', 'Unread updates':'Wala pa mabasa',
    'Request assistance':'Mangayo og tabang', 'Track my registration':'Subaya akong rehistrasyon',
    'View all →':'Tan-awa tanan →', 'All notifications →':'Tanang pahibalo →',
    'Senior Dashboard':'Pangunang panid sa senior', 'My Notifications':'Akong mga pahibalo',
    'My Assistance Requests':'Akong mga hangyo sa tabang', 'Edit Profile':'Usba ang personal nga impormasyon',
    'Announcements':'Mga anunsyo', 'Notifications':'Mga pahibalo', 'Requirements checklist':'Lista sa mga kinahanglan',
    'Mark all as read':'Markahi ang tanan nga nabasa', 'Mark as read':'Markahi nga nabasa',
    'View details':'Tan-awa ang detalye', 'View announcements':'Tan-awa ang mga anunsyo',
    'View my requests':'Tan-awa ang akong mga hangyo', 'Submit Request':'Isumite ang hangyo',
    'Save Profile':'I-save ang impormasyon', 'View submitted document':'Tan-awa ang gisumite nga dokumento',
    'Track application':'Subaya ang aplikasyon', 'Previous':'Miagi', 'Next':'Sunod', 'Filter':'Salain',
    'Full Name':'Kompletong ngalan', 'Phone':'Telepono', 'Address':'Puy-anan',
    'Emergency Contact':'Kontakon sa emerhensiya', 'Emergency Phone':'Numero sa emerhensiya',
    'Valid ID':'Balidong ID', 'Medical Notes':'Mga medikal nga nota', 'All notifications':'Tanang pahibalo',
    'Unread':'Wala pa mabasa', 'Read':'Nabasa', 'No notifications yet.':'Wala pay pahibalo.',
    'Type':'Matang', 'Description':'Detalye', 'Status':'Kahimtang', 'Schedule':'Eskedyul', 'Remarks':'Mga nota',
    'Request History':'Kasaysayan sa mga hangyo', 'Profile Status':'Kahimtang sa account',
    'Application tracking':'Pagsubay sa aplikasyon', 'Application timeline':'Kasaysayan sa aplikasyon'
  };
  if (language) {
    // Only translate known interface text; never translate submitted records.
    document.querySelectorAll('.content h1,.content h2,.content button,.content a,.content th,.content option,.content .form-label,.dashboard .metric-name').forEach(element => {
      if (element.children.length || element.dataset.en) return;
      const english = element.textContent.trim();
      if (words[english]) { element.dataset.en = english; element.dataset.ceb = words[english]; }
    });
    const translate = () => {
      const selected = language.value === 'ceb' ? 'ceb' : 'en';
      document.documentElement.lang = selected;
      document.querySelectorAll('[data-en][data-ceb]').forEach(element => { element.textContent = element.dataset[selected]; });
      remember('senior-interface-language', selected);
    };
    language.value = preference('senior-interface-language', 'en');
    if (!language.value) language.value = 'en';
    language.addEventListener('change', translate);
    translate();
  }
  if (larger) {
    const setLarge = enabled => {
      document.body.classList.toggle('larger-text', enabled);
      larger.setAttribute('aria-pressed', String(enabled));
      remember('senior-larger-text', String(enabled));
    };
    setLarge(preference('senior-larger-text', 'false') === 'true');
    larger.addEventListener('click', () => setLarge(larger.getAttribute('aria-pressed') !== 'true'));
  }
  document.querySelectorAll('[data-notification-link]').forEach((link) => {
    link.addEventListener('click', () => {
      if (link.dataset.read === '1') return;
      const panel = link.closest('[data-read-url]');
      const body = new FormData();
      body.append('notification_id', link.dataset.notificationId);
      body.append('csrf_token', panel?.dataset.csrf || '');
      fetch(panel?.dataset.readUrl || '', { method: 'POST', body, credentials: 'same-origin', keepalive: true }).catch(() => {});
    });
  });
  const badge = document.querySelector('[data-notification-count]');
  if (badge) {
    let latest = null;
    const poll = async () => {
      if (!document.hidden) {
        try {
          const response = await fetch(badge.dataset.countUrl, {credentials: 'same-origin', cache: 'no-store'});
          if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) return;
          const data = await response.json();
          badge.textContent = String(data.unread);
          if (latest !== null && data.latest > latest) {
            const notice = document.querySelector('[data-notification-update]');
            if (notice) notice.hidden = false;
          }
          latest = data.latest;
        } catch { /* Keep the last confirmed count during a connection failure. */ }
      }
      window.setTimeout(poll, 30000);
    };
    poll();
  }
});

document.addEventListener('DOMContentLoaded', () => {
  const button = document.querySelector('.mobile-navigation-toggle');
  if (!button) return;
  button.addEventListener('click', () => {
    const expanded = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(expanded));
    document.body.classList.toggle('navigation-open', expanded);
  });
});
