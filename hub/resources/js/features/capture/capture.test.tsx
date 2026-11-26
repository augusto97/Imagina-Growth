import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { LandingPreview } from './LandingPreview';
import {
    consentsAfter,
    customKey,
    draftFrom,
    fieldsProblem,
    isHttpsUrl,
    keepPossible,
    moveField,
    paragraphs,
    patchFrom,
    possibleChannels,
    readableOn,
    rebase,
    slugValid,
    waMessage,
} from './model';
import type { LandingField, LandingPageDetail } from './types';

afterEach(cleanup);

const page: LandingPageDetail = {
    id: 'lp_1',
    name: 'Valoración gratis',
    slug: 'valoracion-gratis',
    status: 'draft',
    title: 'Agenda tu valoración',
    url: 'https://growth.imagina.cloud/p/valoracion-gratis',
    qr_url: 'https://growth.imagina.cloud/p/valoracion-gratis?utm_source=qr',
    qr: { svg: '/api/qr?format=svg', png: '/api/qr?format=png' },
    consent_channels: ['whatsapp', 'email'],
    site_id: null,
    whatsapp_link: null,
    stats: null,
    published_at: null,
    created_at: null,
    updated_at: null,
    content: {
        title: 'Agenda tu valoración',
        body: 'Te escribimos hoy.\n\nSin costo.',
        button: 'Quiero mi cita',
        success_title: null,
        success_message: null,
        redirect_url: null,
        color: '#0F766E',
        logo_key: null,
        logo_url: null,
        image_key: null,
        image_url: null,
        whatsapp: { enabled: true, label: null, message: 'Hola' },
        logo_src: null,
        image_src: null,
    },
    fields: [
        { key: 'name', label: 'Nombre', type: 'text', required: true },
        { key: 'phone', label: 'WhatsApp', type: 'tel', required: true },
        { key: 'email', label: 'Correo', type: 'email', required: false },
    ],
    settings: { indexable: false },
    preview_url: 'https://growth.imagina.cloud/p/valoracion-gratis?vista_previa=1',
    warnings: [],
};

describe('borrador de la página', () => {
    it('sin cambios no hay nada que guardar; con cambios manda solo lo que cambió', () => {
        const saved = draftFrom(page);
        expect(patchFrom(draftFrom(page), saved)).toEqual({});
        const draft = { ...saved, content: { ...saved.content, title: 'Nuevo título', button: '  ' } };
        expect(patchFrom(draft, saved)).toEqual({ content: { title: 'Nuevo título', button: null } });
    });

    it('al cambiar lo guardado conserva lo que la persona estaba editando', () => {
        const before = draftFrom(page);
        const editing = { ...before, content: { ...before.content, title: 'Lo que escribo' } };
        const after = draftFrom({ ...page, content: { ...page.content, logo_key: 'abc.webp', title: 'Agenda tu valoración' } });
        const merged = rebase(editing, before, after);
        expect(merged.content.title).toBe('Lo que escribo');
        expect(merged.content.logo_key).toBe('abc.webp');
    });
});

describe('campos y casillas', () => {
    const fields = page.fields;

    it('cada casilla va junto a su campo: correo con el correo, WhatsApp y SMS con el teléfono', () => {
        expect(consentsAfter({ key: 'phone' }, ['whatsapp', 'email', 'sms'])).toEqual(['whatsapp', 'sms']);
        expect(consentsAfter({ key: 'email' }, ['whatsapp', 'email'])).toEqual(['email']);
        expect(consentsAfter({ key: 'name' }, ['whatsapp', 'email'])).toEqual([]);
    });

    it('al quitar el teléfono se van las casillas de WhatsApp y SMS', () => {
        const withoutPhone = fields.filter((f) => f.key !== 'phone');
        expect(possibleChannels(withoutPhone)).toEqual(['email']);
        expect(keepPossible(['whatsapp', 'email', 'sms'], withoutPhone)).toEqual(['email']);
    });

    it('exige correo o WhatsApp, un nombre en cada campo y opciones en las listas', () => {
        expect(fieldsProblem(fields, 12)).toBeNull();
        expect(fieldsProblem([{ key: 'name', label: 'Nombre' }], 12)).toBe('needs_contact');
        expect(fieldsProblem(fields, 2)).toBe('too_many');
        expect(fieldsProblem([...fields, { key: 'custom_x', label: ' ', type: 'text' }], 12)).toBe('empty_label');
        expect(fieldsProblem([...fields, { key: 'service', label: 'Servicio', options: [' '] }], 12)).toBe('empty_options');
    });

    it('las claves de las preguntas propias no se repiten y no llevan tildes', () => {
        const mine: LandingField[] = [...fields, { key: 'custom_tienes_seguro', label: '¿Tienes seguro?', type: 'checkbox' }];
        expect(customKey('¿Tienes seguro?', fields)).toBe('custom_tienes_seguro');
        expect(customKey('¿Tienes seguro?', mine)).toBe('custom_tienes_seguro_2');
    });

    it('mueve un campo sin salirse de la lista', () => {
        expect(moveField(fields, 0, 1).map((f) => f.key)).toEqual(['phone', 'name', 'email']);
        expect(moveField(fields, 0, -1).map((f) => f.key)).toEqual(['name', 'phone', 'email']);
    });
});

describe('reglas de la página pública', () => {
    it('el texto del botón es blanco u oscuro según el contraste con el color de la marca', () => {
        expect(readableOn('#0F766E')).toBe('#ffffff');
        expect(readableOn('#FACC15')).toBe('#0f172a');
        expect(readableOn('rojo')).toBe('#ffffff');
    });

    it('párrafos separados por una línea en blanco, dirección válida y solo https', () => {
        expect(paragraphs('Uno\nsigue\n\n\nDos')).toEqual(['Uno\nsigue', 'Dos']);
        expect(slugValid('clinica-sonrisa')).toBe(true);
        expect(slugValid('Clínica')).toBe(false);
        expect(slugValid('a--b')).toBe(false);
        expect(isHttpsUrl('https://tuweb.com')).toBe(true);
        expect(isHttpsUrl('http://tuweb.com')).toBe(false);
    });

    it('el chat abre con el mensaje y el código de atribución', () => {
        expect(waMessage('Hola, quiero una cita', 'Q12AB34')).toBe('Hola, quiero una cita (ref: Q12AB34)');
        expect(waMessage('', 'Q12AB34')).toBe('(ref: Q12AB34)');
    });
});

describe('vista previa', () => {
    it('pinta el título, los campos con su casilla al lado y el botón de WhatsApp', () => {
        render(<LandingPreview draft={draftFrom(page)} device="mobile" state="form" business="Clínica Sonrisa" logo={null} image={null} options={undefined} />);
        expect(screen.getByRole('heading', { level: 1, name: 'Agenda tu valoración' })).toBeInTheDocument();
        expect(screen.getByText('Sin costo.')).toBeInTheDocument();
        expect(screen.getByText('Quiero mi cita')).toBeInTheDocument();
        expect(screen.getByText('Escríbenos por WhatsApp')).toBeInTheDocument();
        expect(screen.getByText('Clínica Sonrisa usará tus datos solo para responder tu solicitud.', { exact: false })).toBeInTheDocument();
    });

    it('el momento «Gracias» muestra el mensaje por defecto si no hay uno propio', () => {
        render(<LandingPreview draft={draftFrom(page)} device="desktop" state="thanks" business="Clínica Sonrisa" logo={null} image={null} options={undefined} />);
        expect(screen.getByText('¡Gracias! Recibimos tus datos')).toBeInTheDocument();
    });
});
