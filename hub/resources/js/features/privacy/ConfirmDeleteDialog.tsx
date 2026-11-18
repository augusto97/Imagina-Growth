import { TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { DELETE_CONFIRMATION } from './api';

/**
 * Borrado definitivo de los datos de una persona: se confirma escribiendo
 * «BORRAR» (DataRequestController::execute lo exige también en el servidor).
 */
export function ConfirmDeleteDialog({
    open,
    onOpenChange,
    onConfirm,
    loading,
    error,
    name,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: (confirm: string) => void;
    loading: boolean;
    error?: string | null;
    name?: string | null;
}) {
    const { t } = useTranslation();
    const [typed, setTyped] = useState('');
    const inputId = useId();
    const ok = typed.trim() === DELETE_CONFIRMATION;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setTyped('');
                }
                onOpenChange(next);
            }}
        >
            <DialogContent>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (ok) {
                            onConfirm(DELETE_CONFIRMATION);
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle className="igr:flex igr:items-center igr:gap-2">
                            <TriangleAlert className="igr:size-4 igr:text-destructive" />
                            {name ? t('privacy.delete.titleNamed', { name }) : t('privacy.delete.title')}
                        </DialogTitle>
                        <DialogDescription>{t('privacy.delete.description')}</DialogDescription>
                    </DialogHeader>
                    <ul className="igr:my-3 igr:list-disc igr:space-y-1 igr:pl-5 igr:text-[13px] igr:text-muted-foreground">
                        <li>{t('privacy.delete.point1')}</li>
                        <li>{t('privacy.delete.point2')}</li>
                        <li>{t('privacy.delete.point3')}</li>
                    </ul>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor={inputId}>{t('privacy.delete.typeToConfirm', { word: DELETE_CONFIRMATION })}</Label>
                        <Input id={inputId} autoComplete="off" value={typed} onChange={(e) => setTyped(e.target.value)} placeholder={DELETE_CONFIRMATION} />
                        <FieldError message={error ?? undefined} />
                    </div>
                    <DialogFooter className="igr:mt-4">
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" variant="destructive" disabled={!ok} loading={loading}>
                            {t('privacy.delete.confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
