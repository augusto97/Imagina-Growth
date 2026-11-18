/*
 * Banner de cookies ligero y propio (cumplimiento.md §3.3, modo «informado»).
 * Solo se carga si el sitio no tiene otro banner y el dueño lo activó.
 * Guarda la decisión en `_igr_consent` (a1m1 = analítica sí, marketing sí).
 */
interface BannerConf {
    text: string;
    accept: string;
    reject: string;
    privacyUrl?: string;
    privacyLabel?: string;
}

const W = window as unknown as { igrConfig?: { banner?: BannerConf; cookieDomain?: string }; igr?: unknown[] };

function decide(all: boolean, host: HTMLElement): void {
    const v = all ? 'a1m1' : 'a0m0';
    const d = W.igrConfig?.cookieDomain;
    document.cookie = '_igr_consent=' + v + '; Max-Age=' + 365 * 86400 + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '') + (d ? '; Domain=' + d : '');
    (W.igr = W.igr ?? []).push(['consent', { analytics: all, marketing: all, source: 'igr-banner' }]);
    document.dispatchEvent(new Event('igr:consent'));
    host.remove();
}

function render(b: BannerConf): void {
    if (/(?:^|; )_igr_consent=/.test(document.cookie)) return;
    const host = document.createElement('div');
    host.setAttribute('data-igr-banner', '');
    const root = host.attachShadow({ mode: 'open' });
    const box = document.createElement('div');
    box.setAttribute('role', 'region');
    box.setAttribute('aria-label', b.text.slice(0, 60));
    box.className = 'b';
    const p = document.createElement('p');
    p.textContent = b.text + ' ';
    if (b.privacyUrl) {
        const a = document.createElement('a');
        a.href = b.privacyUrl;
        a.textContent = b.privacyLabel ?? b.privacyUrl;
        p.appendChild(a);
    }
    const yes = document.createElement('button');
    yes.textContent = b.accept;
    yes.className = 'y';
    const no = document.createElement('button');
    no.textContent = b.reject;
    yes.onclick = () => decide(true, host);
    no.onclick = () => decide(false, host);
    const style = document.createElement('style');
    style.textContent =
        '.b{position:fixed;inset:auto 12px 12px 12px;z-index:2147483000;max-width:640px;margin:0 auto;background:#111827;color:#f9fafb;font:14px/1.45 system-ui,sans-serif;border-radius:10px;padding:14px 16px;box-shadow:0 8px 30px rgba(0,0,0,.25);display:flex;gap:12px;align-items:center;flex-wrap:wrap}' +
        'p{margin:0;flex:1 1 260px}a{color:#bfdbfe}button{font:inherit;border-radius:8px;padding:8px 14px;cursor:pointer;border:1px solid #9ca3af;background:transparent;color:#f9fafb}' +
        'button.y{background:#f9fafb;color:#111827;border-color:#f9fafb}button:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}';
    box.append(p, no, yes);
    root.append(style, box);
    document.body.appendChild(host);
}

try {
    const b = W.igrConfig?.banner;
    if (b) {
        if (document.body) render(b);
        else document.addEventListener('DOMContentLoaded', () => render(b));
    }
} catch {
    /* nunca hacia la página */
}
