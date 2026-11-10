#!/usr/bin/env bash
# Esegue la suite PHPUnit su tutta la matrice PHP (7.4, 8.1, 8.3) IN PARALLELO: ogni versione ha il suo container,
# il suo database e i suoi volumi (vedi run-phpunit-docker.sh), quindi non si calpestano.
#
# Uso (dalla radice di wp-health-check, con l'ambiente wp-env acceso):
#     bash tests/tools/run-phpunit-matrix.sh [argomenti di phpunit]
#     bash tests/tools/run-phpunit-matrix.sh --testsuite enroll
#     WPHC_PHP_VERSIONS="8.1 8.3" bash tests/tools/run-phpunit-matrix.sh
#
# L'output di ogni versione va in un file di log (il percorso e' stampato): a video restano l'esito e il riepilogo di
# PHPUnit per versione. Esce con 1 se almeno una versione fallisce.

set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
VERSIONS="${WPHC_PHP_VERSIONS:-7.4 8.1 8.3}"
LOG_DIR="$(mktemp -d)"

pids=""
for version in $VERSIONS; do
	bash "$HERE/run-phpunit-docker.sh" "$version" "$@" >"$LOG_DIR/php-$version.log" 2>&1 &
	pids="$pids $version:$!"
done

status=0
for entry in $pids; do
	version="${entry%%:*}"
	if wait "${entry##*:}"; then
		result="OK"
	else
		result="FALLITO"
		status=1
	fi
	# Ultima riga di riepilogo di PHPUnit ("OK (643 tests, ...)" oppure "Tests: ..., Failures: ...") e tempo.
	summary="$(grep -E '^(OK \(|Tests: |Time: )' "$LOG_DIR/php-$version.log" | tr '\n' ' ')"
	echo "PHP $version: $result  $summary  (log: $LOG_DIR/php-$version.log)"
done
exit "$status"
