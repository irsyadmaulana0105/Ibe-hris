import './bootstrap';

(() => {
  const overlay = document.getElementById('global-loading');
  const ring = document.getElementById('global-progress-ring');
  const text = document.getElementById('global-progress-text');
  if (!overlay || !ring || !text) return;

  let pending = 0;
  let progress = 0;
  let timer = null;
  const circumference = 2 * Math.PI * 52;

  const setProgress = (value) => {
    const clamped = Math.max(0, Math.min(100, value));
    progress = clamped;
    const offset = circumference - (circumference * clamped) / 100;
    ring.style.strokeDashoffset = String(offset);
    text.textContent = `${Math.round(clamped)}%`;
  };

  const show = () => {
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');
    setProgress(0);
    if (timer) clearInterval(timer);
    timer = setInterval(() => {
      if (progress >= 90) return;
      const increment = progress < 60 ? 6 : progress < 80 ? 3 : 1;
      setProgress(progress + increment);
    }, 200);
  };

  const hide = () => {
    overlay.classList.add('hidden');
    overlay.classList.remove('flex');
    if (timer) clearInterval(timer);
    timer = null;
    setProgress(0);
  };

  const complete = () => {
    setProgress(100);
    setTimeout(() => hide(), 250);
  };

  window.__globalLoading = { show, hide, complete };

  document.addEventListener('submit', () => {
    show();
  });

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-loading]');
    if (link) show();
  });

  document.addEventListener('click', (event) => {
    const wireClick = event.target.closest('[wire\\:click]');
    if (wireClick) show();
  });

  document.addEventListener('livewire:init', () => {
    if (!window.Livewire) return;
    Livewire.hook('request', ({ respond, succeed, fail }) => {
      pending += 1;
      show();

      const done = () => {
        pending = Math.max(0, pending - 1);
        if (pending === 0) complete();
      };

      respond(() => done());
      succeed(() => done());
      fail(() => done());
    });
  });
})();

(() => {
  if (window.Alpine) return;
  const dropdowns = document.querySelectorAll('[data-nav-dropdown]');
  dropdowns.forEach((dropdown) => {
    const trigger = dropdown.querySelector('[data-nav-trigger]');
    const content = dropdown.querySelector('[data-nav-content]');
    if (!trigger || !content) return;
    let open = false;
    const close = () => {
      open = false;
      content.style.display = 'none';
    };
    trigger.addEventListener('click', (event) => {
      event.preventDefault();
      open = !open;
      content.style.display = open ? 'block' : 'none';
    });
    document.addEventListener('click', (event) => {
      if (!dropdown.contains(event.target)) close();
    });
  });
})();

(() => {
  const modal = document.getElementById('global-success-modal');
  const card = document.getElementById('global-success-card');
  const title = document.getElementById('global-success-title');
  const message = document.getElementById('global-success-message');
  const icon = document.getElementById('global-success-icon');
  if (!modal || !card || !title || !message || !icon) return;

  let timer = null;

  const setStyle = (style) => {
    const isDanger = style === 'danger';
    icon.classList.toggle('bg-green-100', !isDanger);
    icon.classList.toggle('text-green-700', !isDanger);
    icon.classList.toggle('bg-red-100', isDanger);
    icon.classList.toggle('text-red-700', isDanger);
    title.textContent = isDanger ? 'Gagal' : 'Berhasil';
  };

  const show = (style, text) => {
    setStyle(style);
    message.textContent = text || '';
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    }, 2500);
  };

  window.addEventListener('banner-message', (event) => {
    if (!event?.detail?.message) return;
    show(event.detail.style, event.detail.message);
  });
})();
