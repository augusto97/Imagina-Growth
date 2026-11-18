import { useCallback, useRef, useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { api, isApiError } from '@/lib/api';

/**
 * Fortify exige confirmar la contraseña (423) antes de tocar la 2FA.
 * run(fn) ejecuta la acción; si el servidor pide confirmación, abre el
 * diálogo y reintenta al confirmar.
 */
export function usePasswordConfirm() {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const pending = useRef<{ resolve: () => void; reject: (e: unknown) => void } | null>(null);

    const run = useCallback(async <T,>(fn: () => Promise<T>): Promise<T> => {
        try {
            return await fn();
        } catch (e) {
            if (!isApiError(e, 'password_confirmation_required')) {
                throw e;
            }
            await new Promise<void>((resolve, reject) => {
                pending.current = { resolve, reject };
                setPassword('');
                setError(undefined);
                setOpen(true);
            });
            return fn();
        }
    }, []);

    const submit = async (event: SyntheticEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(undefined);
        try {
            await api.post('/auth/user/confirm-password', { password });
            setOpen(false);
            pending.current?.resolve();
            pending.current = null;
        } catch (e) {
            setError(isApiError(e, 'validation') ? t('auth.wrongPassword') : t('errors.generic'));
        } finally {
            setBusy(false);
        }
    };

    const dialog = (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    pending.current?.reject(new Error('cancelled'));
                    pending.current = null;
                }
                setOpen(next);
            }}
        >
            <DialogContent>
                <form onSubmit={(e) => void submit(e)}>
                    <DialogHeader>
                        <DialogTitle>{t('auth.confirmPasswordTitle')}</DialogTitle>
                        <DialogDescription>{t('auth.confirmPasswordDescription')}</DialogDescription>
                    </DialogHeader>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="confirm-password">{t('auth.password')}</Label>
                        <Input
                            id="confirm-password"
                            type="password"
                            autoComplete="current-password"
                            autoFocus
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            aria-invalid={error ? true : undefined}
                        />
                        <FieldError message={error} />
                    </div>
                    <DialogFooter>
                        <Button type="submit" loading={busy} disabled={password === ''}>
                            {t('common.confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );

    return { run, dialog };
}
