/* global document */

/**
 * Ticket Numbering — configuration page behaviour.
 *
 * Renders a live preview of the ticket number the entered pattern would produce
 * and lets the example patterns be copied into the input. This is a display aid
 * only: the pattern is validated and applied server-side by
 * GlpiPlugin\Ticketnumbering\Service\NumberPattern, which stays authoritative.
 */
(() => {
    'use strict';

    const form = document.getElementById('ticketnumbering-config-form');
    const preview = document.getElementById('ticketnumbering-preview');

    if (form === null || preview === null) {
        return;
    }

    const input = form.querySelector('input[name="pattern"]');
    if (input === null) {
        return;
    }

    const pad = (value, length) => String(value).padStart(length, '0');

    /** ISO-8601 week number (1-53) of a date. */
    const isoWeek = (date) => {
        const target = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
        // Thursday of the current ISO week decides which week/year the date is in.
        target.setUTCDate(target.getUTCDate() + 4 - (target.getUTCDay() || 7));
        const yearStart = new Date(Date.UTC(target.getUTCFullYear(), 0, 1));

        return Math.ceil(((target - yearStart) / 86400000 + 1) / 7);
    };

    /** ISO-8601 week-numbering year, which may differ from the calendar year. */
    const isoWeekYear = (date) => {
        const target = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
        target.setUTCDate(target.getUTCDate() + 4 - (target.getUTCDay() || 7));

        return target.getUTCFullYear();
    };

    /** @return {?string} the rendered number, or null when the pattern is unusable */
    const renderPattern = (rawPattern) => {
        const pattern = rawPattern.replace(/\s+/g, '').toUpperCase();
        const parts = pattern.match(/^((?:\d|\{[A-Z]+\})*)(#+)$/);
        if (parts === null) {
            return null;
        }

        const prefixPattern = parts[1];
        const digits = parts[2].length;
        const now = new Date();
        const year = prefixPattern.includes('{WW}') ? isoWeekYear(now) : now.getFullYear();

        let prefix = '';
        let index = 0;

        while (index < prefixPattern.length) {
            if (prefixPattern[index] !== '{') {
                prefix += prefixPattern[index];
                index += 1;
                continue;
            }

            const end = prefixPattern.indexOf('}', index);
            if (end === -1) {
                return null;
            }

            switch (prefixPattern.slice(index + 1, end)) {
                case 'YYYY':
                    prefix += pad(year, 4);
                    break;
                case 'YY':
                    prefix += pad(year, 4).slice(-2);
                    break;
                case 'Q':
                    prefix += String(Math.floor(now.getMonth() / 3) + 1);
                    break;
                case 'MM':
                    prefix += pad(now.getMonth() + 1, 2);
                    break;
                case 'WW':
                    prefix += pad(isoWeek(now), 2);
                    break;
                case 'DD':
                    prefix += pad(now.getDate(), 2);
                    break;
                default:
                    return null;
            }

            index = end + 1;
        }

        return prefix + '1'.padStart(digits, '0');
    };

    const update = () => {
        const rendered = renderPattern(input.value);
        preview.textContent = rendered === null ? '—' : rendered;
        preview.classList.toggle('bg-red-lt', rendered === null);
        preview.classList.toggle('bg-blue-lt', rendered !== null);
    };

    input.addEventListener('input', update);

    form.ownerDocument.querySelectorAll('.ticketnumbering-preset').forEach((button) => {
        button.addEventListener('click', () => {
            input.value = button.dataset.pattern || '';
            update();
            input.focus();
        });
    });

    update();
})();
