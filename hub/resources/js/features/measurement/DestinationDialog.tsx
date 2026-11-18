import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { CREDENTIAL_FIELDS, type CatalogEvent, type Destination, type DestinationInput, type DestinationMode, type Platform, useSaveDestination } from './api';

const MODES: DestinationMode[] = ['server_only', 'with_pixel', 'full'];
const GROUPS = ['conversions', 'google_ads', 'spend'] as const;

/** Alta y edición de un destino. Los secretos guardados nunca vuelven: un campo vacío los conserva. */
export function DestinationDialog({ destination, events, onClose }: { destination: Destination | null; events: CatalogEvent[]; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveDestination(workspace.id);
    const [platform, setPlatform] = useState<Platform>(destination?.platform ?? 'meta');
    const [name, setName] = useState(destination?.name ?? '');
    const [mode, setMode] = useState<DestinationMode>(destination?.mode ?? 'server_only');
    const [testCode, setTestCode] = useState(destination?.test_event_code ?? '');
    const [paused, setPaused] = useState(destination?.status === 'paused');
    const [selected, setSelected] = useState<string[]>(destination?.events_enabled ?? events.filter((e) => e.default).map((e) => e.event));
    const [credentials, setCredentials] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | undefined>();

    const toggleEvent = (event: string) => setSelected((list) => (list.includes(event) ? list.filter((e) => e !== event) : [...list, event]));

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        setErrors({});
        setFormError(undefined);
        const input: DestinationInput = {
            name: name.trim() === '' ? null : name.trim(),
            mode,
            events_enabled: selected,
            test_event_code: testCode.trim() === '' ? null : testCode.trim(),
            status: paused ? 'paused' : 'active',
            credentials: Object.fromEntries(Object.entries(credentials).filter(([, v]) => v.trim() !== '')),
        };
        if (destination === null) {
            input.platform = platform;
        }
        save.mutate(
            { id: destination?.id ?? null, input },
            {
                onSuccess: () => {
                    toast.success(destination === null ? t('measurement.form.created') : t('measurement.form.saved'));
                    onClose();
                },
                onError: (error) => {
                    if (isApiError(error) && Object.keys(error.fieldErrors).length > 0) {
                        setErrors(error.fieldErrors);
                        return;
                    }
                    setFormError(isApiError(error) && error.message !== '' ? error.message : t('errors.generic'));
                },
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="igr:max-w-2xl">
                <form onSubmit={submit} className="igr:space-y-5">
                    <DialogHeader>
                        <DialogTitle>{destination === null ? t('measurement.form.newTitle') : t('measurement.form.editTitle')}</DialogTitle>
                        <DialogDescription>{t('measurement.form.help')}</DialogDescription>
                    </DialogHeader>

                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="dst-platform">{t('measurement.form.platform')}</Label>
                            <Select id="dst-platform" value={platform} disabled={destination !== null} onChange={(e) => { setPlatform(e.target.value as Platform); setCredentials({}); }}>
                                {(['meta', 'google', 'tiktok'] as const).map((p) => (
                                    <option key={p} value={p}>
                                        {t(`measurement.platform.${p}`)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="dst-name">{t('measurement.form.name')}</Label>
                            <Input id="dst-name" value={name} maxLength={120} placeholder={t(`measurement.platform.${platform}`)} onChange={(e) => setName(e.target.value)} />
                        </div>
                    </div>

                    <fieldset className="igr:space-y-2">
                        <legend className="igr:text-[13px] igr:font-medium">{t('measurement.form.mode')}</legend>
                        <div className="igr:grid igr:gap-2 igr:sm:grid-cols-3">
                            {MODES.map((m) => (
                                <label key={m} className={cn('igr:cursor-pointer igr:rounded-md igr:border igr:p-2.5 igr:text-xs', mode === m && 'igr:border-primary igr:bg-primary/5')}>
                                    <input type="radio" name="mode" value={m} checked={mode === m} onChange={() => setMode(m)} className="igr:sr-only" />
                                    <span className="igr:block igr:text-[13px] igr:font-medium">{t(`measurement.mode.${m}.title`)}</span>
                                    <span className="igr:mt-0.5 igr:block igr:text-muted-foreground">{t(`measurement.mode.${m}.help`)}</span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    {GROUPS.map((group) => {
                        const fields = CREDENTIAL_FIELDS[platform].filter((f) => f.group === group);
                        if (fields.length === 0) {
                            return null;
                        }
                        return (
                            <fieldset key={group} className="igr:space-y-2">
                                <legend className="igr:text-[13px] igr:font-medium">{t(`measurement.form.group.${group}`)}</legend>
                                <p className="igr:text-xs igr:text-muted-foreground">{t(`measurement.form.groupHelp.${platform}_${group}`)}</p>
                                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                                    {fields.map((field) => {
                                        const saved = destination?.credentials[field.key] ?? null;
                                        const errorKey = `credentials.${field.key}`;
                                        return (
                                            <div key={field.key} className="igr:space-y-1.5">
                                                <Label htmlFor={`cred-${field.key}`}>
                                                    {t(`measurement.credential.${field.key}`)}
                                                    {field.required ? ' *' : ''}
                                                </Label>
                                                <Input
                                                    id={`cred-${field.key}`}
                                                    type={field.secret ? 'password' : 'text'}
                                                    autoComplete="off"
                                                    value={credentials[field.key] ?? ''}
                                                    placeholder={saved !== null ? (field.secret ? t('measurement.form.keepSecret', { masked: saved }) : saved) : ''}
                                                    aria-invalid={errors[errorKey] ? true : undefined}
                                                    onChange={(e) => setCredentials((c) => ({ ...c, [field.key]: e.target.value }))}
                                                />
                                                <FieldError message={errors[errorKey]} />
                                            </div>
                                        );
                                    })}
                                </div>
                            </fieldset>
                        );
                    })}

                    <fieldset className="igr:space-y-2">
                        <legend className="igr:text-[13px] igr:font-medium">{t('measurement.form.events')}</legend>
                        <div className="igr:grid igr:gap-1.5 igr:sm:grid-cols-2">
                            {events.map((e) => (
                                <label key={e.event} className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                                    <input type="checkbox" checked={selected.includes(e.event)} onChange={() => toggleEvent(e.event)} />
                                    <span>{t(`measurement.events.${e.event.replace('.', '_')}`)}</span>
                                    <span className="igr:text-xs igr:text-muted-foreground">→ {e[platform]}</span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="dst-test">{t('measurement.form.testCode')}</Label>
                            <Input id="dst-test" value={testCode} maxLength={64} onChange={(e) => setTestCode(e.target.value)} />
                            <p className="igr:text-xs igr:text-muted-foreground">{t('measurement.form.testCodeHelp')}</p>
                        </div>
                        {destination !== null ? (
                            <label className="igr:flex igr:items-center igr:gap-2 igr:self-center igr:text-[13px]">
                                <input type="checkbox" checked={paused} onChange={(e) => setPaused(e.target.checked)} />
                                {t('measurement.form.pause')}
                            </label>
                        ) : null}
                    </div>

                    {formError ? <p className="igr:text-xs igr:text-destructive">{formError}</p> : null}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={save.isPending}>
                            {t('measurement.form.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
