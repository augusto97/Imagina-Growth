import numpy as np
from fastapi.testclient import TestClient

from app.main import app
from tests.test_models import simulate


def rows(x, tx, T, prefix="c"):
    return [[f"{prefix}{i}", float(x[i]), float(tx[i]), float(T[i]), 150000.0 if x[i] > 0 else 0.0] for i in range(len(x))]


def test_salud_sin_token():
    assert TestClient(app).get("/health").json() == {"status": "ok"}


def test_exige_token(monkeypatch):
    client = TestClient(app)
    monkeypatch.delenv("IGR_ML_TOKEN", raising=False)
    assert client.post("/v1/clv/fit", json={"full": {"customers": []}}).status_code == 503
    monkeypatch.setenv("IGR_ML_TOKEN", "secreto")
    assert client.post("/v1/clv/fit", json={"full": {"customers": []}}, headers={"X-IGR-ML-Token": "otro"}).status_code == 401


def test_ajusta_valida_con_reserva_y_predice(monkeypatch):
    monkeypatch.setenv("IGR_ML_TOKEN", "secreto")
    x, tx, T = simulate(n=600, weeks=40)
    rng = np.random.default_rng(1)
    actual = {f"c{i}": [float(rng.poisson(0.3)), 100000.0] for i in range(600)}
    body = {
        "calibration": {"customers": rows(x, tx, T)},
        "holdout": {"days": 90, "actual": actual},
        "full": {"customers": rows(x, tx, T + 90)},
        "horizons": [90, 365],
    }
    res = TestClient(app).post("/v1/clv/fit", json=body, headers={"X-IGR-ML-Token": "secreto"})
    assert res.status_code == 200
    data = res.json()
    assert set(data["params"]["bgnbd"]) == {"r", "alpha", "a", "b", "variant"}
    v = data["validation"]
    assert v["customers"] == 600 and v["holdout_days"] == 90
    assert {"predicted_total", "actual_total", "error_pct", "mae", "correlation"} <= set(v["purchases"])
    assert len(v["purchases_by_decile"]) == 10 and v["churn_auc"] is not None
    assert v["model"] in ("bgnbd", "mbgnbd") and set(v["candidates"]) == {"bgnbd", "mbgnbd"}
    assert data["params"]["bgnbd"]["variant"] == v["model"]
    p = data["predictions"][0]
    assert {"id", "p_alive", "exp_90", "exp_365", "expected_value", "clv"} <= set(p)
    assert all(0 <= q["p_alive"] <= 1 for q in data["predictions"])
