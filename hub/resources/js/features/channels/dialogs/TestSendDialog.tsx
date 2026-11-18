import { type SyntheticEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { type ChannelAccount, useTestSend } from '../api';
import { fieldError } from '../shared';

export function TestSendDialog({ account, onClose }: { account: ChannelAccount | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const send = useTestSend(workspace.id);
    const [to, setTo] = useState('');
    const email = account?.channel === 'email';

    const submit = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (!account) return;
        send.mutate(
            { id: account.id, to },
            {
                onSuccess: (r) => {
                    if (r.status === 'queued') {
                        toast.success(t('channels.toast.testQueued'));
                        onClose();
                    } else {
                        toast.error(t('channels.toast.testNot', { reason: r.reason ?? r.status }));
                    }
                },
            },
        );
    };

    return (
        <Dialog open={account !== null} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="igr:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('channels.test.title')}</DialogTitle>
                    <DialogDescription>{t('channels.test.help')}</DialogDescription>
                </DialogHeader>
                <form id="igr-test-form" onSubmit={submit} className="igr:space-y-1">
                    <Label htmlFor="test-to">{t('channels.test.to')}</Label>
                    <Input id="test-to" type={email ? 'email' : 'tel'} required placeholder={t(email ? 'channels.test.toEmail' : 'channels.test.toPhone')} value={to} onChange={(e) => setTo(e.target.value)} />
                    <FieldError message={fieldError(send.error, 'to')} />
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" form="igr-test-form" loading={send.isPending}>
                        {t('channels.test.send')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
