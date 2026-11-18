import { describe, expect, it } from "vitest";
import type { UpdateStatus } from "./updates-api";
import {
    isActive,
    plainNotes,
    progress,
    resultTone,
    UPDATE_STEPS,
    visibleSteps,
} from "./updates-model";

const status = (over: Partial<UpdateStatus> = {}): UpdateStatus => ({
    id: "upd_1",
    from: "0.1.0",
    to: "0.2.0",
    requested_by: "usr_1",
    state: "running",
    step: "migrate",
    steps: [],
    started_at: null,
    finished_at: null,
    error: null,
    ...over,
});
const step = (key: string, s: UpdateStatus["steps"][number]["status"]) => ({
    key,
    status: s,
    started_at: null,
    finished_at: null,
    message: null,
});

describe("panel de actualizaciones", () => {
    it("sabe si hay una actualización en curso", () => {
        expect(isActive(status({ state: "queued" }))).toBe(true);
        expect(isActive(status({ state: "running" }))).toBe(true);
        expect(isActive(status({ state: "succeeded" }))).toBe(false);
        expect(isActive(null)).toBe(false);
    });

    it("muestra los pasos del contrato en orden, con rollback al final si hizo falta", () => {
        const s = status({
            steps: [
                step("health", "failed"),
                step("preflight", "done"),
                step("rollback", "done"),
            ],
        });
        const keys = visibleSteps(s).map((x) => x.key);
        expect(keys).toEqual([...UPDATE_STEPS, "rollback"]);
        expect(visibleSteps(s).at(0)?.status).toBe("done");
        expect(visibleSteps(s).find((x) => x.key === "download")?.status).toBe(
            "pending",
        );
        expect(visibleSteps(null).map((x) => x.key)).toEqual([...UPDATE_STEPS]);
    });

    it("calcula el avance con los pasos terminados o saltados", () => {
        expect(
            progress(
                status({
                    steps: [
                        step("preflight", "done"),
                        step("download", "done"),
                        step("backup", "skipped"),
                        step("rollback", "done"),
                    ],
                }),
            ),
        ).toBe(27);
        expect(progress(status({ state: "succeeded" }))).toBe(100);
        expect(progress(null)).toBe(0);
    });

    it("elige el tono del resultado", () => {
        expect(resultTone("succeeded")).toBe("ok");
        expect(resultTone("rolled_back")).toBe("warning");
        expect(resultTone("failed")).toBe("critical");
        expect(resultTone("running")).toBe("neutral");
    });

    it("muestra las notas del release como texto plano", () => {
        expect(
            plainNotes(
                "## Novedades\r\n\r\n- **Leads** con [puntaje](https://x.test)\n* `fix` ![img](a.png)",
            ),
        ).toBe("Novedades\n\n• Leads con puntaje\n• fix");
        expect(plainNotes(null)).toBe("");
        expect(plainNotes("a".repeat(10), 5)).toBe("aaaaa…");
    });
});
