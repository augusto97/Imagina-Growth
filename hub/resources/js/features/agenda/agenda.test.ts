import { describe, expect, it } from "vitest";
import {
    actionsFor,
    addDays,
    dayStartUtc,
    fromWeek,
    groupByDay,
    localDate,
    money,
    toWeek,
    viewRange,
    weekStart,
} from "./model";
import type { Appointment, AvailabilityRow } from "./types";

const appt = (over: Partial<Appointment>): Appointment => ({
    id: "ap_1",
    status: "booked",
    channel: "page",
    starts_at: "2026-12-01T15:00:00Z",
    ends_at: "2026-12-01T16:00:00Z",
    service: { id: "bs_1", name: "Valoración", location: "onsite" },
    staff_id: null,
    contact: null,
    lead_id: null,
    calendar_synced: false,
    ...over,
});

describe("fechas de la agenda", () => {
    it("calcula la semana ISO y el comienzo del día en Bogotá", () => {
        expect(weekStart("2026-12-03")).toBe("2026-11-30");
        expect(weekStart("2026-11-30")).toBe("2026-11-30");
        expect(addDays("2026-12-31", 1)).toBe("2027-01-01");
        expect(dayStartUtc("2026-11-30", "America/Bogota")).toBe(
            "2026-11-30T05:00:00Z",
        );
        expect(dayStartUtc("2026-11-30", "America/Mexico_City")).toBe(
            "2026-11-30T06:00:00Z",
        );
    });

    it("arma el rango de la vista de semana y de día", () => {
        const week = viewRange("week", "2026-12-03", "America/Bogota");
        expect(week.days).toHaveLength(7);
        expect(week.from).toBe("2026-11-30T05:00:00Z");
        expect(week.to).toBe("2026-12-07T05:00:00Z");
        expect(viewRange("day", "2026-12-03", "America/Bogota")).toMatchObject({
            from: "2026-12-03T05:00:00Z",
            to: "2026-12-04T05:00:00Z",
        });
    });

    it("agrupa por día local: las 11 p. m. de Bogotá son del mismo día", () => {
        const groups = groupByDay(
            [
                appt({ id: "b", starts_at: "2026-12-02T04:00:00Z" }),
                appt({ id: "a", starts_at: "2026-12-01T15:00:00Z" }),
            ],
            "America/Bogota",
        );
        expect(Object.keys(groups)).toEqual(["2026-12-01"]);
        expect((groups["2026-12-01"] ?? []).map((x) => x.id)).toEqual([
            "a",
            "b",
        ]);
        expect(localDate("2026-12-02T04:00:00Z", "America/Bogota")).toBe(
            "2026-12-01",
        );
    });
});

describe("acciones de una cita", () => {
    const now = new Date("2026-12-01T12:00:00Z");
    it("antes de empezar se confirma, reprograma o cancela; después se marca cumplida o no asistió", () => {
        expect(actionsFor(appt({}), now)).toEqual([
            "confirm",
            "reschedule",
            "cancel",
        ]);
        expect(actionsFor(appt({ status: "confirmed" }), now)).toEqual([
            "reschedule",
            "cancel",
        ]);
        expect(
            actionsFor(appt({ starts_at: "2026-12-01T10:00:00Z" }), now),
        ).toEqual(["complete", "no-show"]);
        expect(actionsFor(appt({ status: "cancelled" }), now)).toEqual([]);
    });
});

describe("editor de horario", () => {
    const rows: AvailabilityRow[] = [
        {
            id: "ba_1",
            staff_id: null,
            weekday: 1,
            start: "14:00",
            end: "18:00",
            exceptions: [],
        },
        {
            id: "ba_2",
            staff_id: null,
            weekday: 1,
            start: "08:00",
            end: "12:00",
            exceptions: [{ date: "2026-12-24", closed: true }],
        },
        {
            id: "ba_3",
            staff_id: "sf_1",
            weekday: 2,
            start: "09:00",
            end: "12:00",
            exceptions: [],
        },
    ];
    it("convierte filas en semana y de vuelta", () => {
        const { week, exceptions } = toWeek(rows, null);
        expect(week[0]).toEqual({
            weekday: 1,
            open: true,
            ranges: [
                { start: "08:00", end: "12:00" },
                { start: "14:00", end: "18:00" },
            ],
        });
        expect(week[1]?.open).toBe(false);
        expect(exceptions).toEqual([{ date: "2026-12-24", closed: true }]);
        expect(fromWeek(week).rows).toEqual([
            { weekday: 1, start: "08:00", end: "12:00" },
            { weekday: 1, start: "14:00", end: "18:00" },
        ]);
        expect(toWeek(rows, "sf_1").week[1]?.ranges).toEqual([
            { start: "09:00", end: "12:00" },
        ]);
    });
    it("rechaza franjas al revés o que se cruzan", () => {
        expect(
            fromWeek([
                {
                    weekday: 3,
                    open: true,
                    ranges: [{ start: "12:00", end: "09:00" }],
                },
            ]).error,
        ).toEqual({ weekday: 3, message: "order" });
        expect(
            fromWeek([
                {
                    weekday: 3,
                    open: true,
                    ranges: [
                        { start: "08:00", end: "12:00" },
                        { start: "11:00", end: "13:00" },
                    ],
                },
            ]).error,
        ).toEqual({ weekday: 3, message: "overlap" });
    });
});

it("formatea precios en pesos sin decimales", () => {
    expect(money("80000.00", "COP").replace(/\s/g, " ")).toBe("$ 80.000");
    expect(money(null, "COP")).toBe("—");
});
