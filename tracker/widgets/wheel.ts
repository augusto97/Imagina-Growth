/*
 * Ruleta (igr-w-wheel.js, M12). El premio lo decide el hub: la ruleta solo gira hasta
 * esa casilla (sin animación si se pide menos movimiento).
 */
import { h, register } from './lib/dom';
import { capture, frame, type Frame } from './lib/forms';
import type { Ctx, Live } from './lib/types';

const CSS =
    '.ws{display:flex;flex-wrap:wrap;gap:20px;align-items:center;justify-content:center}.wb{position:relative;width:240px;height:240px;flex:none}' +
    '.wh{width:100%;height:100%;border-radius:50%;border:4px solid #fff;box-shadow:0 0 0 2px var(--a);position:relative;overflow:hidden}' +
    '.wl{position:absolute;left:50%;top:50%;width:46%;margin-top:-10px;height:20px;transform-origin:0 50%;text-align:right;padding-right:8px;font-weight:700;font-size:13px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.5)}' +
    '.pt{position:absolute;left:50%;top:-6px;margin-left:-10px;border:10px solid transparent;border-top:18px solid #111827;z-index:1}';

/** Ruleta: el hub decide el premio y la ruleta gira hasta esa casilla. */
function wheel(f: Frame, live: Live, x: Ctx): void {
    const slices = (live.content.slices ?? []).slice(0, 12);
    const n = Math.max(slices.length, 1);
    const seg = 360 / n;
    const colors = ['var(--a)', '#f59e0b', '#059669', '#dc2626', '#7c3aed', '#0891b2'];
    // La última casilla no repite el color de la primera.
    const color = (i: number): string => colors[i > 0 && i === n - 1 && i % 6 === 0 ? 1 : i % 6] as string;
    const grad = slices.map((_, i) => color(i) + ' ' + i * seg + 'deg ' + (i + 1) * seg + 'deg').join(',');
    const disc = h('div', { class: 'wh', style: 'background:conic-gradient(' + (grad || 'var(--a) 0 360deg') + ')' });
    slices.forEach((s, i) => disc.append(h('span', { class: 'wl', style: 'transform:rotate(' + ((i + 0.5) * seg - 90) + 'deg)' }, s.label)));
    const wb = h('div', { class: 'wb', 'aria-hidden': 'true' }, h('span', { class: 'pt' }), disc);
    const side = h('div', { style: 'flex:1;min-width:220px' });
    const keep = Array.from(f.box.children).filter((n2) => n2.classList.contains('x'));
    f.box.replaceChildren(...keep, h('div', { class: 'ws' }, wb, side), h('p', { class: 'sr' }, 'Premios posibles: ' + slices.map((s) => s.label).join(', ') + '.'));
    capture(f, live, x, {
        into: side,
        after: (res, fin) => {
            const idx = Math.max(0, slices.findIndex((s) => s.key === res.prize?.key));
            const reduce = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
            disc.style.transition = reduce ? 'none' : 'transform 4s cubic-bezier(.17,.67,.2,1)';
            disc.style.transform = 'rotate(' + (360 * 5 - (idx + 0.5) * seg) + 'deg)';
            setTimeout(fin, reduce ? 0 : 4200);
        },
    });
}

register({
    spin_wheel: (host, live, x) => {
        if (live) wheel(frame(live, x, host ? 'inline' : 'center', host, CSS), live, x);
    },
});
