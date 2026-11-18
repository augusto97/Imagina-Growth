import { KeyRound, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { errorMessage } from '@/features/contacts/errors';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type ApiClientRow, type IntegrationsOverview, useApiTokens, useCreateToken, useRevokeToken } from './api';
import { SecretReveal } from './SecretReveal';

export function TokensTab({ ws, overview, onCreate }: { ws: string; overview: IntegrationsOverview; onCreate: () => void }) {
    const { t } = useTranslation();
    const tokens = useApiTokens(ws);
    const revoke = useRevokeToken(ws);
    const [confirm, setConfirm] = useState<ApiClientRow | null>(null);

    if (tokens.isError) return <ErrorState error={tokens.error} onRetry={() => void tokens.refetch()} />;
    if (tokens.isPending) return <Skeleton className="igr:h-40" />;

    return (
        <Card>
            {tokens.data.length === 0 ? (
                <EmptyState
                    icon={KeyRound}
                    title={t('integrations.tokens.empty')}
                    description={t('integrations.tokens.emptyHint', { url: overview.base_url })}
                    action={
                        <Button onClick={onCreate}>
                            <Plus className="igr:size-4" aria-hidden />
                            {t('integrations.tokens.create')}
                        </Button>
                    }
                />
            ) : (
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('integrations.tokens.name')}</TH>
                            <TH>{t('integrations.tokens.scopes')}</TH>
                            <TH>{t('integrations.tokens.prefix')}</TH>
                            <TH>{t('integrations.tokens.lastUsed')}</TH>
                            <TH className="igr:text-right">{t('integrations.actions')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {tokens.data.map((c) => (
                            <TR key={c.id} className={c.revoked_at ? 'igr:opacity-60' : undefined}>
                                <TD>
                                    <div className="igr:font-medium">{c.name}</div>
                                    <div className="igr:mt-0.5 igr:flex igr:gap-1">
                                        <Badge variant="outline">{t(`integrations.kind.${c.kind}`)}</Badge>
                                        {c.revoked_at && <Badge variant="destructive">{t('integrations.tokens.revoked')}</Badge>}
                                    </div>
                                </TD>
                                <TD>
                                    <div className="igr:flex igr:max-w-md igr:flex-wrap igr:gap-1">
                                        {c.scopes.map((s) => (
                                            <Badge key={s} variant="secondary" className="igr:font-mono igr:text-[11px]">
                                                {s}
                                            </Badge>
                                        ))}
                                    </div>
                                </TD>
                                <TD className="igr:font-mono igr:text-xs">{c.token_prefix ? `${c.token_prefix}…` : '—'}</TD>
                                <TD className="igr:text-xs igr:text-muted-foreground">{c.last_used_at ? formatDateTime(c.last_used_at) : t('integrations.tokens.never')}</TD>
                                <TD className="igr:text-right">
                                    {!c.revoked_at && (
                                        <Button variant="ghost" size="sm" onClick={() => setConfirm(c)}>
                                            {t('integrations.tokens.revoke')}
                                        </Button>
                                    )}
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            )}
            <Dialog open={confirm !== null} onOpenChange={(open) => !open && setConfirm(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('integrations.tokens.revokeTitle', { name: confirm?.name ?? '' })}</DialogTitle>
                        <DialogDescription>{t('integrations.tokens.revokeHint')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirm(null)}>
                            {t('integrations.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            loading={revoke.isPending}
                            onClick={() =>
                                confirm &&
                                revoke.mutate(confirm.id, {
                                    onSuccess: () => {
                                        toast.success(t('integrations.tokens.revokedToast'));
                                        setConfirm(null);
                                    },
                                    onError: (e) => toast.error(errorMessage(e, t)),
                                })
                            }
                        >
                            {t('integrations.tokens.revoke')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

/** Crear un token: nombre, tipo (API o MCP) y alcances. El token se muestra una vez. */
export function CreateTokenDialog({ ws, overview, open, kind, onClose }: { ws: string; overview: IntegrationsOverview; open: boolean; kind: 'token' | 'mcp'; onClose: () => void }) {
    const { t } = useTranslation();
    const create = useCreateToken(ws);
    const [name, setName] = useState('');
    const [scopes, setScopes] = useState<string[]>(kind === 'mcp' ? ['analytics:read', 'segments:read', 'contacts:read', 'campaigns:write'] : overview.default_scopes);
    const [token, setToken] = useState<string | null>(null);
    const scopeList = overview.scopes.filter((s) => s !== 'mcp');
    const close = () => {
        setToken(null);
        setName('');
        create.reset();
        onClose();
    };
    const error = create.error ? errorMessage(create.error, t) : undefined;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent className="igr:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{kind === 'mcp' ? t('integrations.tokens.createMcp') : t('integrations.tokens.create')}</DialogTitle>
                    <DialogDescription>{kind === 'mcp' ? t('integrations.tokens.mcpHint') : t('integrations.tokens.createHint')}</DialogDescription>
                </DialogHeader>
                {token ? (
                    <SecretReveal label={t('integrations.tokens.yourToken')} value={token} />
                ) : (
                    <form
                        id="create-token"
                        className="igr:space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            create.mutate({ name, kind, scopes }, { onSuccess: (r) => setToken(r.token) });
                        }}
                    >
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="token-name">{t('integrations.tokens.name')}</Label>
                            <Input id="token-name" value={name} onChange={(e) => setName(e.target.value)} placeholder={kind === 'mcp' ? 'Claude' : 'Zapier'} maxLength={120} required />
                        </div>
                        <fieldset className="igr:space-y-1.5">
                            <legend className="igr:text-[13px] igr:font-medium">{t('integrations.tokens.scopes')}</legend>
                            <div className="igr:grid igr:gap-1.5 igr:sm:grid-cols-2">
                                {scopeList.map((s) => (
                                    <label key={s} className="igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:border igr:p-2 igr:text-xs">
                                        <input
                                            type="checkbox"
                                            className="igr:mt-0.5"
                                            checked={scopes.includes(s)}
                                            onChange={(e) => setScopes((cur) => (e.target.checked ? [...cur, s] : cur.filter((x) => x !== s)))}
                                        />
                                        <span>
                                            <span className="igr:block igr:font-mono">{s}</span>
                                            <span className="igr:text-muted-foreground">{t(`integrations.scope.${s.replace(':', '_')}`)}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <FieldError message={error} />
                    </form>
                )}
                <DialogFooter>
                    {token ? (
                        <Button onClick={close}>{t('integrations.done')}</Button>
                    ) : (
                        <>
                            <Button variant="outline" onClick={close}>
                                {t('integrations.cancel')}
                            </Button>
                            <Button type="submit" form="create-token" loading={create.isPending} disabled={name.trim().length < 2 || scopes.length === 0}>
                                {t('integrations.tokens.createAction')}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
