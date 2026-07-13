# wp-health-check — cose da implementare nel plugin

Documento di lavoro per le modifiche al plugin sito `wp-health-check` (l'agent
installato su ogni sito monitorato) necessarie alle nuove funzionalità della
dashboard `wp-enroll`. Il plugin ha accesso diretto a WordPress, quindi è il
posto giusto per calcolare i segnali che poi l'API centrale persiste.

Riferimenti: `docs/backend-implementation-todo.md` (lato API centrale) e la
fonte di verità dei payload `/health` e `/detail/{section}`.

---

## 1. `/detail/theme`: elenco completo dei temi installati

**Oggi**: la rotta `GET /wp-json/health-check/v1/detail/theme` restituisce solo
`active_theme` + `parent_theme`. **Serve**: l'elenco di **tutti** i temi
installati sul sito.

Aggiungere un array `themes` alla risposta (mantenendo `active_theme`/
`parent_theme` per retrocompatibilità con eventuali consumer esistenti):

```php
$all = wp_get_themes();               // tutti i temi installati
$current = wp_get_theme();            // tema attivo
$updates = get_site_transient('update_themes'); // per update_available

$themes = array();
foreach ( $all as $stylesheet => $theme ) {
    $has_update = isset( $updates->response[ $stylesheet ] );
    $themes[] = array(
        'name'             => $theme->get('Name'),
        'stylesheet'       => $stylesheet,
        'version'          => $theme->get('Version'),
        'active'           => ( $stylesheet === $current->get_stylesheet() ),
        'parent'           => $theme->parent() ? $theme->get_template() : null,
        'update_available' => $has_update,
        'new_version'      => $has_update ? $updates->response[ $stylesheet ]['new_version'] : null,
    );
}
```

Shape di ogni voce (contratto atteso dal frontend, `getThemesList`/`ThemeTab`):

| Campo | Tipo | Note |
|---|---|---|
| `name` | string | Nome leggibile del tema. |
| `stylesheet` | string | Slug/cartella del tema (chiave univoca). |
| `version` | string | Versione installata. |
| `active` | boolean | `true` per il tema attualmente attivo. |
| `parent` | string \| null | `stylesheet` del parent se è un child theme, altrimenti `null`. |
| `update_available` | boolean | Aggiornamento disponibile. |
| `new_version` | string \| null | Versione dell'aggiornamento, se disponibile. |

L'API centrale rilancia `themes` invariato (passthrough, vedi doc backend §4).

---

## 2. `/health`: flag `has_gdpr` e `has_builder`

Aggiungere due booleani al blocco `summary` della risposta `/health`, calcolati
dai plugin/temi effettivamente attivi sul sito. L'API centrale li persiste e li
espone (vedi doc backend §1).

```php
$active_plugins = (array) get_option( 'active_plugins', array() );
$slugs = array_map( function ( $p ) {
    return explode( '/', $p )[0]; // "elementor/elementor.php" -> "elementor"
}, $active_plugins );

$has_gdpr = (bool) array_intersect( $slugs, array(
    'iubenda-cookie-law-solution',
    'cookiebot',            // verificare lo slug reale della versione in uso
    'cookiebot-manager',
) );

$current = wp_get_theme();
$theme_slugs = array_filter( array(
    strtolower( $current->get_stylesheet() ),
    strtolower( $current->get_template() ),
) );

$has_builder =
    in_array( 'elementor', $slugs, true ) ||
    in_array( 'divi', $theme_slugs, true );

// $summary['has_gdpr']    = $has_gdpr;
// $summary['has_builder'] = $has_builder;
```

| Campo (`summary`) | Tipo | Rilevamento |
|---|---|---|
| `has_gdpr` | boolean | Plugin attivo iubenda **o** Cookiebot. |
| `has_builder` | boolean | Plugin attivo Elementor **o** tema attivo/parent DIVI. |

> Nota: gli slug vanno verificati contro le versioni realmente installate
> (Cookiebot in particolare ha avuto slug diversi nel tempo). Tenere l'elenco
> degli slug in un punto unico del plugin, così è facile aggiungerne altri
> (es. altri consent manager o builder) senza toccare l'API centrale.

---

## 3. Checklist riepilogo

- [x] `/detail/theme`: aggiungere array `themes` con tutti i temi installati (shape §1).
- [x] `/health` → `summary`: aggiungere `has_gdpr` e `has_builder` (regole §2).
- [ ] Verificare gli slug reali di Cookiebot/iubenda/Elementor sulle versioni in uso.
- [x] Nessun dato sensibile aggiuntivo esposto (solo booleani e metadati temi).

> Implementato nella v1.17.0. Gli slug riconosciuti vivono in un unico punto:
> la funzione `wphc_detect_site_signals()` in `mu-plugins/wp-health-check.php`.
> Resta da verificare a mano gli slug reali di Cookiebot sulle versioni in uso.
