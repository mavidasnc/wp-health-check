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
#     WPHC_NATIVE           0 = niente copia nativa: WordPress e vendor letti dal disco dell'host (lento su Windows)
#     WPHC_NATIVE_REFRESH   1 = rifa' la copia nativa anche se risulta aggiornata
#
# Copia nativa (default). Su Windows i bind mount di Docker Desktop passano dal protocollo 9p: ogni file PHP letto dal
# disco dell'host costa circa 10 ms, e WordPress piu' PHPUnit ne caricano qualche migliaio a OGNI processo PHP. Per
# questo il WordPress di test e la cartella vendor vengono copiati UNA volta in due volumi Docker (filesystem del
# container) e si ricopiano da soli solo quando cambiano la versione di WordPress, il suo wp-config.php o i pacchetti
# di Composer (impronta in .wphc-native-stamp dentro il volume). Dal disco dell'host restano montati la radice del
# progetto (src/, tests/, bin/, mu-plugins/: poche decine di file) e il wp-tests-config.php di wp-env. Il codice
# provato e il database sono gli stessi di prima: cambia solo da dove PHP legge WordPress e vendor.
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
	VENDOR_REL="tests/php74/vendor"
	VENDOR_KIND="vendor-php74"
else
	VENDOR_REL="vendor"
	VENDOR_KIND="vendor"
fi
VENDOR="$APP_DIR/$VENDOR_REL"

# 4b. Copia nativa: nomi dei volumi (uno per il WordPress di test, uno per ogni cartella vendor), distinti per
# cartella di wp-env cosi' due copie del repository non si scambiano i volumi.
NATIVE="${WPHC_NATIVE:-1}"
NATIVE_ID="$(printf '%s' "${WPENV_NAME#wp-env-}" | tr -c 'a-zA-Z0-9_.-' '-')"
WP_VOLUME="wphc-native-${NATIVE_ID}-wp"
VENDOR_VOLUME="wphc-native-${NATIVE_ID}-${VENDOR_KIND}"

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

# 6. Copia nativa: un container di servizio (root) allinea i due volumi. Quando le impronte coincidono non copia
# nulla (legge tre file dall'host e finisce). Va fatto DOPO il lock: due run non devono copiare insieme.
if [ "$NATIVE" != "0" ]; then
	# Utente con cui girera' phpunit (quello di default dell'immagine): i volumi devono appartenergli, perche' i test
	# scrivono in wp-content (uploads, plugins, mu-plugins).
	docker image inspect "$IMAGE" >/dev/null 2>&1 || docker pull "$IMAGE" >&2 || exit 2
	RUN_USER="$(docker image inspect --format '{{.Config.User}}' "$IMAGE")"
	# shellcheck disable=SC2016  # lo script fra apici singoli lo espande la shell del container, non questa.
	MSYS_NO_PATHCONV=1 docker run --rm --user root --entrypoint sh \
		-v "$WPENV_HOST/tests-WordPress:/src/wp:ro" \
		-v "$REPO_HOST/$VENDOR_REL:/src/vendor:ro" \
		-v "$WP_VOLUME:/dst/wp" \
		-v "$VENDOR_VOLUME:/dst/vendor" \
		-e RUN_USER="${RUN_USER:-root}" -e REFRESH="${WPHC_NATIVE_REFRESH:-}" \
		"$IMAGE" -c '
			set -eu
			# Impronta = versione della disposizione + i file che dicono "e cambiato qualcosa".
			layout=1
			sync_dir() {
				src="$1"; dst="$2"; label="$3"; shift 3
				for file in "$@"; do
					[ -f "$src/$file" ] || { echo "copia nativa: manca $src/$file ($label)" >&2; exit 1; }
				done
				key="$layout $(cd "$src" && cat "$@" | sha256sum | cut -d" " -f1)"
				if [ -z "$REFRESH" ] && [ -f "$dst/.wphc-native-stamp" ] && [ "$(cat "$dst/.wphc-native-stamp")" = "$key" ]; then
					return 0
				fi
				echo "copia nativa: aggiorno $label nel volume Docker (solo quando cambia, puo richiedere un paio di minuti)..." >&2
				# Si svuota prima (via anche la vecchia impronta) e l impronta si scrive per ultima: una copia
				# interrotta a meta non risulta mai aggiornata.
				find "$dst" -mindepth 1 -delete
				tar -C "$src" --exclude=./wp-content/debug.log -cf - . | tar -C "$dst" -xf -
				printf "%s" "$key" > "$dst/.wphc-native-stamp"
				return 10
			}
			status=0
			sync_dir /src/wp /dst/wp "il WordPress di test" wp-includes/version.php wp-config.php || status=$?
			if [ "$status" -eq 10 ]; then
				# Punto di mount della radice del progetto e cartelle in cui i test scrivono.
				mkdir -p /dst/wp/wp-content/wphc-dev /dst/wp/wp-content/mu-plugins /dst/wp/wp-content/uploads
				chown -R "$RUN_USER" /dst/wp
			elif [ "$status" -ne 0 ]; then
				exit "$status"
			fi
			status=0
			sync_dir /src/vendor /dst/vendor "la cartella vendor" composer/installed.json || status=$?
			if [ "$status" -eq 10 ]; then
				chown -R "$RUN_USER" /dst/vendor
			elif [ "$status" -ne 0 ]; then
				exit "$status"
			fi
		' || { echo "ERRORE: copia nativa non riuscita (per farne a meno: WPHC_NATIVE=0)." >&2; exit 2; }
	WP_MOUNT="$WP_VOLUME:/var/www/html"
	VENDOR=/opt/wphc-vendor
	VENDOR_MOUNT="-v $VENDOR_VOLUME:$VENDOR"
else
	WP_MOUNT="$WPENV_HOST/tests-WordPress:/var/www/html"
	VENDOR_MOUNT=""
fi

# 7. Run. MSYS_NO_PATHCONV evita che Git Bash "traduca" gli argomenti che iniziano con `/`. `--do-not-cache-result`:
# l'utente del container non puo' scrivere .phpunit.result.cache nella cartella montata (avviso innocuo, cosi' non compare).
# shellcheck disable=SC2086  # EXTRA_ENV, EXTRA_MOUNT e VENDOR_MOUNT sono elenchi di opzioni: vanno divisi in parole.
MSYS_NO_PATHCONV=1 docker run --rm --network "$NETWORK" \
	-v "$WP_MOUNT" \
	-v "$WPENV_HOST/tests-WordPress-PHPUnit/tests/phpunit:/wordpress-phpunit" \
	-v "$REPO_HOST:$APP_DIR" $VENDOR_MOUNT ${EXTRA_MOUNT:-} \
	-e WP_TESTS_DIR=/wordpress-phpunit \
	-e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=password -e WORDPRESS_DB_NAME=tests-wordpress -e WORDPRESS_DB_HOST=tests-mysql \
	-e WPHC_VENDOR_DIR="$VENDOR" ${EXTRA_ENV:-} \
	-w "$APP_DIR" "$IMAGE" \
	bash -c 'php -v | head -1 >&2; exec php -d memory_limit=512M "$0" "$@"' "$VENDOR/bin/phpunit" --do-not-cache-result "$@"
