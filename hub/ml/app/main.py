"""Servicio de predicciones (FastAPI). El hub lo llama por cola (hub/CLAUDE.md §3)."""
from __future__ import annotations

import os
import time

import numpy as np
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from .models import expected_purchases, expected_value, fit_bgnbd, fit_gamma_gamma, p_alive
from .validation import aggregate, auc, deciles

app = FastAPI(title="Imagina Growth ML", version="1.0.0")

# Cliente: [id, x (compras repetidas), tx (días), T (días), m (valor promedio de las repetidas)]
Row = tuple[str, float, float, float, float]


class Summary(BaseModel):
    customers: list[Row]


class Holdout(BaseModel):
    days: int = Field(90, ge=7, le=365)
    # id → [compras en la reserva, ingresos en la reserva]
    actual: dict[str, tuple[float, float]]


class FitRequest(BaseModel):
    calibration: Summary | None = None
    holdout: Holdout | None = None
    full: Summary
    horizons: list[int] = [90, 365]
    clv_horizon: int = 365
    penalizer: float = Field(0.001, ge=0, le=1)


def _arrays(rows: list[Row]):
    ids = [r[0] for r in rows]
    a = np.array([r[1:] for r in rows], dtype=float).reshape(-1, 4)
    return ids, a[:, 0], a[:, 1], a[:, 2], a[:, 3]


def _check_token(token: str | None) -> None:
    expected = os.environ.get("IGR_ML_TOKEN", "")
    if expected == "":
        raise HTTPException(503, "IGR_ML_TOKEN no está configurado")
    if token != expected:
        raise HTTPException(401, "token inválido")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/v1/clv/fit")
def fit(req: FitRequest, x_igr_ml_token: str | None = Header(default=None)) -> dict:
    _check_token(x_igr_ml_token)
    started = time.time()
    if len(req.full.customers) < 20:
        raise HTTPException(422, "Se necesitan al menos 20 clientes")

    # BG/NBD o su versión modificada (MBG/NBD), la que mejor prediga el periodo de reserva.
    validation = None
    variant = "mbgnbd"
    if req.calibration is not None and req.holdout is not None and len(req.calibration.customers) >= 20:
        ids, x, tx, T, m = _arrays(req.calibration.customers)
        days = req.holdout.days
        act = np.array([req.holdout.actual.get(i, (0.0, 0.0))[0] for i in ids], dtype=float)
        rev = np.array([req.holdout.actual.get(i, (0.0, 0.0))[1] for i in ids], dtype=float)
        candidates = {}
        for v in ("bgnbd", "mbgnbd"):
            bg = fit_bgnbd(x, tx, T, req.penalizer, variant=v)
            pred = expected_purchases(bg, days, x, tx, T)
            alive = p_alive(bg, x, tx, T)
            agg = aggregate(pred, act)
            candidates[v] = (bg, pred, alive, agg, auc(alive, (act > 0).astype(int)), auc(pred, (act > 0).astype(int)))
        # Gana el de menor error agregado; con menos de 2 puntos de diferencia, el que mejor ordena (AUC de P(activo)).
        def score(v: str) -> tuple[float, float]:
            err = abs(candidates[v][3]["error_pct"] or 0.0)
            return (round(err / 2.0), -(candidates[v][4] or 0.0))
        variant = min(candidates, key=score)
        bg, pred, alive, agg, churn_auc, purchase_auc = candidates[variant]
        validation = {
            "holdout_days": days,
            "customers": len(ids),
            "model": variant,
            "purchases": agg,
            "purchases_by_decile": deciles(pred, act),
            "churn_auc": None if churn_auc is None else round(churn_auc, 4),
            "purchase_auc": None if purchase_auc is None else round(purchase_auc, 4),
            "active_in_holdout_pct": round(float((act > 0).mean() * 100), 2),
            "candidates": {v: {"error_pct": c[3]["error_pct"], "churn_auc": None if c[4] is None else round(c[4], 4)} for v, c in candidates.items()},
        }
        try:
            gg = fit_gamma_gamma(x, m)
            pred_rev = pred * expected_value(gg, x, m)
            validation["revenue"] = aggregate(pred_rev, rev)
            validation["revenue_by_decile"] = deciles(pred_rev, rev)
        except ValueError as e:
            validation["revenue"] = {"error": str(e)}

    ids, x, tx, T, m = _arrays(req.full.customers)
    bg = fit_bgnbd(x, tx, T, req.penalizer, variant=variant)
    alive = p_alive(bg, x, tx, T)
    expected = {h: expected_purchases(bg, h, x, tx, T) for h in sorted(set(req.horizons + [req.clv_horizon]))}
    gg_params = None
    value = np.zeros_like(x)
    try:
        gg = fit_gamma_gamma(x, m)
        gg_params = gg.as_dict()
        value = expected_value(gg, x, m)
    except ValueError:
        pass
    clv = expected[req.clv_horizon] * value

    predictions = []
    for i, cid in enumerate(ids):
        row = {"id": cid, "p_alive": round(float(alive[i]), 4), "expected_value": round(float(value[i]), 2),
               "clv": round(float(clv[i]), 2)}
        for h in req.horizons:
            row[f"exp_{h}"] = round(float(expected[h][i]), 4)
        predictions.append(row)

    return {
        "params": {"bgnbd": bg.as_dict(), "gamma_gamma": gg_params, "penalizer": req.penalizer},
        "validation": validation,
        "predictions": predictions,
        "seconds": round(time.time() - started, 3),
    }
