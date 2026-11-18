/*
 * «Usar saldo» en el checkout (igr-wallet.js, M13, api-connector.md §5.5). Lo encola el
 * plugin solo en el checkout y solo para clientes con sesión. Con `igrUser.token` pide el
 * saldo a /me y, si hay, muestra el botón; el clic llama al plugin (wc-ajax), que llama
 * al hub (firmado, 3 s) y aplica el cupón de un solo uso al carrito.
 */
interface WWin {
    igrConfig?: { widgetsUrl?: string; currency?: string };
    igrUser?: { token?: string };
    jQuery?: (el: unknown) => { trigger: (ev: string) => void };
    wp?: { data?: { dispatch?: (store: string) => { invalidateResolutionForStore?: () => void } | undefined } };
}

const W = window as unknown as WWin;

export function fmt(v: string | number, currency: string): string {
    const n = Number(v);
    try {
        return new Intl.NumberFormat(document.documentElement.lang || 'es-CO', { style: 'currency', currency, minimumFractionDigits: n % 1 ? 2 : 0 }).format(n);
    } catch {
        return String(v);
    }
}

/** Refresca el checkout clásico o el de bloques para que se vea el cupón. */
function refresh(): void {
    try {
        const cart = W.wp?.data?.dispatch?.('wc/store/cart');
        if (cart && cart.invalidateResolutionForStore) cart.invalidateResolutionForStore();
    } catch {
        /* sin bloques */
    }
    try {
        W.jQuery?.(document.body).trigger('update_checkout');
    } catch {
        /* sin jQuery */
    }
}

export function init(slot: HTMLElement): void {
    const ajax = slot.getAttribute('data-ajax');
    const nonce = slot.getAttribute('data-nonce');
    const token = W.igrUser?.token;
    const base = (W.igrConfig?.widgetsUrl ?? '').replace(/widgets\.json(\?.*)?$/, '');
    if (!ajax || !nonce || !token || !base) return;
    fetch(base + 'me?token=' + encodeURIComponent(token), { credentials: 'omit' })
        .then((r) => (r.ok ? r.json() : null))
        .then((me: { wallet?: { balance?: string; currency?: string } } | null) => {
            const bal = Number(me?.wallet?.balance);
            if (!(bal > 0)) return;
            const cur = me?.wallet?.currency || W.igrConfig?.currency || 'COP';
            const text = slot.querySelector('[data-igr-wallet-text]');
            const btn = slot.querySelector('button');
            const msg = slot.querySelector('[data-igr-wallet-msg]');
            if (!text || !btn || !msg) return;
            text.textContent = 'Tienes ' + fmt(bal, cur) + ' de saldo a favor.';
            slot.hidden = false;
            slot.style.display = '';
            btn.addEventListener('click', () => {
                btn.disabled = true;
                msg.textContent = '';
                const body = new URLSearchParams({ security: nonce });
                fetch(ajax, { method: 'POST', credentials: 'same-origin', body })
                    .then((r) => r.json().catch(() => ({})))
                    .then((res: { ok?: boolean; message?: string }) => {
                        msg.textContent = res.message || (res.ok ? 'Aplicamos tu saldo.' : 'No pudimos usar tu saldo ahora.');
                        if (res.ok) {
                            btn.hidden = true;
                            refresh();
                        } else btn.disabled = false;
                    })
                    .catch(() => {
                        msg.textContent = 'No pudimos usar tu saldo ahora.';
                        btn.disabled = false;
                    });
            });
        })
        .catch(() => undefined);
}

try {
    document.querySelectorAll<HTMLElement>('[data-igr-slot="wallet"]').forEach(init);
} catch {
    /* nunca hacia la página */
}
