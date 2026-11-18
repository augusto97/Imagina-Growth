import {
    CheckCircle2,
    Circle,
    CircleDashed,
    Loader2,
    RefreshCw,
    TriangleAlert,
    XCircle,
} from "lucide-react";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ErrorState } from "@/components/ui/misc";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TBody, TD, TH, THead, TR } from "@/components/ui/table";
import { useWorkspace } from "@/hooks/useWorkspace";
import { isApiError } from "@/lib/api";
import { formatDateTime } from "@/lib/format";
import { toast } from "@/stores/toast";
import {
    type StepStatus,
    type UpdateStatus,
    useCheckUpdates,
    useInstallUpdate,
    useUpdates,
} from "./updates-api";
import {
    isActive,
    plainNotes,
    progress,
    resultTone,
    visibleSteps,
} from "./updates-model";

/**
 * Actualizaciones con un clic (docs/despliegue.md §5): la versión instalada, la nueva con sus notas,
 * el botón «Instalar» (con la contraseña) y los pasos en vivo que escribe growth-update en el servidor.
 */
export function UpdatesTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const updates = useUpdates();
    const check = useCheckUpdates();
    const [confirming, setConfirming] = useState(false);
    const when = (iso: string | null | undefined) =>
        formatDateTime(iso, workspace.locale, workspace.timezone);

    if (updates.isError)
        return (
            <ErrorState
                error={updates.error}
                onRetry={() => void updates.refetch()}
            />
        );
    if (updates.isPending) return <Skeleton className="igr:h-64" />;
    const u = updates.data;
    const status = u.status;
    const active = u.pending !== null || isActive(status);

    return (
        <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{t("platform.updates.versionTitle")}</CardTitle>
                    <CardDescription>
                        {u.checked_at
                            ? t("platform.updates.checkedAt", {
                                  date: when(u.checked_at),
                              })
                            : t("platform.updates.neverChecked")}
                    </CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    <dl className="igr:grid igr:grid-cols-2 igr:gap-3 igr:text-sm">
                        <div>
                            <dt className="igr:text-muted-foreground">
                                {t("platform.updates.current")}
                            </dt>
                            <dd className="igr:font-medium igr:tabular-nums">
                                {u.current}
                            </dd>
                        </div>
                        <div>
                            <dt className="igr:text-muted-foreground">
                                {t("platform.updates.latest")}
                            </dt>
                            <dd className="igr:font-medium igr:tabular-nums">
                                {u.latest ? u.latest.version : "—"}
                                {u.update_available ? (
                                    <Badge className="igr:ml-2">
                                        {t("platform.updates.new")}
                                    </Badge>
                                ) : null}
                            </dd>
                        </div>
                    </dl>
                    {u.check_error ? (
                        <p className="igr:flex igr:items-start igr:gap-2 igr:text-sm igr:text-warning">
                            <TriangleAlert
                                className="igr:mt-0.5 igr:size-4 igr:shrink-0"
                                aria-hidden
                            />
                            {t("platform.updates.checkError", {
                                error: u.check_error,
                            })}
                        </p>
                    ) : null}
                    {u.latest && u.update_available ? (
                        <div>
                            <p className="igr:mb-1 igr:text-sm igr:font-medium">
                                {u.latest.name ?? u.latest.tag}
                            </p>
                            <pre className="igr:max-h-60 igr:overflow-auto igr:whitespace-pre-wrap igr:rounded-md igr:bg-muted igr:p-3 igr:font-sans igr:text-sm">
                                {plainNotes(u.latest.notes) ||
                                    t("platform.updates.noNotes")}
                            </pre>
                        </div>
                    ) : !u.update_available ? (
                        <p className="igr:text-sm igr:text-muted-foreground">
                            {t("platform.updates.upToDate")}
                        </p>
                    ) : null}
                    <div className="igr:flex igr:flex-wrap igr:gap-2">
                        <Button
                            disabled={!u.can_install}
                            onClick={() => setConfirming(true)}
                        >
                            {t("platform.updates.install", {
                                version: u.latest?.version ?? "",
                            })}
                        </Button>
                        <Button
                            variant="outline"
                            disabled={check.isPending}
                            onClick={() =>
                                check.mutate(undefined, {
                                    onError: (e) =>
                                        toast.error(
                                            t("errors.title"),
                                            isApiError(e)
                                                ? e.message
                                                : undefined,
                                        ),
                                })
                            }
                        >
                            <RefreshCw className="igr:size-4" aria-hidden />
                            {t("platform.updates.checkNow")}
                        </Button>
                    </div>
                    {u.busy && !active ? (
                        <p className="igr:text-sm igr:text-muted-foreground">
                            {t("platform.updates.busy")}
                        </p>
                    ) : null}
                    <p className="igr:text-xs igr:text-muted-foreground">
                        {t("platform.updates.howItWorks")}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>
                        {active
                            ? t("platform.updates.inProgress")
                            : t("platform.updates.lastRun")}
                    </CardTitle>
                    <CardDescription>
                        {status?.to
                            ? t("platform.updates.fromTo", {
                                  from: status.from ?? "—",
                                  to: status.to,
                              })
                            : t("platform.updates.noRuns")}
                    </CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-3">
                    {u.pending !== null && !isActive(status) ? (
                        <p className="igr:flex igr:items-center igr:gap-2 igr:text-sm">
                            <Loader2
                                className="igr:size-4 igr:animate-spin"
                                aria-hidden
                            />
                            {t("platform.updates.requested", {
                                version: u.pending.version,
                            })}
                        </p>
                    ) : null}
                    {status ? <RunResult status={status} /> : null}
                    {status ? (
                        <>
                            {isActive(status) ? (
                                <Progress
                                    value={progress(status)}
                                    label={t("platform.updates.progress")}
                                />
                            ) : null}
                            <ol className="igr:space-y-1.5" aria-live="polite">
                                {visibleSteps(status).map((s) => (
                                    <li
                                        key={s.key}
                                        className="igr:flex igr:items-start igr:gap-2 igr:text-sm"
                                    >
                                        <StepIcon status={s.status} />
                                        <div className="igr:min-w-0">
                                            <span
                                                className={
                                                    s.status === "pending"
                                                        ? "igr:text-muted-foreground"
                                                        : undefined
                                                }
                                            >
                                                {t(
                                                    `platform.updates.steps.${s.key}`,
                                                    { defaultValue: s.key },
                                                )}
                                            </span>
                                            {s.message ? (
                                                <p className="igr:text-xs igr:text-muted-foreground igr:break-words">
                                                    {s.message}
                                                </p>
                                            ) : null}
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </>
                    ) : null}
                </CardContent>
            </Card>

            <Card className="igr:lg:col-span-2">
                <CardHeader>
                    <CardTitle>{t("platform.updates.history")}</CardTitle>
                </CardHeader>
                <CardContent>
                    {u.history.length === 0 ? (
                        <p className="igr:text-sm igr:text-muted-foreground">
                            {t("platform.updates.noHistory")}
                        </p>
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t("platform.updates.when")}</TH>
                                    <TH>{t("platform.updates.versions")}</TH>
                                    <TH>{t("platform.updates.result")}</TH>
                                    <TH>{t("platform.updates.detail")}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {u.history.map((h, i) => (
                                    <TR key={h.id ?? i}>
                                        <TD className="igr:whitespace-nowrap">
                                            {when(
                                                h.finished_at ?? h.started_at,
                                            )}
                                        </TD>
                                        <TD className="igr:tabular-nums">
                                            {t("platform.updates.fromTo", {
                                                from: h.from ?? "—",
                                                to: h.to ?? "—",
                                            })}
                                        </TD>
                                        <TD>
                                            <StateBadge status={h} />
                                        </TD>
                                        <TD
                                            className="igr:max-w-md igr:truncate igr:text-muted-foreground"
                                            title={h.error ?? undefined}
                                        >
                                            {h.error ?? "—"}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {confirming && u.latest ? (
                <ConfirmInstall
                    version={u.latest.version}
                    onClose={() => setConfirming(false)}
                />
            ) : null}
        </div>
    );
}

function RunResult({ status }: { status: UpdateStatus }) {
    const { t } = useTranslation();
    if (isActive(status)) return null;
    const tone = resultTone(status.state);
    const Icon =
        tone === "ok"
            ? CheckCircle2
            : tone === "warning"
              ? TriangleAlert
              : XCircle;
    const color =
        tone === "ok"
            ? "igr:text-success"
            : tone === "warning"
              ? "igr:text-warning"
              : "igr:text-destructive";
    return (
        <div
            className={`igr:flex igr:items-start igr:gap-2 igr:text-sm ${color}`}
            role="status"
        >
            <Icon className="igr:mt-0.5 igr:size-4 igr:shrink-0" aria-hidden />
            <div>
                <p className="igr:font-medium">
                    {t(`platform.updates.states.${status.state}`, {
                        version: status.to ?? "",
                        from: status.from ?? "",
                    })}
                </p>
                {status.error ? (
                    <p className="igr:text-foreground">{status.error}</p>
                ) : null}
            </div>
        </div>
    );
}

function StateBadge({ status }: { status: UpdateStatus }) {
    const { t } = useTranslation();
    const tone = resultTone(status.state);
    const variant =
        tone === "ok"
            ? "success"
            : tone === "warning"
              ? "warning"
              : tone === "critical"
                ? "destructive"
                : "secondary";
    return (
        <Badge variant={variant}>
            {t(`platform.updates.badges.${status.state}`)}
        </Badge>
    );
}

function StepIcon({ status }: { status: StepStatus }) {
    const cls = "igr:mt-0.5 igr:size-4 igr:shrink-0";
    if (status === "done")
        return (
            <CheckCircle2 className={`${cls} igr:text-success`} aria-hidden />
        );
    if (status === "failed")
        return (
            <XCircle className={`${cls} igr:text-destructive`} aria-hidden />
        );
    if (status === "running")
        return (
            <Loader2
                className={`${cls} igr:animate-spin igr:text-primary`}
                aria-hidden
            />
        );
    if (status === "skipped")
        return (
            <CircleDashed
                className={`${cls} igr:text-muted-foreground`}
                aria-hidden
            />
        );
    return (
        <Circle className={`${cls} igr:text-muted-foreground`} aria-hidden />
    );
}

function ConfirmInstall({
    version,
    onClose,
}: {
    version: string;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const install = useInstallUpdate();
    const [password, setPassword] = useState("");
    const [error, setError] = useState<string | null>(null);

    const submit = () => {
        setError(null);
        install.mutate(
            { version, password },
            {
                onSuccess: () => {
                    toast.success(
                        t("platform.updates.requestedToast", { version }),
                    );
                    onClose();
                },
                onError: (e) =>
                    setError(isApiError(e) ? e.message : t("errors.title")),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t("platform.updates.confirmTitle", { version })}
                    </DialogTitle>
                    <DialogDescription>
                        {t("platform.updates.confirmBody")}
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <Label htmlFor="update-password">
                        {t("platform.updates.password")}
                    </Label>
                    <Input
                        id="update-password"
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        aria-invalid={error !== null}
                        aria-describedby={
                            error ? "update-password-error" : undefined
                        }
                        autoFocus
                    />
                    {error ? (
                        <p
                            id="update-password-error"
                            className="igr:text-sm igr:text-destructive"
                        >
                            {error}
                        </p>
                    ) : null}
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t("platform.updates.cancel")}
                    </Button>
                    <Button
                        disabled={password === "" || install.isPending}
                        onClick={submit}
                    >
                        {install.isPending ? (
                            <Loader2
                                className="igr:size-4 igr:animate-spin"
                                aria-hidden
                            />
                        ) : null}
                        {t("platform.updates.confirm")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
