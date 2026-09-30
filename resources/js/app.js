(() => {
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('import-form');
        const button = form.querySelector('button[type="submit"]');
        const success = document.getElementById('import-success');
        const errors = document.getElementById('import-errors');

        const showErrors = (messages) => {
            errors.replaceChildren();

            messages.forEach((message) => {
                const item = document.createElement('li');
                item.textContent = message;
                errors.append(item);
            });

            errors.hidden = false;
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (button.disabled) return;

            success.hidden = true;
            success.textContent = '';
            errors.hidden = true;
            errors.replaceChildren();
            button.disabled = true;

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new FormData(form),
                });
                const data = await response.json().catch(() => null);

                if (response.status === 422 && data?.errors) {
                    showErrors(Object.values(data.errors).flat());
                } else if (!response.ok || !data || response.redirected) {
                    showErrors(['Не вдалося прийняти файл. Спробуйте ще раз.']);
                } else {
                    success.textContent = data.message || 'Файл прийнято та передано на обробку.';
                    success.hidden = false;
                }
            } catch {
                showErrors(['Не вдалося надіслати файл. Перевірте з’єднання та спробуйте ще раз.']);
            } finally {
                button.disabled = false;
            }
        });
    });
})();
