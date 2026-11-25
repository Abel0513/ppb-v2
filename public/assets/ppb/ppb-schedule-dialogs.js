(() => {
  async function json(path, options = {}) {
    const url = new URL(path, location.origin);
    if (url.origin !== location.origin) throw new Error('無法取得資料。');
    const response = await fetch(url, {
      ...options, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
      headers: {Accept: 'application/json'}
    });
    const body = await response.json();
    if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat()[0] ?? body.message ?? '操作失敗。');
    return body;
  }

  function bindDialog(dialog, form, prepare, onSaved) {
    const status = dialog.querySelector('[data-dialog-status]');
    let pending;
    let busy = false;
    const close = () => { if (!busy) dialog.close(); };
    dialog.querySelectorAll('[data-dialog-close]').forEach(button => button.addEventListener('click', close));
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => { pending = null; });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (busy) return;
      busy = true;
      const submit = form.querySelector('[type=submit]');
      submit.disabled = true;
      status.textContent = '儲存中…';
      try {
        const body = new FormData(form);
        const signature = JSON.stringify([...body.entries()]);
        if (pending?.signature !== signature) {
          const receipt = await prepare(body);
          if (!receipt?.save_url) throw new Error('這筆資料目前無法儲存。');
          body.set('request_id', crypto.randomUUID());
          body.set('revision', receipt.revision);
          body.set('source_sha256', receipt.source_sha256);
          if (receipt.month) body.set('month', receipt.month);
          pending = {signature, body, url: receipt.save_url};
        }
        await json(pending.url, {method: 'POST', body: pending.body});
        pending = null;
        await onSaved();
      } catch (error) {
        status.textContent = error.message;
      } finally {
        busy = false;
        submit.disabled = false;
      }
    });
    return status;
  }

  const rating = document.querySelector('#ppbScheduleRating');
  if (rating) {
    const form = rating.querySelector('form');
    const fields = rating.querySelector('[data-rating-fields]');
    const submit = form.querySelector('[type=submit]');
    let active;
    let receipt;
    function renderScores(saved, average) {
      const score = name => Number(form.elements.namedItem(name).value);
      const professional = fields.querySelector('[data-score-section="professional"]');
      const sections = saved ?? {
        delivery: score('score_delivery_time'),
        compliance: (score('score_word_count') + score('score_format')) / 2,
        professional: [...professional.querySelectorAll('input:checked')]
          .reduce((sum, input) => sum + Number(input.value), score('score_professional_bonus')),
      };
      for (const [section, value] of Object.entries(sections)) {
        fields.querySelector(`[data-score-section="${section}"] [data-section-score]`).textContent = value;
      }
      const values = Object.values(sections);
      const mean = average ?? values.reduce((sum, value) => sum + value, 0) / values.length;
      rating.querySelector('[data-rating-total]').textContent = Math.round(mean);
    }
    const status = bindDialog(rating, form, body => {
      if (!receipt?.save_url) return null;
      body.set('source_type', receipt.pick._source_type);
      body.set('source_id', receipt.pick._source_id);
      return receipt;
    }, async () => {
      rating.close();
    });

    async function open(trigger) {
      active?.abort();
      const controller = new AbortController();
      active = controller;
      receipt = null;
      form.reset();
      fields.disabled = true;
      submit.hidden = true;
      for (const key of ['game', 'matchup', 'time', 'analyst', 'recommendation']) {
        rating.querySelector(`[data-rating-${key}]`).textContent = trigger.dataset[key];
      }
      for (const output of rating.querySelectorAll('[data-rating-total], [data-section-score]')) {
        output.textContent = '';
      }
      status.textContent = trigger.dataset.ratingUrl ? '讀取評鑑資料…' : '這個歷史名額沒有可評鑑的稿件。';
      rating.showModal();
      if (!trigger.dataset.ratingUrl) return;
      try {
        const result = await json(trigger.dataset.ratingUrl, {signal: controller.signal});
        if (controller.signal.aborted) return;
        receipt = result;
        if (!result.pick) {
          status.textContent = '此場次與分析師目前沒有可評鑑的稿件。';
          return;
        }
        const hasScores = Object.entries(result.pick).some(([key, value]) => key.startsWith('score_') && Number(value) !== 0);
        for (const [key, value] of Object.entries(result.pick)) {
          if (!key.startsWith('score_') || !hasScores) continue;
          const control = form.elements.namedItem(key);
          if (!control) continue;
          if (control instanceof RadioNodeList && ![...control].some(input => Number(input.value) === Number(value))) {
            for (const input of control) input.checked = false;
            for (const input of control) input.required = true;
            continue;
          }
          control.value = key === 'score_professional_bonus' ? Math.min(10, Math.max(0, Number(value))) : value;
        }
        fields.disabled = !result.save_url;
        submit.hidden = !result.save_url;
        status.textContent = result.save_url ? '' : '此評鑑供唯讀查閱。';
        renderScores(result.sections, result.average);
      } catch (error) {
        if (!controller.signal.aborted) status.textContent = error.message;
      }
    }

    form.addEventListener('change', () => renderScores());
    rating.querySelectorAll('[data-rating-step]').forEach(button => button.addEventListener('click', () => {
      const bonus = form.elements.score_professional_bonus;
      bonus.value = Math.min(10, Math.max(0, Number(bonus.value) + Number(button.dataset.ratingStep)));
      renderScores();
    }));
    document.querySelectorAll('[data-open-rating]').forEach(button => button.addEventListener('click', () => open(button)));
    rating.addEventListener('close', () => {
      active?.abort();
      for (const input of fields.querySelectorAll('[required]')) input.required = false;
    });
  }

  const video = document.querySelector('#ppbVideoDialog');
  if (video) {
    const form = video.querySelector('form');
    const title = video.querySelector('h2');
    const submit = form.querySelector('[type=submit]');
    let originalReceiptUrl;
    const status = bindDialog(video, form, () => json(originalReceiptUrl ?? form.elements.guest_1.selectedOptions[0].dataset.receiptUrl),
      async () => location.reload());
    const open = (raw, cancelling) => {
      form.reset();
      const fields = raw ? JSON.parse(raw) : {};
      for (const [key, value] of Object.entries(fields)) form.elements.namedItem(key).value = value ?? '';
      originalReceiptUrl = raw ? form.elements.guest_1.selectedOptions[0]?.dataset.receiptUrl : null;
      if (cancelling) form.elements.cancelled.value = '1';
      video.querySelector('[data-video-fields]').hidden = cancelling;
      title.textContent = cancelling ? '取消班表' : raw ? '修改班表' : '新增班表';
      submit.textContent = cancelling ? '確定取消班表' : raw ? '確定修改' : '確定新增';
      status.textContent = cancelling ? '取消後仍會保留班表紀錄。' : '';
      video.showModal();
    };
    for (const button of document.querySelectorAll('[data-open-video], [data-cancel-video]')) {
      button.addEventListener('click', () => open(button.dataset.openVideo ?? button.dataset.cancelVideo, button.hasAttribute('data-cancel-video')));
    }
  }
})();
