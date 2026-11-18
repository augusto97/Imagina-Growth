"""BG/NBD y Gamma-Gamma con numpy y scipy (inteligencia.md §4.2–4.3).

Se implementan las verosimilitudes publicadas en lugar de usar `lifetimes`: esa
librería ya no se mantiene y sus dependencias (pandas, autograd) pesan más que
todo el servicio. Las fórmulas son las de:

- Fader, Hardie y Lee (2005), «Counting Your Customers the Easy Way: An
  Alternative to the Pareto/NBD Model» (BG/NBD).
- Fader y Hardie (2013), «The Gamma-Gamma Model of Monetary Value».

Unidades: el hub manda días; aquí se trabaja en semanas para estabilidad numérica.
Por cliente: `x` = compras repetidas (pedidos − 1), `tx` = días entre la primera
y la última compra, `T` = días entre la primera compra y el corte, `m` = valor
promedio de las compras repetidas (0 si no hay).
"""
from __future__ import annotations

from dataclasses import dataclass

import numpy as np
from scipy.optimize import minimize
from scipy.special import betaln, gammaln, hyp2f1

WEEK = 7.0


@dataclass
class BgNbd:
    r: float
    alpha: float
    a: float
    b: float
    # «bgnbd» (Fader, Hardie y Lee 2005) o «mbgnbd» (Batislam, Denizel y Filiztekin
    # 2007): en la modificada el cliente también puede irse sin volver a comprar, así
    # que P(activo) de quien compró una sola vez baja con el tiempo.
    variant: str = "bgnbd"

    def as_dict(self) -> dict[str, float | str]:
        return {"r": self.r, "alpha": self.alpha, "a": self.a, "b": self.b, "variant": self.variant}


@dataclass
class GammaGamma:
    p: float
    q: float
    v: float

    def as_dict(self) -> dict[str, float]:
        return {"p": self.p, "q": self.q, "v": self.v}

    def mean_value(self) -> float:
        return self.p * self.v / (self.q - 1.0)


def bgnbd_loglik(r: float, alpha: float, a: float, b: float, x, tx, T) -> np.ndarray:
    x = np.asarray(x, dtype=float)
    tx = np.asarray(tx, dtype=float)
    T = np.asarray(T, dtype=float)
    a1 = gammaln(r + x) - gammaln(r) + r * np.log(alpha)
    a2 = betaln(a, b + x) - betaln(a, b)
    a3 = -(r + x) * np.log(alpha + T)
    with np.errstate(divide="ignore", invalid="ignore"):
        a4 = np.where(
            x > 0,
            np.log(a) - np.log(np.maximum(b + x - 1.0, 1e-12)) - (r + x) * np.log(alpha + tx),
            -np.inf,
        )
    return a1 + a2 + np.logaddexp(a3, a4)


def mbgnbd_loglik(r: float, alpha: float, a: float, b: float, x, tx, T) -> np.ndarray:
    x = np.asarray(x, dtype=float)
    tx = np.asarray(tx, dtype=float)
    T = np.asarray(T, dtype=float)
    a1 = gammaln(r + x) - gammaln(r) + r * np.log(alpha)
    a2 = betaln(a, b + x + 1.0) - betaln(a, b)
    a3 = -(r + x) * np.log(alpha + T)
    a4 = np.log(a) - np.log(b + x) + (r + x) * (np.log(alpha + T) - np.log(alpha + tx))
    return a1 + a2 + a3 + np.logaddexp(0.0, a4)


def fit_bgnbd(x, tx_days, T_days, penalizer: float = 0.001, variant: str = "bgnbd") -> BgNbd:
    """Máxima verosimilitud en el espacio logarítmico (los cuatro parámetros son positivos)."""
    x = np.asarray(x, dtype=float)
    tx = np.asarray(tx_days, dtype=float) / WEEK
    T = np.asarray(T_days, dtype=float) / WEEK
    loglik = mbgnbd_loglik if variant == "mbgnbd" else bgnbd_loglik

    def objective(theta: np.ndarray, a_floor: float) -> float:
        r, alpha, a, b = np.exp(theta)
        a = a + a_floor
        ll = loglik(r, alpha, a, b, x, tx, T)
        if not np.all(np.isfinite(ll)):
            return 1e12
        return -ll.mean() + penalizer * float(np.sum(np.exp(theta) ** 2))

    best = None
    for a_floor in (0.0, 1.01):
        res = minimize(objective, np.zeros(4), args=(a_floor,), method="Nelder-Mead",
                       options={"maxiter": 4000, "xatol": 1e-6, "fatol": 1e-9})
        res = minimize(objective, res.x, args=(a_floor,), method="L-BFGS-B")
        r, alpha, a, b = np.exp(res.x)
        a = a + a_floor
        # La esperanza condicional exige a > 1; si el óptimo libre queda por debajo, se ajusta con a > 1.
        if a > 1.0:
            best = BgNbd(float(r), float(alpha), float(a), float(b), variant)
            break
    if best is None:  # pragma: no cover - el segundo intento siempre cumple a > 1
        raise RuntimeError("BG/NBD no convergió")
    return best


def p_alive(m: BgNbd, x, tx_days, T_days) -> np.ndarray:
    x = np.asarray(x, dtype=float)
    tx = np.asarray(tx_days, dtype=float) / WEEK
    T = np.asarray(T_days, dtype=float) / WEEK
    with np.errstate(divide="ignore", invalid="ignore", over="ignore"):
        if m.variant == "mbgnbd":
            log_ratio = np.log(m.a) - np.log(m.b + x) + (m.r + x) * (np.log(m.alpha + T) - np.log(m.alpha + tx))
            return 1.0 / (1.0 + np.exp(np.clip(log_ratio, -700, 700)))
        log_ratio = np.log(m.a) - np.log(np.maximum(m.b + x - 1.0, 1e-12)) + (m.r + x) * (np.log(m.alpha + T) - np.log(m.alpha + tx))
        out = 1.0 / (1.0 + np.exp(np.clip(log_ratio, -700, 700)))
    return np.where(x > 0, out, 1.0)


def expected_purchases(m: BgNbd, t_days: float, x, tx_days, T_days) -> np.ndarray:
    """E[compras en los próximos t días | historia] (ecuación 10 de Fader, Hardie y Lee)."""
    x = np.asarray(x, dtype=float)
    tx = np.asarray(tx_days, dtype=float) / WEEK
    T = np.asarray(T_days, dtype=float) / WEEK
    t = t_days / WEEK
    z = t / (m.alpha + T + t)
    with np.errstate(divide="ignore", invalid="ignore", over="ignore"):
        if m.variant == "mbgnbd":
            hyp = hyp2f1(m.r + x, m.b + x + 1.0, m.a + m.b + x, z)
            num = (m.a + m.b + x) / (m.a - 1.0) * (1.0 - hyp * ((m.alpha + T) / (m.alpha + t + T)) ** (m.r + x))
            den = 1.0 + (m.a / (m.b + x)) * ((m.alpha + T) / (m.alpha + tx)) ** (m.r + x)
            return np.nan_to_num(np.clip(num / den, 0.0, None), nan=0.0, posinf=0.0)
        hyp = hyp2f1(m.r + x, m.b + x, m.a + m.b + x - 1.0, z)
        num = (m.a + m.b + x - 1.0) / (m.a - 1.0) * (1.0 - ((m.alpha + T) / (m.alpha + T + t)) ** (m.r + x) * hyp)
        den = 1.0 + np.where(x > 0, m.a / np.maximum(m.b + x - 1.0, 1e-12) * ((m.alpha + T) / (m.alpha + tx)) ** (m.r + x), 0.0)
        out = num / den
    return np.nan_to_num(np.clip(out, 0.0, None), nan=0.0, posinf=0.0)


def gg_loglik(p: float, q: float, v: float, x, mval) -> np.ndarray:
    x = np.asarray(x, dtype=float)
    mval = np.asarray(mval, dtype=float)
    return (gammaln(p * x + q) - gammaln(p * x) - gammaln(q) + q * np.log(v)
            + (p * x - 1.0) * np.log(mval) + (p * x) * np.log(x) - (p * x + q) * np.log(x * mval + v))


def fit_gamma_gamma(x, mval, penalizer: float = 0.0) -> GammaGamma:
    """Solo clientes con compras repetidas y valor positivo. q > 1 para que el promedio exista."""
    x = np.asarray(x, dtype=float)
    mval = np.asarray(mval, dtype=float)
    keep = (x > 0) & (mval > 0)
    x, mval = x[keep], mval[keep]
    if x.size < 10:
        raise ValueError("Gamma-Gamma necesita al menos 10 clientes con compras repetidas")
    scale = float(np.median(mval))

    def objective(theta: np.ndarray) -> float:
        p = np.exp(theta[0])
        q = 1.0 + np.exp(theta[1])
        v = np.exp(theta[2])
        ll = gg_loglik(p, q, v, x, mval / scale)
        if not np.all(np.isfinite(ll)):
            return 1e12
        return -ll.mean() + penalizer * float(np.sum(np.exp(theta) ** 2))

    res = minimize(objective, np.array([0.0, 1.0, 0.0]), method="Nelder-Mead", options={"maxiter": 4000})
    res = minimize(objective, res.x, method="L-BFGS-B")
    p, q, v = float(np.exp(res.x[0])), float(1.0 + np.exp(res.x[1])), float(np.exp(res.x[2])) * scale
    return GammaGamma(p, q, v)


def expected_value(m: GammaGamma, x, mval) -> np.ndarray:
    """E[valor por compra | historia]; sin compras repetidas, el promedio de la población."""
    x = np.asarray(x, dtype=float)
    mval = np.asarray(mval, dtype=float)
    with np.errstate(divide="ignore", invalid="ignore"):
        cond = m.p * (m.v + x * mval) / (m.p * x + m.q - 1.0)
    return np.where((x > 0) & (mval > 0), cond, m.mean_value())
