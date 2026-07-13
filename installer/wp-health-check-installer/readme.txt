=== WP Health Check Installer ===
Contributors: mavida
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Installa il must-use plugin wp-health-check.php scaricando l'ultima release da GitHub.

== Descrizione ==

Plugin di supporto all'installazione del fleet agent "WP Health Check".

All'attivazione:

1. Se wp-content/mu-plugins/wp-health-check.php esiste gia', non fa nulla e
   lo segnala con una notice.
2. Altrimenti verifica/crea la cartella mu-plugins, controlla i permessi di
   scrittura, scarica l'ultima release firmata di wp-health-check.php da
   GitHub (con verifica SHA-256) e la installa.
3. In caso di problema lascia una notice con il motivo preciso (permessi,
   download, integrita', scrittura).

Dopo l'installazione questo plugin puo' essere disattivato ed eliminato: il
must-use plugin resta attivo e si aggiorna da solo.

== Uso ==

Plugin -> Aggiungi nuovo -> Carica plugin -> seleziona lo ZIP -> Installa ->
Attiva. Leggi la notice per l'esito.
