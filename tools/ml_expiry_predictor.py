#!/usr/bin/env python3
"""
ml_expiry_predictor.py — Motor ML de predicción de vencimientos WMS Prooriente
================================================================================
Lee datos de inventario + consumo histórico por stdin (JSON), aplica:
  1. EMA (Exponential Moving Average) para estimar consumo diario real
  2. Regresión lineal simple para detectar tendencia (creciente/decreciente)
  3. Clasificación de riesgo basada en dias_para_vencer vs dias_agotamiento
  4. Recomendaciones accionables por nivel de riesgo

Uso desde PHP:
  $json   = json_encode($payload);
  $result = shell_exec("echo " . escapeshellarg($json) . " | python3 tools/ml_expiry_predictor.py");
  $data   = json_decode($result, true);

Formato de entrada (stdin):
  {
    "empresa_id": 1,
    "sucursal_id": 1,
    "productos": [
      {
        "producto_id": 1,
        "nombre": "Leche entera 1L",
        "lote": "L2024-01",
        "fecha_vencimiento": "2024-06-15",
        "stock_actual": 120.0,
        "stock_minimo": 10.0,
        "consumo_historico": [5, 8, 3, 7, 6, 9, 4, 8, 5, 7, 6, 8, 5, 4, 9, 7, 6, 5, 8, 7,
                               6, 9, 5, 7, 8, 6, 4, 7, 9, 5]   // 30 días, más reciente al final
      }
    ]
  }

Formato de salida (stdout): JSON con predictions + summary + model_info
"""

import sys
import json
import math
import statistics
from datetime import datetime, date, timedelta


# ── Constantes ────────────────────────────────────────────────────────────────

ALPHA_EMA      = 0.3    # Factor de suavizado EMA (0=más suave, 1=más reactivo)
DIAS_MIN_DATA  = 7      # Mínimo de días para hacer una predicción confiable
UMBRAL_CRITICO = 30     # días para vencimiento → riesgo CRITICO (solicitado: < 30)
UMBRAL_ALTO    = 60     # días para vencimiento → riesgo ALTO
UMBRAL_MEDIO   = 90     # días para vencimiento → riesgo MEDIO


# ── Funciones ML ──────────────────────────────────────────────────────────────

def ema(series: list, alpha: float = ALPHA_EMA) -> float:
    """
    Exponential Moving Average — da más peso a los días recientes.
    Serie = [día más antiguo ... día más reciente].
    """
    if not series:
        return 0.0
    val = float(series[0])
    for x in series[1:]:
        val = alpha * float(x) + (1 - alpha) * val
    return val


def linear_regression(series: list) -> dict:
    """
    Regresión lineal simple: y = a + b*x
    Retorna pendiente (b), intercepto (a) y R² para medir confianza.
    """
    n = len(series)
    if n < 2:
        return {"slope": 0.0, "intercept": float(series[0]) if series else 0.0, "r2": 0.0}

    xs = list(range(n))
    x_mean = sum(xs) / n
    y_mean = sum(series) / n

    ss_xy = sum((xs[i] - x_mean) * (series[i] - y_mean) for i in range(n))
    ss_xx = sum((xs[i] - x_mean) ** 2 for i in range(n))
    ss_yy = sum((series[i] - y_mean) ** 2 for i in range(n))

    if ss_xx == 0:
        return {"slope": 0.0, "intercept": y_mean, "r2": 0.0}

    b = ss_xy / ss_xx
    a = y_mean - b * x_mean
    r2 = (ss_xy ** 2 / (ss_xx * ss_yy)) if ss_yy > 0 else 0.0

    return {"slope": round(b, 4), "intercept": round(a, 4), "r2": round(r2, 4)}


def project_future_demand(series: list, dias_futuro: int) -> list:
    """
    Proyecta demanda futura usando EMA base + tendencia de regresión.
    Retorna lista de consumo proyectado por día.
    """
    if not series:
        return [0.0] * dias_futuro

    reg   = linear_regression(series)
    base  = ema(series)
    n     = len(series)

    proyeccion = []
    for i in range(1, dias_futuro + 1):
        # EMA como base + ajuste de tendencia
        valor = base + reg["slope"] * i
        proyeccion.append(max(0.0, round(valor, 4)))

    return proyeccion


def confidence_score(series: list, reg: dict) -> float:
    """
    Calcula un score de confianza 0.0–1.0 basado en:
    - Cantidad de datos históricos
    - R² de la regresión (qué tan lineal es el patrón)
    - Variabilidad relativa (CV)
    """
    n = len(series)
    if n == 0:
        return 0.0

    # Factor datos: más datos = más confianza (satura en 30 días)
    factor_datos = min(n / 30, 1.0)

    # Factor R²: mayor linealidad = más confianza
    factor_r2 = reg.get("r2", 0.0)

    # Factor variabilidad: menor CV = más confianza
    mean_ = statistics.mean(series) if series else 0
    std_  = statistics.stdev(series) if len(series) > 1 else 0
    cv    = (std_ / mean_) if mean_ > 0 else 1.0
    factor_cv = max(0.0, 1.0 - min(cv, 1.0))

    confianza = (0.4 * factor_datos + 0.35 * factor_r2 + 0.25 * factor_cv)
    return round(min(1.0, max(0.0, confianza)), 4)


def classify_risk(dias_para_vencer: int, dias_agotamiento: float) -> str:
    """
    Clasifica el nivel de riesgo de vencimiento.
    La combinación de ambos valores determina si hay sobrestock en riesgo.
    """
    if dias_para_vencer <= 0:
        return "vencido"
    if dias_para_vencer < UMBRAL_CRITICO:
        return "critico"
    if dias_para_vencer <= UMBRAL_ALTO:
        return "alto"
    if dias_para_vencer <= UMBRAL_MEDIO:
        return "medio"
    
    # Si el stock se agota ANTES de que venza → no hay riesgo de vencimiento
    if dias_agotamiento <= dias_para_vencer:
        return "bajo"
        
    # Exceso de stock que no se alcanzará a consumir antes del vencimiento
    exceso_dias = dias_agotamiento - dias_para_vencer
    if exceso_dias > 30:
        return "alto"
    if exceso_dias > 0:
        return "medio"
    return "bajo"


def build_recommendations(prod: dict, nivel_riesgo: str, unidades_en_riesgo: float,
                           dias_para_vencer: int, consumo_diario: float,
                           tendencia: str) -> list:
    """
    Genera recomendaciones accionables y basadas en argumentos de negocio.
    """
    recs = []
    nombre = prod.get("nombre", "Producto")
    
    # Mensaje base de rotación
    vel_msg = f"rotación de {consumo_diario:.1f} uds/día" if consumo_diario > 0 else "ausencia de rotación reciente"

    if nivel_riesgo == "vencido":
        recs.append(f"RETIRAR INMEDIATAMENTE: {nombre} ha caducado. Ejecutar proceso de baja física para evitar sanciones legales y riesgos sanitarios.")
        recs.append("El impacto financiero ya es del 100%. No se debe mantener en estantería.")
        return recs

    if nivel_riesgo == "critico":
        recs.append(f"CRÍTICO ({dias_para_vencer} días): Ante la {vel_msg}, se proyecta una pérdida de {unidades_en_riesgo:.0f} unidades antes del vencimiento.")
        recs.append("RECOMENDACIÓN: Ejecutar 'Venta a Empleados' o aplicar 'Ofertas Agresivas' (Flash Sales) para asegurar salida en los próximos 7 días.")
        if tendencia == "decreciente":
            recs.append("ADVERTENCIA: La tendencia de venta es a la baja, lo que agrava el riesgo de obsolescencia.")

    elif nivel_riesgo == "alto":
        recs.append(f"ALERTA PREVENTIVA: Se estima que {unidades_en_riesgo:.0f} unidades no rotarán a tiempo ({dias_para_vencer} días para vencer).")
        recs.append("ESTRATEGIA: Considerar traslados a sucursales de mayor tráfico o promociones tipo 'Amarre' con productos de mayor rotación.")
        if tendencia == "estable":
            recs.append("La rotación es constante pero insuficiente para agotar el stock actual; requiere impulso comercial.")

    elif nivel_riesgo == "medio":
        recs.append(f"MONITOREO ACTIVO: Exceso de stock moderado detectado para los próximos {dias_para_vencer} días.")
        recs.append("ACCIÓN: Pausar nuevas órdenes de compra y priorizar este lote en el flujo de Picking (FEFO).")
        if tendencia == "creciente":
            recs.append("La demanda está subiendo; el riesgo podría mitigarse naturalmente si la tendencia persiste.")

    if not recs:
        recs.append("INVENTARIO SALUDABLE: La proyección indica que el stock se agotará naturalmente antes del vencimiento.")

    return recs


# ── Motor principal ────────────────────────────────────────────────────────────

def analyze_product(prod: dict, today: date) -> dict:
    nombre          = prod.get("nombre", "?")
    producto_id     = prod.get("producto_id")
    lote            = prod.get("lote")
    fecha_venc_str  = prod.get("fecha_vencimiento")
    stock_actual    = float(prod.get("stock_actual", 0))
    consumo_hist    = [float(x) for x in prod.get("consumo_historico", [])]

    # ── Calcular días para vencer ─────────────────────────────────────────────
    dias_para_vencer = 9999
    if fecha_venc_str:
        try:
            fv = datetime.strptime(fecha_venc_str, "%Y-%m-%d").date()
            dias_para_vencer = (fv - today).days
        except ValueError:
            pass

    # ── Consumo diario con EMA ────────────────────────────────────────────────
    consumo_diario = ema(consumo_hist) if consumo_hist else 0.0
    reg            = linear_regression(consumo_hist) if len(consumo_hist) >= 2 else {"slope": 0.0, "r2": 0.0}

    tendencia = "estable"
    if reg["slope"] > 0.05:
        tendencia = "creciente"
    elif reg["slope"] < -0.05:
        tendencia = "decreciente"

    # ── Días hasta agotamiento ────────────────────────────────────────────────
    dias_agotamiento = (stock_actual / consumo_diario) if consumo_diario > 0 else 99999.0

    # ── Proyección de consumo en el período hasta vencimiento ─────────────────
    if dias_para_vencer < 9999 and dias_para_vencer > 0:
        proyeccion = project_future_demand(consumo_hist, min(dias_para_vencer, 90))
        consumo_proyectado = sum(proyeccion)
    else:
        consumo_proyectado = consumo_diario * min(dias_para_vencer, 90) if dias_para_vencer < 9999 else 0.0

    unidades_en_riesgo = max(0.0, stock_actual - consumo_proyectado)

    # ── Clasificación de riesgo ───────────────────────────────────────────────
    nivel_riesgo = classify_risk(dias_para_vencer, dias_agotamiento)

    # ── Confianza del modelo ──────────────────────────────────────────────────
    confianza = confidence_score(consumo_hist, reg)
    if len(consumo_hist) < DIAS_MIN_DATA:
        confianza *= 0.5   # penalizar si hay pocos datos

    # ── Recomendaciones ───────────────────────────────────────────────────────
    recomendaciones = build_recommendations(
        prod, nivel_riesgo, round(unidades_en_riesgo, 2),
        dias_para_vencer, consumo_diario, tendencia
    )

    return {
        "producto_id":          producto_id,
        "nombre":               nombre,
        "lote":                 lote,
        "fecha_vencimiento":    fecha_venc_str,
        "stock_actual":         round(stock_actual, 2),
        "consumo_diario":       round(consumo_diario, 4),
        "dias_agotamiento":     round(dias_agotamiento, 1) if dias_agotamiento < 9999 else None,
        "dias_para_vencer":     dias_para_vencer if dias_para_vencer < 9999 else None,
        "consumo_proyectado":   round(consumo_proyectado, 2),
        "unidades_en_riesgo":   round(unidades_en_riesgo, 2),
        "nivel_riesgo":         nivel_riesgo,
        "tendencia_demanda":    tendencia,
        "confianza":            confianza,
        "recomendaciones":      recomendaciones,
        "serie_consumo":        consumo_hist[-30:],     # últimos 30 días para sparklines
        "pendiente_regresion":  reg.get("slope", 0.0),
        "r2":                   reg.get("r2", 0.0),
    }


def run():
    try:
        # Leer entrada
        raw = sys.stdin.read().strip()
        if not raw:
            print(json.dumps({"error": True, "message": "Sin datos de entrada para el motor ML"}))
            return

        try:
            payload = json.loads(raw)
        except json.JSONDecodeError as e:
            print(json.dumps({"error": True, "message": f"Formato entrada inválido: {e}"}))
            return

        productos = payload.get("productos", [])
        if not productos:
             print(json.dumps({"error": False, "predictions": [], "message": "No hay productos para analizar."}))
             return

        today = date.today()
        predictions = [analyze_product(p, today) for p in productos]

        # ── Resumen ejecutivo ─────────────────────────────────────────────────────
        conteo = {"vencido": 0, "critico": 0, "alto": 0, "medio": 0, "bajo": 0}
        total_unidades_riesgo = 0.0
        for p in predictions:
            nivel = p["nivel_riesgo"]
            conteo[nivel] = conteo.get(nivel, 0) + 1
            total_unidades_riesgo += p["unidades_en_riesgo"]

        # Top 5 más urgentes
        urgentes = sorted(
            [p for p in predictions if p["nivel_riesgo"] in ("vencido", "critico", "alto")],
            key=lambda x: (
                {"vencido": 0, "critico": 1, "alto": 2}.get(x["nivel_riesgo"], 9),
                x["dias_para_vencer"] or 9999
            )
        )[:5]

        result = {
            "error":            False,
            "fecha_analisis":   today.isoformat(),
            "empresa_id":       payload.get("empresa_id"),
            "sucursal_id":      payload.get("sucursal_id"),
            "total_productos":  len(predictions),
            "resumen":          conteo,
            "total_unidades_en_riesgo": round(total_unidades_riesgo, 2),
            "alertas_urgentes": urgentes,
            "predictions":      predictions,
            "model_info": {
                "algoritmo":   "EMA + Regresión Lineal + Clasificación de Riesgo",
                "alpha_ema":   ALPHA_EMA,
                "version":     "1.1",
                "descripcion": (
                    "EMA para consumo diario ponderado (reciente > antiguo), "
                    "regresión lineal para tendencia, "
                    "clasificación de riesgo basada en días_vencimiento vs días_agotamiento."
                )
            }
        }

        print(json.dumps(result, ensure_ascii=False, default=str))

    except Exception as e:
        # Cualquier error interno se reporta como JSON para que PHP lo entienda
        print(json.dumps({"error": True, "message": f"Error interno motor ML: {str(e)}"}))


if __name__ == "__main__":
    run()
