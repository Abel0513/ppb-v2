(() => {
  const table = document.querySelector('[data-legacy-report]');
  const filters = document.querySelector('[data-report-filters]');
  const rows = [...table.querySelectorAll('[data-report-values]')].map(element => ({
    element, values: JSON.parse(element.dataset.reportValues)
  }));
  const buttons = [...table.querySelectorAll('[data-sort]')];
  let sort = table.dataset.legacyReport === 'performance' ? 'month_rate' : null;
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
        (criteria.rating && row.values.rating !== criteria.rating) ||
        (criteria.change && row.values.change !== criteria.change)
      );
      table.tBodies[0].append(row.element);
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

  refresh();
})();
