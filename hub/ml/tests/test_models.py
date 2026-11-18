"""Recupera los parámetros de datos simulados con el propio proceso BG/NBD y Gamma-Gamma."""
import numpy as np

from app.models import BgNbd, expected_purchases, expected_value, fit_bgnbd, fit_gamma_gamma, p_alive
from app.validation import auc, deciles


def simulate(n=3000, r=0.25, alpha=4.0, a=0.8, b=2.4, weeks=52, seed=7):
    """Simula el proceso BG/NBD: tasa gamma, abandono beta tras cada compra."""
    rng = np.random.default_rng(seed)
    lam = rng.gamma(r, 1 / alpha, n)
    p = rng.beta(a, b, n)
    x = np.zeros(n)
    tx = np.zeros(n)
    for i in range(n):
        t = 0.0
        while True:
            t += rng.exponential(1 / lam[i])
            if t > weeks:
                break
            x[i] += 1
            tx[i] = t
            if rng.random() < p[i]:
                break
    return x, tx * 7, np.full(n, weeks * 7.0)


def test_bgnbd_recupera_parametros_aproximados():
    x, tx, T = simulate()
    m = fit_bgnbd(x, tx, T, penalizer=0.0)
    assert 0.15 < m.r < 0.4
    assert 2.0 < m.alpha < 7.0
    # a y b se recuperan con más ruido; su proporción (abandono medio) es estable.
    assert 0.15 < m.a / (m.a + m.b) < 0.4


def test_p_alive_y_esperanza_tienen_sentido():
    m = BgNbd(r=0.25, alpha=4.0, a=1.2, b=2.4)
    # Sin compras repetidas, está vivo; con muchas compras pero ninguna reciente, baja.
    assert p_alive(m, [0], [0], [300])[0] == 1.0
    recent, stale = p_alive(m, [5, 5], [290, 30], [300, 300])
    assert recent > stale
    e = expected_purchases(m, 90, [5, 0], [290, 0], [300, 300])
    assert e[0] > e[1] > 0
    assert expected_purchases(m, 365, [5], [290], [300])[0] > e[0]


def test_gamma_gamma_promedio_por_cliente():
    rng = np.random.default_rng(3)
    n = 800
    x = rng.integers(1, 6, n).astype(float)
    nu = rng.gamma(4.0, 1 / 400000.0, n)
    m = np.array([rng.gamma(6.0 * xi, 1 / (nu[i] * xi)) for i, xi in enumerate(x)])
    gg = fit_gamma_gamma(x, m)
    ev = expected_value(gg, x, m)
    assert abs(ev.mean() - m.mean()) / m.mean() < 0.1
    assert expected_value(gg, [0], [0])[0] == gg.mean_value()


def test_auc_y_deciles():
    assert auc(np.array([0.9, 0.8, 0.1, 0.2]), np.array([1, 1, 0, 0])) == 1.0
    assert auc(np.array([0.5, 0.5]), np.array([1, 1])) is None
    d = deciles(np.arange(20, dtype=float), np.arange(20, dtype=float), groups=4)
    assert d[0]["predicted"] > d[-1]["predicted"] and len(d) == 4


def test_mbgnbd_baja_p_activo_de_quien_compro_una_vez():
    x, tx, T = simulate(n=2000, weeks=60, seed=11)
    m = fit_bgnbd(x, tx, T, variant="mbgnbd")
    assert m.variant == "mbgnbd" and m.a > 1
    reciente, vieja = p_alive(m, [0, 0], [0, 0], [14, 400])
    assert reciente > vieja and vieja < 1.0
    # BG/NBD clásico: quien compró una vez siempre está «activo».
    clasico = fit_bgnbd(x, tx, T, variant="bgnbd")
    assert p_alive(clasico, [0], [0], [400])[0] == 1.0
    e = expected_purchases(m, 90, [3, 0], [300, 0], [320, 320])
    assert e[0] > e[1] >= 0
