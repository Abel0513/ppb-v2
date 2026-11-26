(() => {
  const table = document.querySelector('[data-legacy-report]');
  if (!table) return;
  const filters = document.querySelector('[data-report-filters]');
  const rows = [...table.querySelectorAll('[data-report-values]')].map(element => ({
    element, values: JSON.parse(element.dataset.reportValues)
  }));
  const buttons = [...table.querySelectorAll('[data-sort]')];
  let sort = null;
  let direction = -1;
  let criteria = {};

  function refresh() {
    for (const button of buttons) {
      button.closest('th').setAttribute('aria-sort', button.dataset.sort === sort
        ? (direction === 1 ? 'ascending' : 'descending') : 'none');
    }
    const ordered = [...rows];
    if (sort) ordered.sort((a, b) => {
      const x = a.values[sort];
      const y = b.values[sort];
      if (x == null || y == null) return Number(x == null) - Number(y == null);
      return direction * (sort === 'rating'
        ? ['無資料','C','B','A','S'].indexOf(x) - ['無資料','C','B','A','S'].indexOf(y) : x - y);
    });
    for (const row of ordered) {
      row.element.hidden = Boolean(
        (criteria.name && !row.element.dataset.analystName.toLocaleLowerCase().includes(criteria.name)) ||
        (criteria.rating && !row.element.hasAttribute('aria-busy') && row.values.rating !== criteria.rating) ||
        (criteria.change && !row.element.hasAttribute('aria-busy') && row.values.change !== criteria.change)
      );
      if (sort) table.tBodies[0].append(row.element);
    }
    const visible = rows.filter(row => !row.element.hidden);
    for (const cell of table.querySelectorAll('[data-report-total]')) {
      const key = cell.dataset.reportTotal;
      cell.textContent = table.hasAttribute('data-roster-unavailable') || visible.some(row => !Number.isFinite(row.values[key]))
        ? '—' : visible.reduce((total, row) => total + row.values[key], 0).toLocaleString();
    }
  }

  filters?.addEventListener('submit', event => {
    event.preventDefault();
    criteria = Object.fromEntries(new FormData(filters));
    criteria.name = criteria.name.trim().toLocaleLowerCase();
    refresh();
  });
  filters?.addEventListener('reset', () => {
    criteria = {};
    refresh();
  });
  for (const button of buttons) button.addEventListener('click', () => {
    direction = sort === button.dataset.sort ? -direction : -1;
    sort = button.dataset.sort;
    refresh();
  });

  // 分析師列表 (t60u): legacy badge classes, thresholds read from the old page.
  function decorate(cell, value) {
    const style = cell.dataset.legacyStyle;
    if (!style) return;
    if (value == null) cell.className = 'no-data';
    else if (style === 'month_rate') cell.className = 'rate-badge ' + (value >= 55 ? 'rate-high' : value >= 45 ? 'rate-mid' : 'rate-low');
    else cell.className = style === 'score' ? 'score' : 'rank';
  }

  const pending = rows.filter(row => row.element.dataset.reportUrl);
  let next = 0;

  // Every pending row must end with values or 讀取失敗. Rows rendered from the
  // last known summary keep those values when a background refresh fails.
  function settle(row, values) {
    const cells = row.element.querySelectorAll('[data-report-field]');
    if (values) {
      row.values = values;
      for (const cell of cells) {
        const field = cell.dataset.reportField;
        const value = values[field];
        const rate = field.endsWith('_rate') && Number.isFinite(value);
        cell.textContent = rate ? value + '%' : (value ?? cell.dataset.empty);
        decorate(cell, value);
      }
    } else if (row.element.hasAttribute('aria-busy')) {
      row.values = {};
      for (const cell of cells) { cell.textContent = '讀取失敗'; if (cell.dataset.legacyStyle) cell.className = 'cell-pending'; }
    }
    row.done = true;
    row.element.removeAttribute('aria-busy');
    try { refresh(); } catch { /* sorting/filtering must never stop the queue */ }
  }

  async function load(row) {
    const cells = row.element.querySelectorAll('[data-report-field]');
    for (let attempt = 0; attempt < 2; attempt++) {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 20000);
      try {
        const response = await fetch(row.element.dataset.reportUrl, {
          headers: { Accept: 'application/json' },
          signal: controller.signal
        });
        if (!response.ok) throw new Error('Report summary unavailable');
        const { cells: values } = await response.json();
        if (!values || [...cells].some(cell => !Object.hasOwn(values, cell.dataset.reportField))) {
          throw new Error('Report summary incomplete');
        }
        return values;
      } catch {
        if (attempt === 0) await new Promise(resolve => setTimeout(resolve, 1500));
      } finally {
        clearTimeout(timeout);
      }
    }
    return null;
  }

  async function fillRows() {
    while (next < pending.length) {
      const row = pending[next++];
      if (row.done) continue;
      let values = null;
      try { values = await load(row); } catch { values = null; }
      // A late success still replaces a watchdog 讀取失敗.
      if (values || !row.done) settle(row, values);
    }
  }

  refresh();
  // The legacy reader serves requests serially; a small queue avoids starving later rows.
  for (let worker = 0; worker < Math.min(3, pending.length); worker++) void fillRows();
  // Watchdog: nothing may stay 載入中 indefinitely.
  if (pending.length) setTimeout(() => {
    for (const row of pending) if (!row.done) settle(row, null);
  }, 90000);
})();
