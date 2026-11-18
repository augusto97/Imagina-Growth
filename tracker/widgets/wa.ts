/*
 * Widget de WhatsApp (igr-w-wa.js, M12): botón flotante con el texto prellenado y el
 * token corto de atribución (tracker/CLAUDE.md §5) que el hub cruza con el mensaje
 * entrante. Envía `cta.clicked` con `wa_ref` antes de abrir WhatsApp.
 */
import { h, overlayHost, register, shadow } from './lib/dom';

const CSS =
    'a{position:fixed;bottom:20px;right:20px;z-index:2147482998;display:flex;align-items:center;gap:8px;min-width:56px;height:56px;padding:0 16px;border-radius:999px;background:#128c3a;color:#fff;text-decoration:none;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.25)}' +
    'a.left{right:auto;left:20px}svg{width:28px;height:28px;flex:none;fill:currentColor}a span{white-space:nowrap}' +
    '@media (max-width:600px){a span{display:none}a{padding:0 14px}}';

const ICON =
    'M16 3C8.8 3 3 8.7 3 15.8c0 2.5.7 4.9 2 7L3 29l6.4-2c2 1.1 4.3 1.7 6.6 1.7 7.2 0 13-5.7 13-12.8S23.2 3 16 3zm0 23.4c-2.1 0-4.1-.6-5.9-1.6l-.4-.2-3.8 1.2 1.2-3.7-.3-.4a10.4 10.4 0 0 1-1.7-5.8C5.1 9.9 10 5.1 16 5.1s10.9 4.8 10.9 10.7S22 26.4 16 26.4zm6-8c-.3-.2-1.9-.9-2.2-1s-.5-.2-.7.2-.8 1-1 1.2-.4.2-.7.1a8.9 8.9 0 0 1-4.4-3.8c-.3-.6.3-.5 1-1.7.1-.2 0-.4 0-.5l-1-2.4c-.3-.6-.5-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-.3.3-1.2 1.1-1.2 2.8s1.2 3.2 1.4 3.4c.2.2 2.4 3.7 5.8 5.1 2.2.9 3 1 4.1.8.7-.1 1.9-.8 2.2-1.5.3-.7.3-1.4.2-1.5l-.6-.4z';

/** Token corto sin caracteres ambiguos, como el del tracker. */
export function shortToken(len = 5): string {
    const abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const b = new Uint8Array(len);
    crypto.getRandomValues(b);
    let s = '';
    for (const x of b) s += abc[x % 32];
    return s;
}

register({
    wa_widget: (_host, live, x) => {
        const wa = live?.raw.wa;
        const number = String(wa?.number ?? '').replace(/\D/g, '');
        if (!live || !wa || number.length < 8 || number.length > 15) throw new Error('wa');
        const host = overlayHost('wa');
        const root = shadow(host, CSS);
        const label = live.content.cta || live.content.title || 'Escríbenos por WhatsApp';
        const base = 'https://wa.me/' + number;
        const a = h('a', { href: base + (wa.text ? '?text=' + encodeURIComponent(wa.text) : ''), target: '_blank', rel: 'noopener', class: wa.position === 'left' ? 'left' : null, 'aria-label': label });
        a.innerHTML = '<svg viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="' + ICON + '"/></svg>';
        a.append(h('span', null, label));
        root.append(a);
        a.addEventListener('click', () => {
            // Referencia corta en el mensaje si el sitio tiene activa la atribución de WhatsApp.
            const conf = x.waRef ?? {};
            let token: string | null = null;
            let text = wa.text ?? '';
            if ((conf.widgets ?? conf.enabled) !== false) {
                token = shortToken(5);
                text = (conf.format ?? '{text} (ref: {token})').replace('{text}', text).replace('{token}', token).trim();
            }
            const href = base + (text ? '?text=' + encodeURIComponent(text) : '');
            a.href = href;
            const props: Record<string, unknown> = { kind: 'whatsapp', target: href.slice(0, 2048), label: label.slice(0, 300) };
            if (token) props['wa_ref'] = token;
            try {
                (window as unknown as { igr?: { push: (...c: unknown[]) => number } }).igr?.push(['track', 'cta.clicked', props]);
            } catch {
                /* sin tracker */
            }
            x.mark(live, 'submit');
            x.track('widget.submitted', live);
            x.flush();
        });
    },
});
