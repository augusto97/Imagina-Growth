# hub/ml — servicio de predicciones

FastAPI con BG/NBD (P(activo), compras esperadas a 90 y 365 días) y Gamma-Gamma
(valor por compra → CLV a 12 meses), implementados con numpy y scipy
(`app/models.py`, con las referencias). El hub lo llama desde
`igr:predictions:train` (hub/CLAUDE.md §12, inteligencia.md §4).

- `GET /health`
- `POST /v1/clv/fit` con `X-IGR-ML-Token` (= `IGR_ML_TOKEN`): ajusta con la historia
  hasta el corte, valida contra el periodo de reserva y vuelve a ajustar con todo.

```bash
python3 -m venv venv && ./venv/bin/pip install -r requirements-dev.txt
./venv/bin/pytest -q
IGR_ML_TOKEN=dev ./venv/bin/uvicorn app.main:app --port 8095
```
