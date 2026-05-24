#!/usr/bin/env python3
"""
ml_expiry_predictor.py — Motor ML Profesional de Predicción de Vencimientos
==============================================================================
Sistema de inteligencia para Restaurante Olivia & Clap Burger.

Capacidades:
  1. EMA (Exponential Moving Average) — consumo diario ponderado por recencia
  2. Regresión lineal — tendencia de demanda (creciente/decreciente/estable)
  3. Categorización inteligente de producto — entiende qué es cada insumo
  4. Análisis de festivos colombianos y eventos de alta demanda
  5. Routing específico por punto de venta (Olivia / Clap Burger / Empleados)
  6. Dictamen profesional personalizado por producto, no genérico
"""

import sys
import io
import json
import math
import statistics
from datetime import datetime, date, timedelta

# Forzar UTF-8 en stdout para evitar errores con caracteres especiales en Windows
if hasattr(sys.stdout, 'buffer'):
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')


# ── Constantes ────────────────────────────────────────────────────────────────

ALPHA_EMA      = 0.3    # Factor de suavizado EMA (0=más suave, 1=más reactivo)
DIAS_MIN_DATA  = 7      # Mínimo de días para predicción confiable
UMBRAL_CRITICO = 30
UMBRAL_ALTO    = 60
UMBRAL_MEDIO   = 90


# ── Funciones ML ──────────────────────────────────────────────────────────────

def ema(series: list, alpha: float = ALPHA_EMA) -> float:
    if not series:
        return 0.0
    val = float(series[0])
    for x in series[1:]:
        val = alpha * float(x) + (1 - alpha) * val
    return val


def linear_regression(series: list) -> dict:
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
    if not series:
        return [0.0] * dias_futuro
    reg   = linear_regression(series)
    base  = ema(series)
    proyeccion = []
    for i in range(1, dias_futuro + 1):
        valor = base + reg["slope"] * i
        proyeccion.append(max(0.0, round(valor, 4)))
    return proyeccion


def confidence_score(series: list, reg: dict) -> float:
    n = len(series)
    if n == 0:
        return 0.0
    factor_datos = min(n / 30, 1.0)
    factor_r2    = reg.get("r2", 0.0)
    mean_ = statistics.mean(series) if series else 0
    std_  = statistics.stdev(series) if len(series) > 1 else 0
    cv    = (std_ / mean_) if mean_ > 0 else 1.0
    factor_cv = max(0.0, 1.0 - min(cv, 1.0))
    confianza = (0.4 * factor_datos + 0.35 * factor_r2 + 0.25 * factor_cv)
    return round(min(1.0, max(0.0, confianza)), 4)


def classify_risk(dias_para_vencer: int, dias_agotamiento: float) -> str:
    if dias_para_vencer <= 0:
        return "vencido"
    if dias_para_vencer < UMBRAL_CRITICO:
        return "critico"
    if dias_para_vencer <= UMBRAL_ALTO:
        return "alto"
    if dias_para_vencer <= UMBRAL_MEDIO:
        return "medio"
    if dias_agotamiento <= dias_para_vencer:
        return "bajo"
    exceso_dias = dias_agotamiento - dias_para_vencer
    if exceso_dias > 30:
        return "alto"
    if exceso_dias > 0:
        return "medio"
    return "bajo"


# ── Inteligencia de Negocio — Restaurante Olivia & Clap Burger ───────────────

def categorize_product(nombre: str) -> dict:
    """
    Clasifica el producto dentro del contexto de Restaurante Olivia y Clap Burger.
    Retorna categoría, outlets pertinentes y perfil de manejo.
    """
    n = nombre.lower()
    first_word = n.split()[0] if n else ''

    # ── PRIMERO: Empaques y materiales operativos (NO alimentos) ─────────────
    # Si el nombre COMIENZA con un identificador de empaque, es material operativo.
    # Esto evita clasificar "CAJA PIZZA" como ingrediente de pizza.
    _empaque_inicio = (
        'caja', 'bolsa', 'bolsas', 'empaque', 'empaques', 'embalaje',
        'rollo', 'manga', 'papel', 'servilleta', 'servilletas',
        'desechable', 'desechables', 'guante', 'guantes', 'bandeja',
        'carton', 'cartón', 'tapa ', 'tapas', 'envase', 'envases',
        'precinto', 'etiqueta', 'stretch', 'film', 'palillo', 'cubierto',
    )
    if any(n.startswith(ek.strip()) for ek in _empaque_inicio) or \
       first_word in [e.strip() for e in _empaque_inicio]:
        return {
            'categoria': 'Empaque / Material Operativo',
            'outlets': ['Almacén Central - Materiales', 'Jefe de Producción'],
            'outlet_primario': 'Producción',
            'sensibilidad': 'baja',
            'aplica_oferta': False,
            'aplica_menu_dia': False,
            'es_empaque': True,
            'nota': 'material de empaque o suministro operativo — NO es un alimento',
            'estrategia_rapida': 'Priorizar uso en producción corriente antes de la fecha límite del proveedor'
        }

    if any(k in n for k in ['pizza', 'masa pizza', 'pepperoni', 'mozzarella', 'oregano', 'orégano']):
        return {
            'categoria': 'Masa / Pizza',
            'outlets': ['Olivia - Horno de Pizzas', 'Clap Burger - Menú Pizza Especial'],
            'outlet_primario': 'Olivia',
            'sensibilidad': 'alta',
            'aplica_oferta': False,
            'aplica_menu_dia': True,
            'nota': 'insumo base para pizzas artesanales',
            'estrategia_rapida': 'Activar en menú del día o pizza especial sin costo adicional de publicidad'
        }

    if any(k in n for k in ['carne', 'res', 'burger', 'hamburguesa', 'pollo', 'chicken',
                              'cerdo', 'tocino', 'bacon', 'proteina', 'proteína', 'costilla']):
        return {
            'categoria': 'Proteína / Carne',
            'outlets': ['Clap Burger - Línea de Hamburguesas', 'Olivia - Platos Fuertes del Día'],
            'outlet_primario': 'Clap Burger',
            'sensibilidad': 'muy alta',
            'aplica_oferta': True,
            'aplica_menu_dia': True,
            'nota': 'proteína principal — degradación acelerada en calor',
            'estrategia_rapida': 'Lanzar "Burger del Día" en Clap o "Plato Especial" en Olivia para rotar stock'
        }

    if any(k in n for k in ['pan', 'bun', 'hogaza', 'brioche', 'bollería', 'bollos',
                              'almendra', 'bread', 'pane', 'focaccia', 'ciabatta']):
        return {
            'categoria': 'Panadería / Masas',
            'outlets': ['Clap Burger - Buns de Hamburguesa', 'Olivia - Panes Artesanales'],
            'outlet_primario': 'Clap Burger',
            'sensibilidad': 'alta',
            'aplica_oferta': False,
            'aplica_menu_dia': True,
            'nota': 'insumo de presentación — impacta calidad percibida',
            'estrategia_rapida': 'Rotar como complemento de combos en Clap Burger; no aplica descuento directo'
        }

    if any(k in n for k in ['leche', 'queso', 'yogur', 'crema', 'mantequilla',
                              'butter', 'lacteo', 'lácteo', 'kumis', 'suero']):
        return {
            'categoria': 'Lácteos',
            'outlets': ['Olivia - Cocina Central (salsas/postres)', 'Clap Burger - Complementos'],
            'outlet_primario': 'Olivia',
            'sensibilidad': 'muy alta',
            'aplica_oferta': True,
            'aplica_menu_dia': True,
            'nota': 'cadena de frío crítica — rotar en las próximas horas',
            'estrategia_rapida': 'Incorporar a salsas del día o postres especiales en Olivia de forma inmediata'
        }

    if any(k in n for k in ['tomate', 'lechuga', 'cebolla', 'pimentón', 'pimenton',
                              'aguacate', 'pepino', 'zanahoria', 'verdura', 'vegetal',
                              'ensalada', 'cilantro', 'perejil', 'limón', 'limon', 'sopa']):
        return {
            'categoria': 'Vegetales / Frescos',
            'outlets': ['Olivia - Ensaladas y Guarniciones', 'Clap Burger - Toppings Frescos'],
            'outlet_primario': 'Olivia',
            'sensibilidad': 'muy alta',
            'aplica_oferta': True,
            'aplica_menu_dia': True,
            'nota': 'producto perecedero — ventana de acción máximo 24-48h',
            'estrategia_rapida': 'Activar como topping extra sin costo o en ensalada del día en Olivia'
        }

    if any(k in n for k in ['jugo', 'bebida', 'refresco', 'agua', 'gaseosa',
                              'cerveza', 'vino', 'licor', 'soda', 'té', 'te', 'limonada']):
        return {
            'categoria': 'Bebidas',
            'outlets': ['Olivia - Servicio de Mesa / Bar', 'Clap Burger - Combos y Bebidas'],
            'outlet_primario': 'Olivia',
            'sensibilidad': 'media',
            'aplica_oferta': True,
            'aplica_menu_dia': False,
            'nota': 'alta rotación en servicio de mesa — sensible a temporadas',
            'estrategia_rapida': 'Incluir en combo 2x1 o bebida del combo sin costo adicional'
        }

    # ── Dulces de mesa / repostería de origen antes de "salsa" genérica ──────
    # "salsa de arequipe", "arequipe", "dulce de leche" son productos de repostería,
    # no salsas de acompañamiento. Deben ir a carta de postres, no a salsas de mesa.
    if any(k in n for k in ['arequipe', 'dulce de leche', 'manjar']):
        return {
            'categoria': 'Dulces / Repostería',
            'outlets': ['Olivia - Carta de Postres', 'Clap Burger - Postres y Complementos Dulces'],
            'outlet_primario': 'Olivia',
            'sensibilidad': 'media',
            'aplica_oferta': True,
            'aplica_menu_dia': True,
            'es_empaque': False,
            'nota': 'dulce colombiano — uso en postres, rellenos y decoración de repostería',
            'estrategia_rapida': 'Incorporar en postre del día o relleno de repostería en Olivia; oferta especial dessert en Clap'
        }

    if any(k in n for k in ['salsa', 'ketchup', 'mostaza', 'mayonesa', 'vinagre',
                              'aceite', 'aderez', 'aderezo', 'condimento', 'chile', 'sriracha']):
        return {
            'categoria': 'Condimentos / Salsas',
            'outlets': ['Clap Burger - Salsas de Mesa', 'Olivia - Cocina (preparaciones)'],
            'outlet_primario': 'Clap Burger',
            'sensibilidad': 'media',
            'aplica_oferta': False,
            'aplica_menu_dia': True,
            'nota': 'insumo de apoyo en línea de producción — alto volumen por uso diario',
            'estrategia_rapida': 'Incrementar uso en preparaciones de fondo; no requiere acción comercial directa'
        }

    if any(k in n for k in ['postre', 'helado', 'torta', 'dulce', 'chocolate',
                              'azúcar', 'azucar', 'galleta', 'brownie', 'mousse', 'flan',
                              'tiramisu', 'tiramisú', 'panna cotta', 'pannacotta',
                              'cheesecake', 'mermelada', 'caramelo', 'natilla']):
        return {
            'categoria': 'Postres / Repostería',
            'outlets': ['Olivia - Carta de Postres', 'Clap Burger - Menú Dulce'],
            'outlet_primario': 'Olivia',
            'sensibilidad': 'media',
            'aplica_oferta': True,
            'aplica_menu_dia': True,
            'nota': 'alta demanda en festivos y fines de semana',
            'estrategia_rapida': 'Postre del día o postre gratis al pedir plato fuerte — convierte sin descuento directo'
        }

    if any(k in n for k in ['harina', 'arroz', 'pasta', 'fideos', 'cereal', 'avena',
                              'lenteja', 'frijol', 'garbanzo', 'maíz', 'maiz']):
        return {
            'categoria': 'Granos / Carbohidratos',
            'outlets': ['Olivia - Cocina Central', 'Clap Burger - Guarniciones'],
            'outlet_primario': 'Olivia',
            'sensibilidad': 'baja',
            'aplica_oferta': False,
            'aplica_menu_dia': True,
            'nota': 'larga vida útil relativa — revisar si el riesgo es real o de datos',
            'estrategia_rapida': 'Incorporar como guarnición en menú del día para acelerar consumo'
        }

    # Default
    return {
        'categoria': 'Insumo General',
        'outlets': ['Olivia', 'Clap Burger'],
        'outlet_primario': 'Olivia',
        'sensibilidad': 'normal',
        'aplica_oferta': True,
        'aplica_menu_dia': True,
        'nota': 'insumo operativo general',
        'estrategia_rapida': 'Evaluar uso en preparaciones de fondo o como complemento de menú del día'
    }


def nth_weekday(year: int, month: int, weekday: int, n: int) -> date:
    """Returns the nth occurrence of weekday (0=Mon … 6=Sun) in the given year/month."""
    first = date(year, month, 1)
    days_ahead = (weekday - first.weekday()) % 7
    return first + timedelta(days=days_ahead + 7 * (n - 1))


def get_upcoming_events(today: date, dias_horizonte: int) -> list:
    """
    Retorna festivos colombianos y eventos de alta demanda para restaurantes
    dentro del horizonte de vencimiento del producto.
    Incluye factores de demanda diferenciados para Olivia & Clap Burger.
    """
    events = []
    end_date = today + timedelta(days=max(dias_horizonte, 14))
    year = today.year

    # Festivos fijos colombianos + eventos restaurante
    fixed = [
        # (mes, dia, nombre, factor_olivia, factor_clap, descripcion_operativa)
        (1,  1,  "Año Nuevo",                  2.8, 2.2, "Cierre de año con reservas completas. Pico máximo del año."),
        (2,  14, "San Valentín",               2.5, 1.4, "Olivia: reservas completas toda la semana. Clap: combos pareja."),
        (3,  8,  "Día de la Mujer",             1.6, 1.3, "Alta demanda en Olivia. Oportunidad de menús especiales."),
        (4,  1,  "Semana Santa (aprox.)",       1.5, 1.2, "Restricción de carnes algunos días. Pico de vegetales y pescado."),
        (5,  1,  "Día del Trabajo",             1.4, 1.5, "Festivo con salida masiva. Clap Burger supera a Olivia en tráfico."),
        (7,  20, "Independencia de Colombia",  1.5, 1.4, "Festivo nacional. Alto tráfico en ambos conceptos."),
        (8,  7,  "Batalla de Boyacá",           1.4, 1.3, "Festivo. Afluencia moderada-alta."),
        (10, 31, "Halloween",                   1.7, 2.0, "Clap Burger supera en eventos nocturnos. Olivia: menú temático."),
        (12, 8,  "Inmaculada Concepción",       1.4, 1.2, "Inicio de temporada navideña. Demanda de postres y bebidas sube."),
        (12, 16, "Inicio Novenas de Aguinaldo", 1.6, 1.4, "Temporada alta navideña arranca. Impacto en bebidas y postres."),
        (12, 24, "Nochebuena",                  2.5, 1.8, "Olivia: reservas agotadas. Clap: tráfico familiar alto."),
        (12, 25, "Navidad",                     2.2, 1.6, "Alto tráfico post celebración. Pico en bebidas."),
        (12, 31, "Fin de Año",                  2.8, 2.0, "Pico máximo del año junto a Año Nuevo."),
    ]

    for month, day, name, factor_o, factor_c, desc in fixed:
        for yr in [year, year + 1]:
            try:
                d = date(yr, month, day)
                if today < d <= end_date:
                    dias_hasta = (d - today).days
                    events.append({
                        'fecha': d.isoformat(),
                        'nombre': name,
                        'dias_hasta': dias_hasta,
                        'factor_olivia': factor_o,
                        'factor_clap': factor_c,
                        'factor_promedio': round((factor_o + factor_c) / 2, 2),
                        'descripcion': desc,
                        'tipo': 'festivo'
                    })
            except ValueError:
                pass

    # Festivos de fecha variable (calculados dinámicamente)
    # Día de la Madre Colombia = 2do domingo de mayo
    # Día del Padre Colombia   = 3er domingo de junio
    for yr in [year, year + 1]:
        variable = [
            (nth_weekday(yr, 5, 6, 2), "Día de la Madre",
             2.2, 1.6, "Segundo pico del año en Olivia. Reservas anticipadas semanas antes."),
            (nth_weekday(yr, 6, 6, 3), "Día del Padre",
             1.6, 1.8, "Clap Burger tiene pico igual o superior a Olivia por tipo de menú."),
        ]
        for d, name, factor_o, factor_c, desc in variable:
            if today < d <= end_date:
                dias_hasta = (d - today).days
                events.append({
                    'fecha': d.isoformat(),
                    'nombre': name,
                    'dias_hasta': dias_hasta,
                    'factor_olivia': factor_o,
                    'factor_clap': factor_c,
                    'factor_promedio': round((factor_o + factor_c) / 2, 2),
                    'descripcion': desc,
                    'tipo': 'festivo'
                })

    # Análisis de fines de semana (Vie-Dom = +40% vs. días hábiles)
    weekends = 0
    d = today + timedelta(days=1)
    while d <= end_date:
        if d.weekday() >= 4:  # Viernes=4, Sábado=5, Domingo=6
            weekends += 1
        d += timedelta(days=1)

    if weekends > 0:
        events.append({
            'nombre': f'Fines de Semana ({weekends} días Vie-Dom)',
            'dias_hasta': 1,
            'factor_olivia': 1.45,
            'factor_clap': 1.40,
            'factor_promedio': 1.42,
            'descripcion': f'{weekends} días de fin de semana en el horizonte. Tráfico +40% vs días hábiles en ambos conceptos.',
            'tipo': 'finsemana'
        })

    return sorted(events, key=lambda x: x['dias_hasta'])


def build_recommendations(prod: dict, nivel_riesgo: str, unidades_en_riesgo: float,
                           dias_para_vencer: int, consumo_diario: float,
                           tendencia: str, consumo_proyectado: float) -> list:
    """
    Genera un dictamen profesional específico para cada producto,
    considerando su categoría, consumo real, festivos próximos y
    routing específico a Restaurante Olivia y Clap Burger.
    """
    nombre      = prod.get("nombre", "Producto")
    stock_actual = float(prod.get("stock_actual", 0))
    today        = date.today()
    cat          = categorize_product(nombre)
    categoria    = cat['categoria']
    outlets      = cat['outlets']
    outlet_prim  = cat['outlet_primario']
    estrategia   = cat['estrategia_rapida']
    nota_cat     = cat['nota']
    es_empaque   = cat.get('es_empaque', False)

    pct_riesgo   = round((unidades_en_riesgo / stock_actual * 100), 1) if stock_actual > 0 else 0
    eventos      = get_upcoming_events(today, max(dias_para_vencer or 7, 7))
    ev_proximo   = next((e for e in eventos if e['tipo'] == 'festivo'), None)
    ev_semana    = next((e for e in eventos if e['tipo'] == 'finsemana'), None)

    recs = []

    # ── EMPAQUE / MATERIAL OPERATIVO (no alimento) ───────────────────────────
    if es_empaque:
        if nivel_riesgo in ('vencido', 'critico', 'alto'):
            urgencia = "VENCIDO" if nivel_riesgo == 'vencido' else f"{dias_para_vencer} días para vencer"
            recs.append(
                f"ALERTA MATERIAL OPERATIVO — {nombre} | {urgencia}\n"
                f"IMPORTANTE: Este es un EMPAQUE o SUMINISTRO OPERATIVO, no un alimento. "
                f"El vencimiento indica la vida útil del material según el proveedor, "
                f"no un riesgo de inocuidad alimentaria.\n"
                f"Stock: {stock_actual:.0f} unidades. Acción: usar prioritariamente en producción."
            )
            recs.append(
                f"INSPECCION FISICA REQUERIDA: Verificar condición del material "
                f"(humedad, deformación, contaminación). "
                f"Si está en buen estado: usar antes que lotes más nuevos (FEFO). "
                f"Si hay deterioro: gestionar baja con autorización del Supervisor de Calidad."
            )
            if nivel_riesgo in ('vencido', 'critico'):
                recs.append(
                    f"ACCION INMEDIATA: Comunicar a Jefe de Producción para priorizar estas "
                    f"{stock_actual:.0f} unidades en todos los despachos y empaques de los "
                    f"próximos {dias_para_vencer if nivel_riesgo != 'vencido' else 0} días. "
                    f"No requiere acción comercial ni de cocina — es gestión de inventario de materiales."
                )
        else:
            recs.append(
                f"MATERIAL OPERATIVO — {categoria}: {stock_actual:.0f} unidades con "
                f"{dias_para_vencer} días de vida útil restante según proveedor. "
                f"Rotación normal recomendada aplicando FEFO. Sin urgencia inmediata."
            )
        return recs

    # ── VENCIDO ──────────────────────────────────────────────────────────────
    if nivel_riesgo == "vencido":
        recs.append(
            f"PRODUCTO VENCIDO — ACCIÓN INMEDIATA: {nombre} ({categoria}) ha superado su fecha de vencimiento. "
            f"Stock afectado: {stock_actual:.0f} unidades. Impacto financiero: 100%."
        )
        recs.append(
            "PROTOCOLO WMS: Ejecutar baja física en sistema -> mover a ubicación de CUARENTENA -> "
            "registrar merma con código de causa -> notificar a Supervisor de Calidad."
        )
        recs.append(
            f"NOTA OPERATIVA: Verificar si hay lotes del mismo producto con fecha vigente en stock. "
            f"Este insumo ({nota_cat}) no puede permanecer en zona de picking."
        )
        return recs

    # ── CRÍTICO ──────────────────────────────────────────────────────────────
    if nivel_riesgo == "critico":
        if consumo_diario > 0:
            # CON historial: proyección real
            recs.append(
                f"DICTAMEN CRITICO — {categoria} ({dias_para_vencer} días): "
                f"Con consumo real de {consumo_diario:.1f} uds/día "
                f"se proyectan {consumo_proyectado:.0f} uds consumidas antes de vencer. "
                f"Excedente en riesgo: {unidades_en_riesgo:.0f} uds ({pct_riesgo:.0f}% del stock). "
                f"Pérdida financiera inminente si no se actúa hoy."
            )
            outlet_str = ' -> '.join(outlets)
            recs.append(
                f"RUTA DE DESPACHO URGENTE: {outlet_str}. "
                f"{estrategia}. Comunicar AHORA a Jefe de Cocina de {outlet_prim}."
            )
            # Festival con proyección real
            if ev_proximo and ev_proximo['factor_promedio'] > 1.3:
                factor = ev_proximo['factor_promedio']
                demanda_fest = consumo_diario * factor
                absorcion = min(demanda_fest * ev_proximo['dias_hasta'], unidades_en_riesgo)
                recs.append(
                    f"OPORTUNIDAD FESTIVA: {ev_proximo['nombre']} en {ev_proximo['dias_hasta']} día(s) "
                    f"(factor demanda x{factor}). Consumo proyectado con festivo: {demanda_fest:.1f} uds/día. "
                    f"Absorción potencial antes de vencer: {absorcion:.0f} uds. {ev_proximo['descripcion']}"
                )
            elif ev_semana:
                demanda_wknd = consumo_diario * ev_semana['factor_promedio']
                recs.append(
                    f"VENTANA DE FINES DE SEMANA: {ev_semana['descripcion']} "
                    f"Consumo proyectado en fin de semana: {demanda_wknd:.1f} uds/día "
                    f"(+{round((ev_semana['factor_promedio']-1)*100):.0f}% vs días hábiles)."
                )
            if tendencia == "decreciente":
                recs.append(
                    "AGRAVANTE — Tendencia DECRECIENTE: La demanda está bajando, lo que reduce "
                    "la probabilidad de salida natural. Evaluar si fue sustituido en carta o si hay "
                    "problema de visibilidad en cocina. Acción comercial inmediata es indispensable."
                )
            elif tendencia == "creciente":
                recs.append(
                    f"SEÑAL POSITIVA — Tendencia CRECIENTE: La demanda está subiendo. "
                    f"Si la tasa persiste, el riesgo podría reducirse. "
                    f"Monitorear diariamente los próximos {dias_para_vencer} días."
                )
        else:
            # SIN historial de consumo — dictamen honesto, no proyecciones falsas
            dias_label = f"{dias_para_vencer} día{'s' if dias_para_vencer != 1 else ''}"
            recs.append(
                f"ALERTA DE VENCIMIENTO — {categoria} | Vence en {dias_label} | "
                f"Stock: {stock_actual:.0f} unidades\n"
                f"SIN HISTORIAL DE CONSUMO: No se registraron salidas de este producto "
                f"en los últimos 30 días. No es posible proyectar demanda. "
                f"Cada unidad que no salga antes de vencer es pérdida directa."
            )
            # Festival como OPORTUNIDAD (sin proyección numérica falsa)
            if ev_proximo:
                recs.append(
                    f"VENTANA DE OPORTUNIDAD: {ev_proximo['nombre']} en "
                    f"{ev_proximo['dias_hasta']} día(s). "
                    f"Demanda histórica en {outlet_prim}: x{ev_proximo.get('factor_olivia' if outlet_prim == 'Olivia' else 'factor_clap', ev_proximo['factor_promedio'])} "
                    f"vs día normal. {ev_proximo['descripcion']} "
                    f"Coordinar despacho ANTES del festivo para aprovechar este pico."
                )
            elif ev_semana:
                recs.append(
                    f"VENTANA DE FIN DE SEMANA: {ev_semana['descripcion']} "
                    f"Tráfico +40% en ambos conceptos. Planificar salida del producto para este período."
                )

        # Acción táctica — diferenciada por tipo de producto
        outlet_str = ' -> '.join(outlets)
        if cat.get('aplica_oferta'):
            dias_urgencia = min(dias_para_vencer, 2) if consumo_diario == 0 else min(dias_para_vencer, 3)
            recs.append(
                f"ACCION URGENTE — {nombre}: Activar salida en {outlet_prim} en las próximas "
                f"{dias_urgencia * 24} horas. Opciones según tipo de producto ({nota_cat}): "
                f"(a) Incluir en menú especial o carta del día, "
                f"(b) Combo o acompañamiento sin cargo adicional, "
                f"(c) Oferta interna a empleados con autorización de supervisor."
            )
        else:
            dias_urgencia = min(dias_para_vencer, 2)
            recs.append(
                f"ACCION URGENTE — {nombre}: Incorporar en preparaciones de {outlet_prim} "
                f"en los próximos {dias_urgencia} turnos de cocina. "
                f"Contexto del producto: {nota_cat}. "
                f"Ruta sugerida: {outlet_str}. "
                f"Comunicar a Jefe de Cocina con descripción del uso operativo."
            )
        return recs

    # ── ALTO ─────────────────────────────────────────────────────────────────
    if nivel_riesgo == "alto":
        if consumo_diario > 0:
            recs.append(
                f"ALERTA PREVENTIVA — {categoria} ({dias_para_vencer} días): Proyección muestra excedente de "
                f"{unidades_en_riesgo:.0f} uds ({pct_riesgo:.0f}% del stock) que no rotará naturalmente. "
                f"Consumo actual: {consumo_diario:.1f} uds/día (tendencia: {tendencia})."
            )
        else:
            recs.append(
                f"ALERTA PREVENTIVA — {categoria} | {dias_para_vencer} días para vencer | "
                f"Stock: {stock_actual:.0f} uds\n"
                f"SIN HISTORIAL DE CONSUMO: No se puede proyectar demanda. "
                f"Se requiere plan de rotación activo. Producto: {nota_cat}."
            )

        outlet_str = ' / '.join(outlets)
        recs.append(
            f"ESTRATEGIA DE DISTRIBUCIÓN: Activar ruta {outlet_str}. "
            f"Recomendar traslado del {min(60, int(pct_riesgo))}% del excedente a la locación de mayor tráfico. "
            f"{estrategia}."
        )

        if ev_proximo:
            factor_o = ev_proximo['factor_olivia']
            factor_c = ev_proximo['factor_clap']
            recs.append(
                f"PLANIFICACION FESTIVA: {ev_proximo['nombre']} en {ev_proximo['dias_hasta']} día(s). "
                f"Factor de demanda: Olivia x{factor_o}, Clap Burger x{factor_c}. "
                f"{ev_proximo['descripcion']} — usar este evento para absorber el excedente."
            )

        if tendencia == "decreciente":
            recs.append(
                "REVISION DE CARTA: La demanda está bajando. Verificar si este insumo tiene visibilidad "
                f"en el menú actual de {outlet_prim}. Considerar 'Amarre' con producto de alta rotación."
            )
        elif tendencia == "estable" and consumo_diario > 0:
            recs.append(
                f"ACCION COMERCIAL: Rotación estable pero insuficiente para este stock. Incrementar "
                f"presencia en {outlet_prim} mediante menú del día o combo por los próximos 15 días."
            )

        return recs

    # ── MEDIO ─────────────────────────────────────────────────────────────────
    if nivel_riesgo == "medio":
        recs.append(
            f"MONITOREO ACTIVO — {categoria} ({dias_para_vencer} días): Excedente moderado de "
            f"{unidades_en_riesgo:.0f} uds detectado. Con consumo actual de {consumo_diario:.1f} uds/día "
            f"(tendencia {tendencia}), el riesgo es manejable si se actúa en los próximos 7 días."
        )
        recs.append(
            f"ACCION PREVENTIVA: Pausar nuevas OC de {nombre} hasta que el stock baje al 50%. "
            f"Priorizar este lote en flujo FEFO en {outlets[0]}."
        )

        if ev_proximo and ev_proximo['factor_promedio'] > 1.3:
            recs.append(
                f"OPORTUNIDAD: {ev_proximo['nombre']} en {ev_proximo['dias_hasta']} días puede "
                f"normalizar el inventario con factor x{ev_proximo['factor_promedio']}. "
                f"Planificar producción anticipada para el evento."
            )
        elif tendencia == "creciente":
            recs.append(
                "SEÑAL POSITIVA: Tendencia creciente — si persiste 10 días más, el inventario "
                "se resolverá sin intervención comercial. Revisar en próxima corrida ML."
            )

        return recs

    # ── BAJO / SALUDABLE ──────────────────────────────────────────────────────
    recs.append(
        f"INVENTARIO SALUDABLE — {categoria}: Proyección confirma rotación completa antes de vencer. "
        f"Consumo de {consumo_diario:.1f} uds/día (tendencia {tendencia}) es suficiente para agotar "
        f"el stock en tiempo. Sin acción requerida."
    )
    if ev_proximo and ev_proximo['factor_promedio'] > 1.3:
        recs.append(
            f"PLANIFICACION: {ev_proximo['nombre']} en {ev_proximo['dias_hasta']} días (x{ev_proximo['factor_promedio']}). "
            f"Verificar que el stock sea suficiente para cubrir la demanda del evento. "
            f"Si el stock es bajo, abrir OC preventiva para {outlet_prim}."
        )

    return recs


# ── Motor principal ────────────────────────────────────────────────────────────

def analyze_product(prod: dict, today: date) -> dict:
    nombre          = prod.get("nombre", "?")
    producto_id     = prod.get("producto_id")
    lote            = prod.get("lote")
    fecha_venc_str  = prod.get("fecha_vencimiento")
    stock_actual    = float(prod.get("stock_actual", 0))
    consumo_hist    = [float(x) for x in prod.get("consumo_historico", [])]

    dias_para_vencer = 9999
    if fecha_venc_str:
        try:
            fv = datetime.strptime(fecha_venc_str, "%Y-%m-%d").date()
            dias_para_vencer = (fv - today).days
        except ValueError:
            pass

    consumo_diario = ema(consumo_hist) if consumo_hist else 0.0
    reg            = linear_regression(consumo_hist) if len(consumo_hist) >= 2 else {"slope": 0.0, "r2": 0.0}

    tendencia = "estable"
    if reg["slope"] > 0.05:
        tendencia = "creciente"
    elif reg["slope"] < -0.05:
        tendencia = "decreciente"

    dias_agotamiento = (stock_actual / consumo_diario) if consumo_diario > 0 else 99999.0

    if dias_para_vencer < 9999 and dias_para_vencer > 0:
        proyeccion = project_future_demand(consumo_hist, min(dias_para_vencer, 90))
        consumo_proyectado = sum(proyeccion)
    else:
        consumo_proyectado = consumo_diario * min(dias_para_vencer, 90) if dias_para_vencer < 9999 else 0.0

    unidades_en_riesgo = max(0.0, stock_actual - consumo_proyectado)
    nivel_riesgo       = classify_risk(dias_para_vencer, dias_agotamiento)

    confianza = confidence_score(consumo_hist, reg)
    if len(consumo_hist) < DIAS_MIN_DATA:
        confianza *= 0.5

    cat_info = categorize_product(nombre)

    recomendaciones = build_recommendations(
        prod, nivel_riesgo, round(unidades_en_riesgo, 2),
        dias_para_vencer, consumo_diario, tendencia,
        round(consumo_proyectado, 2)
    )

    # Eventos próximos para exponer en el resultado
    eventos_proximos = []
    if dias_para_vencer < 9999:
        eventos_proximos = get_upcoming_events(today, min(dias_para_vencer, 90))[:3]

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
        "categoria_producto":   cat_info['categoria'],
        "outlet_primario":      cat_info['outlet_primario'],
        "outlets":              cat_info['outlets'],
        "eventos_proximos":     eventos_proximos,
        "serie_consumo":        consumo_hist[-30:],
        "pendiente_regresion":  reg.get("slope", 0.0),
        "r2":                   reg.get("r2", 0.0),
    }


def run():
    try:
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

        conteo = {"vencido": 0, "critico": 0, "alto": 0, "medio": 0, "bajo": 0}
        total_unidades_riesgo = 0.0
        for p in predictions:
            nivel = p["nivel_riesgo"]
            conteo[nivel] = conteo.get(nivel, 0) + 1
            total_unidades_riesgo += p["unidades_en_riesgo"]

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
                "algoritmo":   "EMA + Regresión Lineal + Categorización de Producto + Análisis Festivos CO",
                "alpha_ema":   ALPHA_EMA,
                "version":     "2.0",
                "restaurantes": ["Restaurante Olivia", "Clap Burger"],
                "descripcion": (
                    "Motor ML profesional para gestión de vencimientos en restaurantes. "
                    "Categoriza cada insumo, considera festivos colombianos y fines de semana, "
                    "y genera dictámenes específicos con routing a Olivia y Clap Burger."
                )
            }
        }

        sys.stdout.write(json.dumps(result, ensure_ascii=False, default=str) + '\n')
        sys.stdout.flush()

    except Exception as e:
        print(json.dumps({"error": True, "message": f"Error interno motor ML: {str(e)}"}))


if __name__ == "__main__":
    run()
