/* Only same-origin sequential GET; no writes. */
(async () => {
  for (const row of document.querySelectorAll('[data-preview-summary]')) {
    try {
      const url = new URL(row.dataset.previewSummary, location.origin);
      if (url.origin !== location.origin || !url.pathname.startsWith('/admin/evaluations-preview/legacy/')) throw Error('origin');
      const response = await fetch(url,{credentials:'same-origin',redirect:'error',cache:'no-store',headers:{Accept:'application/json'}});
      if (!response.ok) throw Error('unavailable');
      const data = await response.json();
      const values = {score:data.score,previous_score:data.previous_score,level:data.level_label,
        next_level:data.next_level_label,salary:data.salary.total};
      for (const cell of row.querySelectorAll('[data-preview-field]')) {
        if (!Object.hasOwn(values,cell.dataset.previewField)) throw Error('missing');
        const v = values[cell.dataset.previewField];
        cell.textContent = v === null ? '—' : String(v);
      }
    } catch {
      for (const cell of row.querySelectorAll('[data-preview-field]')) cell.textContent = '未取得';
    }
  }
})();
