import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import { LIQUID_ATTR, escapeAttr, liquidChip, normalizeMjml, wrapLiquid } from './liquid';

const t = (key: string, opts?: Record<string, unknown>): string => (opts ? i18n.t(key, opts) : i18n.t(key));

describe('wrapLiquid', () => {
    it('convierte en fichas el Liquid del texto y deja quietos los atributos', () => {
        const html = '<mj-text>Hola {{ contacto.nombre }}, <a href="{{ enlaces.baja }}">baja</a></mj-text>';
        expect(wrapLiquid(html)).toBe(`<mj-text>Hola <span ${LIQUID_ATTR}="{{ contacto.nombre }}"></span>, <a href="{{ enlaces.baja }}">baja</a></mj-text>`);
    });

    it('envuelve las etiquetas de lógica y escapa las comillas en el atributo', () => {
        const out = wrapLiquid('{% if tienda.logo != "" %}<img src="{{ tienda.logo }}">{% else %}{{ tienda.nombre }}{% endif %}');
        expect(out).toContain(`<span ${LIQUID_ATTR}="{% if tienda.logo != &quot;&quot; %}"></span>`);
        expect(out).toContain('<img src="{{ tienda.logo }}">');
        expect(out).toContain(`<span ${LIQUID_ATTR}="{% else %}"></span>`);
        expect(out).toContain(`<span ${LIQUID_ATTR}="{{ tienda.nombre }}"></span>`);
        expect(out.match(new RegExp(LIQUID_ATTR, 'g'))).toHaveLength(4);
    });

    it('no toca el <mj-head>, los estilos ni un > dentro de un atributo entre comillas', () => {
        const html = '<mj-head><mj-style>.a { color: red }</mj-style><mj-attributes><mj-text padding="{{ x }}"></mj-text></mj-attributes></mj-head><mj-text title="a > b">{{ y }}</mj-text>';
        const out = wrapLiquid(html);
        expect(out).toContain('<mj-head><mj-style>.a { color: red }</mj-style><mj-attributes><mj-text padding="{{ x }}"></mj-text></mj-attributes></mj-head>');
        expect(out).toContain(`<mj-text title="a > b"><span ${LIQUID_ATTR}="{{ y }}"></span></mj-text>`);
    });

    it('lo que ya es ficha no se vuelve a envolver (al terminar de editar un texto)', () => {
        const once = wrapLiquid('<p>{{ cupon.codigo }}</p>');
        const asRendered = once.replace('></span>', ' class="igr-lq igr-lq--var" contenteditable="false">Código del cupón</span>');
        expect(wrapLiquid(asRendered)).toBe(asRendered);
    });

    it('deja un {{ sin cerrar como texto', () => {
        expect(wrapLiquid('Usa {{ sin cierre')).toBe('Usa {{ sin cierre');
    });

    it('escapa los atributos', () => {
        expect(escapeAttr('a "b" & <c>')).toBe('a &quot;b&quot; &amp; &lt;c&gt;');
    });
});

describe('normalizeMjml', () => {
    it('cierra las etiquetas mj-* con cierre propio para el parser HTML', () => {
        expect(normalizeMjml('<mj-column><mj-image src="a.png" alt="x/y" /><mj-text>Hola</mj-text></mj-column>')).toBe(
            '<mj-column><mj-image src="a.png" alt="x/y"></mj-image><mj-text>Hola</mj-text></mj-column>',
        );
    });

    it('deja planas las reglas de <mj-attributes> (también las que un error anidó al guardar)', () => {
        const nested = '<mj-attributes><mj-all font-family="Inter"><mj-text padding="8px 0"><mj-section background-color="#ffffff"></mj-section></mj-text></mj-all></mj-attributes>';
        expect(normalizeMjml(nested)).toBe('<mj-attributes><mj-all font-family="Inter"></mj-all><mj-text padding="8px 0"></mj-text><mj-section background-color="#ffffff"></mj-section></mj-attributes>');
        const selfClosing = '<mj-attributes>\n<mj-all font-family="Inter" />\n<mj-text padding="8px 0" />\n</mj-attributes>';
        expect(normalizeMjml(selfClosing)).toBe('<mj-attributes><mj-all font-family="Inter"></mj-all><mj-text padding="8px 0"></mj-text></mj-attributes>');
    });

    it('no cambia un MJML que ya está bien', () => {
        const ok = '<mjml><mj-body><mj-section><mj-column><mj-text>Hola</mj-text></mj-column></mj-section></mj-body></mjml>';
        expect(normalizeMjml(ok)).toBe(ok);
        expect(normalizeMjml(normalizeMjml(ok))).toBe(ok);
    });
});

describe('liquidChip', () => {
    it('nombra las variables en palabras', () => {
        expect(liquidChip('{{ tienda.nombre }}', t)).toEqual({ kind: 'var', label: 'Nombre del negocio' });
        expect(liquidChip("{{ contacto.nombre | default: 'Hola' }}", t)).toEqual({ kind: 'var', label: 'Nombre' });
        expect(liquidChip('{{ p.precio | moneda }}', t)).toEqual({ kind: 'var', label: 'Precio' });
        expect(liquidChip('{{ algo.ultimo_pedido }}', t)).toEqual({ kind: 'var', label: 'Ultimo pedido' });
    });

    it('lee las condiciones y las listas', () => {
        expect(liquidChip('{% if tienda.logo != "" %}', t)).toEqual({ kind: 'logic', label: 'Si hay logo' });
        expect(liquidChip('{% if p.imagen and p.imagen != "" %}', t)).toEqual({ kind: 'logic', label: 'Si hay imagen del producto' });
        expect(liquidChip('{% if producto.precio_anterior != "" and producto.precio_anterior != producto.precio %}', t).label).toBe('Si se cumple la condición');
        expect(liquidChip('{% else %}', t)).toEqual({ kind: 'logic', label: 'Si no' });
        expect(liquidChip('{% endif %}', t).kind).toBe('end');
        expect(liquidChip('{% for p in recomendaciones.para_ti limit: 3 %}', t)).toEqual({ kind: 'logic', label: 'Por cada recomendado para el contacto (hasta 3)' });
        expect(liquidChip('{% if tienda.whatsapp_url != "" %}', t).label).toBe('Si hay WhatsApp del negocio');
        expect(liquidChip('{% for n in (0..10) %}', t).label).toBe('Por cada número');
        expect(liquidChip('{{ n }}', t).label).toBe('Número');
        expect(liquidChip('{% endfor %}', t).kind).toBe('end');
        expect(liquidChip('{% assign x = 1 %}', t)).toEqual({ kind: 'logic', label: 'Lógica' });
    });
});
