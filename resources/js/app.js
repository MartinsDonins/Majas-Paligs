/*
 * Mājas Palīgs — resident app JS. Alpine is bundled by Livewire (CSP-safe build on resident pages), so every
 * Alpine component is registered here with Alpine.data(): templates only reference properties and methods,
 * never inline expressions (no 'unsafe-eval').
 */

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * Copies a secret from the URL FRAGMENT (#c=CODE or #t=TOKEN) into a form field and immediately clears the
     * fragment from the address bar and history. The value never reaches the server in a URL.
     * Usage: <div x-data="fragmentSecret" data-param="c" data-target="code" data-livewire="true">
     */
    Alpine.data('fragmentSecret', () => ({
        init() {
            const param = this.$el.dataset.param || 'c';
            const hash = window.location.hash.replace(/^#/, '');
            if (!hash) {
                return;
            }
            const value = new URLSearchParams(hash).get(param);
            history.replaceState(null, '', window.location.pathname);
            if (!value) {
                return;
            }
            const target = this.$el.dataset.target;
            if (this.$el.dataset.livewire === 'true' && this.$wire) {
                this.$wire.set(target, value);
            } else {
                const field = this.$el.querySelector(`[name="${target}"]`);
                if (field) {
                    field.value = value;
                }
                if (this.$el.dataset.autosubmit === 'true') {
                    const form = this.$el.closest('form') || this.$el.querySelector('form');
                    // Never auto-submit: mail scanners must not be able to burn the token. The user taps "Ieiet".
                    if (form) {
                        form.dataset.ready = '1';
                    }
                }
            }
        },
    }));

    /** Show/hide toggle for the PIN pad ("Rādīt / Показать"). */
    Alpine.data('revealToggle', () => ({
        shown: false,
        toggle() {
            this.shown = !this.shown;
        },
        get inputType() {
            return this.shown ? 'text' : 'password';
        },
    }));

    /** Offline banner: "Nav savienojuma — mēģiniet vēlreiz, kad būsiet tiešsaistē". Disables save buttons. */
    Alpine.data('connectivity', () => ({
        offline: !navigator.onLine,
        init() {
            window.addEventListener('online', () => {
                this.offline = false;
                document.documentElement.removeAttribute('data-offline');
            });
            window.addEventListener('offline', () => {
                this.offline = true;
                document.documentElement.setAttribute('data-offline', '');
            });
            if (this.offline) {
                document.documentElement.setAttribute('data-offline', '');
            }
        },
    }));

    /** Bottom sheet with focus trap, Esc and a visible "Aizvērt" button. */
    Alpine.data('sheet', () => ({
        open: false,
        show() {
            this.open = true;
        },
        hide() {
            this.open = false;
        },
        toggle() {
            this.open = !this.open;
        },
        onKeydown(event) {
            if (event.key === 'Escape') {
                this.hide();
            }
        },
    }));

    /** Disclosure ("Kas redz šo informāciju?"). */
    Alpine.data('disclosure', () => ({
        expanded: false,
        toggle() {
            this.expanded = !this.expanded;
        },
    }));

    /** Copy text to clipboard (temporary PIN reset code, share text). */
    Alpine.data('copyText', () => ({
        copied: false,
        async copy() {
            const text = this.$el.dataset.text || '';
            try {
                await navigator.clipboard.writeText(text);
                this.copied = true;
                setTimeout(() => {
                    this.copied = false;
                }, 2500);
            } catch (e) {
                this.copied = false;
            }
        },
    }));

    /** Arrow-key navigation inside role="grid" building grids. */
    Alpine.data('gridNav', () => ({
        onKeydown(event) {
            const cell = event.target.closest('[role="gridcell"]');
            if (!cell) {
                return;
            }
            const row = cell.parentElement;
            const cells = Array.from(row.querySelectorAll('[role="gridcell"]'));
            const rows = Array.from(this.$el.querySelectorAll('[role="row"]'));
            const ci = cells.indexOf(cell);
            const ri = rows.indexOf(row);
            let next = null;
            if (event.key === 'ArrowRight') next = cells[ci + 1];
            if (event.key === 'ArrowLeft') next = cells[ci - 1];
            if (event.key === 'ArrowDown' && rows[ri + 1]) next = rows[ri + 1].querySelectorAll('[role="gridcell"]')[ci];
            if (event.key === 'ArrowUp' && rows[ri - 1]) next = rows[ri - 1].querySelectorAll('[role="gridcell"]')[ci];
            if (next) {
                event.preventDefault();
                cell.setAttribute('tabindex', '-1');
                next.setAttribute('tabindex', '0');
                next.focus();
            }
        },
    }));

    Alpine.data('stairwellTabs', () => ({
        active: null,
        init() {
            this.active = this.$el.dataset.initial || null;
        },
        select(event) {
            this.active = event.currentTarget.dataset.section;
        },
        isActive(section) {
            return String(this.active) === String(section);
        },
    }));
});

// Service worker: static assets only (HTML and /livewire/* are NetworkOnly).
if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}
