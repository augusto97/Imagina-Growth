import { type SyntheticEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { useConnectTwilio } from '../api';
import { fieldError } from '../shared';

export function TwilioDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const connect = useConnectTwilio(workspace.id);
    const [form, setForm] = useState({ account_sid: '', auth_token: '', from: '', messaging_service_sid: '' });

    const submit = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        connect.mutate(
            { account_sid: form.account_sid, auth_token: form.auth_token, from: form.from || undefined, messaging_service_sid: form.messaging_service_sid || undefined },
            {
                onSuccess: () => {
                    toast.success(t('channels.toast.connected'));
                    onClose();
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="igr:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('channels.sms.title')}</DialogTitle>
                    <DialogDescription>{t('channels.empty.sms.description')}</DialogDescription>
                </DialogHeader>
                <form id="igr-twilio-form" onSubmit={submit} className="igr:grid igr:gap-3">
                    {(
                        [
                            ['account_sid', 'channels.sms.sid', 'text'],
                            ['auth_token', 'channels.sms.token', 'password'],
                            ['from', 'channels.sms.from', 'tel'],
                            ['messaging_service_sid', 'channels.sms.service', 'text'],
                        ] as const
                    ).map(([field, label, type]) => (
                        <div key={field} className="igr:space-y-1">
                            <Label htmlFor={`tw-${field}`}>{t(label)}</Label>
                            <Input id={`tw-${field}`} type={type} autoComplete="off" required={field === 'account_sid' || field === 'auth_token'} value={form[field]} onChange={(e) => setForm({ ...form, [field]: e.target.value.trim() })} />
                            <FieldError message={fieldError(connect.error, field)} />
                        </div>
                    ))}
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" form="igr-twilio-form" loading={connect.isPending}>
                        {t('channels.wa.connect')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
