import grapesjs, { type Editor } from 'grapesjs';
import 'grapesjs/dist/css/grapes.min.css';
import grapesjsMjmlImport from 'grapesjs-mjml';
import es from 'grapesjs/locale/es';
import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { BLANK_EMAIL_MJML } from '../content';
import { DYNAMIC_BLOCKS } from './blocks';

/*
 * Editor de correo de arrastrar y soltar (GrapesJS + MJML, modulos.md M08).
 * Pesa varios cientos de KB: solo se carga en la pantalla del editor (lazy).
 * El MJML se compila a HTML aquí mismo con el MJML del plugin; el hub guarda los
 * dos y el proyecto de GrapesJS para volver a editar.
 */

/**
 * grapesjs-mjml es UMD: según el empaquetador, el import por defecto llega como la
 * función o como `{ default: función }`. Se desenvuelve hasta llegar a la función.
 */
function unwrapDefault<T>(mod: T): T {
    let current: unknown = mod;
    for (let i = 0; i < 3 && typeof current === 'object' && current !== null && 'default' in current; i++) {
        current = current.default;
    }
    return current as T;
}

const grapesjsMjml = unwrapDefault(grapesjsMjmlImport);

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

export const EmailCanvas = forwardRef<EmailCanvasHandle, Props>(function EmailCanvas({ mjml, state, readOnly = false, onChange }, ref) {
    const { t } = useTranslation();
    const container = useRef<HTMLDivElement>(null);
    const editorRef = useRef<Editor | null>(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    useEffect(() => {
        if (!container.current) return;
        let projectData: object | undefined;
        if (state) {
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
            plugins: [(e) => grapesjsMjml(e, { resetBlocks: true, columnsPadding: '0', imagePlaceholderSrc: '' })],
            ...(projectData ? { projectData } : {}),
        });
        if (!projectData) editor.setComponents(mjml && mjml.trim() !== '' ? mjml : BLANK_EMAIL_MJML);

        const category = t('templates.blocks.category');
        for (const block of DYNAMIC_BLOCKS) {
            editor.Blocks.add(block.id, {
                label: block.phase ? `${t(`templates.blocks.${block.labelKey}`)} (${t('templates.phase', { n: block.phase })})` : t(`templates.blocks.${block.labelKey}`),
                category,
                content: block.content,
                attributes: { class: 'gjs-fonts gjs-f-b1' },
            });
        }
        if (readOnly) {
            editor.Commands.run('core:preview');
        }

        let timer: ReturnType<typeof setTimeout> | undefined;
        let ready = false;
        editor.on('load', () => {
            ready = true;
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
