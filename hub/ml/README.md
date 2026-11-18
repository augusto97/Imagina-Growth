# hub/ml — servicio de predicciones

FastAPI con BG/NBD (P(activo), compras esperadas a 90 y 365 días) y Gamma-Gamma
(valor por compra → CLV a 12 meses), implementados con numpy y scipy
(`app/models.py`, con las referencias). El hub lo llama desde
`igr:predictions:train` (hub/CLAUDE.md §12, inteligencia.md §4).

- `GET /health`
- `POST /v1/clv/fit` con `X-IGR-ML-Token` (= `IGR_ML_TOKEN`): ajusta con la historia
  hasta el corte, valida contra el periodo de reserva y vuelve a ajustar con todo.

Las dependencias están en `pyproject.toml`, fijadas en `uv.lock` (se instalan con
[uv](https://docs.astral.sh/uv/)):

```bash
uv sync --frozen                 # entorno .venv con las dependencias de desarrollo
uv run --frozen pytest -q
IGR_ML_TOKEN=dev uv run --frozen uvicorn app.main:app --port 8095
```

Para cambiar una versión, edita `pyproject.toml` y corre `uv lock`; el `uv.lock` va en el repositorio.

En producción (docs/despliegue.md) el paquete de versión trae `pyproject.toml` y `uv.lock`; el
actualizador crea el entorno en el servidor con `uv sync --frozen --no-dev` y el servicio
`growth-ml` corre uvicorn en `127.0.0.1:8095` con 2 workers.
