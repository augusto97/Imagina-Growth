import { FlaskConical } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { useTestFlow } from '../api';
import type { GraphNode } from '../types';
import { Field } from './NodePanel';

/** Modo prueba (modulos.md M09): recorre el borrador sin esperas y los mensajes van a quien prueba. */
export function TestDialog({ flowId, open, onOpenChange, nodes }: { flowId: string; open: boolean; onOpenChange: (open: boolean) => void; nodes: GraphNode[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const test = useTestFlow(workspace.id, flowId);
    const [contact, setContact] = useState('');
    const [phone, setPhone] = useState('');
    const error = test.isError ? (isApiError(test.error, 'validation') ? (Object.values(test.error.fieldErrors)[0] ?? test.error.message) : isApiError(test.error) ? test.error.message : t('flows.builder.saveFailed')) : undefined;
    const typeOf = (id: string) => nodes.find((n) => n.id === id)?.type ?? id;

    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                onOpenChange(o);
                if (!o) {
                    test.reset();
                }
            }}
        >
            <DialogContent className="igr:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{t('flows.test.title')}</DialogTitle>
                    <DialogDescription>{t('flows.test.description')}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        test.mutate({ contact_id: contact.trim() === '' ? null : contact.trim(), phone: phone.trim() === '' ? null : phone.trim() });
                    }}
                >
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <Field label={t('flows.test.contact')} htmlFor="test-contact" help={t('flows.test.contactHelp')}>
                            <Input id="test-contact" placeholder="ct_…" value={contact} onChange={(e) => setContact(e.target.value)} />
                        </Field>
                        <Field label={t('flows.test.phone')} htmlFor="test-phone">
                            <Input id="test-phone" inputMode="tel" placeholder="+573001234567" value={phone} onChange={(e) => setPhone(e.target.value)} />
                        </Field>
                    </div>
                    <FieldError message={error} />
                    {test.data ? (
                        <div className="igr:space-y-1.5">
                            <h3 className="igr:text-[13px] igr:font-semibold">{t('flows.test.result')}</h3>
                            <ol className="igr:max-h-64 igr:space-y-1 igr:overflow-y-auto igr:rounded-md igr:border igr:p-2 igr:text-[13px]">
                                {test.data.steps.map((s, i) => (
                                    <li key={`${s.node_id}-${String(i)}`} className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                                        <span>
                                            <span className="igr:mr-1.5 igr:tabular-nums igr:text-muted-foreground">{i + 1}.</span>
                                            {t(`flows.nodes.${typeOf(s.node_id)}`, { defaultValue: s.node_id })}
                                            {typeof s.details.reason === 'string' ? <span className="igr:ml-1.5 igr:text-xs igr:text-muted-foreground">({s.details.reason})</span> : null}
                                        </span>
                                        <Badge variant={s.outcome === 'sent' ? 'success' : s.outcome === 'failed' || s.outcome === 'denied' ? 'destructive' : 'secondary'}>{t(`flows.test.outcomes.${s.outcome}`, { defaultValue: s.outcome })}</Badge>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    ) : null}
                    <DialogFooter>
                        <Button type="submit" loading={test.isPending}>
                            <FlaskConical />
                            {t('flows.test.run')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
