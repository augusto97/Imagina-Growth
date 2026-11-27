import grapesjs, { type Editor } from 'grapesjs';
import 'grapesjs/dist/css/grapes.min.css';
import grapesjsMjmlImport from 'grapesjs-mjml';
import mjmlEsImport from 'grapesjs-mjml/locale/es';
import es from 'grapesjs/locale/es';
import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { BLANK_EMAIL_MJML } from '../content';
import { iconSvg } from './blockIcons';
import { BLOCK_CATEGORIES, type BlockContext, INK, blocksFor, textOn } from './blocks';
import './email-editor.css';
import { LIQUID_ATTR, type Translate, liquidChip, normalizeMjml, wrapLiquid } from './liquid';

/*
 * Editor de correo de arrastrar y soltar (GrapesJS + MJML, modulos.md M08).
 * Pesa varios cientos de KB: solo se carga en la pantalla del editor (lazy).
 * El MJML se compila a HTML aquí mismo con el MJML del plugin; el hub guarda los
 * dos y el proyecto de GrapesJS (el editor vuelve a abrir desde el MJML, ver abajo).
 *
 * En el lienzo, el Liquid del texto se ve como fichas legibles (`liquid.ts`) y al exportar vuelve a ser el Liquid
 * de siempre; las imágenes cuya dirección es una variable se ven como una imagen de muestra.
 */

/**
 * Los paquetes UMD o CommonJS llegan, según el empaquetador, como el valor o como `{ default: valor }`. Se
 * desenvuelve hasta llegar al valor.
 */
function unwrapDefault<T>(mod: T): T {
    let current: unknown = mod;
    for (let i = 0; i < 3 && typeof current === 'object' && current !== null && 'default' in current; i++) {
        current = current.default;
    }
    return current as T;
}

const grapesjsMjml = unwrapDefault(grapesjsMjmlImport);
const mjmlEs = unwrapDefault(mjmlEsImport);

/** Lo que solo se ve en el lienzo (nunca va en el correo): fichas de Liquid, imágenes de muestra y el `<mj-head>` oculto. */
const PLACEHOLDER_IMG = `data:image/svg+xml,${encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 80"><rect width="120" height="80" fill="#eef2ff"/><g fill="none" stroke="#a5b4fc" stroke-width="2.5" stroke-linejoin="round"><rect x="42" y="25" width="36" height="30" rx="4"/><circle cx="51" cy="34" r="3"/><path d="M44 52l11-11 8 8 5-5 8 8"/></g></svg>',
)}`;
const CANVAS_CSS = `
[data-gjs-type="mj-head"]{display:none!important}
.igr-lq{display:inline-block;border-radius:5px;padding:0 5px;margin:0 1px;font-size:.86em;line-height:1.45;font-weight:600;white-space:nowrap;vertical-align:baseline;cursor:default;user-select:none;font-style:normal;letter-spacing:0;text-transform:none;text-decoration:none}
.igr-lq--var{background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe}
.igr-lq--logic{background:#f8fafc;color:#475569;border:1px dashed #94a3b8;font-size:11px;font-weight:500}
.igr-lq--end{background:transparent;color:#94a3b8;border:1px dashed #cbd5e1;font-size:10px;font-weight:500}
img[src*="{{"],img[src*="%7B%7B"],img[src=""]{content:url("${PLACEHOLDER_IMG}");min-height:40px;min-width:40px;background:#eef2ff;object-fit:cover}
`;
const CANVAS_CSS_URL = `data:text/css;charset=utf-8,${encodeURIComponent(CANVAS_CSS)}`;

export interface EmailOutput {
    mjml: string;
    html: string;
    state: string;
    errors: string[];
}

export interface EmailCanvasHandle {
    /** Inserta una variable en el texto que se está editando. false si no hay ninguno. */
    insertVariable: (expr: string) => boolean;
    setDevice: (device: 'desktop' | 'mobile') => void;
    output: () => EmailOutput | null;
}

interface Props {
    mjml: string | null;
    state: string | null;
    readOnly?: boolean;
    /** Qué bloques se ofrecen: sin tienda no hay carrito, cupón ni puntos; sin servicios, ni citas ni cotizaciones. */
    business?: { store: boolean; services: boolean };
    /** Color de la marca (#RRGGBB) para los botones de los bloques y la paleta. */
    brand?: string | null;
    onChange: (output: EmailOutput) => void;
}

function compile(editor: Editor): EmailOutput {
    const mjml = String(editor.runCommand('mjml-code') ?? '');
    const result = editor.runCommand('mjml-code-to-html') as { html?: string; errors?: { formattedMessage?: string; message?: string }[] } | undefined;
    return {
        mjml,
        html: result?.html ?? '',
        state: JSON.stringify(editor.getProjectData()),
        errors: (result?.errors ?? []).map((e) => e.formattedMessage ?? e.message ?? '').filter((m) => m !== ''),
    };
}

/** Lo que el parser de GrapesJS lee: MJML que el parser HTML entiende y el Liquid del texto como fichas. */
export function prepareForCanvas(input: string): string {
    return wrapLiquid(normalizeMjml(input));
}

/**
 * Fichas de Liquid: un componente que en el lienzo se ve como «Nombre del negocio» o «Si hay logo» y al exportar
 * devuelve la expresión tal cual. No se selecciona ni se arrastra: es parte de su texto (se borra con la tecla de
 * borrar al editarlo).
 */
function liquidPlugin(t: Translate) {
    return (editor: Editor) => {
        // Una imagen cuya dirección es una variable (`{{ tienda.logo }}`) no carga en el lienzo. GrapesJS cambiaba su
        // `src` en la página por su ícono de imagen rota y, al terminar de editar ese texto, ese ícono quedaba guardado
        // en lugar de la variable. Sin ese reemplazo el `src` no se toca y el CSS del lienzo muestra la imagen de muestra.
        editor.Components.addType('image', { model: { defaults: { fallback: '' } } });
        editor.Components.addType('igr-liquid', {
            isComponent: (el: HTMLElement) => {
                const expr = typeof el.getAttribute === 'function' ? el.getAttribute(LIQUID_ATTR) : null;
                return expr !== null ? { type: 'igr-liquid', liquid: expr, components: [] } : false;
            },
            model: {
                defaults: {
                    tagName: 'span',
                    liquid: '',
                    draggable: false,
                    droppable: false,
                    copyable: false,
                    selectable: false,
                    hoverable: false,
                    highlightable: false,
                    layerable: false,
                    editable: false,
                    stylable: false,
                    badgable: false,
                },
                toHTML() {
                    const raw: unknown = this.get('liquid');
                    return typeof raw === 'string' ? raw : '';
                },
            },
            view: {
                onRender({ el, model }: { el: HTMLElement; model: { get: (k: string) => unknown } }) {
                    const raw = model.get('liquid');
                    const expr = typeof raw === 'string' ? raw : '';
                    const chip = liquidChip(expr, t);
                    el.textContent = chip.label;
                    el.className = `igr-lq igr-lq--${chip.kind}`;
                    el.setAttribute('contenteditable', 'false');
                    el.setAttribute(LIQUID_ATTR, expr);
                    el.setAttribute('title', expr);
                },
            },
        });
    };
}

export const EmailCanvas = forwardRef<EmailCanvasHandle, Props>(function EmailCanvas({ mjml, state, readOnly = false, business, brand, onChange }, ref) {
    const { t } = useTranslation();
    const container = useRef<HTMLDivElement>(null);
    const editorRef = useRef<Editor | null>(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    useEffect(() => {
        if (!container.current) return;
        const translate: Translate = (key, opts) => (opts ? t(key, opts) : t(key));
        const brandColor = brand && /^#[0-9a-f]{6}$/i.test(brand) ? brand : INK;
        // El editor abre desde el MJML (la fuente de verdad que se envía); el proyecto guardado solo si no hay MJML.
        let projectData: object | undefined;
        if ((!mjml || mjml.trim() === '') && state) {
            try {
                projectData = JSON.parse(state) as object;
            } catch {
                projectData = undefined;
            }
        }
        const editor = grapesjs.init({
            container: container.current,
            height: '100%',
            width: 'auto',
            fromElement: false,
            storageManager: false,
            telemetry: false,
            i18n: { locale: 'es', detectLocale: false, messages: { es } },
            parser: { optionsHtml: { preParser: (input: string) => prepareForCanvas(input) } },
            canvas: { styles: [CANVAS_CSS_URL] },
            colorPicker: {
                showPalette: true,
                showInput: true,
                showInitial: true,
                preferredFormat: 'hex',
                chooseText: translate('templates.editor.colorChoose'),
                cancelText: translate('templates.editor.colorCancel'),
                palette: [
                    [brandColor, INK, '#374151', '#6b7280', '#e5e7eb', '#f9fafb', '#ffffff'],
                    ['#4f46e5', '#2563eb', '#0891b2', '#059669', '#65a30d', '#25d366'],
                    ['#d97706', '#ea580c', '#dc2626', '#db2777', '#9333ea', '#f59e0b'],
                ],
            },
            plugins: [
                (e) =>
                    grapesjsMjml(e, {
                        resetBlocks: true,
                        blocks: [],
                        useCustomTheme: false,
                        columnsPadding: '0',
                        imagePlaceholderSrc: '',
                        i18n: { es: mjmlEs },
                    }),
                liquidPlugin(translate),
            ],
            ...(projectData ? { projectData } : {}),
        });
        if (!projectData) editor.setComponents(mjml && mjml.trim() !== '' ? mjml : BLANK_EMAIL_MJML);

        const ctx: BlockContext = { brand: brandColor, onBrand: textOn(brandColor), origin: window.location.origin, t: translate };
        for (const block of blocksFor(business ?? { store: true, services: true })) {
            editor.Blocks.add(block.id, {
                label: translate(`templates.blocks.items.${block.id}`),
                category: {
                    id: block.category,
                    label: translate(`templates.blocks.categories.${block.category}`),
                    order: BLOCK_CATEGORIES.indexOf(block.category),
                    open: block.category !== 'sections',
                },
                media: iconSvg(block.icon),
                content: block.content(ctx),
                attributes: { title: translate(`templates.blocks.items.${block.id}`) },
            });
        }
        editor.Panels.removeButton('options', 'mjml-import');

        if (readOnly) {
            editor.Commands.run('core:preview');
        }

        let timer: ReturnType<typeof setTimeout> | undefined;
        let ready = false;
        editor.on('load', () => {
            ready = true;
            // Los bloques a la vista desde el principio: es lo primero que se busca al armar un correo.
            if (!readOnly) editor.Panels.getButton('views', 'open-blocks')?.set('active', true);
        });
        editor.on('update', () => {
            if (!ready) return;
            clearTimeout(timer);
            timer = setTimeout(() => onChangeRef.current(compile(editor)), 500);
        });
        editorRef.current = editor;

        return () => {
            clearTimeout(timer);
            editor.destroy();
            editorRef.current = null;
        };
        // Se crea una sola vez por plantilla: el padre cambia la `key` al abrir otra.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useImperativeHandle(ref, () => ({
        insertVariable: (expr: string) => {
            const editor = editorRef.current;
            const rte = editor?.RichTextEditor.globalRte;
            if (!editor || !editor.getEditing() || !rte) return false;
            // Como ficha: al terminar de editar el texto vuelve a leerse como Liquid (`prepareForCanvas`).
            rte.insertHTML(expr);
            return true;
        },
        setDevice: (device) => {
            editorRef.current?.runCommand(device === 'mobile' ? 'set-device-mobile' : 'set-device-desktop');
        },
        output: () => (editorRef.current ? compile(editorRef.current) : null),
    }));

    return <div ref={container} className="igr-email-canvas igr:h-full igr:min-h-[560px] igr:overflow-hidden igr:rounded-lg igr:border" />;
});

export default EmailCanvas;
