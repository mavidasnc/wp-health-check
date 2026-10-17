#!/usr/bin/env bash
# Esegue la suite PHPUnit in un container USA-E-GETTA con la versione di PHP indicata, senza toccare l'ambiente
# wp-env condiviso (che resta su PHP 8.1): stessa rete, stesso WordPress di test e stesso database dell'ambiente
# "tests" di wp-env, con la radice del progetto montata. Serve per la matrice 7.4 / 8.1 / 8.3 (e successive).
#
# Uso (dalla radice di wp-health-check, con l'ambiente wp-env acceso: `wp-env start`):
#     bash tests/tools/run-phpunit-docker.sh <php> [argomenti di phpunit]
#     bash tests/tools/run-phpunit-docker.sh 8.3
#     bash tests/tools/run-phpunit-docker.sh 7.4 --testsuite enroll
#
# <php> e' la versione di PHP (7.4, 8.1, 8.3, ...): l'immagine e' `wordpress:cli-php<versione>` (il tag `wordpress:cli`
# senza versione puo' cambiare PHP da un giorno all'altro, per questo qui non si usa). Con 7.4 si usa il vendor dedicato
# `tests/php74/vendor` (vedi tests/README.md); con le altre versioni il vendor principale `vendor/`.
#
# Variabili d'ambiente (tutte facoltative):
#     WPHC_WPENV_DIR        cartella di wp-env del progetto (default: l'UNICA ~/.wp-env/wp-env-wp-health-check-<hash8>)
#     WPHC_PHPUNIT_LOCK     cartella usata come lock (default: C:/tmp/wphc-phpunit.lock su Windows, /tmp/... altrove)
#     EXTRA_ENV             opzioni `-e NOME=valore` per il container (es. "-e WPHC_GOLDEN_UPDATE=1")
#     EXTRA_MOUNT           opzioni `-v origine:destinazione` aggiuntive (es. per montare un file esterno)
#
# Lo script serializza i run con un lock a directory (mkdir e' atomico anche su Windows): l'ambiente di test ha UN solo
# database e due phpunit in parallelo si calpesterebbero.

set -u

# 1. Argomenti: la versione di PHP e' obbligatoria; il resto va a phpunit.
if [ $# -lt 1 ]; then
	echo "Uso: $0 <versione PHP: 7.4|8.1|8.3|...> [argomenti di phpunit]" >&2
	exit 2
fi
PHP_VERSION="$1"
shift

# 2. Percorsi: radice del progetto e, su Windows (Git Bash), forma `C:/...` che Docker Desktop capisce.
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
to_host_path() {
	if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else printf '%s' "$1"; fi
}
REPO_HOST="$(to_host_path "$REPO")"

# 3. Cartella di wp-env del progetto: una sola cartella `wp-env-wp-health-check-<hash8>` (quella con suffisso `e2e-...` e'
# l'ambiente e2e e non va usata), oppure quella indicata da WPHC_WPENV_DIR.
if [ -n "${WPHC_WPENV_DIR:-}" ]; then
	WPENV_DIR="$WPHC_WPENV_DIR"
else
	WPENV_DIR=""
	count=0
	for dir in "$HOME"/.wp-env/wp-env-wp-health-check-????????; do
		if [ -d "$dir/tests-WordPress" ]; then
			WPENV_DIR="$dir"
			count=$((count + 1))
		fi
	done
	if [ "$count" -ne 1 ]; then
		echo "ERRORE: trovate $count cartelle wp-env del progetto in ~/.wp-env: indicare quella giusta con WPHC_WPENV_DIR (e avviare wp-env: npx @wordpress/env start)." >&2
		exit 2
	fi
fi
WPENV_NAME="$(basename "$WPENV_DIR")"       # wp-env-wp-health-check-<hash8>
NETWORK="${WPENV_NAME}_default"
WPENV_HOST="$(to_host_path "$WPENV_DIR")"

# 4. Immagine, vendor e comando di phpunit secondo la versione.
IMAGE="wordpress:cli-php${PHP_VERSION}"
APP_DIR=/var/www/html/wp-content/wphc-dev
if [ "$PHP_VERSION" = "7.4" ]; then
	VENDOR="$APP_DIR/tests/php74/vendor"
else
	VENDOR="$APP_DIR/vendor"
fi

# 5. Lock: aspetta il proprio turno e lo rilascia all'uscita (anche con Ctrl+C).
if [ -n "${WPHC_PHPUNIT_LOCK:-}" ]; then
	LOCK="$WPHC_PHPUNIT_LOCK"
elif [ -d /c/tmp ]; then
	LOCK="C:/tmp/wphc-phpunit.lock"
else
	LOCK="/tmp/wphc-phpunit.lock"
fi
until mkdir "$LOCK" 2>/dev/null; do sleep 5; done
trap 'rmdir "$LOCK"' EXIT

# 6. Run. MSYS_NO_PATHCONV evita che Git Bash "traduca" gli argomenti che iniziano con `/`. `--do-not-cache-result`:
# l'utente del container non puo' scrivere .phpunit.result.cache nella cartella montata (avviso innocuo, cosi' non compare).
# shellcheck disable=SC2086  # EXTRA_ENV e EXTRA_MOUNT sono elenchi di opzioni: vanno divisi in parole.
MSYS_NO_PATHCONV=1 docker run --rm --network "$NETWORK" \
	-v "$WPENV_HOST/tests-WordPress:/var/www/html" \
	-v "$WPENV_HOST/tests-WordPress-PHPUnit/tests/phpunit:/wordpress-phpunit" \
	-v "$REPO_HOST:$APP_DIR" ${EXTRA_MOUNT:-} \
	-e WP_TESTS_DIR=/wordpress-phpunit \
	-e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=password -e WORDPRESS_DB_NAME=tests-wordpress -e WORDPRESS_DB_HOST=tests-mysql \
	-e WPHC_VENDOR_DIR="$VENDOR" ${EXTRA_ENV:-} \
	-w "$APP_DIR" "$IMAGE" \
	bash -c 'php -v | head -1 >&2; exec php -d memory_limit=512M "$0" "$@"' "$VENDOR/bin/phpunit" --do-not-cache-result "$@"
