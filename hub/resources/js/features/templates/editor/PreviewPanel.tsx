import { Monitor, Search, Smartphone, UserRound, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { type PreviewResult, useContactSearch } from '../api';
import { fillWaText } from '../content';
import { PhoneMock, SmsBubble, WaBubble } from './MessageMock';

/** Vista previa renderizada en el hub: correo en un iframe aislado, SMS y WhatsApp en burbujas. */
export function PreviewView({ result, channel, waBody }: { result: PreviewResult; channel: 'email' | 'sms' | 'whatsapp'; waBody?: { header?: string; body?: string; footer?: string; buttons?: string[]; urlButtons?: boolean[] } | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
    const [tab, setTab] = useState<'html' | 'text'>('html');

    if (channel === 'email') {
        return (
            <div className="igr:space-y-3">
                <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                    <div className="igr:min-w-0">
                        <p className="igr:truncate igr:text-[13px] igr:font-semibold">{result.subject}</p>
                        <p className="igr:truncate igr:text-xs igr:text-muted-foreground">{result.preheader}</p>
                    </div>
                    <div className="igr:flex igr:gap-1" role="group" aria-label={t('templates.preview.device')}>
                        <Button type="button" size="sm" variant={tab === 'html' && device === 'desktop' ? 'secondary' : 'ghost'} onClick={() => { setTab('html'); setDevice('desktop'); }} aria-pressed={tab === 'html' && device === 'desktop'}>
                            <Monitor className="igr:size-3.5" />
                            {t('templates.preview.desktop')}
                        </Button>
                        <Button type="button" size="sm" variant={tab === 'html' && device === 'mobile' ? 'secondary' : 'ghost'} onClick={() => { setTab('html'); setDevice('mobile'); }} aria-pressed={tab === 'html' && device === 'mobile'}>
                            <Smartphone className="igr:size-3.5" />
                            {t('templates.preview.mobile')}
                        </Button>
                        <Button type="button" size="sm" variant={tab === 'text' ? 'secondary' : 'ghost'} onClick={() => setTab('text')} aria-pressed={tab === 'text'}>
                            {t('templates.preview.text')}
                        </Button>
                    </div>
                </div>
                {tab === 'html' ? (
                    <div className="igr:flex igr:justify-center igr:rounded-lg igr:bg-muted/50 igr:p-3">
                        <iframe
                            title={t('templates.preview.title')}
                            sandbox=""
                            srcDoc={result.html ?? ''}
                            className={cn('igr:h-[620px] igr:rounded-md igr:border igr:bg-white', device === 'mobile' ? 'igr:w-[375px]' : 'igr:w-full igr:max-w-[680px]')}
                        />
                    </div>
                ) : (
                    <pre className="igr:max-h-[620px] igr:overflow-auto igr:whitespace-pre-wrap igr:rounded-lg igr:bg-muted/50 igr:p-4 igr:font-sans igr:text-[13px]">{result.text}</pre>
                )}
            </div>
        );
    }

    if (channel === 'sms') {
        return (
            <div>
                <PhoneMock business={workspace.name} variant="sms">
                    <SmsBubble text={result.text ?? ''} />
                </PhoneMock>
                {result.sms && (
                    <p className="igr:mt-2 igr:text-center igr:text-caption igr:text-muted-foreground">
                        {t('templates.sms.previewCounter', { length: result.sms.length, count: result.sms.segments, encoding: result.sms.encoding })}
                    </p>
                )}
            </div>
        );
    }

    const values = (result.whatsapp?.components ?? []).reduce<Record<string, string[]>>((acc, c) => {
        if (c.type === 'header' || c.type === 'body') acc[c.type] = c.parameters.map((p) => (typeof p.text === 'string' ? p.text : ''));
        return acc;
    }, {});
    return (
        <PhoneMock business={workspace.name}>
            <WaBubble
                header={waBody?.header ? fillWaText(waBody.header, values.header ?? []) : null}
                body={fillWaText(waBody?.body ?? '', values.body ?? [])}
                footer={waBody?.footer ?? null}
                buttons={(waBody?.buttons ?? []).map((text, i) => ({ text, url: waBody?.urlButtons?.[i] ?? false }))}
            />
        </PhoneMock>
    );
}

/** Elegir con quién se previsualiza: datos de ejemplo o un contacto real (con permiso para ver contactos). */
export function PreviewContactPicker({ contact, onChange }: { contact: { id: string; name: string } | null; onChange: (c: { id: string; name: string } | null) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canSee = useCan('viewAllContacts');
    const [q, setQ] = useState('');
    const [debounced, setDebounced] = useState('');
    useEffect(() => {
        const id = setTimeout(() => setDebounced(q), 300);
        return () => clearTimeout(id);
    }, [q]);
    const results = useContactSearch(workspace.id, debounced, canSee && contact === null);

    if (!canSee) {
        return <p className="igr:text-xs igr:text-muted-foreground">{t('templates.preview.sampleOnly')}</p>;
    }
    if (contact) {
        return (
            <div className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                <UserRound className="igr:size-4 igr:text-muted-foreground" />
                <span>{t('templates.preview.asContact', { name: contact.name })}</span>
                <Button type="button" variant="ghost" size="sm" onClick={() => onChange(null)} aria-label={t('templates.preview.useSample')}>
                    <X className="igr:size-3.5" />
                    {t('templates.preview.useSample')}
                </Button>
            </div>
        );
    }
    return (
        <div className="igr:relative igr:w-full igr:max-w-sm">
            <Search className="igr:pointer-events-none igr:absolute igr:top-2.5 igr:left-2.5 igr:size-3.5 igr:text-muted-foreground" />
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('templates.preview.searchContact')} className="igr:pl-8" aria-label={t('templates.preview.searchContact')} />
            {debounced.trim().length >= 2 && (
                <div className="igr:absolute igr:z-20 igr:mt-1 igr:w-full igr:rounded-md igr:border igr:bg-popover igr:p-1 igr:shadow-md">
                    {results.isPending ? (
                        <Skeleton className="igr:h-8" />
                    ) : (results.data ?? []).length === 0 ? (
                        <p className="igr:px-2 igr:py-1.5 igr:text-xs igr:text-muted-foreground">{t('templates.preview.noContacts')}</p>
                    ) : (
                        (results.data ?? []).map((c) => (
                            <button
                                key={c.id}
                                type="button"
                                onClick={() => {
                                    onChange({ id: c.id, name: c.name });
                                    setQ('');
                                }}
                                className="igr:flex igr:w-full igr:flex-col igr:rounded-sm igr:px-2 igr:py-1.5 igr:text-left igr:text-[13px] igr:hover:bg-accent"
                            >
                                <span>{c.name}</span>
                                <span className="igr:text-xs igr:text-muted-foreground">{[c.email, c.city].filter(Boolean).join(' · ')}</span>
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
