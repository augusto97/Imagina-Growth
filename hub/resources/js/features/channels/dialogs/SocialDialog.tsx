import { Link2 } from 'lucide-react';
import { type SyntheticEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { useConnectSocial } from '../api';
import { fieldError } from '../shared';

/**
 * Messenger o Instagram Direct (M11, Fase 5): con un clic desde Conexiones → Meta (se
 * elige la página y su cuenta de Instagram) o a mano con el ID de la página, su token
 * (de un usuario del sistema) y, para Instagram, el ID de la cuenta profesional.
 */
export function SocialDialog({ kind, open, onClose }: { kind: 'messenger' | 'instagram'; open: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const connect = useConnectSocial(workspace.id, kind);
    const [form, setForm] = useState({ page_id: '', ig_user_id: '', page_access_token: '' });
    const fields = kind === 'instagram' ? (['page_id', 'ig_user_id', 'page_access_token'] as const) : (['page_id', 'page_access_token'] as const);

    const submit = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        connect.mutate(
            { page_id: form.page_id, page_access_token: form.page_access_token, ig_user_id: kind === 'instagram' ? form.ig_user_id : undefined },
            {
                onSuccess: (r) => {
                    toast.success(t('channels.toast.connected'));
                    if (r.data.last_error) toast.info(r.data.last_error);
                    setForm({ page_id: '', ig_user_id: '', page_access_token: '' });
                    onClose();
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="igr:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t(`channels.social.${kind}.title`)}</DialogTitle>
                    <DialogDescription>{t(`channels.social.${kind}.description`)}</DialogDescription>
                </DialogHeader>
                <div className="igr:flex igr:items-start igr:gap-3 igr:rounded-lg igr:border igr:p-3">
                    <Link2 className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-primary" aria-hidden="true" />
                    <div className="igr:flex-1 igr:space-y-1">
                        <h3 className="igr:text-[13px] igr:font-semibold">{t('connections.shortcut.title')}</h3>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('connections.shortcut.social')}</p>
                        <Button variant="outline" size="sm" asChild>
                            <Link to={`/w/${workspace.id}/conexiones`}>{t('connections.shortcut.go')}</Link>
                        </Button>
                    </div>
                </div>
                <form id={`igr-${kind}-form`} onSubmit={submit} className="igr:grid igr:gap-3">
                    {fields.map((field) => (
                        <div key={field} className="igr:space-y-1">
                            <Label htmlFor={`${kind}-${field}`}>{t(`channels.social.fields.${field}`)}</Label>
                            <Input
                                id={`${kind}-${field}`}
                                type={field === 'page_access_token' ? 'password' : 'text'}
                                inputMode={field === 'page_access_token' ? undefined : 'numeric'}
                                autoComplete="off"
                                required
                                value={form[field]}
                                onChange={(e) => setForm({ ...form, [field]: e.target.value.trim() })}
                            />
                            <FieldError message={fieldError(connect.error, field)} />
                        </div>
                    ))}
                    <p className="igr:rounded-md igr:bg-muted igr:p-2.5 igr:text-xs igr:text-muted-foreground">{t(`channels.social.${kind}.rules`)}</p>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" form={`igr-${kind}-form`} loading={connect.isPending}>
                        {t('channels.wa.connect')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
