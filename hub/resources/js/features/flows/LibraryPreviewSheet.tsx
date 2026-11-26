import { Flag, Repeat, Zap } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { ErrorState } from '@/components/ui/misc';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { EmailMock, PhoneMock, SmsBubble, WaBubble } from '@/features/templates/editor/MessageMock';
import { useFlowOptions, useLibraryFlowPreview } from './api';
import { eventLabel, triggerText } from './catalog';
import { FlowOutlineList } from './builder/MobileFlowView';
import { useRunLibraryFlow } from './LibraryGallery';
import type { FlowOptions, GraphNode, LibraryFlow, LibraryMessage, ReentryPolicy } from './types';

/**
 * «Ver recorrido» de un flujo de la biblioteca antes de crearlo: cuándo empieza, cuándo termina, cada paso en palabras
 * (la misma lista del celular, `FlowOutlineList`) y, debajo de cada envío, el mensaje con datos de ejemplo como lo verá
 * el cliente. Desde aquí se activa o se personaliza.
 */
export function LibraryPreviewSheet({ flow, onClose }: { flow: LibraryFlow | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const preview = useLibraryFlowPreview(workspace.id, flow?.key ?? null);
    const options = useFlowOptions(workspace.id);
    const { run, pending, variables } = useRunLibraryFlow();
    const data = preview.data;
    const messages = new Map((data?.messages ?? []).map((m) => [m.node_id, m]));
    // Las plantillas de la biblioteca todavía no están instaladas: el nombre sale de la vista previa.
    const withNames: FlowOptions | undefined = options.data
        ? {
              ...options.data,
              templates: [
                  ...options.data.templates,
                  ...(data?.messages ?? [])
                      .filter((m) => m.name !== null && !options.data.templates.some((tpl) => tpl.library_key === m.template_key))
                      .map((m) => ({ id: `lib:${m.template_key}`, name: m.name ?? m.template_key, channel: m.channel, category: m.kind, library_key: m.template_key, status: 'ready' })),
              ],
          }
        : undefined;

    return (
        <Sheet open={flow !== null} onOpenChange={(o) => !o && onClose()}>
            <SheetContent className="igr:overflow-hidden igr:sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>{flow?.name}</SheetTitle>
                    <SheetDescription>{flow?.description}</SheetDescription>
                </SheetHeader>
                <SheetBody className="igr:space-y-5 igr:overflow-y-auto">
                    {preview.isError ? (
                        <ErrorState error={preview.error} onRetry={() => void preview.refetch()} />
                    ) : preview.isPending || !data ? (
                        <div className="igr:space-y-3" aria-busy="true">
                            <Skeleton className="igr:h-20 igr:rounded-xl" />
                            <Skeleton className="igr:h-16 igr:rounded-lg" />
                            <Skeleton className="igr:h-40 igr:rounded-lg" />
                        </div>
                    ) : (
                        <>
                            <dl className="igr:grid igr:gap-2 igr:rounded-xl igr:border igr:bg-muted/30 igr:p-3 igr:text-support">
                                {data.trigger ? <Fact icon={Zap} label={t('flows.library.previewSheet.starts')} value={triggerText(t, data.trigger, options.data)} /> : null}
                                <Fact
                                    icon={Flag}
                                    label={t('flows.library.previewSheet.ends')}
                                    value={data.goal ? t('flows.library.previewSheet.goal', { event: eventLabel(t, data.goal.event) }) : t('flows.library.previewSheet.noGoal')}
                                />
                                {data.reentry_policy ? <Fact icon={Repeat} label={t('flows.library.previewSheet.repeat')} value={reentryText(t, data.reentry_policy)} /> : null}
                            </dl>
                            {!data.whatsapp_connected ? <Callout tone="info">{t('flows.library.previewSheet.noWhatsapp')}</Callout> : null}
                            {data.graph ? (
                                <section className="igr:space-y-2">
                                    <h3 className="igr:text-support igr:font-semibold">{t('flows.library.previewSheet.journey')}</h3>
                                    <p className="igr:text-caption igr:text-muted-foreground">{t('flows.library.previewSheet.sample')}</p>
                                    <FlowOutlineList
                                        graph={data.graph}
                                        options={withNames}
                                        trigger={data.trigger ?? { type: null }}
                                        goal={data.goal}
                                        issues={{}}
                                        renderExtra={(node: GraphNode) => {
                                            const message = messages.get(node.id);
                                            return message ? <MessagePreview message={message} business={workspace.name} /> : null;
                                        }}
                                    />
                                </section>
                            ) : (
                                <Callout tone="neutral">{t('flows.library.moduleHelp')}</Callout>
                            )}
                        </>
                    )}
                </SheetBody>
                {flow ? (
                    <SheetFooter>
                        <Button variant="outline" onClick={onClose}>
                            {t('common.close')}
                        </Button>
                        {flow.module ? (
                            <Button asChild>
                                <Link to={`/w/${workspace.id}/${flow.module}`}>{t('flows.library.configure')}</Link>
                            </Button>
                        ) : flow.created_flow_id ? (
                            <Button asChild>
                                <Link to={`/w/${workspace.id}/flujos/${flow.created_flow_id}`}>{t('flows.library.open')}</Link>
                            </Button>
                        ) : canManage ? (
                            <>
                                <Button variant="outline" loading={pending && variables?.activate === false} onClick={() => run(flow, false, onClose)}>
                                    {t('flows.library.customize')}
                                </Button>
                                {flow.missing_templates.length === 0 ? (
                                    <Button loading={pending && variables?.activate === true} onClick={() => run(flow, true, onClose)}>
                                        {t('flows.library.activate')}
                                    </Button>
                                ) : null}
                            </>
                        ) : null}
                    </SheetFooter>
                ) : null}
            </SheetContent>
        </Sheet>
    );
}

function Fact({ icon: Icon, label, value }: { icon: typeof Zap; label: string; value: string }) {
    return (
        <div className="igr:flex igr:items-start igr:gap-2">
            <Icon className="igr:mt-0.5 igr:size-3.5 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
            <dt className="igr:shrink-0 igr:font-medium igr:text-foreground">{label}</dt>
            <dd className="igr:min-w-0 igr:text-muted-foreground">{value}</dd>
        </div>
    );
}

function reentryText(t: ReturnType<typeof useTranslation>['t'], policy: ReentryPolicy): string {
    if (policy.mode === 'every_days') {
        return t('flows.library.previewSheet.every', { count: policy.days ?? 30 });
    }
    return t(`flows.library.previewSheet.${policy.mode}`);
}

/** El mensaje de un paso, como le llega a la persona (con datos de ejemplo). */
function MessagePreview({ message: m, business }: { message: LibraryMessage; business: string }) {
    const { t } = useTranslation();
    if (!m.in_library || (m.text === null && m.subject === null)) {
        return <p className="igr:mt-2 igr:rounded-md igr:bg-muted/50 igr:px-2.5 igr:py-1.5 igr:text-caption igr:text-muted-foreground">{t('flows.library.previewSheet.chosenTemplate')}</p>;
    }
    if (m.channel === 'email') {
        return <EmailMock className="igr:mt-2" business={business} subject={m.subject} preheader={m.preheader} text={m.text} />;
    }
    return (
        <PhoneMock business={business} variant={m.channel === 'sms' ? 'sms' : 'whatsapp'} compact className="igr:mt-2 igr:max-w-[300px]">
            {m.channel === 'sms' ? <SmsBubble text={m.text ?? ''} /> : <WaBubble body={m.text ?? ''} buttons={m.buttons.map((b) => ({ text: b }))} />}
        </PhoneMock>
    );
}
