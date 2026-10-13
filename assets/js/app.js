/* Client-side behaviour.
   Asynchronous polling keeps queue position and predicted waiting time
   current without a page reload (FR-13, NFR-01). */

const QMS = (() => {
  const DEFAULT_INTERVAL = 15000;

  function setText(selector, value) {
    const el = document.querySelector(`[data-live="${selector}"]`);
    if (el && value !== undefined && value !== null) {
      el.textContent = value;
    }
  }

  async function fetchTicket(entryId) {
    try {
      const res = await fetch(`api/queue_status.php?id=${entryId}`, {
        headers: { 'Accept': 'application/json' },
        cache: 'no-store',
      });
      if (!res.ok) return null;
      return await res.json();
    } catch (err) {
      return null;   // network hiccup: keep the last known values on screen
    }
  }

  function applyTicket(data) {
    if (!data || !data.ok) return;

    setText('wait', data.predicted_wait_label);
    setText('position', data.position === null ? '—' : data.position);
    setText('now', data.now_serving || '—');
    setText('now-counter', data.now_serving_counter || 'No client currently called');

    const bar = document.querySelector('[data-live="progress"]');
    if (bar) bar.style.width = `${data.progress}%`;

    const stamp = document.querySelector('[data-live="stamp"]');
    if (stamp) {
      stamp.textContent = `Updated ${new Date().toLocaleTimeString()} — this page refreshes automatically.`;
    }

    // A change of status (called, completed, expired) changes the available
    // actions, so reload rather than patching the page piecemeal.
    if (data.status_changed) {
      window.location.reload();
    }
  }

  function pollTicket(entryId, interval = DEFAULT_INTERVAL) {
    const tick = async () => applyTicket(await fetchTicket(entryId));
    tick();
    setInterval(tick, interval);
  }

  function autoReload(interval = DEFAULT_INTERVAL) {
    setInterval(() => window.location.reload(), interval);
  }

  /* Counter console: refresh the queue panel without losing the page. */
  function pollCounter(counterId, interval = 10000) {
    setInterval(async () => {
      try {
        const res = await fetch(`../api/counter_status.php?counter=${counterId}`,
                                { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        if (!data.ok) return;
        setText('waiting-count', data.waiting);
        if (data.reload) window.location.reload();
      } catch (err) { /* ignore */ }
    }, interval);
  }

  /* Render a QR code into an element carrying a data-value attribute.
     Uses the bundled qrcode-generator library, so no internet connection is
     needed at runtime. */
  function renderQr(elementId) {
    const el = document.getElementById(elementId);
    if (!el || typeof qrcode !== 'function') return;

    const value = el.dataset.value;
    if (!value) return;

    try {
      // Type 0 lets the library pick the smallest version that fits;
      // 'M' error correction tolerates a printed ticket being creased.
      const qr = qrcode(0, 'M');
      qr.addData(value);
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    } catch (err) {
      el.innerHTML = '<span class="tiny muted">QR code unavailable</span>';
    }
  }

  return { pollTicket, autoReload, pollCounter, renderQr };
})();
