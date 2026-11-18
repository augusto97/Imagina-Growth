import { describe, expect, it } from 'vitest';
import audienceTexts from '@/locales/es-CO/audiences.json';
import texts from '@/locales/es-CO/onsite-commerce.json';
import { RECO_KINDS, renderBannerText, usesPrivateVariables } from './api';

type Tree = { [key: string]: string | Tree };

function has(tree: Tree, path: string): boolean {
    let node: string | Tree = tree;
    for (const part of path.split('.')) {
        if (typeof node !== 'object' || !(part in node)) {
            return false;
        }
        node = node[part] as string | Tree;
    }
    return typeof node === 'string';
}

describe('Comercio en el sitio: textos de las claves dinámicas', () => {
    it('tiene nombre y lógica para cada tipo de recomendación, estado, motivo y audiencia', () => {
        const missing: string[] = [];
        const check = (tree: Tree, key: string) => {
            if (!has(tree, key)) missing.push(key);
        };
        for (const kind of RECO_KINDS) {
            check(texts, `onsiteCommerce.kinds.${kind}.name`);
            check(texts, `onsiteCommerce.kinds.${kind}.logic`);
            check(texts, `onsiteCommerce.reco.preview.empty.${kind}`);
        }
        for (const s of ['active', 'notified', 'cancelled']) check(texts, `onsiteCommerce.watchStatus.${s}`);
        for (const k of ['back_in_stock', 'price_drop']) check(texts, `onsiteCommerce.watchKind.${k}`);
        for (const a of ['points', 'pending_cart', 'at_risk', 'segment']) check(texts, `onsiteCommerce.banners.audience.${a}`);
        for (const n of ['product', 'category', 'cart', 'contact']) check(texts, `onsiteCommerce.reco.preview.need.${n}`);
        for (const p of ['recent_buyers_exclusion', 'high_value_seed', 'at_risk_reactivation']) {
            check(audienceTexts, `audiences.preset.${p}.title`);
            check(audienceTexts, `audiences.preset.${p}.help`);
        }
        for (const s of ['pending', 'active', 'paused', 'error']) check(audienceTexts, `audiences.status.${s}`);
        for (const p of ['target', 'exclusion', 'lookalike_seed']) check(audienceTexts, `audiences.purpose.${p}`);
        for (const r of ['explicit', 'unless_revoked']) check(audienceTexts, `audiences.consent.${r}.title`);
        expect(missing).toEqual([]);
    });
});

describe('Banners', () => {
    it('detecta el nombre y los puntos en el texto sin sesión', () => {
        expect(usesPrivateVariables('Hola, {{ nombre }}')).toBe(true);
        expect(usesPrivateVariables('Tienes {{puntos}} puntos')).toBe(true);
        expect(usesPrivateVariables('Tu carrito tiene {{carrito_items}} productos')).toBe(false);
        expect(usesPrivateVariables(null)).toBe(false);
    });

    it('arma la vista previa igual que el hub', () => {
        expect(renderBannerText('Hola, {{nombre}}: tienes {{puntos}} puntos', { nombre: 'Ana', puntos: '1.250' })).toBe('Hola, Ana: tienes 1.250 puntos');
        expect(renderBannerText('Hola {{nombre}}  y {{otra}}', {})).toBe('Hola y');
    });
});
