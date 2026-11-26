import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from './button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './dialog';

/**
 * Confirmación de algo que no se deshace o que afecta a los clientes (despublicar, archivar, rotar una clave, dejar de
 * recibir leads). El título pregunta por la acción, la descripción dice qué pasa después y el botón repite la acción
 * («Despublicar», no «Aceptar»). `tone="danger"` pinta el botón de peligro. El foco empieza en «Cancelar».
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    cancelLabel,
    tone = 'default',
    loading = false,
    onConfirm,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    confirmLabel: ReactNode;
    cancelLabel?: ReactNode;
    tone?: 'default' | 'danger';
    loading?: boolean;
    onConfirm: () => void;
    children?: ReactNode;
}) {
    const { t } = useTranslation();
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent role="alertdialog" className="igr:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description ? <DialogDescription>{description}</DialogDescription> : null}
                </DialogHeader>
                {children}
                <DialogFooter>
                    <Button type="button" variant="ghost" autoFocus onClick={() => onOpenChange(false)}>
                        {cancelLabel ?? t('common.cancel')}
                    </Button>
                    <Button type="button" variant={tone === 'danger' ? 'destructive' : 'default'} loading={loading} onClick={onConfirm}>
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
