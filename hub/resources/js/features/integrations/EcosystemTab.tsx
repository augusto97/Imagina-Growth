import { Plug } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { errorMessage } from '@/features/contacts/errors';
import { type EcosystemPreset, type IntegrationsOverview, useConnectPreset } from './api';
import { SecretReveal } from './SecretReveal';

/** Productos de Imagina La Web: un token con sus alcances y, si aplica, su webhook. */
export function EcosystemTab({ ws, overview }: { ws: string; overview: IntegrationsOverview }) {
    const { t } = useTranslation();
    const [connecting, setConnecting] = useState<EcosystemPreset | null>(null);
    return (
        <>
            <div className="igr:grid igr:gap-4 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                {overview.ecosystem.map((p) => (
                    <Card key={p.key} data-testid={`preset-${p.key}`} className="igr:flex igr:flex-col">
                        <CardHeader>
                            <CardTitle className="igr:flex igr:items-center igr:gap-2">
                                <Plug className="igr:size-4" aria-hidden />
                                {p.name}
                                {p.connected && <Badge variant="success">{t('integrations.ecosystem.connected')}</Badge>}
                            </CardTitle>
                            <CardDescription>{t(`integrations.ecosystem.${p.key}`)}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:flex-1 igr:space-y-1 igr:text-xs igr:text-muted-foreground">
                            <p>
                                <span className="igr:font-medium igr:text-foreground">{t('integrations.tokens.scopes')}:</span> {p.scopes.join(', ')}
                            </p>
                            {p.events.length > 0 && (
                                <p>
                                    <span className="igr:font-medium igr:text-foreground">{t('integrations.ecosystem.events')}:</span> {p.events.join(', ')}
                                </p>
                            )}
                        </CardContent>
                        <CardFooter>
                            <Button variant={p.connected ? 'outline' : 'default'} size="sm" onClick={() => setConnecting(p)} disabled={!overview.plan.api}>
                                {p.connected ? t('integrations.ecosystem.newToken') : t('integrations.ecosystem.connect')}
                            </Button>
                        </CardFooter>
                    </Card>
                ))}
            </div>
            {connecting && <ConnectDialog key={connecting.key} ws={ws} preset={connecting} onClose={() => setConnecting(null)} />}
        </>
    );
}

function ConnectDialog({ ws, preset, onClose }: { ws: string; preset: EcosystemPreset; onClose: () => void }) {
    const { t } = useTranslation();
    const connect = useConnectPreset(ws);
    const [url, setUrl] = useState('');
    const result = connect.data;
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="igr:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('integrations.ecosystem.connectTitle', { name: preset.name })}</DialogTitle>
                    <DialogDescription>{t('integrations.ecosystem.connectHint')}</DialogDescription>
                </DialogHeader>
                {result ? (
                    <div className="igr:space-y-4">
                        <SecretReveal label={t('integrations.tokens.yourToken')} value={result.token} />
                        {result.secret && <SecretReveal label={t('integrations.webhooks.secret')} value={result.secret} />}
                    </div>
                ) : (
                    <form
                        id="connect-preset"
                        className="igr:space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            connect.mutate({ preset: preset.key, webhook_url: url.trim() === '' ? null : url.trim() });
                        }}
                    >
                        {preset.events.length > 0 && (
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="preset-url">{t('integrations.ecosystem.webhookUrl')}</Label>
                                <Input id="preset-url" type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://" />
                                <p className="igr:text-xs igr:text-muted-foreground">{t('integrations.ecosystem.webhookUrlHint')}</p>
                            </div>
                        )}
                        <FieldError message={connect.error ? errorMessage(connect.error, t) : undefined} />
                    </form>
                )}
                <DialogFooter>
                    {result ? (
                        <Button onClick={onClose}>{t('integrations.done')}</Button>
                    ) : (
                        <>
                            <Button variant="outline" onClick={onClose}>
                                {t('integrations.cancel')}
                            </Button>
                            <Button type="submit" form="connect-preset" loading={connect.isPending}>
                                {t('integrations.ecosystem.connect')}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
