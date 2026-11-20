import { describe, expect, it } from 'vitest';
import { briefDraft, briefEdits, briefFilled, duration, filtersQuery, moveCard, moveNeeds, parseList, pipelineErrors, stageKey, temperatureTone } from './logic';
import type { BoardColumn, Brief, LeadRow } from './types';

const lead = (id: string, stage: string): LeadRow => ({
    id, stage, pipeline_id: null, contact: { id: 'ct_1', name: 'Ana', city: null }, service: null, value: null, currency: 'COP', owner: null, score: 40, temperature: 'warm',
    origin: 'form', channel: null, channel_label: null, next_step: null, next_step_at: null, stage_changed_at: null, first_response_at: null, last_activity_at: null, won_at: null, lost_at: null, lost_reason: null, created_at: null,
});
const col = (key: string, leads: LeadRow[]): BoardColumn => ({ key, label: key, kind: key === 'won' ? 'won' : key === 'lost' ? 'lost' : 'open', count: leads.length, value: '0.00', leads });

describe('leads', () => {
    it('arma la query de filtros sin vacíos y en orden estable', () => {
        expect(filtersQuery({ temperature: 'hot', owner: '', source: 'paid_meta', page: 2 })).toBe('?page=2&source=paid_meta&temperature=hot');
        expect(filtersQuery({ owner: '', service: '' })).toBe('');
    });

    it('mueve una tarjeta de columna y ajusta los conteos', () => {
        const cols = [col('new', [lead('a', 'new'), lead('b', 'new')]), col('contacted', [])];
        const next = moveCard(cols, 'a', 'contacted');
        expect(next[0]?.count).toBe(1);
        expect(next[1]?.leads[0]).toMatchObject({ id: 'a', stage: 'contacted' });
        expect(moveCard(cols, 'zzz', 'contacted')).toBe(cols);
    });

    it('pide valor al ganar y motivo al perder', () => {
        expect(moveNeeds({ key: 'won', label: 'Ganado', kind: 'won' })).toBe('value');
        expect(moveNeeds({ key: 'lost', label: 'Perdido', kind: 'lost' })).toBe('reason');
        expect(moveNeeds({ key: 'new', label: 'Nuevo', kind: 'open' })).toBeNull();
        expect(temperatureTone('hot')).toBe('destructive');
    });

    it('escribe la duración de la primera respuesta', () => {
        expect(duration(null).unit).toBe('none');
        expect(duration(40)).toEqual({ unit: 'sec', a: 40, b: 0 });
        expect(duration(900)).toEqual({ unit: 'min', a: 15, b: 0 });
        expect(duration(7500)).toEqual({ unit: 'hm', a: 2, b: 5 });
        expect(duration(3 * 86400)).toEqual({ unit: 'day', a: 3, b: 0 });
    });

    it('valida pipelines como el servidor y crea claves de etapa únicas', () => {
        const ok = [
            { key: 'new', label: 'Nuevo', kind: 'open' as const },
            { key: 'won', label: 'Ganado', kind: 'won' as const },
            { key: 'lost', label: 'Perdido', kind: 'lost' as const },
        ];
        expect(pipelineErrors('Ventas', ok)).toEqual([]);
        expect(pipelineErrors('', ok.slice(0, 2))).toEqual(expect.arrayContaining(['name', 'count', 'kinds']));
        expect(stageKey('Visita técnica', [])).toBe('visita_tecnica');
        expect(stageKey('Visita técnica', ['visita_tecnica'])).toBe('visita_tecnica_2');
        expect(stageKey('123', [])).toBe('etapa');
        expect(parseList(' Bogotá, Chía ;Cota,Bogotá ')).toEqual(['Bogotá', 'Chía', 'Cota']);
    });

    it('la ficha cuenta lo que tiene y solo manda lo que la persona cambió (vacío = null)', () => {
        const brief: Brief = {
            need: { value: 'pintura azul', by: 'ai', quote: 'necesito pintura azul', reason: null, at: null },
            quantity: null,
            city: { value: 'Bogotá', by: 'ai', quote: 'en Bogotá', reason: null, at: null },
            zone: null,
            timing: null,
            budget: { value: null, by: 'user', quote: null, reason: null, at: null },
            temperature: { value: 'hot', by: 'ai', quote: 'para el viernes', reason: 'Lo quiere pronto', at: null },
        };
        expect(briefFilled(brief)).toBe(3);
        expect(briefFilled(null)).toBe(0);
        const draft = briefDraft(brief);
        expect(draft.need).toBe('pintura azul');
        expect(draft.budget).toBe('');
        expect(briefEdits(brief, { ...draft, city: 'Medellín', need: '', timing: '  ' })).toEqual({ city: 'Medellín', need: null });
    });
});
