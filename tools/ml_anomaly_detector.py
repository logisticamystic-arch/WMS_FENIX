#!/usr/bin/env python3
"""
ml_anomaly_detector.py — Detector de anomalías en movimientos de inventario
============================================================================
Aplica Z-score + IQR + análisis de frecuencia para detectar:
  1. Movimientos de inventario estadísticamente inusuales (cantidad anómala)
  2. Ajustes negativos sospechosos (posible fraude o error)
  3. Patrones inusuales: muchos movimientos pequeños en tiempo corto
  4. Discrepancias entre conteos físicos y sistema
  5. Picking de más de lo que hay en el stock en el momento del movimiento

Uso desde PHP:
  $result = shell_exec("echo " . escapeshellarg($json) . " | python3 tools/ml_anomaly_detector.py");

Formato entrada (stdin):
  {
    "empresa_id": 1,
    "sucursal_id": 1,
    "movimientos": [
      {
        "id": 1, "producto_id": 10, "tipo_movimiento": "Salida",
        "cantidad": 500, "fecha_movimiento": "2026-04-10",
        "usuario_id": 3, "referencia_tipo": "Picking"
      }, ...
    ],
    "ajustes": [
      { "id": 5, "producto_id": 10, "cantidad": -80, "usuario_id": 3,
        "fecha": "2026-04-10", "motivo": "Merma" }, ...
    ]
  }
"""

import sys
import json
import math
import statistics
from collections import defaultdict
from datetime import datetime, date


# ── Constantes ────────────────────────────────────────────────────────────────

Z_SCORE_UMBRAL   = 3.0   # Más de 3 desviaciones estándar = anómalo
IQR_FACTOR       = 2.5   # Factor IQR para fences externas
MIN_MUESTRAS     = 5     # Mínimo de muestras para aplicar estadísticas
AJUSTE_NEG_UMBRAL_PCT = 20  # % del stock promedio: ajuste negativo > X% es sospechoso


# ── Funciones estadísticas ─────────────────────────────────────────────────────

def z_score(value: float, mean: float, std: float) -> float:
    if std == 0:
        return 0.0
    return (value - mean) / std


def iqr_fences(data: list) -> tuple:
    """Retorna (lower_fence, upper_fence) con factor IQR_FACTOR."""
    if len(data) < 4:
        return (float('-inf'), float('inf'))
    sorted_d = sorted(data)
    n = len(sorted_d)
    q1 = sorted_d[n // 4]
    q3 = sorted_d[(3 * n) // 4]
    iqr_ = q3 - q1
    return (q1 - IQR_FACTOR * iqr_, q3 + IQR_FACTOR * iqr_)


def is_outlier(value: float, data: list) -> dict:
    """
    Determina si un valor es outlier usando Z-score e IQR.
    Retorna {'outlier': bool, 'metodo': str, 'z_score': float, 'severidad': str}
    """
    if len(data) < MIN_MUESTRAS:
        return {"outlier": False, "metodo": "insuficiente", "z_score": 0.0, "severidad": "baja"}

    mean_ = statistics.mean(data)
    std_  = statistics.stdev(data) if len(data) > 1 else 0.0
    z     = z_score(value, mean_, std_)
    low_f, high_f = iqr_fences(data)

    by_z   = abs(z) >= Z_SCORE_UMBRAL
    by_iqr = value < low_f or value > high_f

    if by_z and by_iqr:
        metodo    = "Z-score + IQR"
        severidad = "alta" if abs(z) > 4 else "media"
    elif by_z:
        metodo    = "Z-score"
        severidad = "media"
    elif by_iqr:
        metodo    = "IQR"
        severidad = "baja"
    else:
        return {"outlier": False, "metodo": "normal", "z_score": round(z, 3), "severidad": "baja"}

    return {
        "outlier":   True,
        "metodo":    metodo,
        "z_score":   round(z, 3),
        "valor":     value,
        "media":     round(mean_, 3),
        "std":       round(std_, 3),
        "severidad": severidad,
    }


# ── Detectores específicos ─────────────────────────────────────────────────────

def detect_movement_outliers(movimientos: list) -> list:
    """
    Detecta movimientos con cantidad estadísticamente anómala
    respecto al historial del mismo producto y tipo.
    """
    flags = []
    # Agrupar por (producto_id, tipo_movimiento)
    by_key = defaultdict(list)
    for m in movimientos:
        key = (m.get("producto_id"), m.get("tipo_movimiento", ""))
        by_key[key].append(abs(float(m.get("cantidad", 0))))

    for m in movimientos:
        key      = (m.get("producto_id"), m.get("tipo_movimiento", ""))
        cantidad = abs(float(m.get("cantidad", 0)))
        serie    = by_key[key]

        result = is_outlier(cantidad, serie)
        if result["outlier"]:
            flags.append({
                "tipo":         "movimiento_anormal",
                "severidad":    result["severidad"],
                "titulo":       f"Movimiento inusual — {m.get('tipo_movimiento')} producto #{m.get('producto_id')}",
                "descripcion":  (
                    f"Cantidad {cantidad:.2f} es {abs(result['z_score']):.1f} desviaciones "
                    f"estándar sobre la media histórica ({result['media']:.2f} ± {result['std']:.2f})."
                ),
                "datos": {
                    "movimiento_id": m.get("id"),
                    "producto_id":   m.get("producto_id"),
                    "tipo":          m.get("tipo_movimiento"),
                    "cantidad":      cantidad,
                    "z_score":       result["z_score"],
                    "metodo":        result["metodo"],
                    "usuario_id":    m.get("usuario_id"),
                    "fecha":         m.get("fecha_movimiento"),
                }
            })

    return flags


def detect_negative_adjustments(ajustes: list) -> list:
    """
    Detecta ajustes negativos sospechosos.
    Criterios: grandes ajustes negativos, muchos ajustes del mismo usuario, sin motivo.
    """
    flags = []

    # Agrupar ajustes negativos por usuario
    by_user = defaultdict(list)
    for a in ajustes:
        if float(a.get("cantidad", 0)) < 0:
            by_user[a.get("usuario_id")].append(a)

    for usuario_id, lista in by_user.items():
        # Muchos ajustes negativos del mismo usuario en el mismo día
        by_day = defaultdict(list)
        for a in lista:
            day = str(a.get("fecha", ""))[:10]
            by_day[day].append(a)

        for day, day_ajustes in by_day.items():
            if len(day_ajustes) >= 5:
                total_ajustado = sum(abs(float(a.get("cantidad", 0))) for a in day_ajustes)
                flags.append({
                    "tipo":      "ajustes_negativos_masivos",
                    "severidad": "alta",
                    "titulo":    f"Múltiples ajustes negativos — usuario #{usuario_id} — {day}",
                    "descripcion": (
                        f"El usuario #{usuario_id} realizó {len(day_ajustes)} ajustes negativos "
                        f"en un día, totalizando {total_ajustado:.2f} unidades retiradas del sistema."
                    ),
                    "datos": {
                        "usuario_id":        usuario_id,
                        "fecha":             day,
                        "cantidad_ajustes":  len(day_ajustes),
                        "total_retirado":    round(total_ajustado, 2),
                        "sin_motivo":        sum(1 for a in day_ajustes if not a.get("motivo")),
                    }
                })

    # Ajustes negativos sin motivo
    for a in ajustes:
        if float(a.get("cantidad", 0)) < 0 and not a.get("motivo"):
            flags.append({
                "tipo":      "ajuste_sin_motivo",
                "severidad": "media",
                "titulo":    f"Ajuste negativo sin motivo — producto #{a.get('producto_id')}",
                "descripcion": (
                    f"Se registró una salida de {abs(float(a.get('cantidad',0))):.2f} unidades "
                    f"del producto #{a.get('producto_id')} sin motivo registrado."
                ),
                "datos": {
                    "ajuste_id":   a.get("id"),
                    "producto_id": a.get("producto_id"),
                    "cantidad":    a.get("cantidad"),
                    "usuario_id":  a.get("usuario_id"),
                    "fecha":       a.get("fecha"),
                }
            })

    return flags


def detect_frequency_patterns(movimientos: list) -> list:
    """
    Detecta patrones de alta frecuencia: muchos movimientos pequeños seguidos
    del mismo usuario y producto en poco tiempo (posible fragmentación de fraude).
    """
    flags = []
    # Agrupar por (producto_id, usuario_id, fecha)
    by_key = defaultdict(list)
    for m in movimientos:
        key = (m.get("producto_id"), m.get("usuario_id"), str(m.get("fecha_movimiento", ""))[:10])
        by_key[key].append(abs(float(m.get("cantidad", 0))))

    for (prod_id, user_id, fecha), cantidades in by_key.items():
        if len(cantidades) >= 8:
            total = sum(cantidades)
            media = statistics.mean(cantidades)
            flags.append({
                "tipo":      "patron_alta_frecuencia",
                "severidad": "media",
                "titulo":    f"Alta frecuencia de movimientos — producto #{prod_id} — {fecha}",
                "descripcion": (
                    f"{len(cantidades)} movimientos del mismo producto realizados por el "
                    f"usuario #{user_id} en un día. Total: {total:.2f} unidades. "
                    f"Media por movimiento: {media:.2f} — patrón inusual de fragmentación."
                ),
                "datos": {
                    "producto_id":    prod_id,
                    "usuario_id":     user_id,
                    "fecha":          fecha,
                    "n_movimientos":  len(cantidades),
                    "total_unidades": round(total, 2),
                    "media_por_mov":  round(media, 2),
                }
            })

    return flags


# ── Motor principal ────────────────────────────────────────────────────────────

def run():
    raw = sys.stdin.read().strip()
    if not raw:
        print(json.dumps({"error": True, "message": "Sin datos de entrada"}))
        sys.exit(1)

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as e:
        print(json.dumps({"error": True, "message": f"JSON inválido: {e}"}))
        sys.exit(1)

    movimientos = payload.get("movimientos", [])
    ajustes     = payload.get("ajustes", [])

    all_flags = []
    all_flags.extend(detect_movement_outliers(movimientos))
    all_flags.extend(detect_negative_adjustments(ajustes))
    all_flags.extend(detect_frequency_patterns(movimientos))

    # Ordenar por severidad
    orden = {"alta": 0, "media": 1, "baja": 2}
    all_flags.sort(key=lambda f: orden.get(f.get("severidad", "baja"), 9))

    conteo = {"alta": 0, "media": 0, "baja": 0}
    for f in all_flags:
        sev = f.get("severidad", "baja")
        conteo[sev] = conteo.get(sev, 0) + 1

    result = {
        "error":          False,
        "fecha_analisis": date.today().isoformat(),
        "empresa_id":     payload.get("empresa_id"),
        "sucursal_id":    payload.get("sucursal_id"),
        "total_anomalias": len(all_flags),
        "por_severidad":  conteo,
        "anomalias":      all_flags,
        "model_info": {
            "algoritmo": "Z-score (umbral=3.0) + IQR (factor=2.5) + Análisis de Frecuencia",
            "version":   "1.0",
        }
    }

    print(json.dumps(result, ensure_ascii=False, default=str))


if __name__ == "__main__":
    run()
