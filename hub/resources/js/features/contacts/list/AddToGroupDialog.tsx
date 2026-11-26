import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type ContactSelection, useAddToGroup } from '../api';
import { errorMessage } from '../errors';
import type { GroupOption } from './ContactFiltersSheet';

/**
 * «Agregar a un grupo»: las personas elegidas entran a un grupo fijo que ya existe o a uno nuevo. Los grupos que se arman
 * solos con condiciones no aparecen (su lista la deciden sus reglas).
 */
export function AddToGroupDialog({
    open,
    onOpenChange,
    selection,
    count,
    groups,
    onDone,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    selection: ContactSelection;
    count: number;
    /** Grupos fijos propios (estáticos, no del sistema). */
    groups: GroupOption[];
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const add = useAddToGroup(workspace.id);
    const [mode, setMode] = useState<'new' | 'existing'>('new');
    const [name, setName] = useState('');
    const [segmentId, setSegmentId] = useState(groups[0]?.id ?? '');
    const valid = mode === 'new' ? name.trim().length >= 2 : segmentId !== '';
    const formatted = formatNumber(count, workspace.locale);

    const submit = () =>
        add.mutate(mode === 'new' ? { selection, name: name.trim() } : { selection, segment_id: segmentId }, {
            onSuccess: (r) => {
                toast.success(t('contacts.group.done', { count: r.added, formatted: formatNumber(r.added, workspace.locale), name: r.segment.name }));
                onDone();
                onOpenChange(false);
            },
        });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    add.reset();
                }
                onOpenChange(next);
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('contacts.group.title')}</DialogTitle>
                    <DialogDescription>{t('contacts.group.description', { count, formatted })}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (valid && !add.isPending) {
                            submit();
                        }
                    }}
                >
                    <fieldset className="igr:space-y-2">
                        <legend className="igr:sr-only">{t('contacts.group.where')}</legend>
                        <label className="igr:flex igr:items-center igr:gap-2 igr:text-body">
                            <input type="radio" name="group-mode" className="igr:accent-primary" checked={mode === 'new'} onChange={() => setMode('new')} />
                            {t('contacts.group.new')}
                        </label>
                        <label className="igr:flex igr:items-center igr:gap-2 igr:text-body">
                            <input type="radio" name="group-mode" className="igr:accent-primary" checked={mode === 'existing'} disabled={groups.length === 0} onChange={() => setMode('existing')} />
                            {t('contacts.group.existing')}
                        </label>
                        {groups.length === 0 ? <p className="igr:pl-6 igr:text-caption igr:text-muted-foreground">{t('contacts.group.noStatic')}</p> : null}
                    </fieldset>
                    {mode === 'new' ? (
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="group-name">{t('contacts.group.name')}</Label>
                            <Input id="group-name" autoFocus value={name} maxLength={160} placeholder={t('contacts.group.namePlaceholder')} onChange={(e) => setName(e.target.value)} />
                        </div>
                    ) : (
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="group-existing">{t('contacts.group.pick')}</Label>
                            <Select id="group-existing" value={segmentId} onChange={(e) => setSegmentId(e.target.value)}>
                                {groups.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.name}
                                    </option>
                                ))}
                            </Select>
                            <p className="igr:text-caption igr:text-muted-foreground">{t('contacts.group.staticHelp')}</p>
                        </div>
                    )}
                    <FieldError message={add.isError ? errorMessage(add.error, t) : undefined} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={add.isPending} disabled={!valid}>
                            {t('contacts.group.submit', { count, formatted })}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
