#!/usr/bin/env bash
# Esegue la suite PHPUnit in un container USA-E-GETTA con la versione di PHP indicata, senza toccare l'ambiente
# wp-env condiviso (che resta su PHP 8.1): stessa rete e stesso MySQL dell'ambiente "tests" di wp-env, con la radice
# del progetto montata. Serve per la matrice 7.4 / 8.1 / 8.3 (e successive).
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
# VELOCITA': il core di WordPress e la cartella vendor NON si leggono dal bind mount di Windows (ogni include passa dal
# filesystem dell'host: la suite impiegava quasi 5 minuti) ma da due volumi Docker per versione di PHP, sul filesystem
# nativo di Docker (circa 40 secondi). I volumi sono copie: si ripopolano da soli quando cambia l'origine (version.php
# del core di wp-env, vendor/composer/installed.json), o sempre con WPHC_REFRESH_VOLUMES=1. Il repo resta un bind mount
# (pochi file letti: test, fixture e la build di src/), quindi una modifica a src/ o a tests/ vale subito.
#
# PARALLELO: ogni versione di PHP ha il suo database (`tests-wordpress-php<NN>`, creato se manca), il suo volume del core
# (i test scrivono plugin di prova e upload) e il suo lock: versioni DIVERSE possono girare insieme
# (tests/tools/run-phpunit-matrix.sh le lancia tutte e tre), due run della STESSA versione si mettono in coda.
# Il database `tests-wordpress` del container tests-cli di wp-env (`composer run test`) non viene toccato.
#
# Variabili d'ambiente (tutte facoltative):
#     WPHC_WPENV_DIR        cartella di wp-env del progetto (default: l'UNICA ~/.wp-env/wp-env-wp-health-check-<hash8>)
#     WPHC_PHPUNIT_LOCK     cartella usata come lock (default: C:/tmp/wphc-phpunit-php<NN>.lock su Windows, /tmp/... altrove)
#     WPHC_REFRESH_VOLUMES  1 = ripopola i volumi del core e di vendor anche se l'origine non e' cambiata
#     EXTRA_ENV             opzioni `-e NOME=valore` per il container (es. "-e WPHC_GOLDEN_UPDATE=1")
#     EXTRA_MOUNT           opzioni `-v origine:destinazione` aggiuntive (es. per montare un file esterno)

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

# 4. Immagine, vendor, volumi e database secondo la versione.
IMAGE="wordpress:cli-php${PHP_VERSION}"
APP_DIR=/var/www/html/wp-content/wphc-dev
VENDOR=/wphc-vendor
TAG="php${PHP_VERSION//./}"                 # php74, php81, php83
if [ "$PHP_VERSION" = "7.4" ]; then
	VENDOR_SRC="$REPO/tests/php74/vendor"
else
	VENDOR_SRC="$REPO/vendor"
fi
CORE_VOLUME="wphc-tests-core-${TAG}"
VENDOR_VOLUME="wphc-tests-vendor-${TAG}"
DB_NAME="tests-wordpress-${TAG}"

# 5. Lock per versione: aspetta il proprio turno e lo rilascia all'uscita (anche con Ctrl+C).
if [ -n "${WPHC_PHPUNIT_LOCK:-}" ]; then
	LOCK="$WPHC_PHPUNIT_LOCK"
elif [ -d /c/tmp ]; then
	LOCK="C:/tmp/wphc-phpunit-${TAG}.lock"
else
	LOCK="/tmp/wphc-phpunit-${TAG}.lock"
fi
until mkdir "$LOCK" 2>/dev/null; do sleep 5; done
trap 'rmdir "$LOCK"' EXIT

# MSYS_NO_PATHCONV evita che Git Bash "traduca" gli argomenti che iniziano con `/`.
export MSYS_NO_PATHCONV=1

# 6. Volumi: si ripopolano solo se l'impronta dell'origine e' cambiata (o con WPHC_REFRESH_VOLUMES=1). L'impronta sta
# nel file .wphc-stamp del volume. La copia gira come root e poi passa i file all'utente dell'immagine (www-data).
stamp_of() {
	if [ -f "$1" ]; then sha256sum "$1" | cut -c1-16; else echo "assente"; fi
}
# sync_volume <volume> <cartella origine> <impronta> [esclusione tar]
sync_volume() {
	docker run --rm --user root -v "$(to_host_path "$2"):/src:ro" -v "$1:/dst" "$IMAGE" sh -c '
		if [ "$(cat /dst/.wphc-stamp 2>/dev/null)" = "$1" ] && [ "$2" != "1" ]; then exit 0; fi
		echo "Aggiorno il volume $3 (copia unica, circa mezzo minuto)..." >&2
		find /dst -mindepth 1 -delete
		cd /src && tar cf - ${4:+--exclude="$4"} . | tar xf - -C /dst || exit 1
		chown -R www-data:www-data /dst
		echo "$1" > /dst/.wphc-stamp' _ "$3" "${WPHC_REFRESH_VOLUMES:-0}" "$1" "${4:-}"
}
if [ ! -f "$VENDOR_SRC/autoload.php" ]; then
	echo "ERRORE: $VENDOR_SRC/autoload.php non trovato: eseguire composer install (per la 7.4: --working-dir=tests/php74)." >&2
	exit 2
fi
# Il repo e' montato sotto wp-content/wphc-dev: la cartella del core di wp-env non va copiata con il suo contenuto.
sync_volume "$CORE_VOLUME" "$WPENV_DIR/tests-WordPress" "$(stamp_of "$WPENV_DIR/tests-WordPress/wp-includes/version.php")" "./wp-content/wphc-dev" || exit 2
sync_volume "$VENDOR_VOLUME" "$VENDOR_SRC" "$(stamp_of "$VENDOR_SRC/composer/installed.json")" || exit 2

# 7. Run. `--do-not-cache-result`: il vendor e' montato in sola lettura e l'utente del container non puo' scrivere
# .phpunit.result.cache nella cartella del repo. Il database della versione si crea se manca (la test library lo installa).
# shellcheck disable=SC2086  # EXTRA_ENV e EXTRA_MOUNT sono elenchi di opzioni: vanno divisi in parole.
docker run --rm --network "$NETWORK" \
	-v "$CORE_VOLUME:/var/www/html" \
	-v "$VENDOR_VOLUME:$VENDOR:ro" \
	-v "$WPENV_HOST/tests-WordPress-PHPUnit/tests/phpunit:/wordpress-phpunit:ro" \
	-v "$REPO_HOST:$APP_DIR" ${EXTRA_MOUNT:-} \
	-e WP_TESTS_DIR=/wordpress-phpunit \
	-e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=password -e WORDPRESS_DB_NAME="$DB_NAME" -e WORDPRESS_DB_HOST=tests-mysql \
	-e WPHC_VENDOR_DIR="$VENDOR" ${EXTRA_ENV:-} \
	-w "$APP_DIR" "$IMAGE" \
	bash -c 'php -v | head -1 >&2
		mysql --skip-ssl -h "$WORDPRESS_DB_HOST" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS \`$WORDPRESS_DB_NAME\`" || exit 3
		exec php -d memory_limit=512M "$0" "$@"' "$VENDOR/bin/phpunit" --do-not-cache-result "$@"
