import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const button = event.target.closest('[data-copy-target]');
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const target = document.querySelector(button.dataset.copyTarget);
    const text = target?.textContent?.trim();
    if (!text) {
        return;
    }

    const originalText = button.textContent;
    try {
        await navigator.clipboard.writeText(text);
        button.textContent = 'Copied';
        window.setTimeout(() => {
            button.textContent = originalText;
        }, 1600);
    } catch {
        button.textContent = 'Copy failed';
        window.setTimeout(() => {
            button.textContent = originalText;
        }, 1600);
    }
});
