import { describe, expect, it } from 'vitest';
import { answerLabel, type HdyhauSurvey } from './api';
import { addOption, breakdown, draftProblems, moveOption, removeOption, toDraft, toPayload } from './options';

const questions = {
    title: 'Una pregunta rápida',
    question: '¿Cómo nos conociste?',
    options: [
        { key: 'instagram', label: 'Instagram' },
        { key: 'google', label: 'Buscando en Google' },
        { key: 'recomendacion', label: 'Me lo recomendó alguien' },
    ],
    allow_other: true,
    other_label: 'Otro',
    thanks: '¡Gracias!',
};

describe('opciones de la encuesta', () => {
    it('reordena, agrega y quita conservando las claves', () => {
        const draft = toDraft('active', questions);
        const moved = moveOption(draft.options, 2, -1);
        expect(moved.map((o) => o.key)).toEqual(['instagram', 'recomendacion', 'google']);
        expect(moveOption(moved, 0, -1).map((o) => o.key)).toEqual(['instagram', 'recomendacion', 'google']);
        const added = addOption(moved, 'TikTok');
        expect(added[3]).toMatchObject({ key: null, label: 'TikTok' });
        const payload = toPayload({ ...draft, options: removeOption(added, 0) });
        expect(payload.options).toEqual([{ key: 'recomendacion', label: 'Me lo recomendó alguien' }, { key: 'google', label: 'Buscando en Google' }, { label: 'TikTok' }]);
    });

    it('no baja de dos opciones ni pasa de quince', () => {
        const two = toDraft('active', { ...questions, options: questions.options.slice(0, 2) }).options;
        expect(removeOption(two, 0)).toHaveLength(2);
        let many = two;
        for (let i = 0; i < 20; i++) {
            many = addOption(many, `x${String(i)}`);
        }
        expect(many).toHaveLength(15);
    });

    it('detecta opciones vacías o repetidas', () => {
        const draft = toDraft('active', questions);
        expect(draftProblems(draft)).toEqual([]);
        expect(draftProblems({ ...draft, options: [...draft.options, { uid: 'a', key: null, label: 'instagram ' }] })).toEqual(['duplicateOption']);
        expect(draftProblems({ ...draft, title: ' ', options: addOption(draft.options) })).toEqual(['title', 'emptyOption']);
    });

    it('ordena las respuestas y les pone su etiqueta', () => {
        const survey: HdyhauSurvey = { id: 'sv_1', status: 'active', questions, responses: { total: 10, by_answer: { google: 2, instagram: 7, otro: 1 } } };
        expect(breakdown(survey.responses.by_answer, survey.responses.total).map((r) => [r.answer, r.share])).toEqual([
            ['instagram', 0.7],
            ['google', 0.2],
            ['otro', 0.1],
        ]);
        expect(answerLabel(survey, 'otro')).toBe('Otro');
        expect(answerLabel(survey, 'google')).toBe('Buscando en Google');
        expect(answerLabel(survey, 'borrada')).toBe('borrada');
        expect(answerLabel(undefined, 'google')).toBe('google');
        expect(answerLabel(survey, null)).toBeNull();
    });
});
