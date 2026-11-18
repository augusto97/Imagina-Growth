# Servicio de predicciones (BG/NBD y Gamma-Gamma). El hub lo llama por HTTP con X-IGR-ML-Token.
FROM python:3.13-slim
WORKDIR /srv
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app ./app
USER nobody
EXPOSE 8095
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8095", "--workers", "2"]
