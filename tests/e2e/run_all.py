"""Lancia in sequenza i tre test e2e con `python -I` e riassume l'esito.

Uso (con l'ambiente e2e acceso, vedi tests/e2e/README.md):
    python -I tests/e2e/run_all.py

Variabili d'ambiente inoltrate agli script: WPHC_CANDIDATE_FILE, WPHC_BASELINE_REF.
"""
import os
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
SCRIPTS = ["hub_contract.py", "upgrade_test.py", "wpcli_test.py"]


def main() -> int:
    results = {}
    for script in SCRIPTS:
        print(f"\n######## {script}", flush=True)
        # Interprete isolato anche per i figli; PYTHONDONTWRITEBYTECODE evita __pycache__ nel repo.
        env = dict(os.environ, PYTHONDONTWRITEBYTECODE="1")
        results[script] = subprocess.run([sys.executable, "-I", str(HERE / script)], env=env).returncode
    print("\n######## Riepilogo")
    for script, code in results.items():
        print(f"  {'ok  ' if code == 0 else 'FAIL'} {script}")
    return 1 if any(results.values()) else 0


if __name__ == "__main__":
    sys.exit(main())
