import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import channels from '@/locales/es-CO/channels.json';
import inbox from '@/locales/es-CO/inbox.json';
import { capabilityNotes, marketingBlocked } from './capabilities';
import { CapabilityNotes, TemplateAsTextNote } from './CapabilityNotes';

const OFFICIAL = { buttons: true, templates: true, media: false, marketing: true };
const IMAGINA_WHATS = { buttons: false, templates: false, media: true, marketing: false };
const ZENDER = { buttons: false, templates: false, media: false, marketing: false };

describe('Lo que puede cada WhatsApp', () => {
    it('un número oficial no tiene notas; uno no oficial dice que no hay marketing, botones ni plantillas, y qué pasa con la imagen', () => {
        expect(capabilityNotes(OFFICIAL)).toEqual([]);
        expect(capabilityNotes(IMAGINA_WHATS)).toEqual(['noMarketing', 'numbered', 'templatesAsText', 'mediaApart']);
        expect(capabilityNotes(ZENDER)).toEqual(['noMarketing', 'numbered', 'templatesAsText', 'noMedia']);
        const texts = channels.channels.capabilities as Record<string, string>;
        for (const note of [...capabilityNotes(IMAGINA_WHATS), ...capabilityNotes(ZENDER)]) {
            expect(texts[note], note).toBeTypeOf('string');
        }
    });

    it('un paso de marketing por un WhatsApp no oficial queda avisado; un aviso de utilidad no', () => {
        expect(marketingBlocked(IMAGINA_WHATS, 'marketing')).toBe(true);
        expect(marketingBlocked(IMAGINA_WHATS, 'utility')).toBe(false);
        expect(marketingBlocked(OFFICIAL, 'marketing')).toBe(false);
        expect(marketingBlocked(null, 'marketing')).toBe(false);
    });

    it('la tarjeta del número y la plantilla lo dicen en palabras', () => {
        const { unmount } = render(<CapabilityNotes caps={IMAGINA_WHATS} />);
        expect(screen.getByText('Qué hace distinto este número')).toBeTruthy();
        expect(screen.getByText(/las opciones salen numeradas en el texto/)).toBeTruthy();
        expect(screen.getByText(/va aparte, con el texto como pie/)).toBeTruthy();
        unmount();

        const zender = render(<TemplateAsTextNote caps={ZENDER} name="Zender ventas" />);
        expect(screen.getByText(/Por Zender ventas esta plantilla sale como texto/)).toBeTruthy();
        expect(screen.getByText(/La imagen del encabezado no sale/)).toBeTruthy();
        zender.unmount();

        const { container } = render(<TemplateAsTextNote caps={OFFICIAL} name="Oficial" />);
        expect(container.textContent).toBe('');
        expect(render(<CapabilityNotes caps={OFFICIAL} />).container.textContent).toBe('');
    });

    it('la bandeja explica lo que no salió de una plantilla compuesta como texto', () => {
        expect(inbox.inbox.chat.degraded_header_media).toContain('no manda archivos');
        expect(inbox.inbox.chat.degraded_button).toBeTypeOf('string');
    });
});
