(async () => {
  const table = document.querySelector('[data-legacy-report]');
  const filters = document.querySelector('[data-report-filters]');
  const rows = [...table.querySelectorAll('[data-report-url]')].map(element => ({
    element, values: {rating: element.dataset.initialRating}
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

  const record = stats => stats.wins == null ? null : `${stats.wins} - ${stats.losses}`;
  refresh();
  for (const row of rows) {
    try {
      const url = new URL(row.element.dataset.reportUrl, location.origin);
      if (url.origin !== location.origin) throw Error('origin');
      const response = await fetch(url, {credentials: 'same-origin', redirect: 'error', cache: 'no-store',
        headers: {Accept: 'application/json'}});
      if (!response.ok) throw Error('unavailable');
      const data = await response.json();
      if (data.performance) {
        const {month, recent10, bonus} = data.performance;
        row.values = {rating: data.rating, score: data.score, month_rate: month.rate, month_record: record(month),
          recent_rate: recent10.rate, recent_record: record(recent10), bonus_rate: bonus.rate, bonus_record: record(bonus)};
      } else {
        row.values = {...data.salary, rating: data.rating, next_rating: data.next_rating, change: data.change,
          score_0: data.scores[0], score_1: data.scores[1], score_2: data.scores[2]};
      }
      for (const cell of row.element.querySelectorAll('[data-report-field]')) {
        const key = cell.dataset.reportField;
        const value = row.values[key];
        const display = cell.firstElementChild;
        if (key.endsWith('_rate') && Number.isFinite(value)) {
          display.className = 'legacy-win-rate';
        } else if (Number.isFinite(value) && value < 0) {
          display.className = 'legacy-negative';
        }
        display.textContent = value == null ? (key.startsWith('recent_') ? '未達10場' : key.startsWith('score_') ? '無資料' : '未分析')
          : typeof value === 'number' ? value.toLocaleString() + (key.endsWith('_rate') ? '%' : '') : value;
      }
    } catch {
      for (const cell of row.element.querySelectorAll('[data-report-field]')) {
        if (cell.dataset.reportField !== 'rating' || !row.element.dataset.initialRating) cell.firstElementChild.textContent = '未取得';
      }
    }
    refresh();
  }
})();
