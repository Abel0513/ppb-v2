/* Read-only, sequential bounded page summaries. No writes or external endpoints. */
(async () => {
  for (const row of document.querySelectorAll('[data-evaluation-summary]')) {
    try {
      const url = new URL(row.dataset.evaluationSummary, window.location.origin);
      if (url.origin !== window.location.origin) throw new Error('origin');
      const response = await fetch(url, {credentials: 'same-origin', redirect: 'error', cache: 'no-store', headers: {'Accept': 'application/json'}});
      if (!response.ok) throw new Error('unavailable');
      const data = await response.json();
      const values = {...data, level: data.level_label, next_level: data.next_level_label, salary: data.salary.total, month_rate: data.performance.month.rate,
        recent_rate: data.performance.recent10.rate, bonus_rate: data.performance.bonus.rate};
      for (const cell of row.querySelectorAll('[data-summary-cell]')) {
        const key = cell.dataset.summaryCell;
        if (!Object.hasOwn(values, key)) throw new Error('incomplete');
        const value = values[key];
        cell.textContent = value === null ? '—' : String(value) + (key.endsWith('_rate') ? '%' : '');
      }
      row.dataset.summaryState = 'ready';
    } catch {
      for (const cell of row.querySelectorAll('[data-summary-cell]')) cell.textContent = '未取得';
      row.dataset.summaryState = 'unavailable';
    }
  }
})();
