import { ShieldAlert } from 'lucide-react';
import { type SyntheticEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { WhatsAppOneClick } from '@/features/connections/WhatsAppOneClick';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { type ChannelsResponse, useConnectWhatsApp } from '../api';
import { fieldError } from '../shared';

/*
 * WhatsApp oficial: con un clic (Embedded Signup de Meta, también para números que siguen en la app de WhatsApp
 * Business) o a mano con la WABA, el ID del número y un token permanente. Zender queda aparte, con su aviso.
 */
export function WhatsAppDialog({ open, onClose, meta, onZender }: { open: boolean; onClose: () => void; meta: ChannelsResponse['meta'] | undefined; onZender: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const manual = useConnectWhatsApp(workspace.id);
    const [form, setForm] = useState({ waba_id: '', phone_number_id: '', access_token: '', display_name: '' });
    const [showManual, setShowManual] = useState(false);
    const signupReady = meta?.embedded_signup.configured ?? false;
    const isOwner = workspace.role === 'owner';

    const done = () => {
        toast.success(t('channels.toast.connected'));
        onClose();
    };

    const submit = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        manual.mutate({ ...form, display_name: form.display_name || undefined }, { onSuccess: done });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="igr:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{t('channels.wa.title')}</DialogTitle>
                    <DialogDescription>{t('channels.wa.officialHelp')}</DialogDescription>
                </DialogHeader>
                <div className="igr:space-y-4">
                    <section className="igr:space-y-2 igr:rounded-lg igr:border igr:p-3">
                        <h3 className="igr:text-[13px] igr:font-semibold">{t('channels.wa.official')}</h3>
                        <WhatsAppOneClick configured={signupReady} onConnected={done} />
                        {!showManual && signupReady ? (
                            <Button variant="link" size="sm" onClick={() => setShowManual(true)}>
                                {t('channels.wa.manual')}
                            </Button>
                        ) : (
                            <form id="igr-wa-form" onSubmit={submit} className="igr:grid igr:gap-3 igr:pt-1 igr:sm:grid-cols-2">
                                <div className="igr:space-y-1">
                                    <Label htmlFor="wa-waba">{t('channels.wa.wabaId')}</Label>
                                    <Input id="wa-waba" inputMode="numeric" required value={form.waba_id} onChange={(e) => setForm({ ...form, waba_id: e.target.value.trim() })} />
                                    <FieldError message={fieldError(manual.error, 'waba_id')} />
                                </div>
                                <div className="igr:space-y-1">
                                    <Label htmlFor="wa-phone">{t('channels.wa.phoneId')}</Label>
                                    <Input
                                        id="wa-phone"
                                        inputMode="numeric"
                                        required
                                        value={form.phone_number_id}
                                        onChange={(e) => setForm({ ...form, phone_number_id: e.target.value.trim() })}
                                    />
                                    <FieldError message={fieldError(manual.error, 'phone_number_id')} />
                                </div>
                                <div className="igr:space-y-1 igr:sm:col-span-2">
                                    <Label htmlFor="wa-token">{t('channels.wa.token')}</Label>
                                    <Input
                                        id="wa-token"
                                        type="password"
                                        autoComplete="off"
                                        required
                                        value={form.access_token}
                                        onChange={(e) => setForm({ ...form, access_token: e.target.value.trim() })}
                                    />
                                    <FieldError message={fieldError(manual.error, 'access_token')} />
                                    <p className="igr:text-xs igr:text-muted-foreground">{t('channels.wa.manualHelp')}</p>
                                </div>
                                <div className="igr:space-y-1 igr:sm:col-span-2">
                                    <Label htmlFor="wa-name">{t('channels.wa.displayName')}</Label>
                                    <Input id="wa-name" value={form.display_name} onChange={(e) => setForm({ ...form, display_name: e.target.value })} />
                                </div>
                            </form>
                        )}
                    </section>
                    <section className="igr:flex igr:items-start igr:gap-3 igr:rounded-lg igr:border igr:border-dashed igr:p-3">
                        <ShieldAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" aria-hidden="true" />
                        <div className="igr:flex-1 igr:space-y-1">
                            <h3 className="igr:text-[13px] igr:font-semibold">{t('channels.wa.unofficial')}</h3>
                            <p className="igr:text-xs igr:text-muted-foreground">{isOwner ? t('channels.wa.unofficialHelp') : t('channels.wa.ownerOnly')}</p>
                            <Button variant="outline" size="sm" disabled={!isOwner} onClick={onZender}>
                                {t('channels.actions.connect')}
                            </Button>
                        </div>
                    </section>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    {(showManual || !signupReady) && (
                        <Button type="submit" form="igr-wa-form" loading={manual.isPending}>
                            {t('channels.wa.connect')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
