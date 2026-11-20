import { useState, type SyntheticEvent } from "react";
import { useTranslation } from "react-i18next";
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
import { COUNTRY_DEFAULTS, countryDefaults } from "@/features/platform/api";
import { isApiError } from "@/lib/api";
import { toast } from "@/stores/toast";
import { type NewAgencyClient, useCreateAgencyClient } from "./api";

const CURRENCIES = ["COP", "MXN", "USD", "PEN", "CLP", "ARS", "EUR", "VES"];

/** La agencia da de alta un cliente: su espacio de trabajo y, opcional, la invitación a su dueño. */
export function NewClientDialog({
    ws,
    accounts,
    onClose,
}: {
    ws: string;
    accounts: { id: string; name: string; can_manage: boolean }[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const create = useCreateAgencyClient(ws);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [form, setForm] = useState<NewAgencyClient>({
        account_id: accounts[0]?.id ?? "",
        name: "",
        country: "CO",
        currency: "COP",
        timezone: "America/Bogota",
        invite_email: "",
        invite_role: "owner",
    });

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        const body: NewAgencyClient = { ...form };
        if (!body.invite_email) {
            delete body.invite_email;
            delete body.invite_role;
        }
        create.mutate(body, {
            onSuccess: (r) => {
                toast.success(
                    form.invite_email
                        ? t("agency.newClient.createdInvited", {
                              name: r.name,
                              email: form.invite_email,
                          })
                        : t("agency.newClient.created", { name: r.name }),
                );
                onClose();
            },
            onError: (err) => {
                if (isApiError(err) && Object.keys(err.fieldErrors).length > 0)
                    setErrors(err.fieldErrors);
                else
                    toast.error(
                        t("errors.title"),
                        isApiError(err) ? err.message : undefined,
                    );
            },
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="igr:max-w-lg">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>{t("agency.newClient.title")}</DialogTitle>
                        <DialogDescription>
                            {t("agency.newClient.help")}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="igr:grid igr:gap-4 igr:py-4 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1.5 igr:sm:col-span-2">
                            <Label htmlFor="agency-client-name">
                                {t("agency.newClient.name")}
                            </Label>
                            <Input
                                id="agency-client-name"
                                required
                                value={form.name}
                                onChange={(e) =>
                                    setForm({ ...form, name: e.target.value })
                                }
                                placeholder={t(
                                    "agency.newClient.namePlaceholder",
                                )}
                            />
                            {errors.name && (
                                <p className="igr:text-xs igr:text-destructive">
                                    {errors.name}
                                </p>
                            )}
                        </div>
                        {accounts.length > 1 && (
                            <div className="igr:space-y-1.5 igr:sm:col-span-2">
                                <Label htmlFor="agency-client-account">
                                    {t("agency.newClient.account")}
                                </Label>
                                <Select
                                    id="agency-client-account"
                                    value={form.account_id}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            account_id: e.target.value,
                                        })
                                    }
                                >
                                    {accounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        )}
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="agency-client-country">
                                {t("platform.clients.country")}
                            </Label>
                            <Select
                                id="agency-client-country"
                                value={form.country}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        country: e.target.value,
                                        ...countryDefaults(e.target.value),
                                    })
                                }
                            >
                                {Object.keys(COUNTRY_DEFAULTS).map((c) => (
                                    <option key={c} value={c}>
                                        {t(`platform.clients.countries.${c}`)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="agency-client-currency">
                                {t("platform.clients.currency")}
                            </Label>
                            <Select
                                id="agency-client-currency"
                                value={form.currency}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        currency: e.target.value,
                                    })
                                }
                            >
                                {CURRENCIES.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="igr:space-y-1.5 igr:sm:col-span-2">
                            <Label htmlFor="agency-client-email">
                                {t("agency.newClient.inviteEmail")}
                            </Label>
                            <Input
                                id="agency-client-email"
                                type="email"
                                value={form.invite_email ?? ""}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        invite_email: e.target.value,
                                    })
                                }
                            />
                            <p className="igr:text-xs igr:text-muted-foreground">
                                {t("agency.newClient.inviteHelp")}
                            </p>
                            {errors.invite_email && (
                                <p className="igr:text-xs igr:text-destructive">
                                    {errors.invite_email}
                                </p>
                            )}
                        </div>
                        {form.invite_email ? (
                            <div className="igr:space-y-1.5 igr:sm:col-span-2">
                                <Label htmlFor="agency-client-role">
                                    {t("platform.clients.role")}
                                </Label>
                                <Select
                                    id="agency-client-role"
                                    value={form.invite_role}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            invite_role: e.target.value as
                                                | "owner"
                                                | "admin",
                                        })
                                    }
                                >
                                    <option value="owner">
                                        {t("platform.clients.roles.owner")}
                                    </option>
                                    <option value="admin">
                                        {t("platform.clients.roles.admin")}
                                    </option>
                                </Select>
                            </div>
                        ) : null}
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
                            {t("agency.newClient.create")}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
