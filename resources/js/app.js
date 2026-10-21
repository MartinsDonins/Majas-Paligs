/*
 * Mājas Palīgs — resident app JS. Alpine is bundled by Livewire (CSP-safe build on resident pages), so every
 * Alpine component is registered here with Alpine.data(): templates only reference properties and methods,
 * never inline expressions (no 'unsafe-eval').
 */
import {
    browserSupportsWebAuthn,
    browserSupportsWebAuthnAutofill,
    startAuthentication,
    startRegistration,
} from '@simplewebauthn/browser';

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

    /**
     * Bottom sheet with Esc and a visible "Aizvērt" button. `data-open="1"` opens it on load (e.g. Home tile
     * "Ziņot par siltumu" → /heating?report=1). Focus moves into the sheet when it opens and back on close; the
     * dialog carries x-trap.noscroll.inert (Livewire bundles the Alpine focus plugin), so it is a real modal.
     * Listens for `mp-sheet-open` with detail.id === data-sheet-id (e.g. "Mainīt auto" from the delete warning).
     */
    Alpine.data('sheet', () => ({
        open: false,
        opener: null,
        init() {
            if (this.$el.dataset.open === '1') {
                this.open = true;
                this.$nextTick(() => this.focusFirst());
            }
            window.addEventListener('mp-sheet-close', () => {
                this.open = false;
            });
            window.addEventListener('mp-sheet-open', (event) => {
                const id = this.$el.dataset.sheetId;
                if (id && event.detail && String(event.detail.id) === id) {
                    this.show();
                }
            });
        },
        show() {
            this.opener = document.activeElement;
            this.open = true;
            this.$nextTick(() => this.focusFirst());
        },
        hide() {
            this.open = false;
            if (this.opener && this.opener.focus) {
                this.opener.focus();
            }
        },
        focusFirst() {
            const panel = this.$el.querySelector('[role="dialog"]');
            const target = panel && panel.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (target) {
                target.focus();
            }
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

    /**
     * Opens another bottom sheet by its data-sheet-id (CSP-safe: no object literal in the template).
     * Usage: <button x-data="sheetOpener" data-sheet="change-12" x-on:click="openSheet">
     */
    Alpine.data('sheetOpener', () => ({
        openSheet() {
            window.dispatchEvent(new CustomEvent('mp-sheet-open', { detail: { id: this.$el.dataset.sheet } }));
        },
    }));

    /** Moves focus to a confirmation block when it appears (tabindex="-1" on the element). */
    Alpine.data('focusOnShow', () => ({
        init() {
            this.$nextTick(() => this.$el.focus());
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
            // Enter / Space on a focused cell opens its column summary (the inner button is not in the tab order).
            if ((event.key === 'Enter' || event.key === ' ') && event.target === cell) {
                const action = cell.querySelector('button');
                if (action) {
                    event.preventDefault();
                    action.click();
                }
                return;
            }
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

    /**
     * One of several panels (riser summary sheets per grid column). Triggers carry data-key; panels call
     * isOpen('<key>'). Esc / "Aizvērt" close it and return focus to the trigger.
     */
    Alpine.data('panelSwitch', () => ({
        current: null,
        opener: null,
        open(event) {
            this.opener = event.currentTarget;
            this.current = String(event.currentTarget.dataset.key);
            // Move focus into the dialog (x-trap keeps it there): its visible "Aizvērt" button first.
            this.$nextTick(() => {
                const panel = this.$el.querySelector(`[data-panel="${this.current}"]`);
                const close = panel && panel.querySelector('button');
                if (close) {
                    close.focus();
                }
            });
        },
        close() {
            this.current = null;
            if (this.opener && this.opener.focus) {
                this.opener.focus();
            }
        },
        isOpen(key) {
            return this.current === String(key);
        },
        get anyOpen() {
            return this.current !== null;
        },
        onKeydown(event) {
            if (event.key === 'Escape') {
                this.close();
            }
        },
    }));

    /** Visible countdown for the undo window ("Atsaukt (8)"); hides itself at zero. */
    Alpine.data('countdown', () => ({
        left: 0,
        timer: null,
        init() {
            this.left = parseInt(this.$el.dataset.seconds || '8', 10);
            this.timer = setInterval(() => {
                this.left = Math.max(0, this.left - 1);
                if (this.left === 0) {
                    clearInterval(this.timer);
                }
            }, 1000);
        },
        get active() {
            return this.left > 0;
        },
        destroy() {
            clearInterval(this.timer);
        },
    }));

    /**
     * Plate forms (Package C). The typed plate is passed as an ACTION ARGUMENT and the field is cleared afterwards,
     * so a plate never sits in a Livewire public property / snapshot (MVP-SPEC §0).
     * Usage: <form x-data="plateForm" data-method="check" x-on:submit="submit"> … <input data-plate> …
     */
    Alpine.data('plateForm', () => ({
        busy: false,
        async submit(event) {
            event.preventDefault();
            const input = this.$el.querySelector('[data-plate]');
            if (!input || this.busy) {
                return;
            }
            this.busy = true;
            try {
                await this.$wire.call(this.$el.dataset.method || 'check', input.value);
                // Keep the typed plate when the server rejected it (aria-invalid after the re-render), so a typo can
                // be fixed instead of retyping the whole plate.
                if (this.$el.dataset.keep !== '1' && input.getAttribute('aria-invalid') !== 'true') {
                    input.value = '';
                }
            } finally {
                this.busy = false;
            }
        },
    }));

    /**
     * Sighting photo: decode in the browser (iOS Safari decodes HEIC into the canvas), downscale to 1600 px and
     * re-encode as JPEG before upload — the canvas also drops EXIF / GPS. Undecodable files show the friendly
     * "nofotografējiet ar pogu «Fotografēt»" message. The server re-checks and re-encodes anyway.
     */
    Alpine.data('photoCapture', () => ({
        status: '',
        pickCamera() {
            this.$refs.camera.click();
        },
        pickFile() {
            this.$refs.file.click();
        },
        async onChange(event) {
            const file = event.target.files && event.target.files[0];
            event.target.value = '';
            if (!file) {
                return;
            }
            this.status = 'working';
            try {
                const blob = await this.toJpeg(file);
                const jpeg = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
                this.$wire.upload('photo', jpeg, () => {
                    this.status = 'ready';
                }, () => {
                    this.status = 'error';
                });
            } catch (e) {
                this.status = 'error';
            }
        },
        toJpeg(file) {
            return new Promise((resolve, reject) => {
                const url = URL.createObjectURL(file);
                const img = new Image();
                img.onload = () => {
                    const max = 1600;
                    const scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
                    canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    URL.revokeObjectURL(url);
                    canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('encode'))), 'image/jpeg', 0.85);
                };
                img.onerror = () => {
                    URL.revokeObjectURL(url);
                    reject(new Error('decode'));
                };
                img.src = url;
            });
        },
        clear() {
            this.status = '';
            this.$wire.call('removePhoto');
        },
        get isWorking() {
            return this.status === 'working';
        },
        get isReady() {
            return this.status === 'ready';
        },
        get isError() {
            return this.status === 'error';
        },
    }));

    /** Friendly rate-limit countdown "mm:ss" (data-seconds); re-enables the form when it reaches zero. */
    Alpine.data('retryCountdown', () => ({
        left: 0,
        timer: null,
        init() {
            this.left = parseInt(this.$el.dataset.seconds || '0', 10);
            this.timer = setInterval(() => {
                this.left = Math.max(0, this.left - 1);
                if (this.left === 0) {
                    clearInterval(this.timer);
                }
            }, 1000);
        },
        get label() {
            const m = Math.floor(this.left / 60);
            const s = this.left % 60;
            return `${m}:${String(s).padStart(2, '0')}`;
        },
        get waiting() {
            return this.left > 0;
        },
        get done() {
            return this.left === 0;
        },
        destroy() {
            clearInterval(this.timer);
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

    /**
     * "Ieiet ar pirkstu / Face ID" and the passkey step-up (ADR 0006). The Livewire component (AssertsPasskey) issues
     * single-use options and verifies the signed response; nothing secret ever reaches JS. With data-autofill="1" the
     * phone also offers its passkey in the e-mail field's autofill (conditional UI, input autocomplete="… webauthn").
     * A newer ceremony (the button) aborts the pending autofill one.
     */
    Alpine.data('passkeyAuth', () => ({
        supported: false,
        busy: false,
        error: '',
        failedText: '',
        init() {
            this.failedText = this.$el.dataset.error || '';
            this.supported = browserSupportsWebAuthn();
            if (this.supported && this.$el.dataset.autofill === '1') {
                browserSupportsWebAuthnAutofill().then((ok) => {
                    if (ok) {
                        this.run(true);
                    }
                }).catch(() => {});
            }
        },
        start() {
            this.run(false);
        },
        async run(autofill) {
            this.error = '';
            let optionsJSON;
            try {
                const json = await this.$wire.passkeyOptions();
                if (!json) {
                    if (!autofill) {
                        this.error = this.failedText;
                    }
                    return;
                }
                optionsJSON = JSON.parse(json);
            } catch (e) {
                return;
            }
            let response;
            try {
                response = await startAuthentication({ optionsJSON, useBrowserAutofill: autofill });
            } catch (e) {
                // Cancelled, timed out or replaced by a newer ceremony: only an explicit tap shows a hint.
                if (!autofill && !(e && (e.name === 'AbortError' || e.code === 'ERROR_CEREMONY_ABORTED'))) {
                    this.error = this.failedText;
                }
                return;
            }
            this.busy = true;
            try {
                await this.$wire.passkeyAssert(JSON.stringify(response));
            } finally {
                this.busy = false;
            }
        },
    }));

    /** "Izveidot ieejas atslēgu" (RegistersPasskey). Shows a hint instead of the button where passkeys are missing. */
    Alpine.data('passkeyRegister', () => ({
        supported: false,
        busy: false,
        error: '',
        failedText: '',
        existsText: '',
        init() {
            this.failedText = this.$el.dataset.error || '';
            this.existsText = this.$el.dataset.exists || this.failedText;
            this.supported = browserSupportsWebAuthn();
        },
        get unsupported() {
            return !this.supported;
        },
        async register() {
            this.error = '';
            this.busy = true;
            try {
                const json = await this.$wire.passkeyRegisterOptions();
                if (!json) {
                    return;
                }
                const response = await startRegistration({ optionsJSON: JSON.parse(json) });
                await this.$wire.passkeyRegister(JSON.stringify(response));
            } catch (e) {
                this.error = e && e.name === 'InvalidStateError' ? this.existsText : this.failedText;
            } finally {
                this.busy = false;
            }
        },
    }));
});

// An expired session (419, e.g. after a long pause without "Atcerēties šo telefonu") reloads the page instead of
// Livewire's English "This page has expired" dialog: a remembered device is signed in again silently, others land on
// the login page in their language.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419) {
                preventDefault();
                window.location.reload();
            }
        });
    });
});

// wire:navigate feedback without Livewire's injected <style> (CSP): a CSS-only bar while the next page loads.
document.addEventListener('livewire:navigate', () => document.documentElement.classList.add('is-navigating'));
document.addEventListener('livewire:navigated', () => document.documentElement.classList.remove('is-navigating'));

// LV/RU toggle: post the page the person is ON (path + query, never the #fragment, which may hold a code) — the
// server-rendered value can be stale after wire:navigate. The server re-validates it (App\Support\SafeReturn).
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-locale-form')) {
        return;
    }
    const input = form.querySelector('input[name="return"]');
    if (input) {
        input.value = window.location.pathname + window.location.search;
    }
}, true);

// Service worker: static assets only (HTML and /livewire/* are NetworkOnly).
if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}
