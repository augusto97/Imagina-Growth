import { Building2, MailPlus, Plus } from "lucide-react";
import { useState, type SyntheticEvent } from "react";
import { useTranslation } from "react-i18next";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input, Select } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ErrorState } from "@/components/ui/misc";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api";
import { formatDate } from "@/lib/format";
import { toast } from "@/stores/toast";
import {
    COUNTRY_DEFAULTS,
    countryDefaults,
    type ClientDetail,
    type NewClientInput,
    type PlatformPlan,
    type WorkspaceInput,
    useAddClientWorkspace,
    useClientDetail,
    useCreateClient,
    useInviteToClient,
    useUpdateClient,
} from "./api";

const COUNTRIES = Object.keys(COUNTRY_DEFAULTS);
const CURRENCIES = ["COP", "MXN", "USD", "PEN", "CLP", "ARS", "EUR", "VES"];
const ROLES = ["owner", "admin", "marketing", "agent", "viewer"] as const;

function useApiErrors() {
    const { t } = useTranslation();
    const [errors, setErrors] = useState<Record<string, string>>({});
    const onError = (e: unknown) => {
        if (isApiError(e) && Object.keys(e.fieldErrors).length > 0)
            setErrors(e.fieldErrors);
        else
            toast.error(
                t("errors.title"),
                isApiError(e) ? e.message : undefined,
            );
    };
    return { errors, setErrors, onError };
}

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="igr:text-xs igr:text-destructive">{message}</p>
    ) : null;
}

/** Campos del espacio de trabajo: al cambiar el país se sugieren su moneda y su zona horaria. */
function WorkspaceFields({
    value,
    onChange,
    errors,
    prefix,
}: {
    value: WorkspaceInput;
    onChange: (v: WorkspaceInput) => void;
    errors: Record<string, string>;
    prefix: string;
}) {
    const { t } = useTranslation();
    const id = (k: string) => `${prefix}-${k}`;
    return (
        <>
            <div className="igr:space-y-1.5 igr:sm:col-span-2">
                <Label htmlFor={id("ws-name")}>
                    {t("platform.clients.workspaceName")}
                </Label>
                <Input
                    id={id("ws-name")}
                    required
                    value={value.name}
                    onChange={(e) =>
                        onChange({ ...value, name: e.target.value })
                    }
                />
                <p className="igr:text-xs igr:text-muted-foreground">
                    {t("platform.clients.workspaceHelp")}
                </p>
                <FieldError message={errors[`${prefix}name`]} />
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor={id("country")}>
                    {t("platform.clients.country")}
                </Label>
                <Select
                    id={id("country")}
                    value={value.country}
                    onChange={(e) =>
                        onChange({
                            ...value,
                            country: e.target.value,
                            ...countryDefaults(e.target.value),
                        })
                    }
                >
                    {COUNTRIES.map((c) => (
                        <option key={c} value={c}>
                            {t(`platform.clients.countries.${c}`)}
                        </option>
                    ))}
                </Select>
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor={id("currency")}>
                    {t("platform.clients.currency")}
                </Label>
                <Select
                    id={id("currency")}
                    value={value.currency}
                    onChange={(e) =>
                        onChange({ ...value, currency: e.target.value })
                    }
                >
                    {CURRENCIES.map((c) => (
                        <option key={c} value={c}>
                            {c}
                        </option>
                    ))}
                </Select>
            </div>
        </>
    );
}

/** Alta de un cliente: empresa, primer espacio, plan e invitación al dueño. */
export function NewClientDialog({
    plans,
    onClose,
    onCreated,
}: {
    plans: PlatformPlan[];
    onClose: () => void;
    onCreated: (id: string) => void;
}) {
    const { t } = useTranslation();
    const create = useCreateClient();
    const { errors, onError } = useApiErrors();
    const [form, setForm] = useState<NewClientInput>({
        name: "",
        type: "direct",
        country: "CO",
        plan_id: null,
        owner_email: "",
        workspace: {
            name: "",
            country: "CO",
            currency: "COP",
            timezone: "America/Bogota",
        },
    });
    const [wsTouched, setWsTouched] = useState(false);

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        create.mutate(
            { ...form, country: form.workspace.country },
            {
                onSuccess: (account) => {
                    toast.success(
                        t("platform.clients.created", {
                            email: form.owner_email,
                        }),
                    );
                    onCreated(account.id);
                },
                onError,
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="igr:max-w-xl">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>
                            {t("platform.clients.newTitle")}
                        </DialogTitle>
                        <DialogDescription>
                            {t("platform.clients.newHelp")}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="igr:grid igr:max-h-[65vh] igr:gap-4 igr:overflow-y-auto igr:py-4 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1.5 igr:sm:col-span-2">
                            <Label htmlFor="client-name">
                                {t("platform.clients.company")}
                            </Label>
                            <Input
                                id="client-name"
                                required
                                value={form.name}
                                placeholder={t(
                                    "platform.clients.companyPlaceholder",
                                )}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        name: e.target.value,
                                        workspace: wsTouched
                                            ? f.workspace
                                            : {
                                                  ...f.workspace,
                                                  name: e.target.value,
                                              },
                                    }))
                                }
                            />
                            <FieldError message={errors.name} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="client-type">
                                {t("platform.clients.type")}
                            </Label>
                            <Select
                                id="client-type"
                                value={form.type}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        type: e.target
                                            .value as NewClientInput["type"],
                                    }))
                                }
                            >
                                <option value="direct">
                                    {t("platform.clients.types.direct")}
                                </option>
                                <option value="agency">
                                    {t("platform.clients.types.agency")}
                                </option>
                            </Select>
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="client-plan">
                                {t("platform.clients.plan")}
                            </Label>
                            <Select
                                id="client-plan"
                                value={form.plan_id ?? ""}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        plan_id: e.target.value || null,
                                    }))
                                }
                            >
                                <option value="">
                                    {t("platform.clients.trial")}
                                </option>
                                {plans
                                    .filter((p) => !p.archived)
                                    .map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                            </Select>
                        </div>
                        <div className="igr:space-y-1.5 igr:sm:col-span-2">
                            <Label htmlFor="client-owner">
                                {t("platform.clients.ownerEmail")}
                            </Label>
                            <Input
                                id="client-owner"
                                type="email"
                                required
                                value={form.owner_email}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        owner_email: e.target.value,
                                    }))
                                }
                            />
                            <p className="igr:text-xs igr:text-muted-foreground">
                                {form.type === "agency"
                                    ? t("platform.clients.ownerHelpAgency")
                                    : t("platform.clients.ownerHelp")}
                            </p>
                            <FieldError message={errors.owner_email} />
                        </div>
                        <WorkspaceFields
                            prefix="workspace."
                            value={form.workspace}
                            errors={errors}
                            onChange={(workspace) => {
                                setWsTouched(true);
                                setForm((f) => ({ ...f, workspace }));
                            }}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            {t("common.cancel")}
                        </Button>
                        <Button type="submit" loading={create.isPending}>
                            {t("platform.clients.create")}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Ficha del cliente: datos, espacios, personas e invitaciones; agregar espacios e invitar. */
export function ClientDetailDialog({
    id,
    onClose,
}: {
    id: string;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const detail = useClientDetail(id);
    const [mode, setMode] = useState<"view" | "edit" | "workspace" | "invite">(
        "view",
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="igr:max-w-2xl">
                {detail.isError ? (
                    <ErrorState
                        error={detail.error}
                        onRetry={() => void detail.refetch()}
                    />
                ) : detail.isPending ? (
                    <Skeleton className="igr:h-64" />
                ) : (
                    <>
                        <DialogHeader>
                            <DialogTitle className="igr:flex igr:items-center igr:gap-2">
                                <Building2 className="igr:size-4" />
                                {detail.data.name}
                                <Badge variant="outline">
                                    {t(
                                        `platform.clients.types.${detail.data.type}`,
                                    )}
                                </Badge>
                            </DialogTitle>
                            <DialogDescription>
                                {t("platform.clients.since", {
                                    date: formatDate(detail.data.created_at),
                                })}
                                {detail.data.billing_email
                                    ? ` · ${detail.data.billing_email}`
                                    : ""}
                            </DialogDescription>
                        </DialogHeader>
                        {mode === "edit" ? (
                            <EditClientForm
                                client={detail.data}
                                onDone={() => setMode("view")}
                            />
                        ) : mode === "workspace" ? (
                            <AddWorkspaceForm
                                client={detail.data}
                                onDone={() => setMode("view")}
                            />
                        ) : mode === "invite" ? (
                            <InviteForm
                                client={detail.data}
                                onDone={() => setMode("view")}
                            />
                        ) : (
                            <ClientSummary client={detail.data} />
                        )}
                        {mode === "view" && (
                            <DialogFooter className="igr:flex-wrap igr:gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setMode("edit")}
                                >
                                    {t("platform.clients.edit")}
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setMode("workspace")}
                                >
                                    <Plus />
                                    {t("platform.clients.addWorkspace")}
                                </Button>
                                <Button onClick={() => setMode("invite")}>
                                    <MailPlus />
                                    {t("platform.clients.invite")}
                                </Button>
                            </DialogFooter>
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function ClientSummary({ client }: { client: ClientDetail }) {
    const { t } = useTranslation();
    return (
        <div className="igr:max-h-[55vh] igr:space-y-4 igr:overflow-y-auto igr:py-2">
            {client.account_users.length > 0 && (
                <section>
                    <h4 className="igr:mb-1 igr:text-sm igr:font-medium">
                        {t("platform.clients.accountPeople")}
                    </h4>
                    <ul className="igr:text-sm">
                        {client.account_users.map((u) => (
                            <li key={u.email}>
                                {u.name} ·{" "}
                                <span className="igr:text-muted-foreground">
                                    {u.email}
                                </span>{" "}
                                · {t(`platform.clients.accountRoles.${u.role}`)}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            {client.workspaces.map((ws) => (
                <section
                    key={ws.id}
                    className="igr:rounded-md igr:border igr:p-3"
                >
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                        <h4 className="igr:font-medium">{ws.name}</h4>
                        <span className="igr:text-xs igr:text-muted-foreground">
                            {t(`platform.clients.countries.${ws.country}`)} ·{" "}
                            {ws.currency} ·{" "}
                            {t("platform.clients.sites", { count: ws.sites })}
                        </span>
                    </div>
                    <ul className="igr:mt-2 igr:space-y-0.5 igr:text-sm">
                        {ws.members.length === 0 &&
                            ws.invitations.length === 0 && (
                                <li className="igr:text-muted-foreground">
                                    {t("platform.clients.nobody")}
                                </li>
                            )}
                        {ws.members.map((m) => (
                            <li key={m.email}>
                                {m.name} ·{" "}
                                <span className="igr:text-muted-foreground">
                                    {m.email}
                                </span>{" "}
                                · {t(`platform.clients.roles.${m.role}`)}
                            </li>
                        ))}
                        {ws.invitations.map((i) => (
                            <li
                                key={i.email}
                                className="igr:text-muted-foreground"
                            >
                                {i.email} ·{" "}
                                {t(`platform.clients.roles.${i.role}`)} ·{" "}
                                <Badge variant="warning">
                                    {t("platform.clients.pending")}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}

function EditClientForm({
    client,
    onDone,
}: {
    client: ClientDetail;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const update = useUpdateClient();
    const { errors, onError } = useApiErrors();
    const [form, setForm] = useState({
        name: client.name,
        legal_name: client.legal_name ?? "",
        billing_email: client.billing_email ?? "",
        type: client.type,
    });
    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        update.mutate(
            {
                id: client.id,
                data: {
                    ...form,
                    legal_name: form.legal_name || null,
                    billing_email: form.billing_email || null,
                },
            },
            {
                onSuccess: () => {
                    toast.success(t("platform.saved"));
                    onDone();
                },
                onError,
            },
        );
    };
    return (
        <form
            onSubmit={submit}
            className="igr:grid igr:gap-4 igr:py-2 igr:sm:grid-cols-2"
        >
            <div className="igr:space-y-1.5 igr:sm:col-span-2">
                <Label htmlFor="edit-name">
                    {t("platform.clients.company")}
                </Label>
                <Input
                    id="edit-name"
                    required
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
                <FieldError message={errors.name} />
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="edit-legal">
                    {t("platform.clients.legalName")}
                </Label>
                <Input
                    id="edit-legal"
                    value={form.legal_name}
                    onChange={(e) =>
                        setForm({ ...form, legal_name: e.target.value })
                    }
                />
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="edit-billing">
                    {t("platform.clients.billingEmail")}
                </Label>
                <Input
                    id="edit-billing"
                    type="email"
                    value={form.billing_email}
                    onChange={(e) =>
                        setForm({ ...form, billing_email: e.target.value })
                    }
                />
                <FieldError message={errors.billing_email} />
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="edit-type">{t("platform.clients.type")}</Label>
                <Select
                    id="edit-type"
                    value={form.type}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            type: e.target.value as ClientDetail["type"],
                        })
                    }
                >
                    <option value="direct">
                        {t("platform.clients.types.direct")}
                    </option>
                    <option value="agency">
                        {t("platform.clients.types.agency")}
                    </option>
                </Select>
            </div>
            <div className="igr:flex igr:justify-end igr:gap-2 igr:sm:col-span-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    {t("common.cancel")}
                </Button>
                <Button type="submit" loading={update.isPending}>
                    {t("platform.clients.save")}
                </Button>
            </div>
        </form>
    );
}

function AddWorkspaceForm({
    client,
    onDone,
}: {
    client: ClientDetail;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const add = useAddClientWorkspace();
    const { errors, onError } = useApiErrors();
    const [ws, setWs] = useState<WorkspaceInput>({
        name: "",
        country: client.country,
        ...countryDefaults(client.country),
    });
    const [owner, setOwner] = useState("");
    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        add.mutate(
            {
                id: client.id,
                data: { ...ws, ...(owner ? { owner_email: owner } : {}) },
            },
            {
                onSuccess: () => {
                    toast.success(t("platform.clients.workspaceAdded"));
                    onDone();
                },
                onError,
            },
        );
    };
    return (
        <form
            onSubmit={submit}
            className="igr:grid igr:gap-4 igr:py-2 igr:sm:grid-cols-2"
        >
            <WorkspaceFields
                prefix=""
                value={ws}
                onChange={setWs}
                errors={errors}
            />
            <div className="igr:space-y-1.5 igr:sm:col-span-2">
                <Label htmlFor="ws-owner">
                    {t("platform.clients.ownerEmailOptional")}
                </Label>
                <Input
                    id="ws-owner"
                    type="email"
                    value={owner}
                    onChange={(e) => setOwner(e.target.value)}
                />
                <FieldError message={errors.owner_email} />
            </div>
            <div className="igr:flex igr:justify-end igr:gap-2 igr:sm:col-span-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    {t("common.cancel")}
                </Button>
                <Button type="submit" loading={add.isPending}>
                    {t("platform.clients.addWorkspace")}
                </Button>
            </div>
        </form>
    );
}

function InviteForm({
    client,
    onDone,
}: {
    client: ClientDetail;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const invite = useInviteToClient();
    const { errors, onError } = useApiErrors();
    const [form, setForm] = useState({
        workspace_id: client.workspaces[0]?.id ?? "",
        email: "",
        role: "admin",
        account_owner: false,
    });
    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        invite.mutate(
            { id: client.id, ...form },
            {
                onSuccess: () => {
                    toast.success(
                        t("platform.clients.invited", { email: form.email }),
                    );
                    onDone();
                },
                onError,
            },
        );
    };
    return (
        <form
            onSubmit={submit}
            className="igr:grid igr:gap-4 igr:py-2 igr:sm:grid-cols-2"
        >
            <div className="igr:space-y-1.5 igr:sm:col-span-2">
                <Label htmlFor="inv-email">{t("platform.clients.email")}</Label>
                <Input
                    id="inv-email"
                    type="email"
                    required
                    value={form.email}
                    onChange={(e) =>
                        setForm({ ...form, email: e.target.value })
                    }
                />
                <FieldError message={errors.email} />
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="inv-ws">
                    {t("platform.clients.workspace")}
                </Label>
                <Select
                    id="inv-ws"
                    value={form.workspace_id}
                    onChange={(e) =>
                        setForm({ ...form, workspace_id: e.target.value })
                    }
                >
                    {client.workspaces.map((ws) => (
                        <option key={ws.id} value={ws.id}>
                            {ws.name}
                        </option>
                    ))}
                </Select>
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="inv-role">{t("platform.clients.role")}</Label>
                <Select
                    id="inv-role"
                    value={form.role}
                    onChange={(e) => setForm({ ...form, role: e.target.value })}
                >
                    {ROLES.map((r) => (
                        <option key={r} value={r}>
                            {t(`platform.clients.roles.${r}`)}
                        </option>
                    ))}
                </Select>
            </div>
            {client.type === "agency" && (
                <label className="igr:flex igr:items-center igr:gap-2 igr:text-sm igr:sm:col-span-2">
                    <input
                        type="checkbox"
                        checked={form.account_owner}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                account_owner: e.target.checked,
                            })
                        }
                    />
                    {t("platform.clients.accountOwner")}
                </label>
            )}
            <div className="igr:flex igr:justify-end igr:gap-2 igr:sm:col-span-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    {t("common.cancel")}
                </Button>
                <Button type="submit" loading={invite.isPending}>
                    {t("platform.clients.sendInvite")}
                </Button>
            </div>
        </form>
    );
}
