"""Validación contra el periodo de reserva (inteligencia.md §4): se ajusta con la
historia hasta el corte y se compara lo predicho para la reserva con lo que pasó."""
from __future__ import annotations

import numpy as np
from scipy.stats import rankdata


def auc(score: np.ndarray, label: np.ndarray) -> float | None:
    """Área bajo la curva ROC por Mann-Whitney; None si hay una sola clase."""
    pos = int(label.sum())
    neg = int(label.size - pos)
    if pos == 0 or neg == 0:
        return None
    ranks = rankdata(score)
    return float((ranks[label == 1].sum() - pos * (pos + 1) / 2) / (pos * neg))


def corr(a: np.ndarray, b: np.ndarray) -> float | None:
    if a.size < 3 or np.std(a) == 0 or np.std(b) == 0:
        return None
    return float(np.corrcoef(a, b)[0, 1])


def deciles(predicted: np.ndarray, actual: np.ndarray, groups: int = 10) -> list[dict[str, float]]:
    order = np.argsort(-predicted, kind="stable")
    out = []
    for i, idx in enumerate(np.array_split(order, groups)):
        if idx.size == 0:
            continue
        out.append({"decile": i + 1, "customers": int(idx.size), "predicted": round(float(predicted[idx].mean()), 4),
                    "actual": round(float(actual[idx].mean()), 4)})
    return out


def aggregate(predicted: np.ndarray, actual: np.ndarray) -> dict[str, float | None]:
    total_p = float(predicted.sum())
    total_a = float(actual.sum())
    return {
        "predicted_total": round(total_p, 2),
        "actual_total": round(total_a, 2),
        "error_pct": round((total_p - total_a) / total_a * 100, 2) if total_a > 0 else None,
        "mae": round(float(np.abs(predicted - actual).mean()), 4) if predicted.size else None,
        "correlation": None if (c := corr(predicted, actual)) is None else round(c, 4),
    }
