#!/usr/bin/env python3
"""
validate_controllers.py — WMS Prooriente controller + route validator
Usage:
  python3 tools/validate_controllers.py           # human-readable
  python3 tools/validate_controllers.py --json    # machine-readable JSON
"""
import os, sys, re, json, argparse

BASE      = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CTRL_DIR  = os.path.join(BASE, "src", "Controllers")
INDEX_PHP = os.path.join(BASE, "public", "index.php")
JS_DIR    = os.path.join(BASE, "public", "assets", "js", "desktop")

errors   = []
warnings = []

# ── helpers ───────────────────────────────────────────────────────────────────

def has_null_bytes(path):
    with open(path, "rb") as f:
        data = f.read()
    return bytes([0]) in data

def count_braces(path):
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        src = f.read()
    return src.count("{"), src.count("}")

def read_text(path):
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        return f.read()

# ── 1. PHP Controllers ────────────────────────────────────────────────────────

ctrl_files = sorted(f for f in os.listdir(CTRL_DIR) if f.endswith(".php"))
ctrl_report = []

SKIP_EXTENDS = {"BaseController.php"}

for fname in ctrl_files:
    path = os.path.join(CTRL_DIR, fname)
    report = {"file": fname, "issues": []}

    # null bytes
    if has_null_bytes(path):
        report["issues"].append("NULL_BYTES")
        errors.append(f"{fname}: contiene null bytes")

    # brace balance
    o, c = count_braces(path)
    if o != c:
        report["issues"].append(f"BRACE_MISMATCH({o}o/{c}c)")
        errors.append(f"{fname}: llaves desbalanceadas ({o} open / {c} close)")

    # extends BaseController
    if fname not in SKIP_EXTENDS:
        src = read_text(path)
        if "extends BaseController" not in src:
            report["issues"].append("NO_EXTENDS_BASE")
            errors.append(f"{fname}: no extiende BaseController")

    # collect public methods
    src = read_text(path)
    methods = re.findall(r"public function (\w+)\s*\(", src)
    report["methods"] = [m for m in methods if not m.startswith("__")]
    report["ok"] = len(report["issues"]) == 0
    ctrl_report.append(report)

# ── 2. Routes in index.php ────────────────────────────────────────────────────

index_src = read_text(INDEX_PHP)

# single-quoted routes
route_sq = re.findall(
    r"\$(?:app|group)->(?:get|post|put|patch|delete|map)\s*\('([^']+)'",
    index_src
)
# double-quoted routes
route_dq = re.findall(
    r'\$(?:app|group)->(?:get|post|put|patch|delete|map)\s*\("([^"]+)"',
    index_src
)
routes_count = len(route_sq) + len(route_dq)

# controllers referenced in index.php
used_classes = set(re.findall(
    r"App\\Controllers\\(\w+Controller)",
    index_src
))

# ── 3. Cross-check: route methods vs controller methods ──────────────────────

route_methods_sq = re.findall(
    r"App\\Controllers\\(\w+Controller)::class,\s*'(\w+)'",
    index_src
)
route_methods_dq = re.findall(
    r'App\\Controllers\\(\w+Controller)::class,\s*"(\w+)"',
    index_src
)
route_methods = route_methods_sq + route_methods_dq

ctrl_method_map = {r["file"].replace(".php", ""): set(r["methods"]) for r in ctrl_report}

for cls, mth in route_methods:
    if cls in ctrl_method_map:
        if mth not in ctrl_method_map[cls]:
            errors.append(f"index.php: ruta apunta a {cls}::{mth}() pero el metodo no existe")
    else:
        warnings.append(f"index.php: usa {cls} pero no hay {cls}.php en Controllers/")

# ── 4. JS desktop files ───────────────────────────────────────────────────────

js_report = []
if os.path.isdir(JS_DIR):
    js_files = sorted(f for f in os.listdir(JS_DIR) if f.endswith(".js"))
    for fname in js_files:
        path = os.path.join(JS_DIR, fname)
        report = {"file": fname, "issues": []}

        if has_null_bytes(path):
            report["issues"].append("NULL_BYTES")
            errors.append(f"JS/{fname}: contiene null bytes")

        o, c = count_braces(path)
        if o != c:
            report["issues"].append(f"BRACE_MISMATCH({o}o/{c}c)")
            errors.append(f"JS/{fname}: llaves desbalanceadas ({o} open / {c} close)")

        src = read_text(path)
        module_name = fname.replace(".js", "")
        if "WMS_MODULES" in src and module_name not in src:
            report["issues"].append("MODULE_NAME_MISMATCH")
            warnings.append(
                f"JS/{fname}: WMS_MODULES referenciado pero modulo '{module_name}' no encontrado"
            )

        report["ok"] = len(report["issues"]) == 0
        js_report.append(report)
else:
    warnings.append(f"Directorio JS no encontrado: {JS_DIR}")

# ── 5. Output ─────────────────────────────────────────────────────────────────

parser = argparse.ArgumentParser()
parser.add_argument("--json", action="store_true")
args = parser.parse_args()

result = {
    "controladores":    len(ctrl_report),
    "controladores_ok": sum(1 for r in ctrl_report if r["ok"]),
    "rutas":            routes_count,
    "js_files":         len(js_report),
    "js_ok":            sum(1 for r in js_report if r["ok"]),
    "errores":          len(errors),
    "warnings":         len(warnings),
    "error_list":       errors,
    "warning_list":     warnings,
    "controllers":      ctrl_report,
    "js":               js_report,
}

if args.json:
    print(json.dumps(result, indent=2, ensure_ascii=False))
    sys.exit(0 if len(errors) == 0 else 1)

# Human-readable ──────────────────────────────────────────────────────────────
GREEN  = "\033[92m"
RED    = "\033[91m"
YELLOW = "\033[93m"
BOLD   = "\033[1m"
RESET  = "\033[0m"

def ok(msg):   print(f"  {GREEN}OK{RESET} {msg}")
def err(msg):  print(f"  {RED}ERR{RESET} {msg}")
def warn(msg): print(f"  {YELLOW}WARN{RESET} {msg}")

print(f"\n{BOLD}=== WMS Prooriente - Validacion de Controladores ==={RESET}")
print(f"Base: {BASE}\n")

print(f"{BOLD}PHP Controllers ({CTRL_DIR}){RESET}")
for r in ctrl_report:
    if r["ok"]:
        ok(f"{r['file']}  ({len(r['methods'])} metodos)")
    else:
        err(f"{r['file']}  ISSUES: {', '.join(r['issues'])}")

print(f"\n{BOLD}Rutas (index.php){RESET}")
print(f"  Total rutas detectadas: {routes_count}")
print(f"  Controladores referenciados: {len(used_classes)}")

print(f"\n{BOLD}JS Desktop ({JS_DIR}){RESET}")
if js_report:
    for r in js_report:
        if r["ok"]:
            ok(r["file"])
        else:
            err(f"{r['file']}  ISSUES: {', '.join(r['issues'])}")
else:
    warn("No se encontraron archivos JS o directorio ausente")

print(f"\n{BOLD}Resumen{RESET}")
print(f"  Controladores : {result['controladores']}  ({result['controladores_ok']} OK)")
print(f"  Rutas         : {result['rutas']}")
print(f"  JS files      : {result['js_files']}  ({result['js_ok']} OK)")
print(f"  Errores       : {result['errores']}")
print(f"  Advertencias  : {result['warnings']}")

if errors:
    print(f"\n{BOLD}{RED}ERRORES:{RESET}")
    for e in errors:
        err(e)

if warnings:
    print(f"\n{BOLD}{YELLOW}ADVERTENCIAS:{RESET}")
    for w in warnings:
        warn(w)

if len(errors) == 0:
    print(f"\n{GREEN}{BOLD}Todo OK - sin errores criticos{RESET}\n")
else:
    print(f"\n{RED}{BOLD}Hay {len(errors)} error(es) critico(s){RESET}\n")

sys.exit(0 if len(errors) == 0 else 1)
