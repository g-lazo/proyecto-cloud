</main>
<footer class="max-w-5xl mx-auto px-6 py-12 text-xs text-slate-400 text-center">
    StudentWallet · Proyecto final Cloud Computing 2026
</footer>
<script>
    // Prompt para abonar/retirar a metas: pide monto y rellena input hidden
    function promptMonto(form, accionLabel) {
        const max  = parseFloat(form.dataset.max);
        const meta = form.dataset.meta || '';
        const v = prompt(
            `¿Cuánto quieres ${accionLabel}${meta ? ' a "' + meta + '"' : ''}? (máx $${max.toFixed(2)})`
        );
        if (v === null) return false;
        const num = parseFloat(String(v).replace(',', '.'));
        if (isNaN(num) || num <= 0) {
            alert('Monto inválido. Debe ser mayor a 0.');
            return false;
        }
        if (num > max) {
            alert(`No puede ser mayor a $${max.toFixed(2)}.`);
            return false;
        }
        const input = form.querySelector('input[name="abono"], input[name="retiro"]');
        if (!input) return false;
        input.value = num.toFixed(2);
        return true;
    }
</script>
</body>
</html>
