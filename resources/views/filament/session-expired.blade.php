{{-- Panels: an expired session (419) reloads the page instead of Livewire's English "This page has expired" dialog —
     a remembered device is signed in again silently (same as resources/js/app.js on resident pages). Inline is
     allowed here only because the panel CSP already needs 'unsafe-inline' (docs/SECURITY.md, tracked debt). --}}
<script>
    (function () {
        function register() {
            window.Livewire.hook('request', function (ctx) {
                ctx.fail(function (failure) {
                    if (failure.status === 419) {
                        failure.preventDefault();
                        window.location.reload();
                    }
                });
            });
        }
        if (window.Livewire) { register(); } else { document.addEventListener('livewire:init', register); }
    })();
</script>
