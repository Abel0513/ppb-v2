(() => {
  const wrapper = document.getElementById('ppbUnsettled');
  if (!wrapper) return;

  const button = document.getElementById('ppbUnsettledButton');
  const badge = document.getElementById('ppbUnsettledCount');
  const dropdown = document.getElementById('ppbUnsettledDropdown');
  const status = document.getElementById('ppbUnsettledStatus');
  const list = document.getElementById('ppbUnsettledGames');

  const setOpen = open => {
    dropdown.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
    if (open) dropdown.focus();
  };

  button.addEventListener('click', () => setOpen(dropdown.hidden));
  // Capture clicks on the profile button before its handler stops propagation.
  document.addEventListener('click', event => {
    if (!dropdown.hidden && !wrapper.contains(event.target)) setOpen(false);
  }, true);
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || dropdown.hidden) return;
    setOpen(false);
    button.focus();
  });

  const unavailable = '未結算場次清單暫時無法載入';
  const renderGames = (games, count) => {
    const rows = document.createDocumentFragment();
    for (const game of games) {
      const item = document.createElement('li');
      const linked = typeof game.url === 'string' && /^\/[\w-]+(?:\/[\w-]+)*$/.test(game.url);
      const row = document.createElement(linked ? 'a' : 'div');
      row.className = 'admin-unsettled-row';
      if (linked) row.href = game.url;

      const code = document.createElement('span');
      code.className = 'admin-unsettled-code';
      code.textContent = game.code;
      const at = document.createElement('span');
      at.className = 'admin-unsettled-time';
      at.textContent = game.at;
      row.append(code, at);

      if (game.matchup) {
        const matchup = document.createElement('strong');
        matchup.className = 'admin-unsettled-matchup';
        matchup.textContent = game.matchup;
        row.append(matchup);
      }

      item.append(row);
      rows.append(item);
    }

    list.replaceChildren(rows);
    status.hidden = games.length > 0;
    if (!status.hidden) status.textContent = count === 0 ? '目前沒有未結算場次' : unavailable;
  };

  const load = async attempt => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(badge.dataset.url, {
        headers: {'Accept': 'application/json'}, signal: controller.signal,
      });
      const data = response.ok ? await response.json() : null;
      if (!Number.isInteger(data?.count)) throw new Error('unsettled count unavailable');
      badge.textContent = data.count;
      renderGames(data.games ?? [], data.count);
    } catch {
      if (attempt < 2) {
        setTimeout(() => load(attempt + 1), 2000 * (attempt + 1));
        return;
      }
      if (!/^[0-9]+$/.test(badge.textContent)) badge.textContent = '讀取失敗';
      status.textContent = unavailable;
    } finally {
      clearTimeout(timer);
    }
  };

  load(0);
})();
