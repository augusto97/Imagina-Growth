import { Copy, Mail } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { useAffiliateActions } from './api';
import type { Affiliate } from './types';

/** Enlace mágico del panel del creador: enviarlo por correo o copiarlo. Uno nuevo invalida el anterior. */
export function PortalLinkDialog({ affiliate, onClose }: { affiliate: Affiliate; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { portalLink } = useAffiliateActions(workspace.id);
    const [result, setResult] = useState<{ url: string; sent: boolean; reason: string | null } | null>(null);

    const generate = (email: boolean) =>
        portalLink.mutate(
            { id: affiliate.id, email },
            {
                onSuccess: (r) => {
                    setResult(r);
                    if (email && r.sent) toast.success(t('referrals.portal.sent'));
                },
                onError: (error) => toast.error(t('referrals.portal.failed'), isApiError(error) ? error.message : undefined),
            },
        );

    return (
        <Dialog open onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('referrals.portal.title', { name: affiliate.name })}</DialogTitle>
                    <DialogDescription>{t('referrals.portal.description')}</DialogDescription>
                </DialogHeader>
                {result ? (
                    <div className="igr:space-y-2">
                        {!result.sent && result.reason !== null ? <p className="igr:text-xs igr:text-muted-foreground">{t(`referrals.portal.notSent.${result.reason === 'no_email' ? 'no_email' : 'other'}`)}</p> : null}
                        <div className="igr:flex igr:gap-2">
                            <Input readOnly value={result.url} aria-label={t('referrals.portal.link')} onFocus={(e) => e.currentTarget.select()} />
                            <Button
                                variant="outline"
                                onClick={() => void navigator.clipboard.writeText(result.url).then(() => toast.success(t('referrals.portal.copied')))}
                            >
                                <Copy />
                                {t('referrals.portal.copy')}
                            </Button>
                        </div>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('referrals.portal.privateWarning')}</p>
                    </div>
                ) : (
                    <p className="igr:text-[13px]">{affiliate.portal_sent_at ? t('referrals.portal.replaces') : t('referrals.portal.first')}</p>
                )}
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('referrals.close')}
                    </Button>
                    {result === null ? (
                        <>
                            <Button variant="outline" onClick={() => generate(false)} loading={portalLink.isPending}>
                                <Copy />
                                {t('referrals.portal.onlyLink')}
                            </Button>
                            <Button onClick={() => generate(true)} loading={portalLink.isPending} disabled={!affiliate.has_email}>
                                <Mail />
                                {t('referrals.portal.send')}
                            </Button>
                        </>
                    ) : null}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
