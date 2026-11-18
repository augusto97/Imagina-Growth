import { CalendarSync, Plus, Trash2, UserRound } from "lucide-react";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useWorkspace } from "@/hooks/useWorkspace";
import { toast } from "@/stores/toast";
import { errorMessage } from "@/features/contacts/errors";
import { useAgendaMutations } from "./api";
import { fromWeek, toWeek, type DayHours } from "./model";
import type { AvailabilityException, BookingOverview, Staff } from "./types";

/** Personas que atienden, su conexión con Google Calendar y el horario de cada una (o el del negocio). */
export function StaffTab({ data }: { data: BookingOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { deleteStaff, connectGoogle, disconnectGoogle } = useAgendaMutations(
        workspace.id,
    );
    const [adding, setAdding] = useState(false);
    const [selected, setSelected] = useState<string | null>(null); // null = horario del negocio
    const active = data.staff.filter((s) => s.active);

    const connect = (s: Staff) =>
        connectGoogle.mutate(s.id, {
            onSuccess: (res) => window.location.assign(res.url),
            onError: (e) => toast.error(errorMessage(e, t)),
        });

    return (
        <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            <Card>
                <CardHeader className="igr:flex igr:flex-row igr:items-center igr:justify-between">
                    <CardTitle>{t("agenda.staff.title")}</CardTitle>
                    {data.can_manage ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setAdding(true)}
                        >
                            <Plus />
                            {t("agenda.staff.add")}
                        </Button>
                    ) : null}
                </CardHeader>
                <CardContent className="igr:space-y-2">
                    <button
                        type="button"
                        onClick={() => setSelected(null)}
                        className={`igr:w-full igr:rounded-md igr:border igr:p-3 igr:text-left igr:text-[13px] ${selected === null ? "igr:border-primary igr:bg-primary/5" : ""}`}
                    >
                        <p className="igr:font-medium">
                            {t("agenda.staff.business")}
                        </p>
                        <p className="igr:text-xs igr:text-muted-foreground">
                            {t("agenda.staff.businessHelp")}
                        </p>
                    </button>
                    {active.map((s) => (
                        <div
                            key={s.id}
                            className={`igr:flex igr:items-center igr:gap-2 igr:rounded-md igr:border igr:p-3 igr:text-[13px] ${selected === s.id ? "igr:border-primary igr:bg-primary/5" : ""}`}
                        >
                            <button
                                type="button"
                                className="igr:flex igr:min-w-0 igr:flex-1 igr:items-center igr:gap-2 igr:text-left"
                                onClick={() => setSelected(s.id)}
                            >
                                <UserRound className="igr:size-4 igr:text-muted-foreground" />
                                <span className="igr:min-w-0">
                                    <span className="igr:block igr:truncate igr:font-medium">
                                        {s.name}
                                    </span>
                                    <span className="igr:block igr:truncate igr:text-xs igr:text-muted-foreground">
                                        {s.email ?? s.timezone}
                                    </span>
                                </span>
                            </button>
                            {s.calendar_connected ? (
                                <Badge variant="success">
                                    <CalendarSync className="igr:mr-1 igr:size-3" />
                                    Google
                                </Badge>
                            ) : null}
                            {data.can_manage ? (
                                <>
                                    {s.calendar_connected ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                disconnectGoogle.mutate(s.id, {
                                                    onSuccess: () =>
                                                        toast.success(
                                                            t(
                                                                "agenda.staff.googleDisconnected",
                                                            ),
                                                        ),
                                                })
                                            }
                                        >
                                            {t("agenda.staff.disconnect")}
                                        </Button>
                                    ) : data.google_configured ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => connect(s)}
                                            loading={
                                                connectGoogle.isPending &&
                                                connectGoogle.variables === s.id
                                            }
                                        >
                                            {t("agenda.staff.connect")}
                                        </Button>
                                    ) : null}
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={t("agenda.staff.remove")}
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    t(
                                                        "agenda.staff.removeConfirm",
                                                        { name: s.name },
                                                    ),
                                                )
                                            ) {
                                                deleteStaff.mutate(s.id, {
                                                    onSuccess: () => {
                                                        if (selected === s.id)
                                                            setSelected(null);
                                                    },
                                                });
                                            }
                                        }}
                                    >
                                        <Trash2 />
                                    </Button>
                                </>
                            ) : null}
                        </div>
                    ))}
                    {!data.google_configured ? (
                        <p className="igr:text-xs igr:text-muted-foreground">
                            {t("agenda.staff.googleNotConfigured")}
                        </p>
                    ) : null}
                </CardContent>
            </Card>
            <HoursEditor
                key={selected ?? "business"}
                data={data}
                staffId={selected}
            />
            {adding ? <StaffDialog onClose={() => setAdding(false)} /> : null}
        </div>
    );
}

function HoursEditor({
    data,
    staffId,
}: {
    data: BookingOverview;
    staffId: string | null;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { saveAvailability } = useAgendaMutations(workspace.id);
    const initial = toWeek(data.availability, staffId);
    const [week, setWeek] = useState<DayHours[]>(initial.week);
    const [exceptions, setExceptions] = useState<AvailabilityException[]>(
        initial.exceptions,
    );
    const [newDate, setNewDate] = useState("");
    const own = data.availability.some((r) => r.staff_id === staffId);
    const name =
        staffId === null
            ? t("agenda.staff.business")
            : (data.staff.find((s) => s.id === staffId)?.name ?? "");
    const update = (weekday: number, fn: (d: DayHours) => DayHours) =>
        setWeek((w) => w.map((d) => (d.weekday === weekday ? fn(d) : d)));

    const save = () => {
        const { rows, error } = fromWeek(week);
        if (error) {
            toast.error(
                t(`agenda.hours.error.${error.message}`, {
                    day: t(`agenda.weekday.${error.weekday}`),
                }),
            );
            return;
        }
        saveAvailability.mutate(
            { staff_id: staffId, rows, exceptions },
            {
                onSuccess: () => toast.success(t("agenda.hours.saved")),
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t("agenda.hours.title", { name })}</CardTitle>
                {staffId !== null && !own ? (
                    <p className="igr:text-xs igr:text-muted-foreground">
                        {t("agenda.hours.usesBusiness")}
                    </p>
                ) : null}
            </CardHeader>
            <CardContent className="igr:space-y-2">
                {week.map((day) => (
                    <div
                        key={day.weekday}
                        className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-[13px]"
                    >
                        <label className="igr:flex igr:w-28 igr:items-center igr:gap-2">
                            <input
                                type="checkbox"
                                checked={day.open}
                                disabled={!data.can_manage}
                                onChange={(e) =>
                                    update(day.weekday, (d) => ({
                                        ...d,
                                        open: e.target.checked,
                                    }))
                                }
                            />
                            {t(`agenda.weekday.${day.weekday}`)}
                        </label>
                        {day.open ? (
                            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                                {day.ranges.map((r, i) => (
                                    <span
                                        key={i}
                                        className="igr:flex igr:items-center igr:gap-1"
                                    >
                                        <Input
                                            type="time"
                                            className="igr:w-28"
                                            value={r.start}
                                            disabled={!data.can_manage}
                                            aria-label={t("agenda.hours.from")}
                                            onChange={(e) =>
                                                update(day.weekday, (d) => ({
                                                    ...d,
                                                    ranges: d.ranges.map(
                                                        (x, j) =>
                                                            j === i
                                                                ? {
                                                                      ...x,
                                                                      start: e
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : x,
                                                    ),
                                                }))
                                            }
                                        />
                                        –
                                        <Input
                                            type="time"
                                            className="igr:w-28"
                                            value={r.end}
                                            disabled={!data.can_manage}
                                            aria-label={t("agenda.hours.to")}
                                            onChange={(e) =>
                                                update(day.weekday, (d) => ({
                                                    ...d,
                                                    ranges: d.ranges.map(
                                                        (x, j) =>
                                                            j === i
                                                                ? {
                                                                      ...x,
                                                                      end: e
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : x,
                                                    ),
                                                }))
                                            }
                                        />
                                        {day.ranges.length > 1 &&
                                        data.can_manage ? (
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label={t(
                                                    "agenda.hours.removeRange",
                                                )}
                                                onClick={() =>
                                                    update(
                                                        day.weekday,
                                                        (d) => ({
                                                            ...d,
                                                            ranges: d.ranges.filter(
                                                                (_, j) =>
                                                                    j !== i,
                                                            ),
                                                        }),
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        ) : null}
                                    </span>
                                ))}
                                {data.can_manage && day.ranges.length < 4 ? (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            update(day.weekday, (d) => ({
                                                ...d,
                                                ranges: [
                                                    ...d.ranges,
                                                    {
                                                        start: "14:00",
                                                        end: "18:00",
                                                    },
                                                ],
                                            }))
                                        }
                                    >
                                        <Plus />
                                        {t("agenda.hours.addRange")}
                                    </Button>
                                ) : null}
                            </div>
                        ) : (
                            <span className="igr:text-muted-foreground">
                                {t("agenda.hours.closed")}
                            </span>
                        )}
                    </div>
                ))}
                <div className="igr:border-t igr:pt-3">
                    <p className="igr:text-[13px] igr:font-medium">
                        {t("agenda.hours.exceptions")}
                    </p>
                    <p className="igr:mb-2 igr:text-xs igr:text-muted-foreground">
                        {t("agenda.hours.exceptionsHelp")}
                    </p>
                    <ul className="igr:space-y-1">
                        {exceptions.map((ex, i) => (
                            <li
                                key={`${ex.date}-${i}`}
                                className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-[13px]"
                            >
                                <span className="igr:w-28 igr:tabular-nums">
                                    {ex.date}
                                </span>
                                <label className="igr:flex igr:items-center igr:gap-1">
                                    <input
                                        type="checkbox"
                                        checked={ex.closed === true}
                                        disabled={!data.can_manage}
                                        onChange={(e) =>
                                            setExceptions((xs) =>
                                                xs.map((x, j) =>
                                                    j === i
                                                        ? e.target.checked
                                                            ? {
                                                                  date: x.date,
                                                                  closed: true,
                                                              }
                                                            : {
                                                                  date: x.date,
                                                                  start: "09:00",
                                                                  end: "13:00",
                                                              }
                                                        : x,
                                                ),
                                            )
                                        }
                                    />
                                    {t("agenda.hours.closedDay")}
                                </label>
                                {ex.closed !== true ? (
                                    <>
                                        <Input
                                            type="time"
                                            className="igr:w-28"
                                            value={ex.start ?? ""}
                                            disabled={!data.can_manage}
                                            aria-label={t("agenda.hours.from")}
                                            onChange={(e) =>
                                                setExceptions((xs) =>
                                                    xs.map((x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  start: e
                                                                      .target
                                                                      .value,
                                                              }
                                                            : x,
                                                    ),
                                                )
                                            }
                                        />
                                        –
                                        <Input
                                            type="time"
                                            className="igr:w-28"
                                            value={ex.end ?? ""}
                                            disabled={!data.can_manage}
                                            aria-label={t("agenda.hours.to")}
                                            onChange={(e) =>
                                                setExceptions((xs) =>
                                                    xs.map((x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  end: e.target
                                                                      .value,
                                                              }
                                                            : x,
                                                    ),
                                                )
                                            }
                                        />
                                    </>
                                ) : null}
                                {data.can_manage ? (
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={t(
                                            "agenda.hours.removeException",
                                        )}
                                        onClick={() =>
                                            setExceptions((xs) =>
                                                xs.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                    {data.can_manage ? (
                        <div className="igr:mt-2 igr:flex igr:items-center igr:gap-2">
                            <Input
                                type="date"
                                className="igr:w-44"
                                value={newDate}
                                onChange={(e) => setNewDate(e.target.value)}
                                aria-label={t("agenda.hours.exceptionDate")}
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={
                                    newDate === "" ||
                                    exceptions.some((x) => x.date === newDate)
                                }
                                onClick={() => {
                                    setExceptions((xs) =>
                                        [
                                            ...xs,
                                            { date: newDate, closed: true },
                                        ].sort((a, b) =>
                                            a.date.localeCompare(b.date),
                                        ),
                                    );
                                    setNewDate("");
                                }}
                            >
                                {t("agenda.hours.addException")}
                            </Button>
                        </div>
                    ) : null}
                    <p className="igr:mt-2 igr:text-xs igr:text-muted-foreground">
                        {t("agenda.hours.holidays")}
                    </p>
                </div>
                {data.can_manage ? (
                    <div className="igr:flex igr:justify-end igr:pt-2">
                        <Button
                            onClick={save}
                            loading={saveAvailability.isPending}
                        >
                            {t("agenda.hours.save")}
                        </Button>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

function StaffDialog({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { saveStaff } = useAgendaMutations(workspace.id);
    const [name, setName] = useState("");
    const [email, setEmail] = useState("");
    const submit = () =>
        saveStaff.mutate(
            {
                id: null,
                data: {
                    name: name.trim(),
                    email: email.trim() === "" ? null : email.trim(),
                },
            },
            {
                onSuccess: () => {
                    toast.success(t("agenda.staff.added"));
                    onClose();
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="igr:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t("agenda.staff.add")}</DialogTitle>
                </DialogHeader>
                <div className="igr:space-y-3">
                    <div>
                        <Label htmlFor="staff-name">
                            {t("agenda.staff.name")}
                        </Label>
                        <Input
                            id="staff-name"
                            className="igr:mt-1"
                            value={name}
                            maxLength={120}
                            onChange={(e) => setName(e.target.value)}
                        />
                    </div>
                    <div>
                        <Label htmlFor="staff-email">
                            {t("agenda.staff.email")}
                        </Label>
                        <Input
                            id="staff-email"
                            type="email"
                            className="igr:mt-1"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                        />
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">
                            {t("agenda.staff.emailHelp")}
                        </p>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t("common.cancel")}
                    </Button>
                    <Button
                        onClick={submit}
                        loading={saveStaff.isPending}
                        disabled={name.trim().length < 2}
                    >
                        {t("agenda.staff.add")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
