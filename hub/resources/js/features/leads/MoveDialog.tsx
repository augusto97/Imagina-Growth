import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Stage } from './types';

/** Al ganar pide el valor (va a la conversión offline); al perder, el motivo. */
export function MoveDialog({
    stage,
    initialValue,
    onCancel,
    onConfirm,
}: {
    stage: Stage | null;
    initialValue: string | null;
    onCancel: () => void;
    onConfirm: (extra: { value?: string | null; lost_reason?: string | null }) => void;
}) {
    const { t } = useTranslation();
    const [value, setValue] = useState(initialValue ?? '');
    const [reason, setReason] = useState('');
    const won = stage?.kind === 'won';
    return (
        <Dialog open={stage !== null} onOpenChange={(open) => (!open ? onCancel() : undefined)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{won ? t('leads.move.wonTitle') : t('leads.move.lostTitle')}</DialogTitle>
                    <DialogDescription>{won ? t('leads.move.wonHelp') : t('leads.move.lostHelp')}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        onConfirm(won ? { value: value.trim() === '' ? null : value.replace(/[^\d.]/g, '') } : { lost_reason: reason.trim() || null });
                    }}
                >
                    {won ? (
                        <div className="igr:space-y-1">
                            <Label htmlFor="lead-won-value">{t('leads.move.value')}</Label>
                            <Input id="lead-won-value" inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)} autoFocus />
                        </div>
                    ) : (
                        <div className="igr:space-y-1">
                            <Label htmlFor="lead-lost-reason">{t('leads.move.reason')}</Label>
                            <Input id="lead-lost-reason" maxLength={120} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('leads.move.reasonPlaceholder')} autoFocus />
                        </div>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onCancel}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit">{won ? t('leads.move.markWon') : t('leads.move.markLost')}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
