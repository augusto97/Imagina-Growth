import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import { aiReactivateSummary } from '@/features/flows/builder/nodes/AiReactivateConfig';
import { QualificationCard } from './QualificationCard';
import { changeType, choiceQuestions, draftErrors, emptyDraft, newQuestion, nextKey, parseWords, type QualificationDraft } from './qualification';

const labels = { yes: 'Sí', no: 'No', option: (n: number) => `Opción ${n}` };

afterEach(cleanup);

describe('preguntas de calificación', () => {
    it('arma preguntas nuevas con claves libres y cambia de tipo sin perder el texto', () => {
        expect(nextKey('q', ['q1', 'q2'])).toBe('q3');
        expect(nextKey('q', ['q3'])).toBe('q2');
        const choice = newQuestion('choice', [], labels);
        expect(choice.options?.map((o) => o.label)).toEqual(['Opción 1', 'Opción 2']);
        const yesNo = changeType({ ...choice, text: '¿Es para este mes?', required: false }, 'yes_no', labels);
        expect(yesNo).toMatchObject({ key: 'q1', text: '¿Es para este mes?', required: false, type: 'yes_no' });
        expect(yesNo.options?.map((o) => o.key)).toEqual(['yes', 'no']);
        expect(changeType(choice, 'number', labels)).toMatchObject({ min: null, max: null, out_of_range: 'none' });
    });

    it('avisa lo que falta antes de guardar', () => {
        const d: QualificationDraft = emptyDraft();
        expect(draftErrors(d)).toEqual(expect.arrayContaining(['name', 'noQuestions']));
        d.name = 'Fachadas';
        d.questions = [{ ...newQuestion('choice', [], labels), text: '¿Para qué es?', options: [{ key: 'o1', label: 'Casa', points: 10, disqualifies: false }] }];
        d.routing.rules.push({ when: 'answer', question: 'q1', option: 'o9', action: 'seller' });
        d.routing.default = { action: 'web', link: 'quote', url: null };
        expect(draftErrors(d)).toEqual(expect.arrayContaining(['options', 'ruleAnswer', 'url']));
        d.questions = d.questions.map((q) => ({ ...q, options: [...(q.options ?? []), { key: 'o2', label: 'Empresa', points: 30, disqualifies: false }] }));
        d.routing.rules[1] = { when: 'answer', question: 'q1', option: 'o2', action: 'seller' };
        d.routing.default = { action: 'web', link: 'quote', url: 'https://negocio.co/cotiza' };
        expect(draftErrors(d)).toEqual([]);
        expect(choiceQuestions(d).map((q) => q.key)).toEqual(['q1']);
        expect(parseWords(' pintura, fachada,, pintura ')).toEqual(['pintura', 'fachada']);
    });

    it('la ficha muestra cada respuesta con la frase del cliente, el estado y la derivación', async () => {
        await i18n.changeLanguage('es-CO');
        render(
            <QualificationCard
                qualification={{
                    set: { id: 'lqs_1', name: 'Fachadas' },
                    answers: [
                        {
                            key: 'tipo',
                            question: '¿Es para tu casa o una empresa?',
                            required: true,
                            value: 'Empresa',
                            quote: 'para la bodega de mi empresa',
                            points: 30,
                            disqualifies: false,
                            at: null,
                        },
                        { key: 'pronto', question: '¿Lo necesitas este mes?', required: false, value: null, quote: null, points: null, disqualifies: false, at: null },
                    ],
                    score: 30,
                    disqualified: false,
                    complete: true,
                    route: { action: 'seller' },
                }}
            />,
        );
        expect(screen.getByText('Empresa')).toBeTruthy();
        expect(screen.getByText('«para la bodega de mi empresa»')).toBeTruthy();
        expect(screen.getByText('Completa')).toBeTruthy();
        expect(screen.getByText(/pasó a un vendedor/)).toBeTruthy();
        expect(screen.getByText('Sin responder (opcional)')).toBeTruthy();
    });

    it('el nodo «Reactivar con IA» resume sus canales y si lleva oferta', async () => {
        await i18n.changeLanguage('es-CO');
        expect(aiReactivateSummary(i18n.t, { channels: ['whatsapp', 'email'] })).toContain('WhatsApp → ');
        expect(aiReactivateSummary(i18n.t, { channels: ['email'], offer: '10% con VUELVE10' })).toContain('oferta');
    });
});
