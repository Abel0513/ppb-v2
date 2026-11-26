(() => {
    const dialog = document.getElementById('attendance-delete-dialog');
    if (!dialog) return;

    const confirm = dialog.querySelector('[data-confirm]');
    const cancel = dialog.querySelector('[data-cancel]');
    const error = dialog.querySelector('.delete-error');
    const requestIds = new WeakMap();
    let trigger;

    document.querySelectorAll('[data-attendance-delete]').forEach(button => {
        button.addEventListener('click', () => {
            trigger = button;
            error.hidden = true;
            dialog.showModal();
        });
    });

    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', event => {
        if (confirm.disabled) event.preventDefault();
    });
    dialog.addEventListener('click', event => {
        if (event.target !== dialog || confirm.disabled) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right ||
            event.clientY < bounds.top || event.clientY > bounds.bottom) {
            dialog.close();
        }
    });

    confirm.addEventListener('click', async () => {
        confirm.disabled = true;
        cancel.disabled = true;
        trigger.disabled = true;
        error.hidden = true;
        try {
            const payload = JSON.parse(trigger.dataset.attendanceDelete);
            if (!requestIds.has(trigger)) requestIds.set(trigger, crypto.randomUUID());
            payload.request_id = requestIds.get(trigger);
            const response = await fetch(dialog.dataset.url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': dialog.dataset.csrf,
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || result.request_id !== payload.request_id) {
                error.textContent = result.message || '刪除失敗，請稍後再試。';
                error.hidden = false;
                return;
            }
            window.location.reload();
            return;
        } catch {
            error.textContent = '刪除失敗，請稍後再試。';
            error.hidden = false;
        } finally {
            if (!error.hidden) {
                confirm.disabled = false;
                cancel.disabled = false;
                trigger.disabled = false;
                confirm.focus();
            }
        }
    });
})();
