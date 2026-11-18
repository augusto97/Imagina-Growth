import type { UpdateState, UpdateStatus, UpdateStep } from "./updates-api";

/* Lógica pura del panel de actualizaciones (docs/despliegue.md §5), sin React, para probarla sola. */

/** Los pasos de growth-update en orden. `rollback` solo aparece si hizo falta volver atrás. */
export const UPDATE_STEPS = [
    "preflight",
    "download",
    "verify",
    "extract",
    "caches",
    "backup",
    "migrate",
    "switch",
    "restart",
    "health",
    "prune",
] as const;

export function isActive(status: UpdateStatus | null | undefined): boolean {
    return status?.state === "queued" || status?.state === "running";
}

/** Los pasos para mostrar: los del contrato en orden (pendientes si aún no llegan) y `rollback` al final si existe. */
export function visibleSteps(
    status: UpdateStatus | null | undefined,
): UpdateStep[] {
    const byKey = new Map((status ? status.steps : []).map((s) => [s.key, s]));
    const steps: UpdateStep[] = UPDATE_STEPS.map(
        (key) =>
            byKey.get(key) ?? {
                key,
                status: "pending",
                started_at: null,
                finished_at: null,
                message: null,
            },
    );
    const rollback = byKey.get("rollback");
    if (rollback) steps.push(rollback);
    return steps;
}

/** Avance de 0 a 100: pasos terminados (o saltados) sobre el total del contrato. */
export function progress(status: UpdateStatus | null | undefined): number {
    if (!status) return 0;
    if (status.state === "succeeded") return 100;
    const finished = status.steps.filter(
        (s) =>
            UPDATE_STEPS.includes(s.key as (typeof UPDATE_STEPS)[number]) &&
            (s.status === "done" || s.status === "skipped"),
    ).length;
    return Math.round((finished / UPDATE_STEPS.length) * 100);
}

export function resultTone(
    state: UpdateState | undefined,
): "ok" | "warning" | "critical" | "neutral" {
    if (state === "succeeded") return "ok";
    if (state === "rolled_back") return "warning";
    if (state === "failed") return "critical";
    return "neutral";
}

/** Notas del release como texto plano: sin marcas de Markdown y con un límite de largo. */
export function plainNotes(
    notes: string | null | undefined,
    max = 4000,
): string {
    if (!notes) return "";
    const text = notes
        .replace(/\r\n/g, "\n")
        .replace(/^[ \t]*[-+*][ \t]+/gm, "• ")
        .replace(/!\[[^\]]*]\([^)]*\)/g, "")
        .replace(/\[([^\]]+)]\([^)]*\)/g, "$1")
        .replace(/^#{1,6}[ \t]+/gm, "")
        .replace(/[*_`~]{1,3}/g, "")
        .replace(/[ \t]+$/gm, "")
        .replace(/\n{3,}/g, "\n\n")
        .trim();
    return text.length > max ? `${text.slice(0, max).trimEnd()}…` : text;
}
