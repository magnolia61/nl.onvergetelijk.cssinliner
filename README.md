# nl.onvergetelijk.cssinliner

## Functionele beschrijving

De `cssinliner`-extensie zorgt ervoor dat CSS-stijlen in uitgaande CiviCRM-emails automatisch worden omgezet naar inline `style`-attributen. Dit is noodzakelijk voor een consistente weergave in emailclients zoals Outlook en Gmail, die externe en ingebedde stylesheets grotendeels negeren.

De extensie is een PHP 8.4-compatibele vervanging voor de verouderde Fuzion `cssinliner`-extensie. In plaats van verouderde regex-gebaseerde vervangingen gebruikt deze module de **Pelago/Emogrifier** library, die de HTML inlaadt via een DOM-parser en CSS-regels nauwkeurig per element toepast.

## Afhankelijkheden

- Composer: `pelago/emogrifier` (via `vendor/`)
- `nl.onvergetelijk.base` (voor `wachthond`)

---

## Technische documentatie

### Kernfuncties

- `cssinliner_civicrm_alterMailParams(&$params, $context)` — intercepteert elke uitgaande mail:
  1. Controleert of er HTML-inhoud aanwezig is
  2. Converteert HTML naar UTF-8 HTML-entities (PHP 8.4-compatibel)
  3. Roept Emogrifier aan om CSS-blokken inline te zetten
  4. Verwijdert de oorspronkelijke `<style>`-blokken
  5. Schrijft het resultaat terug naar `$params['html']`
- `_cssinliner_cleanup_html($html, $title)` — normaliseert de HTML-structuur (DOCTYPE, head, title)
- `_cssinliner_fetch_external_css($url, $extdebug)` — haalt externe CSS-bestanden op voor inlining

### Vergelijking met Fuzion cssinliner

| Kenmerk | Fuzion | nl.onvergetelijk.cssinliner |
|---|---|---|
| PHP-compatibiliteit | Tot PHP 8.1 | PHP 8.2, 8.3, 8.4 |
| Core library | `cssin` (verouderd) | Emogrifier v7+ |
| Encoding | Beperkt | Strikt via HTML-ENTITIES |
| Dependencies | Hardcoded in packages/ | Composer-beheerd |

### Hooks geïmplementeerd
- `civicrm_alterMailParams`
- `civicrm_config`

---

## OZK Smarty-in-site-tokens pipeline

CiviCRM escapet Smarty-syntax in token-waarden via `tokenEscapeSmarty()` vóór Smarty draait. Hierdoor kunnen Smarty-variabelen (`{$foo}`, `{if}` etc.) in site token bodies **niet** rechtstreeks renderen. De cssinliner-extensie omzeilt dit via twee event-listeners:

### `_cssinliner_on_token_eval()` — `civi.token.eval` prioriteit -10

Draait **na** alle standaard token-evaluators (prioriteit 0). Loopt over alle site token waarden. Als een waarde Smarty-code bevat (`{$`, `{if`, `{assign}`, `{capture}` etc.):

1. Slaat de raw body op in een statische PHP-cache (`_cssinliner_smarty_token_cache()`)
2. Vervangt de tokenwaarde door een accoladeloze placeholder: `##OZK_SMARTY:veldnaam##`

De placeholder overleeft `tokenEscapeSmarty()` ongeschonden (geen accolades).

### `_cssinliner_on_token_render()` — `civi.token.render` prioriteit 10

Draait **vóór** `TokenCompatSubscriber` (prioriteit 0, die Smarty uitvoert).

**Stap 1 — injectie:** Vervangt alle `##OZK_SMARTY:naam##` placeholders door de raw Smarty body uit de cache. De template-string bevat nu letterlijke Smarty-code.

**Stap 2 — CiviCRM token-herverwerking:** De site token bodies bevatten vaak CiviCRM tokens zoals `{participant.custom_1781}` of `{event.end_date}`. Deze zijn *niet* geëvalueerd door de standaard evaluators, omdat `getMessageTokens()` de site token bodies niet scant. Oplossing: na de injectie maakt de listener een tijdelijke `TokenProcessor` aan met dezelfde context (contactId, participantId, eventId) en `smarty=FALSE`, en evalueert/rendert de uitgebreide string. Hierdoor worden alle participant/contact/event tokens correct vervangen met hun waarden.

Een **recursie-guard** (`static $reprocessing`) voorkomt dat de mini-TokenProcessor opnieuw de OZK listeners triggert.

**Stap 3 — Smarty:** `TokenCompatSubscriber` (prioriteit 0) draait `parseOneOffStringThroughSmarty()`. De Smarty-code en alle vervangen waarden zijn nu aanwezig → variabelen zoals `$kampstart_ts`, `$kampeinde_ts`, `$dayssince`, `$weeksuntil` worden correct berekend.

### `smarty_header` — de centrale variabele-setup

De site token `{site.smarty_header}` bevat alle Smarty-variabele-toewijzingen voor OZK email-templates: kampdata, persoonlijke velden, aanspreekvorm, timing-variabelen. Hij wordt door het **headersync-script** (`/usr/local/bin/templates/civicrm_templates_headersync.sh`) gesynchroniseerd naar alle 400+ custom templates.

**Gesimuleerde verzenddatum (primaire aanpak):** het headersync-script berekent de scheduleddatum als Smarty-math rechtstreeks in elke template-header:
```smarty
{math assign="m61testdatum_ts" equation="x - 604800" x=$user_kampstart_ts}
{assign var="m61testdatum" value=$kampstart_full}
{assign var="m61testlabel" value="1 week voor kampstart"}
```
De smarty_header overschrijft dan `$smartynow` met `$m61testdatum_ts` voor testcontacten (Testdeel/Testleid). Dit werkt zowel bij mailtest als bij handmatig verzenden via de UI — de template-header bevat de juiste datum al op basis van `$user_kampstart_ts`/`$user_kampeinde_ts`, die dankzij de mini-TokenProcessor correct zijn opgelost.

### Versiehistorie (relevant)

| Versie | Wijziging |
|--------|-----------|
| V3.0.0 | Native site tokens met Smarty: placeholder-techniek via civi.token.eval/render |
| V3.2.0 | `<p>`-tags in site token bodies gestript bij render |
| V3.3.0 | Dubbele witregels gecollapseerd |
| V3.5.0 | Block-tokens krijgen automatisch `<div>`-wrapper |
| V3.6.0 | `m61testdatum_ts` override voor gesimuleerde verzenddatum in smarty_header |
| V3.7.0 | CiviCRM tokens in site token bodies opgelost via mini-TokenProcessor in render stap 2 |

---

*Beheerd door Stichting Onvergetelijke Zomerkampen.*
