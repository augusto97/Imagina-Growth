import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Me, Workspace } from '@/api/types';
import { PagePreviewFrame } from '@/components/ui/page-preview';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import { api } from '@/lib/api';
import { NewPageWizard } from './NewPageWizard';
import {
    applyTexts,
    cleanFaq,
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

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

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
        template: 'split',
        eyebrow: null,
        benefits: ['Sin costo'],
        faq: [],
        reviews: false,
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

describe('diseño, beneficios, preguntas e IA', () => {
    it('el diseño, la etiqueta, los beneficios y las preguntas van en el PATCH solo si cambiaron, sin renglones vacíos', () => {
        const saved = draftFrom(page);
        expect(patchFrom(saved, saved)).toEqual({});
        const draft = {
            ...saved,
            content: {
                ...saved.content,
                template: 'hero' as const,
                eyebrow: '  ',
                benefits: ['Sin costo', ' ', 'Te escribimos hoy '],
                faq: [
                    { q: '¿Duele?', a: 'No.' },
                    { q: ' ', a: 'sin pregunta' },
                ],
                reviews: true,
            },
        };
        expect(patchFrom(draft, saved).content).toEqual({
            eyebrow: null,
            template: 'hero',
            reviews: true,
            benefits: ['Sin costo', 'Te escribimos hoy'],
            faq: [{ q: '¿Duele?', a: 'No.' }],
        });
    });

    it('los textos de la IA reemplazan solo lo que trajeron', () => {
        const draft = applyTexts(draftFrom(page), { eyebrow: 'Cupos esta semana', title: '', body: 'Nuevo texto', benefits: [], faq: [{ q: '¿Cuánto dura?', a: 'Media hora.' }], button: 'Quiero mi cita ya' });
        expect(draft.content).toMatchObject({
            eyebrow: 'Cupos esta semana',
            title: 'Agenda tu valoración',
            body: 'Nuevo texto',
            benefits: ['Sin costo'],
            faq: [{ q: '¿Cuánto dura?', a: 'Media hora.' }],
            button: 'Quiero mi cita ya',
        });
        expect(cleanFaq([{ q: '  ¿Hay parqueadero? ', a: ' Sí ' }])).toEqual([{ q: '¿Hay parqueadero?', a: 'Sí' }]);
    });

    it('la vista previa pinta el HTML del hub en un iframe sin scripts ni formularios', () => {
        render(<PagePreviewFrame device="mobile" title="Vista previa" srcDoc="<h1>Hola</h1>" height={400} />);
        const frame = screen.getByTitle('Vista previa');
        expect(frame.getAttribute('srcdoc')).toBe('<h1>Hola</h1>');
        expect(frame.getAttribute('sandbox')).toBe('');
        expect(frame).toHaveStyle({ width: '390px' });
    });
});

const workspace: Workspace = {
    id: 'ws_1',
    name: 'Clínica Sonrisa',
    slug: 'clinica-sonrisa',
    account_id: 'acc_1',
    country: 'CO',
    timezone: 'America/Bogota',
    currency: 'COP',
    locale: 'es-CO',
    industry: null,
    legal_name: null,
    privacy_policy_url: null,
    settings: {},
    status: 'active',
    role: 'owner',
    abilities: ['viewWorkspace', 'viewDashboards', 'manageMarketing'],
    requires_two_factor: false,
};
const me: Me = {
    user: { id: 'usr_1', name: 'Laura Gómez', email: 'laura@clinica.co', locale: 'es-CO', two_factor_enabled: true, two_factor_pending: false, is_platform_admin: false },
    workspaces: [workspace],
    require_two_factor: false,
};

describe('asistente de nueva página', () => {
    it('objetivo → diseño → nombre: crea la página con el objetivo y el diseño elegidos', async () => {
        const user = userEvent.setup();
        vi.spyOn(api, 'get').mockRejectedValue(new Error('sin IA'));
        const post = vi.spyOn(api, 'post').mockResolvedValue({ id: 'lp_9' });
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={client}>
                <MemoryRouter>
                    <WorkspaceContext.Provider value={{ workspace, me }}>
                        <NewPageWizard onClose={() => undefined} />
                    </WorkspaceContext.Provider>
                </MemoryRouter>
            </QueryClientProvider>,
        );
        expect(screen.getByRole('heading', { name: '¿Para qué es la página?' })).toBeInTheDocument();
        await user.click(screen.getByRole('radio', { name: /Pedir una cotización/ }));
        await user.click(screen.getByRole('button', { name: /Siguiente/ }));
        // Cotizar va con el diseño clásico; se cambia por el destacado.
        expect(screen.getByRole('radio', { name: /Clásica/ })).toBeChecked();
        await user.click(screen.getByRole('radio', { name: /Destacada/ }));
        await user.click(screen.getByRole('button', { name: /Siguiente/ }));
        expect(screen.getByPlaceholderText('Recibe tu cotización sin costo')).toBeInTheDocument();
        await user.type(screen.getByLabelText('Nombre de la página'), 'Cotización de pintura');
        await user.click(screen.getByRole('button', { name: 'Crear y diseñar' }));
        expect(post).toHaveBeenCalledWith('/api/w/ws_1/capture/pages', { name: 'Cotización de pintura', goal: 'quote', template: 'hero' });
    });
});
