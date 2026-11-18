/*
 * Quiz (igr-w-quiz.js, M12): preguntas con ramas, formulario opcional al final y los
 * productos que responde el hub.
 */
import { CARDS_CSS, h, productCard, register, uid } from './lib/dom';
import { capture, frame, head, send, sent, success, type Frame } from './lib/forms';
import type { Ctx, Live, Question, SubmitResult } from './lib/types';

const CSS =
    '.qo{display:grid;gap:8px;margin:12px 0}.qo button{background:#fff;color:#111827;border-color:#9ca3af;text-align:left}.qo button:hover{border-color:var(--a)}' +
    CARDS_CSS;

/** Productos del resultado del quiz. */
function products(x: Ctx): (res: SubmitResult) => Node | null {
    return (res) => (res.products && res.products.length ? h('ul', { class: 'pg' }, ...res.products.slice(0, 8).map((p) => productCard(p, x.currency))) : null);
}

/** Quiz con ramas: cada opción lleva a `next` (o a la siguiente pregunta) y al final, al formulario o al resultado. */
function quiz(f: Frame, live: Live, x: Ctx): void {
    const qs: Question[] = live.raw.quiz?.questions ?? [];
    const answers: Record<string, string> = {};
    const path: string[] = [];
    const keep = Array.from(f.box.children).filter((n) => n.classList.contains('x'));
    // El título se queda en cada paso: el diálogo o la sección conservan su nombre accesible.
    const title = (): Node[] => (live.content.title ? [h('h2', { id: f.box.getAttribute('data-title') ?? '' }, live.content.title)] : []);
    const finish = (): void => {
        const c = live.content;
        if ((c.fields ?? []).length || (c.steps ?? []).length) {
            // Las respuestas viajan con el formulario final.
            capture(f, live, x, { answers, products: products(x) });
            return;
        }
        const wait = h('p', { role: 'status' }, 'Buscando tu resultado…');
        f.box.replaceChildren(...keep, ...title(), wait);
        void send(live, x, { step: 1, fields: {}, consents: {}, answers }).then((r) => {
            if (r.status === 200) {
                sent(f, live, x);
                f.box.replaceChildren(...keep, ...title(), success(live, x, r.data ?? {}, products(x)));
            } else wait.textContent = 'No pudimos mostrar tu resultado.';
        });
    };
    const ask = (qid: string | undefined): void => {
        const q = qs.find((y) => y.id === qid);
        if (!q) {
            finish();
            return;
        }
        const tid = uid('q');
        const opts = h('div', { class: 'qo', role: 'group', 'aria-labelledby': tid });
        for (const o of q.options) {
            const b = h('button', { type: 'button' }, o.label);
            b.addEventListener('click', () => {
                answers[q.id] = o.key;
                path.push(q.id);
                const idx = qs.indexOf(q);
                ask(o.next === null ? undefined : o.next ?? qs[idx + 1]?.id);
            });
            opts.append(b);
        }
        const back = path.length ? h('button', { type: 'button', class: 's' }, 'Atrás') : null;
        back?.addEventListener('click', () => {
            const prev = path.pop();
            if (prev) delete answers[prev];
            ask(prev);
        });
        const nodes = path.length ? title() : head(f, live.content.title, live.content.body, live.content.image ?? live.design.image);
        f.box.replaceChildren(...keep, ...nodes, h('p', { class: 'sm' }, 'Pregunta ' + (path.length + 1)), h('h3', { id: tid }, q.text), opts, back ?? '');
        (opts.querySelector('button') as HTMLElement | null)?.focus();
    };
    ask(qs[0]?.id);
}

register({
    quiz: (host, live, x) => {
        if (live) quiz(frame(live, x, host ? 'inline' : 'center', host, CSS), live, x);
    },
});
