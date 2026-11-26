import { ArrowRight, CalendarDays, Gift, Inbox, MessageSquare, Package, Palette, Plus, ShoppingCart, Sparkles, TrendingUp, Users } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout, type CalloutTone } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checklist, Stepper, type ChecklistStep } from '@/components/ui/checklist';
import { Chip, ChipGroup } from '@/components/ui/chip';
import { EmptyState } from '@/components/ui/empty-state';
import { HeroCard } from '@/components/ui/hero-card';
import { ICON_TONES, IconTile } from '@/components/ui/icon-tile';
import { Illustration, ILLUSTRATION_NAMES } from '@/components/ui/illustrations';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LockedState } from '@/components/ui/locked-state';
import { FieldError } from '@/components/ui/misc';
import { PageHeader } from '@/components/ui/page-header';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Skeleton, SkeletonCard, SkeletonText } from '@/components/ui/skeleton';
import { StatCard, StatGrid } from '@/components/ui/stat-card';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { NotFoundPage } from '@/components/layout/guards';
import { SECTIONS, type NavLocation } from '@/components/layout/nav';
import { SectionNav, SectionNavTree } from '@/components/layout/SectionNav';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';

/*
 * Guía de estilo del panel v2 (docs/diseno/rediseno-v2.md §7), solo para quien administra la plataforma. Muestra los
 * tokens y cada componente base con sus variantes, en vivo (respeta el modo oscuro). Los demás frentes del rediseño
 * la usan como referencia: si una pieza no está aquí, primero se agrega aquí.
 */

const TOKENS = [
    ['background', '--background'],
    ['card', '--card'],
    ['foreground', '--foreground'],
    ['muted', '--muted'],
    ['mutedForeground', '--muted-foreground'],
    ['primary', '--primary'],
    ['brand2', '--brand-2'],
    ['brandSoft', '--brand-soft'],
    ['success', '--success'],
    ['warning', '--warning'],
    ['destructive', '--destructive'],
    ['info', '--info'],
    ['border', '--border'],
    ['input', '--input'],
    ['ring', '--ring'],
] as const;

const SECTION_KEYS = [
    'color',
    'chart',
    'type',
    'shape',
    'buttons',
    'fields',
    'header',
    'sectionNav',
    'hero',
    'stats',
    'tiles',
    'empty',
    'locked',
    'checklist',
    'callout',
    'sheet',
    'table',
    'tabs',
    'chips',
] as const;

function GuideSection({ id, children, note }: { id: (typeof SECTION_KEYS)[number]; children: ReactNode; note?: boolean }) {
    const { t } = useTranslation();
    return (
        <section id={`guia-${id}`} aria-labelledby={`guia-${id}-titulo`} className="igr:scroll-mt-6 igr:border-t igr:pt-8 igr:pb-4">
            <h2 id={`guia-${id}-titulo`} className="igr:text-section igr:font-semibold igr:tracking-tight">
                {t(`styleguide.sections.${id}`)}
            </h2>
            {note ? <p className="igr:mt-1 igr:max-w-3xl igr:text-support igr:text-muted-foreground">{t(`styleguide.notes.${id}`)}</p> : null}
            <div className="igr:mt-5">{children}</div>
        </section>
    );
}

function Swatch({ name, token }: { name: string; token: string }) {
    return (
        <div className="igr:min-w-0 igr:overflow-hidden igr:rounded-xl igr:border igr:bg-card">
            <div className="igr:h-14 igr:border-b" style={{ background: `var(${token})` }} />
            <div className="igr:px-3 igr:py-2">
                <p className="igr:truncate igr:text-support igr:font-medium">{name}</p>
                <code className="igr:text-caption igr:text-muted-foreground">{token}</code>
            </div>
        </div>
    );
}

export function StyleGuidePage() {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    if (!me.user.is_platform_admin) {
        return <NotFoundPage />;
    }
    const base = `/w/${workspace.id}`;
    const customers = SECTIONS.find((s) => s.key === 'customers');
    const fakeLocation: NavLocation | null = customers ? { section: customers, item: customers.items[0] ?? null, isEntry: false, isDetail: false } : null;
    const steps: ChecklistStep[] = [
        { key: 'business', title: t('styleguide.checklist.business'), status: 'done' },
        {
            key: 'web',
            title: t('styleguide.checklist.web'),
            description: t('styleguide.checklist.webDescription'),
            status: 'current',
            action: (
                <Button size="sm">
                    {t('styleguide.checklist.connect')}
                    <ArrowRight aria-hidden />
                </Button>
            ),
        },
        { key: 'history', title: t('styleguide.checklist.history'), status: 'todo' },
        { key: 'channels', title: t('styleguide.checklist.channels'), status: 'todo' },
        { key: 'strategies', title: t('styleguide.checklist.strategies'), status: 'blocked' },
    ];

    return (
        <>
            <PageHeader title={t('styleguide.title')} description={t('styleguide.description')} icon={Palette} tone="violet" breadcrumb={false} />
            <Callout tone="brand" className="igr:mb-6">
                {t('styleguide.intro')}
            </Callout>
            <nav aria-label={t('styleguide.toc')} className="igr:mb-8 igr:flex igr:flex-wrap igr:gap-2">
                {SECTION_KEYS.map((key) => (
                    <a
                        key={key}
                        href={`#guia-${key}`}
                        className="igr:rounded-full igr:border igr:bg-card igr:px-3 igr:py-1 igr:text-caption igr:font-medium igr:text-muted-foreground igr:hover:text-foreground"
                    >
                        {t(`styleguide.sections.${key}`)}
                    </a>
                ))}
            </nav>

            <GuideSection id="color" note>
                <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:sm:grid-cols-3 igr:lg:grid-cols-5">
                    {TOKENS.map(([key, token]) => (
                        <Swatch key={key} name={t(`styleguide.tokens.${key}`)} token={token} />
                    ))}
                </div>
            </GuideSection>

            <GuideSection id="chart" note>
                <div className="igr:grid igr:grid-cols-3 igr:gap-3 igr:sm:grid-cols-6">
                    {[1, 2, 3, 4, 5, 6].map((n) => (
                        <Swatch key={n} name={String(n)} token={`--chart-${n}`} />
                    ))}
                </div>
            </GuideSection>

            <GuideSection id="type" note>
                <div className="igr:space-y-3">
                    <p className="igr:text-title igr:font-semibold">{t('styleguide.type.title')}</p>
                    <p className="igr:text-section igr:font-semibold">{t('styleguide.type.section')}</p>
                    <p className="igr:text-body">{t('styleguide.type.body')}</p>
                    <p className="igr:text-support igr:text-muted-foreground">{t('styleguide.type.support')}</p>
                    <p className="igr:text-caption igr:text-muted-foreground">{t('styleguide.type.caption')}</p>
                    <p className="igr:text-figure igr:font-semibold igr:tabular-nums">{t('styleguide.type.figure')}</p>
                    <p className="igr:text-hero igr:font-semibold igr:tabular-nums">{t('styleguide.type.hero')}</p>
                </div>
            </GuideSection>

            <GuideSection id="shape" note>
                <div className="igr:grid igr:grid-cols-2 igr:gap-4 igr:sm:grid-cols-5">
                    {(
                        [
                            ['sm', 'igr:rounded-sm'],
                            ['md', 'igr:rounded-md'],
                            ['lg', 'igr:rounded-lg'],
                            ['xl', 'igr:rounded-xl'],
                            ['2xl', 'igr:rounded-2xl'],
                        ] as const
                    ).map(([name, radius]) => (
                        <div key={name} className={cn('igr:flex igr:h-20 igr:items-end igr:border igr:bg-card igr:p-2 igr:text-caption igr:text-muted-foreground', radius)}>
                            {t('styleguide.shape.radius', { name })}
                        </div>
                    ))}
                </div>
                <div className="igr:mt-4 igr:grid igr:grid-cols-2 igr:gap-4 igr:sm:grid-cols-4">
                    <div className="igr:flex igr:h-20 igr:items-end igr:rounded-xl igr:bg-card igr:p-3 igr:text-caption igr:text-muted-foreground igr:shadow-xs">
                        {t('styleguide.shape.shadow', { name: 'xs' })}
                    </div>
                    <div className="igr:flex igr:h-20 igr:items-end igr:rounded-xl igr:bg-card igr:p-3 igr:text-caption igr:text-muted-foreground igr:shadow-sm">
                        {t('styleguide.shape.shadow', { name: 'sm' })}
                    </div>
                    <div className="igr:flex igr:h-20 igr:items-end igr:rounded-xl igr:bg-card igr:p-3 igr:text-caption igr:text-muted-foreground igr:shadow-md">
                        {t('styleguide.shape.shadow', { name: 'md' })}
                    </div>
                    <div className="igr:flex igr:h-20 igr:items-end igr:rounded-xl igr:bg-card igr:p-3 igr:text-caption igr:text-muted-foreground igr:shadow-lg">
                        {t('styleguide.shape.shadow', { name: 'lg' })}
                    </div>
                </div>
            </GuideSection>

            <GuideSection id="buttons">
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                    <Button>
                        <Plus aria-hidden />
                        {t('styleguide.buttons.primary')}
                    </Button>
                    <Button variant="brand">
                        <Sparkles aria-hidden />
                        {t('styleguide.buttons.brand')}
                    </Button>
                    <Button variant="outline">{t('styleguide.buttons.outline')}</Button>
                    <Button variant="secondary">{t('styleguide.buttons.secondary')}</Button>
                    <Button variant="ghost">{t('styleguide.buttons.ghost')}</Button>
                    <Button variant="destructive">{t('styleguide.buttons.destructive')}</Button>
                    <Button variant="link">{t('styleguide.buttons.link')}</Button>
                </div>
                <div className="igr:mt-3 igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                    <Button size="sm">{t('styleguide.buttons.small')}</Button>
                    <Button size="lg">{t('styleguide.buttons.large')}</Button>
                    <Button loading disabled>
                        {t('styleguide.buttons.primary')}
                    </Button>
                </div>
                <div className="igr:mt-4 igr:flex igr:flex-wrap igr:gap-2">
                    <Badge variant="brand">{t('styleguide.badges.brand')}</Badge>
                    <Badge variant="success">{t('styleguide.badges.success')}</Badge>
                    <Badge variant="warning">{t('styleguide.badges.warning')}</Badge>
                    <Badge variant="destructive">{t('styleguide.badges.destructive')}</Badge>
                    <Badge variant="info">{t('styleguide.badges.info')}</Badge>
                    <Badge variant="outline">{t('styleguide.badges.outline')}</Badge>
                </div>
            </GuideSection>

            <GuideSection id="fields">
                <div className="igr:grid igr:max-w-3xl igr:gap-4 igr:md:grid-cols-2">
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="guia-nombre">{t('styleguide.fields.name')}</Label>
                        <Input id="guia-nombre" placeholder={t('styleguide.fields.namePlaceholder')} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="guia-pais">{t('styleguide.fields.country')}</Label>
                        <Select id="guia-pais" defaultValue="CO">
                            <option value="CO">Colombia</option>
                            <option value="MX">México</option>
                            <option value="VE">Venezuela</option>
                        </Select>
                    </div>
                    <div className="igr:space-y-1.5 igr:md:col-span-2">
                        <Label htmlFor="guia-mensaje">{t('styleguide.fields.message')}</Label>
                        <Textarea id="guia-mensaje" placeholder={t('styleguide.fields.messagePlaceholder')} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="guia-error">{t('styleguide.fields.name')}</Label>
                        <Input id="guia-error" aria-invalid defaultValue="Pi" />
                        <FieldError message={t('styleguide.fields.error')} />
                    </div>
                    <label className="igr:flex igr:items-center igr:gap-2 igr:self-end igr:text-body">
                        <Checkbox defaultChecked />
                        {t('styleguide.fields.checkbox')}
                    </label>
                </div>
            </GuideSection>

            <GuideSection id="header" note>
                <div className="igr:rounded-xl igr:border igr:border-dashed igr:p-5">
                    <PageHeader
                        className="igr:mb-0"
                        breadcrumb={[{ label: t('styleguide.header.crumb'), to: `${base}/mensajes` }]}
                        title={t('styleguide.header.title')}
                        description={t('styleguide.header.description')}
                        actions={
                            <>
                                <Button variant="outline">{t('styleguide.header.secondary')}</Button>
                                <Button>
                                    <Plus aria-hidden />
                                    {t('styleguide.header.primary')}
                                </Button>
                            </>
                        }
                    />
                </div>
            </GuideSection>

            <GuideSection id="sectionNav" note>
                {customers && fakeLocation ? (
                    <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[240px_minmax(0,1fr)]">
                        <div className="igr:rounded-xl igr:border igr:bg-sidebar igr:p-2">
                            <p className="igr:flex igr:h-9 igr:items-center igr:gap-2.5 igr:px-2.5 igr:text-[13.5px] igr:font-medium">
                                <Users className="igr:size-[18px] igr:text-primary" aria-hidden />
                                {t('nav.sections.customers')}
                            </p>
                            <SectionNavTree section={customers} location={fakeLocation} base={base} ctx={{ abilities: workspace.abilities }} />
                        </div>
                        <div className="igr:min-w-0 igr:rounded-xl igr:border igr:border-dashed igr:p-5">
                            <SectionNav section={customers} location={fakeLocation} base={base} ctx={{ abilities: workspace.abilities }} />
                            <SkeletonText lines={4} />
                        </div>
                    </div>
                ) : null}
            </GuideSection>

            <GuideSection id="hero">
                <HeroCard
                    eyebrow={t('styleguide.hero.eyebrow')}
                    icon={TrendingUp}
                    title={t('styleguide.hero.title')}
                    value={t('styleguide.hero.value')}
                    description={t('styleguide.hero.description')}
                    illustration="results"
                    actions={
                        <Button variant="brand">
                            {t('styleguide.hero.action')}
                            <ArrowRight aria-hidden />
                        </Button>
                    }
                />
            </GuideSection>

            <GuideSection id="stats">
                <StatGrid>
                    <StatCard
                        label={t('styleguide.stats.sales')}
                        value={t('styleguide.stats.salesValue')}
                        help={t('styleguide.stats.salesHelp')}
                        change={0.142}
                        changeLabel="+14,2 %"
                        compareLabel={t('styleguide.stats.vs')}
                        trend={[12, 14, 11, 16, 18, 17, 21, 24]}
                    />
                    <StatCard
                        label={t('styleguide.stats.orders')}
                        value={t('styleguide.stats.ordersValue')}
                        change={-0.172}
                        changeLabel="-17,2 %"
                        compareLabel={t('styleguide.stats.vs')}
                        trend={[30, 28, 26, 27, 22, 20, 21, 19]}
                    />
                    <StatCard
                        label={t('styleguide.stats.conversion')}
                        value={t('styleguide.stats.conversionValue')}
                        change={0}
                        changeLabel="0 pp"
                        compareLabel={t('styleguide.stats.vs')}
                    />
                    <StatCard label={t('styleguide.stats.customers')} value={t('styleguide.stats.customersValue')} icon={Users} tone="sky" hint={t('styleguide.stats.hint')} />
                </StatGrid>
                <StatGrid className="igr:mt-3">
                    <StatCard label={t('styleguide.stats.sales')} value="" loading />
                </StatGrid>
            </GuideSection>

            <GuideSection id="tiles">
                <div className="igr:flex igr:flex-wrap igr:gap-3">
                    {ICON_TONES.map((tone, i) => (
                        <div key={tone} className="igr:flex igr:flex-col igr:items-center igr:gap-1.5">
                            <IconTile icon={[ShoppingCart, Gift, Inbox, Users, TrendingUp][i % 5] ?? Users} tone={tone} size="lg" />
                            <code className="igr:text-caption igr:text-muted-foreground">{tone}</code>
                        </div>
                    ))}
                </div>
            </GuideSection>

            <GuideSection id="empty">
                <EmptyState
                    illustration="messages"
                    title={t('styleguide.empty.title')}
                    description={t('styleguide.empty.description')}
                    action={<Button>{t('styleguide.empty.action')}</Button>}
                    steps={[t('styleguide.empty.step1'), t('styleguide.empty.step2'), t('styleguide.empty.step3')]}
                />
                <div className="igr:mt-4 igr:grid igr:grid-cols-2 igr:gap-3 igr:sm:grid-cols-3 igr:lg:grid-cols-6">
                    {ILLUSTRATION_NAMES.map((name) => (
                        <div key={name} className="igr:flex igr:flex-col igr:items-center igr:rounded-xl igr:border igr:bg-card igr:p-3">
                            <Illustration name={name} className="igr:w-full igr:max-w-36" />
                            <code className="igr:mt-1 igr:text-caption igr:text-muted-foreground">{name}</code>
                        </div>
                    ))}
                </div>
            </GuideSection>

            <GuideSection id="locked" note>
                <div className="igr:space-y-4">
                    <LockedState
                        illustration="web"
                        title={t('styleguide.locked.title')}
                        description={t('styleguide.locked.description')}
                        requirements={[{ key: 'web', title: t('styleguide.locked.web'), description: t('styleguide.locked.webHow'), status: 'current' }]}
                        action={
                            <Button variant="brand" size="lg">
                                {t('styleguide.locked.action')}
                                <ArrowRight aria-hidden />
                            </Button>
                        }
                    />
                    <LockedState
                        variant="plan"
                        planName="Tienda · Crece"
                        title={t('styleguide.locked.planTitle')}
                        description={t('styleguide.locked.planDescription')}
                        action={<Button size="lg">{t('styleguide.locked.planAction')}</Button>}
                    />
                </div>
            </GuideSection>

            <GuideSection id="checklist">
                <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                    <Card>
                        <CardContent className="igr:pt-5">
                            <Checklist steps={steps} showProgress />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="igr:pt-5">
                            <Stepper steps={steps} />
                        </CardContent>
                    </Card>
                </div>
            </GuideSection>

            <GuideSection id="callout">
                <div className="igr:space-y-3">
                    {(['info', 'success', 'warning', 'danger', 'brand', 'neutral'] as CalloutTone[]).map((tone) => (
                        <Callout
                            key={tone}
                            tone={tone}
                            action={
                                tone === 'warning' ? (
                                    <Button size="sm" variant="outline">
                                        {t('styleguide.callout.action')}
                                    </Button>
                                ) : undefined
                            }
                        >
                            {t(`styleguide.callout.${tone}`)}
                        </Callout>
                    ))}
                </div>
            </GuideSection>

            <GuideSection id="sheet">
                <Sheet>
                    <SheetTrigger asChild>
                        <Button variant="outline">{t('styleguide.sheet.open')}</Button>
                    </SheetTrigger>
                    <SheetContent>
                        <SheetHeader>
                            <SheetTitle>{t('styleguide.sheet.title')}</SheetTitle>
                            <SheetDescription>{t('styleguide.sheet.description')}</SheetDescription>
                        </SheetHeader>
                        <SheetBody>
                            <p className="igr:text-support igr:text-muted-foreground">{t('styleguide.sheet.body')}</p>
                        </SheetBody>
                        <SheetFooter>
                            <Button>{t('styleguide.sheet.apply')}</Button>
                        </SheetFooter>
                    </SheetContent>
                </Sheet>
            </GuideSection>

            <GuideSection id="table">
                <Card className="igr:overflow-hidden">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('styleguide.table.name')}</TH>
                                <TH>{t('styleguide.table.city')}</TH>
                                <TH className="igr:text-right">{t('styleguide.table.orders')}</TH>
                                <TH className="igr:text-right">{t('styleguide.table.spent')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {['1', '2', '3'].map((row) => (
                                <TR key={row}>
                                    <TD className="igr:font-medium">{t(`styleguide.table.rows.${row}.name`)}</TD>
                                    <TD className="igr:text-muted-foreground">{t(`styleguide.table.rows.${row}.city`)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{t(`styleguide.table.rows.${row}.orders`)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{t(`styleguide.table.rows.${row}.spent`)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
                <div className="igr:mt-4 igr:grid igr:gap-4 igr:md:grid-cols-3">
                    <SkeletonCard />
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('styleguide.stats.sales')}</CardTitle>
                            <CardDescription>{t('styleguide.type.support')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Skeleton className="igr:h-24" />
                        </CardContent>
                    </Card>
                    <div className="igr:rounded-xl igr:border igr:bg-card igr:p-5">
                        <SkeletonText lines={5} />
                    </div>
                </div>
            </GuideSection>

            <GuideSection id="tabs">
                <Tabs defaultValue="one">
                    <TabsList>
                        <TabsTrigger value="one">{t('styleguide.tabs.one')}</TabsTrigger>
                        <TabsTrigger value="two">{t('styleguide.tabs.two')}</TabsTrigger>
                        <TabsTrigger value="three">{t('styleguide.tabs.three')}</TabsTrigger>
                    </TabsList>
                    <TabsContent value="one" className="igr:text-support igr:text-muted-foreground">
                        {t('styleguide.tabs.content')}
                    </TabsContent>
                    <TabsContent value="two" className="igr:text-support igr:text-muted-foreground">
                        {t('styleguide.tabs.content')}
                    </TabsContent>
                    <TabsContent value="three" className="igr:text-support igr:text-muted-foreground">
                        {t('styleguide.tabs.content')}
                    </TabsContent>
                </Tabs>
            </GuideSection>

            <GuideSection id="chips" note>
                <ChipsExample />
            </GuideSection>
        </>
    );
}

/** Chips de filtro, control segmentado y avatares (frente de Clientes): con estado, para verlos funcionar. */
function ChipsExample() {
    const { t } = useTranslation();
    const [filter, setFilter] = useState('all');
    const [view, setView] = useState<'day' | 'week'>('week');
    const chips = [
        { key: 'all', icon: undefined, count: 24 },
        { key: 'appointments', icon: CalendarDays, count: 6 },
        { key: 'messages', icon: MessageSquare, count: 15 },
        { key: 'orders', icon: Package, count: 3 },
    ] as const;
    return (
        <div className="igr:space-y-5">
            <ChipGroup label={t('styleguide.chips.filterLabel')} scroll>
                {chips.map((c) => (
                    <Chip key={c.key} pressed={filter === c.key} icon={c.icon} count={c.count} onClick={() => setFilter(c.key)}>
                        {t(`styleguide.chips.${c.key}`)}
                    </Chip>
                ))}
            </ChipGroup>
            <SegmentedControl
                label={t('styleguide.chips.viewLabel')}
                value={view}
                onChange={setView}
                options={[
                    { value: 'day', label: t('styleguide.chips.day') },
                    { value: 'week', label: t('styleguide.chips.week') },
                ]}
            />
            <div className="igr:flex igr:items-center igr:gap-3">
                {t('styleguide.chips.people')
                    .split(/, | y /)
                    .map((name, i) => (
                        <Avatar key={name} name={name} size={(['sm', 'md', 'lg'] as const)[i] ?? 'md'} />
                    ))}
                <span className="igr:text-support igr:text-muted-foreground">{t('styleguide.chips.people')}</span>
            </div>
        </div>
    );
}
