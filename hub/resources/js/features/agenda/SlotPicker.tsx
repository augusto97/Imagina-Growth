import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/cn";
import { useSlots } from "./api";
import { localDate, localTime } from "./model";

/** Horarios libres de un servicio en los 7 días desde la fecha elegida, agrupados por día. */
export function SlotPicker({
    ws,
    serviceId,
    staffId,
    tz,
    value,
    onChange,
    today,
}: {
    ws: string;
    serviceId: string;
    staffId: string;
    tz: string;
    value: string;
    onChange: (start: string, staff: string | null) => void;
    today: string;
}) {
    const { t, i18n } = useTranslation();
    const [date, setDate] = useState(today);
    const slots = useSlots(ws, serviceId, date, staffId, true);
    const byDay = new Map<
        string,
        { start: string; staff_id: string | null }[]
    >();
    for (const s of slots.data?.data ?? []) {
        const d = localDate(s.start, tz);
        const list = byDay.get(d) ?? [];
        if (!list.some((x) => x.start === s.start)) list.push(s);
        byDay.set(d, list);
    }
    const dayLabel = (d: string) =>
        new Intl.DateTimeFormat(i18n.language, {
            timeZone: "UTC",
            weekday: "long",
            day: "numeric",
            month: "long",
        }).format(new Date(`${d}T12:00:00Z`));

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:items-center igr:gap-2">
                <Input
                    type="date"
                    value={date}
                    min={today}
                    onChange={(e) => setDate(e.target.value || today)}
                    className="igr:w-44"
                    aria-label={t("agenda.book.from")}
                />
                <span className="igr:text-xs igr:text-muted-foreground">
                    {t("agenda.book.tz", { tz })}
                </span>
            </div>
            {slots.isPending ? (
                <Skeleton className="igr:h-24" />
            ) : byDay.size === 0 ? (
                <p className="igr:text-[13px] igr:text-muted-foreground">
                    {t("agenda.book.noSlots")}
                </p>
            ) : (
                <div className="igr:max-h-72 igr:space-y-3 igr:overflow-y-auto igr:pr-1">
                    {[...byDay.entries()].map(([d, list]) => (
                        <div key={d}>
                            <p className="igr:mb-1 igr:text-xs igr:font-medium igr:capitalize igr:text-muted-foreground">
                                {dayLabel(d)}
                            </p>
                            <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                                {list.map((s) => (
                                    <Button
                                        key={s.start}
                                        type="button"
                                        size="sm"
                                        variant={
                                            value === s.start
                                                ? "default"
                                                : "outline"
                                        }
                                        className={cn("igr:tabular-nums")}
                                        onClick={() =>
                                            onChange(s.start, s.staff_id)
                                        }
                                        aria-pressed={value === s.start}
                                    >
                                        {localTime(s.start, tz, i18n.language)}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
