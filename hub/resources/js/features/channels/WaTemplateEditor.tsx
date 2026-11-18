import { Link2, MessageCircle, Phone, Plus, Reply, TriangleAlert, X } from 'lucide-react';
import { type SyntheticEvent, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { type WaTemplate, type WaTemplateForm, useSaveWaTemplate } from './api';
import { emptyForm, fill, formErrors, formFromTemplate, marketingWords, slugName, variables } from './templates';

const LANGUAGES = ['es', 'es_CO', 'es_MX', 'es_AR', 'en_US', 'pt_BR'];
const BUTTON_ICONS = { URL: Link2, QUICK_REPLY: Reply, PHONE_NUMBER: Phone, COPY_CODE: X } as const;

/** Vista previa con la burbuja de WhatsApp y los ejemplos puestos. */
export function WaPreview({ form }: { form: WaTemplateForm }) {
    const { t } = useTranslation();
    return (
        <div className="igr:rounded-xl igr:bg-[#e5ddd5] igr:p-4 igr:dark:bg-[#0b141a]" aria-label={t('channels.wa_editor.preview')}>
            <div className="igr:max-w-[300px] igr:rounded-lg igr:rounded-tl-none igr:bg-white igr:p-2.5 igr:text-[13px] igr:text-[#111b21] igr:shadow-sm igr:dark:bg-[#202c33] igr:dark:text-[#e9edef]">
                {form.header.format === 'TEXT' && form.header.text && <p className="igr:mb-1 igr:font-semibold">{form.header.text}</p>}
                {form.header.format !== 'NONE' && form.header.format !== 'TEXT' && (
                    <div className="igr:mb-2 igr:flex igr:h-28 igr:items-center igr:justify-center igr:rounded-md igr:bg-black/10 igr:text-xs igr:text-black/50 igr:dark:bg-white/10 igr:dark:text-white/50">
                        {t(`channels.wa_editor.headerFormat.${form.header.format}`)}
                    </div>
                )}
                <p className="igr:whitespace-pre-wrap igr:break-words">{fill(form.body.text, form.body.examples) || '…'}</p>
                {form.footer.text && <p className="igr:mt-1 igr:text-[11px] igr:text-black/45 igr:dark:text-white/45">{form.footer.text}</p>}
            </div>
            {form.buttons.length > 0 && (
                <div className="igr:mt-1 igr:max-w-[300px] igr:space-y-1">
                    {form.buttons.map((b, i) => {
                        const Icon = BUTTON_ICONS[b.type];
                        return (
                            <div key={i} className="igr:flex igr:items-center igr:justify-center igr:gap-1.5 igr:rounded-lg igr:bg-white igr:py-2 igr:text-[13px] igr:font-medium igr:text-[#027eb5] igr:shadow-sm igr:dark:bg-[#202c33] igr:dark:text-[#53bdeb]">
                                <Icon className="igr:size-3.5" aria-hidden="true" />
                                {b.type === 'COPY_CODE' ? t('channels.wa_editor.buttonTypes.COPY_CODE') : b.text || '…'}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export function WaTemplateEditor({ open, onClose, account, template, linksBase }: { open: boolean; onClose: () => void; account: string; template: WaTemplate | null; linksBase: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveWaTemplate(workspace.id);
    const creating = template === null;
    const [form, setForm] = useState<WaTemplateForm>(() => (template ? { ...formFromTemplate(template, linksBase), category: template.category } : emptyForm(account)));
    const [touched, setTouched] = useState(false);
    const errors = touched ? formErrors(form, creating) : {};
    const words = marketingWords(form.category, form);
    const vars = useMemo(() => variables(form.body.text), [form.body.text]);
    const err = (field: string) => (errors[field] ? t(errors[field], { v1: '{{1}}', v2: '{{2}}', v3: '{{3}}' }) : isApiError(save.error) ? save.error.fieldErrors[field] : undefined);
    const set = (patch: Partial<WaTemplateForm>) => setForm((f) => ({ ...f, ...patch }));
    const categoryLabel = t(`channels.templates.categories.${form.category ?? 'MARKETING'}`).toLocaleLowerCase('es');

    const submit = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        setTouched(true);
        if (Object.keys(formErrors(form, creating)).length > 0 || (words.length > 0 && !form.acknowledge_category_warning)) return;
        const payload: WaTemplateForm = creating ? { ...form, account } : { header: form.header, body: form.body, footer: form.footer, buttons: form.buttons, acknowledge_category_warning: form.acknowledge_category_warning };
        save.mutate(
            { id: template?.id ?? null, form: payload },
            {
                onSuccess: () => {
                    toast.success(t('channels.templates.saved'));
                    onClose();
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="igr:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{creating ? t('channels.wa_editor.titleNew') : t('channels.wa_editor.titleEdit', { name: template.name })}</DialogTitle>
                </DialogHeader>
                <div className="igr:grid igr:max-h-[70vh] igr:gap-5 igr:overflow-y-auto igr:md:grid-cols-[1fr_320px]">
                    <form id="igr-wa-template" onSubmit={submit} className="igr:space-y-4">
                        {creating && (
                            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                                <div className="igr:space-y-1 igr:sm:col-span-3">
                                    <Label htmlFor="tpl-name">{t('channels.wa_editor.name')}</Label>
                                    <Input id="tpl-name" value={form.name ?? ''} onChange={(e) => set({ name: slugName(e.target.value) })} placeholder="carrito_abandonado" />
                                    <p className="igr:text-xs igr:text-muted-foreground">{t('channels.wa_editor.nameHelp')}</p>
                                    <FieldError message={err('name')} />
                                </div>
                                <div className="igr:space-y-1 igr:sm:col-span-2">
                                    <Label htmlFor="tpl-cat">{t('channels.templates.category')}</Label>
                                    <Select id="tpl-cat" value={form.category} onChange={(e) => set({ category: e.target.value as WaTemplate['category'], acknowledge_category_warning: false })}>
                                        {(['MARKETING', 'UTILITY', 'AUTHENTICATION'] as const).map((c) => (
                                            <option key={c} value={c}>
                                                {t(`channels.templates.categories.${c}`)}
                                            </option>
                                        ))}
                                    </Select>
                                    <p className="igr:text-xs igr:text-muted-foreground">{t(`channels.wa_editor.categoryHelp.${form.category ?? 'MARKETING'}`)}</p>
                                </div>
                                <div className="igr:space-y-1">
                                    <Label htmlFor="tpl-lang">{t('channels.templates.language')}</Label>
                                    <Select id="tpl-lang" value={form.language} onChange={(e) => set({ language: e.target.value })}>
                                        {LANGUAGES.map((l) => (
                                            <option key={l} value={l}>
                                                {l}
                                            </option>
                                        ))}
                                    </Select>
                                </div>
                            </div>
                        )}
                        <fieldset className="igr:space-y-2">
                            <legend className="igr:text-[13px] igr:font-semibold">{t('channels.wa_editor.header')}</legend>
                            <Select aria-label={t('channels.wa_editor.header')} value={form.header.format} onChange={(e) => set({ header: { ...form.header, format: e.target.value as WaTemplateForm['header']['format'] } })}>
                                {(['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'] as const).map((f) => (
                                    <option key={f} value={f}>
                                        {t(`channels.wa_editor.headerFormat.${f}`)}
                                    </option>
                                ))}
                            </Select>
                            {form.header.format === 'TEXT' && <Input aria-label={t('channels.wa_editor.headerFormat.TEXT')} maxLength={60} value={form.header.text} onChange={(e) => set({ header: { ...form.header, text: e.target.value } })} />}
                            {form.header.format !== 'NONE' && form.header.format !== 'TEXT' && (
                                <Input aria-label={t('channels.wa_editor.headerExample')} placeholder={t('channels.wa_editor.headerExample')} type="url" value={form.header.example} onChange={(e) => set({ header: { ...form.header, example: e.target.value } })} />
                            )}
                            <FieldError message={err('header.text')} />
                        </fieldset>
                        <div className="igr:space-y-1">
                            <div className="igr:flex igr:items-center igr:justify-between">
                                <Label htmlFor="tpl-body">{t('channels.wa_editor.body')}</Label>
                                <Button type="button" variant="ghost" size="sm" onClick={() => set({ body: { ...form.body, text: `${form.body.text}{{${vars.length + 1}}}` } })}>
                                    <Plus />
                                    {t('channels.wa_editor.addVariable')}
                                </Button>
                            </div>
                            <Textarea id="tpl-body" rows={5} maxLength={1024} value={form.body.text} onChange={(e) => set({ body: { ...form.body, text: e.target.value } })} />
                            <p className="igr:text-xs igr:text-muted-foreground">{t('channels.wa_editor.bodyHelp', { v1: '{{1}}', v2: '{{2}}' })}</p>
                            <FieldError message={err('body.text')} />
                        </div>
                        {vars.length > 0 && (
                            <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                                {vars.map((n) => (
                                    <div key={n} className="igr:space-y-1">
                                        <Label htmlFor={`tpl-ex-${n}`}>{t('channels.wa_editor.example', { n: `{{${n}}}` })}</Label>
                                        <Input
                                            id={`tpl-ex-${n}`}
                                            value={form.body.examples[n - 1] ?? ''}
                                            onChange={(e) => {
                                                const examples = [...form.body.examples];
                                                examples[n - 1] = e.target.value;
                                                set({ body: { ...form.body, examples } });
                                            }}
                                        />
                                    </div>
                                ))}
                                <FieldError message={err('body.examples')} />
                            </div>
                        )}
                        <div className="igr:space-y-1">
                            <Label htmlFor="tpl-footer">{t('channels.wa_editor.footer')}</Label>
                            <Input id="tpl-footer" maxLength={60} value={form.footer.text} onChange={(e) => set({ footer: { text: e.target.value } })} />
                            <FieldError message={err('footer.text')} />
                        </div>
                        <fieldset className="igr:space-y-2">
                            <legend className="igr:text-[13px] igr:font-semibold">{t('channels.wa_editor.buttons')}</legend>
                            {form.buttons.map((b, i) => (
                                <div key={i} className="igr:grid igr:gap-2 igr:rounded-lg igr:border igr:p-2 igr:sm:grid-cols-[140px_1fr_auto]">
                                    <Select aria-label={t('channels.wa_editor.buttons')} value={b.type} onChange={(e) => set({ buttons: form.buttons.map((x, j) => (j === i ? { ...x, type: e.target.value as typeof b.type } : x)) })}>
                                        {(['URL', 'QUICK_REPLY', 'PHONE_NUMBER', 'COPY_CODE'] as const).map((type) => (
                                            <option key={type} value={type}>
                                                {t(`channels.wa_editor.buttonTypes.${type}`)}
                                            </option>
                                        ))}
                                    </Select>
                                    <div className="igr:space-y-1">
                                        {b.type !== 'COPY_CODE' && (
                                            <Input aria-label={t('channels.wa_editor.buttonText')} placeholder={t('channels.wa_editor.buttonText')} maxLength={25} value={b.text} onChange={(e) => set({ buttons: form.buttons.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)) })} />
                                        )}
                                        {b.type === 'URL' && (
                                            <>
                                                <label className="igr:flex igr:items-center igr:gap-2 igr:text-xs">
                                                    <input type="checkbox" checked={b.tracked} onChange={(e) => set({ buttons: form.buttons.map((x, j) => (j === i ? { ...x, tracked: e.target.checked } : x)) })} />
                                                    {t('channels.wa_editor.tracked')}
                                                </label>
                                                {!b.tracked && <Input aria-label={t('channels.wa_editor.buttonUrl')} type="url" placeholder="https://" value={b.url} onChange={(e) => set({ buttons: form.buttons.map((x, j) => (j === i ? { ...x, url: e.target.value } : x)) })} />}
                                            </>
                                        )}
                                        {b.type === 'PHONE_NUMBER' && <Input aria-label={t('channels.wa_editor.phone')} type="tel" placeholder="+573001234567" value={b.phone} onChange={(e) => set({ buttons: form.buttons.map((x, j) => (j === i ? { ...x, phone: e.target.value } : x)) })} />}
                                        {b.type === 'COPY_CODE' && <Input aria-label={t('channels.wa_editor.code')} placeholder="CUPON10" maxLength={20} value={b.example} onChange={(e) => set({ buttons: form.buttons.map((x, j) => (j === i ? { ...x, example: e.target.value } : x)) })} />}
                                        <FieldError message={err(`buttons.${i}.text`) ?? err(`buttons.${i}.url`)} />
                                    </div>
                                    <Button type="button" variant="ghost" size="icon" aria-label={t('channels.wa_editor.remove')} onClick={() => set({ buttons: form.buttons.filter((_, j) => j !== i) })}>
                                        <X />
                                    </Button>
                                </div>
                            ))}
                            <FieldError message={err('buttons')} />
                            {form.buttons.length < 10 && (
                                <Button type="button" variant="outline" size="sm" onClick={() => set({ buttons: [...form.buttons, { type: 'QUICK_REPLY', text: '', url: '', tracked: false, phone: '', example: '' }] })}>
                                    <Plus />
                                    {t('channels.wa_editor.addButton')}
                                </Button>
                            )}
                        </fieldset>
                        {(words.length > 0 || err('category')) && (
                            <div role="alert" className="igr:space-y-2 igr:rounded-lg igr:border igr:border-warning/40 igr:bg-warning/10 igr:p-3 igr:text-[13px]">
                                <p className="igr:flex igr:items-center igr:gap-1.5 igr:font-semibold igr:text-warning">
                                    <TriangleAlert className="igr:size-4" aria-hidden="true" />
                                    {t('channels.wa_editor.warningTitle')}
                                </p>
                                <p>{err('category') ?? t('channels.wa_editor.warning', { words: words.join(', '), category: categoryLabel })}</p>
                                <label className="igr:flex igr:items-center igr:gap-2">
                                    <input type="checkbox" checked={Boolean(form.acknowledge_category_warning)} onChange={(e) => set({ acknowledge_category_warning: e.target.checked })} />
                                    {t('channels.wa_editor.acknowledge', { category: categoryLabel })}
                                </label>
                            </div>
                        )}
                    </form>
                    <aside className="igr:space-y-2">
                        <h3 className={cn('igr:flex igr:items-center igr:gap-1.5 igr:text-[13px] igr:font-semibold')}>
                            <MessageCircle className="igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                            {t('channels.wa_editor.preview')}
                        </h3>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('channels.wa_editor.previewHelp')}</p>
                        <WaPreview form={form} />
                    </aside>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" form="igr-wa-template" loading={save.isPending}>
                        {t('channels.wa_editor.submit')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
