"""Lancia in sequenza i tre test e2e con `python -I` e riassume l'esito.

Uso (con l'ambiente e2e acceso, vedi tests/e2e/README.md):
    python -I tests/e2e/run_all.py

Variabili d'ambiente inoltrate agli script: WPHC_CANDIDATE_FILE, WPHC_BASELINE_REF.
Con WPHC_CANDIDATE_FILE impostata TUTTI e tre gli script installano quel file come plugin sotto test
(hub_contract e wpcli_test lo installano e lo provano; upgrade_test lo usa come candidato dell'upgrade
dalla 1.38.1 e come plugin installato nei blocchi candidate-self-update e backup-failed). Ognuno stampa
all'avvio percorso, versione e sha256 del file che usa ("[script] plugin sotto test: ...").
"""
import os
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
SCRIPTS = ["hub_contract.py", "upgrade_test.py", "wpcli_test.py"]


def main() -> int:
    results = {}
    print("WPHC_CANDIDATE_FILE =", os.environ.get("WPHC_CANDIDATE_FILE") or "(non impostata: plugin sotto test = baseline git)", flush=True)
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
