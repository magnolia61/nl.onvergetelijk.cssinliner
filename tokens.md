# CiviCRM Token Render Pipeline — Technische Documentatie

> Gebaseerd op praktijkonderzoek mei 2026  
> CiviCRM op Drupal 7 · Smarty 5 · PHP CLI + UI  
> Implementatie: `nl.onvergetelijk.cssinliner/cssinliner.php` V3.0.0

---

## 1. Overzicht van de volledige pipeline

Wanneer een mail wordt verzonden via `MessageTemplate::renderTemplateRaw()` + `Email.send`,
doorloopt de HTML de volgende stappen **in deze volgorde**:

```
[1] renderTemplateRaw()
      ↓
[2] TokenProcessor::evaluate()   ← civi.token.eval wordt hier dispatched
      ↓
[3] TokenProcessor::renderString()
      → tokenEscapeSmarty() wordt hier aangeroepen op elke token-waarde
      → $event->string opgebouwd (tokens vervangen door hun waarden / escaped waarden)
      → civi.token.render wordt hier dispatched
          ↓
          [3a] OZK listener (prio 10): placeholders → raw Smarty body
          ↓
          [3b] TokenCompatSubscriber::onRender() (prio 0):
                → pushScope($smartyTokenAliases)
                → parseOneOffStringThroughSmarty($e->string)
                    → {assign} statements uit template body draaien
                    → {$fotoactie} etc. worden ingevuld
                → popScope()   ← ALLE assign-variabelen VERDWIJNEN hier
      ↓
[4] alterMailParams hook   ← HIER zijn alle Smarty-variabelen al NULL
      ↓
[5] Emogrifier CSS inliner (cssinliner extensie)
      ↓
[6] Mail verzonden
```

---

## 2. TokenProcessor::evaluate() — civi.token.eval

**Bestand:** `Civi/Token/TokenProcessor.php` ~regel 353

```php
$event = new TokenValueEvent($this);
$this->dispatcher->dispatch('civi.token.eval', $event);
```

**Wat er gebeurt:**
- Alle geregistreerde token-subscribers (AbstractTokenSubscriber subclasses) worden aangeslagen
- Elke subscriber vult `$row->tokenProcessor->rowValues[$rowIdx]['text/html'][$entity][$field]` in
- `SiteTokens::evaluateToken()` haalt `body_html` op uit de database en slaat die op in `rowValues`
- De waarden zijn op dit punt nog **raw HTML** — nog niet geëscaped

**Prioriteiten bij civi.token.eval:**

| Prioriteit | Subscriber | Actie |
|-----------|-----------|-------|
| 1000 | `TokenCompatSubscriber::setupSmartyAliases` | Zet Smarty-aliassen op |
| 0 | `AbstractTokenSubscriber::evaluateTokens` | Alle token-subscribers, incl. SiteTokens |
| **-10** | **OZK: `_cssinliner_on_token_eval()`** | Detecteer Smarty in site tokens, zet placeholder |

**OZK listener (prio -10) — wat hij doet:**  
Vuurt NA SiteTokens (prio 0), dus alle site token waarden zijn al gezet.  
Loopt over alle rows → `rowValues['text/html']['site']`.  
Als een waarde `{$`, `{if`, `{foreach`, `{assign` of `{capture` bevat:
- Slaat de raw body op in `_cssinliner_smarty_token_cache()` (statische PHP array)
- Vervangt de waarde door `##OZK_SMARTY:veldnaam##` (geen accolades → tokenEscapeSmarty laat hem met rust)

---

## 3. TokenProcessor::renderString() — tokenEscapeSmarty

**Bestand:** `Civi/Token/TokenProcessor.php` ~regel 375–395

```php
$getToken = function(?string $fullToken, ...) use ($tokens, $useSmarty, ...) {
    if (isset($tokens[$entity][$field])) {
        $v = $tokens[$entity][$field];
        $v = $this->filterTokenValue($v, $modifier, $row, $message['format']);
        if ($useSmarty) {
            $v = \CRM_Utils_Token::tokenEscapeSmarty($v);  // ← HIER
        }
        return $v;
    }
    return $fullToken;
};
$event->string = $this->visitTokens($message['string'] ?? '', $getToken, $message['format']);
$this->dispatcher->dispatch('civi.token.render', $event);
```

**`tokenEscapeSmarty()` — `CRM/Utils/Token.php`:**

```php
public static function tokenEscapeSmarty(string $string): string {
    return str_replace(['{', '}'], ['{ldelim}', '{rdelim}'], $string);
}
```

**Effect:** Elke `{` in een token-waarde wordt `{ldelim}`, elke `}` wordt `{rdelim}`.

- `{$fotoactie}` in een site token body → `{ldelim}$fotoactie{rdelim}`
- Wanneer Smarty dit later rendert: `{ldelim}` → `{`, `{rdelim}` → `}` → **letterlijke tekst** `{$fotoactie}`, NIET de variabelenwaarde
- Dit is de fundamentele reden waarom Smarty-variabelen in site tokens zonder extra maatregelen niet werken

**OZK placeholder overleeft tokenEscapeSmarty:**  
`##OZK_SMARTY:smarty_checkdeel##` bevat geen `{` of `}` → wordt niet aangeraakt ✓

---

## 4. civi.token.render

**Bestand:** `Civi/Token/TokenProcessor.php` ~regel 393

```php
$this->dispatcher->dispatch('civi.token.render', $event);
return $event->string;
```

`$event->string` bevat op dit punt de template-HTML met alle CiviCRM-tokens al vervangen
(inclusief Smarty-escaping). Smarty heeft nog **niet** gedraaid.

**Prioriteiten bij civi.token.render:**

| Prioriteit | Listener | Actie |
|-----------|---------|-------|
| **10** | **OZK: `_cssinliner_on_token_render()`** | Vervang `##OZK_SMARTY:naam##` → raw Smarty body |
| 0 | `TokenCompatSubscriber::onRender()` | Draait Smarty via `parseOneOffStringThroughSmarty()` |

**OZK listener (prio 10) — wat hij doet:**  
Vuurt VOOR TokenCompatSubscriber (prio 0).  
Kijkt of `$e->string` `##OZK_SMARTY:` bevat.  
Vervangt elke placeholder door de bijbehorende raw Smarty body uit de statische cache.  
Resultaat: de raw Smarty-code (`{$fotoactie}`, `{if}` etc.) staat nu letterlijk in `$e->string`.

---

## 5. TokenCompatSubscriber::onRender() — Smarty render

**Bestand:** `Civi/Token/TokenCompatSubscriber.php` ~regel 57

```php
public function onRender(TokenRenderEvent $e): void {
    // [stap 1] Verwijder onopgeloste tokens en verwerk { }-constructies
    $e->string = $this->visitTokens(...);
    $e->string = preg_replace(...);  // { } spatie-constructies

    // [stap 2] Smarty render
    if ($useSmarty) {
        $smartyVars = [];
        foreach ($e->context['smartyTokenAlias'] ?? [] as $smartyName => $tokenName) {
            $smartyVars[$smartyName] = ...;  // token-waarden als Smarty-vars
        }
        \CRM_Core_Smarty::singleton()->pushScope($smartyVars);
        try {
            $e->string = \CRM_Utils_String::parseOneOffStringThroughSmarty($e->string);
        } finally {
            \CRM_Core_Smarty::singleton()->popScope();  // ← ALLE VARIABELEN WEG
        }
    }
}
```

**`parseOneOffStringThroughSmarty()` — `CRM/Utils/String.php` (Smarty 5 versie):**  
Gebruikt `eval:` resource:

```php
return CRM_Core_Smarty::singleton()->fetch('eval:' . $content);
```

> ⚠️ In Smarty 5 werkt `fetch('string:...')` NIET — `registerStringResource` is een no-op
> (`method_exists($smarty, 'register_resource')` is false in Smarty 5).  
> De `eval:` resource is de correcte Smarty-5-compatibele methode.

**Wat er tijdens Smarty-render gebeurt:**
1. `{assign}` statements uit de template body draaien → `$fotoactie`, `$fotoedit` etc. worden gezet in de Smarty scope
2. Alle `{$fotoactie}` etc. in `$e->string` worden vervangen door hun waarden
3. De OZK site token body (nu in `$e->string` dankzij de prio-10 listener) profiteert hiervan ✓

**`popScope()` — het kritieke moment:**  
Na `parseOneOffStringThroughSmarty` roept het `finally`-blok `popScope()` aan.  
Dit verwijdert **alle** via `{assign}` gezette variabelen uit de Smarty scope.  
Vanaf dit punt zijn `$fotoactie`, `$fotoedit`, `$zelfedit` etc. allemaal **NULL**.

---

## 6. alterMailParams hook — te laat voor Smarty-variabelen

**Registratie:** `hook_civicrm_alterMailParams`  
**Tijdstip:** na `popScope()`, dus NA stap 5

Alle Smarty-variabelen zijn hier al verdwenen. Pogingen om `parseOneOffStringThroughSmarty()`
hier aan te roepen leveren lege strings op (`{$fotoactie}` → variabele is NULL → `""`).

**Aanvullend probleem:** CiviCRM tokens met een punt in de naam (bijv. `{d7onetime.url}`)
geven een Smarty-5 parse error: *"Unexpected '.'"*. Dit was in mei 2026 reden om de
oorspronkelijke stap-0 herrender-poging in cssinliner volledig te verwijderen.

---

## 7. Smarty scope — pushScope / popScope

**`pushScope($vars)` — `CRM/Core/Smarty.php`:**  
Slaat de huidige waarden op van de opgegeven variabelen, zet de nieuwe waarden.

**`popScope()` — `CRM/Core/Smarty.php`:**  
Herstelt de opgeslagen waarden. Variabelen die via `{assign}` tijdens de render zijn aangemaakt
en NIET in de scope-stack zaten, worden op NULL gezet.

```
pushScope([])
  {assign var="fotoactie" value="Upload foto"}  → $fotoactie = "Upload foto" (in Smarty)
  {$fotoactie}  →  "Upload foto" ✓  (TIJDENS render)
popScope()      →  $fotoactie = NULL (DAARNA)
```

**Smarty function plugins** die geregistreerd zijn via `registerPlugin('function', ...)` draaien
TIJDENS de render, dus VOOR popScope. Ze hebben volledige toegang tot alle assign-variabelen
via `$smarty->getTemplateVars('fotoactie')`. Dit is de basis voor `{ozk_checkdeel}`.

---

## 8. SiteTokens — hoe site tokens werken

**Bestand:** `CRM/Core/SiteTokens.php`

```php
public function evaluateToken(TokenRow $row, $entity, $field, $prefetch = NULL): void {
    $row->format('text/html')->tokens($entity, $field, self::getSiteTokenValues()[$field]);
    $row->format('text/plain')->tokens($entity, $field, self::getSiteTokenValues(NULL, FALSE)[$field]);
}
```

`getSiteTokenValues()` haalt `body_html` rechtstreeks op via APIv4 `SiteToken::get`.  
De waarde wordt gecached in `\Civi::$statics` per domein/locale/sessie.

`$row->format('text/html')->tokens(...)` slaat de waarde op in `rowValues['text/html']['site'][$field]`.  
Bij render wordt deze waarde door `tokenEscapeSmarty()` gejaagd als de context Smarty is.

> **Conclusie:** Er is geen ingebouwde manier om Smarty-code in site token bodies te renderen.  
> De OZK placeholder-techniek (secties 2 en 4) omzeilt dit correct.

---

## 9. OZK Placeholder-techniek — volledige flow

Geïmplementeerd in: `cssinliner.php` V3.0.0  
Functies: `_cssinliner_on_token_eval()`, `_cssinliner_on_token_render()`, `_cssinliner_smarty_token_cache()`

```
Template body bevat:    {site.smarty_checkdeel}
Site token body_html:   <table>..{$fotoactie}..{$zelfedit}..</table>

── civi.token.eval, prio 0  (SiteTokens) ──────────────────────────────────────
  rowValues['text/html']['site']['smarty_checkdeel'] = '<table>..{$fotoactie}..'

── civi.token.eval, prio -10  (_cssinliner_on_token_eval) ─────────────────────
  detecteert {$ in de waarde
  cache['smarty_checkdeel'] = '<table>..{$fotoactie}..'         ← opgeslagen
  rowValues[..]['site']['smarty_checkdeel'] = '##OZK_SMARTY:smarty_checkdeel##'

── TokenProcessor::renderString() ─────────────────────────────────────────────
  tokenEscapeSmarty('##OZK_SMARTY:smarty_checkdeel##')
  → geen accolades → ongewijzigd ✓
  $e->string = '...##OZK_SMARTY:smarty_checkdeel##...'

── civi.token.render, prio 10  (_cssinliner_on_token_render) ──────────────────
  vervangt ##OZK_SMARTY:smarty_checkdeel## → '<table>..{$fotoactie}..'
  $e->string = '...<table>..{$fotoactie}...</table>...'

── civi.token.render, prio 0  (TokenCompatSubscriber::onRender) ───────────────
  parseOneOffStringThroughSmarty($e->string)
    {assign} uit template header → $fotoactie = "Upload foto" in scope
    {$fotoactie} → "Upload foto" ✓
  popScope()
```

**Registratie in `cssinliner_civicrm_config()`:**

```php
static $registered = FALSE;
if ($registered) return;
$registered = TRUE;

\Civi::dispatcher()->addListener('civi.token.eval',   '_cssinliner_on_token_eval',   -10);
\Civi::dispatcher()->addListener('civi.token.render', '_cssinliner_on_token_render',  10);
```

De `static $registered` guard voorkomt dubbele registratie (config-hook kan meerdere keren vuren).

---

## 10. Beperkingen en randgevallen

### Wat WEL werkt via de OZK techniek

| Constructie | Voorbeeld | Werkt? |
|-------------|-----------|--------|
| Smarty variabele | `{$fotoactie}` | ✓ |
| Smarty conditional | `{if $x}...{else}...{/if}` | ✓ |
| Smarty loop | `{foreach}` | ✓ |
| Smarty capture | `{capture assign="x"}` | ✓ |
| Geneste constructies | combinaties hierboven | ✓ |
| Gewone HTML site token (geen Smarty) | — | ✓ ongewijzigd |

### Wat NIET werkt

| Probleem | Reden |
|----------|-------|
| CiviCRM tokens in site token body (`{contact.first_name}`) | Al vervangen/leeg vóór Smarty-render |
| Smarty-vars die niet via `{assign}` in template body zijn gezet | Niet in scope tijdens eval: render |
| `{d7onetime.url}` en CiviCRM tokens met punt in naam | Smarty-5 parse error "Unexpected '.'" |

### Bulk-mailing / cron

`_cssinliner_smarty_token_cache()` is een statische PHP array (per process, per request).  
Bij bulk-mailing wordt de cache hergebruikt. Omdat site token bodies niet per contact variëren
is dit correct en efficiënt.  
⚠️ Als in de toekomst contact-specifieke site tokens komen, moet de cache worden herzien.

---

## 11. Relevante bestanden

Alle CiviCRM core: `/var/www/vhosts/ozkprod/web/sites/all/modules/civicrm/`  
OZK extensies: `/var/www/vhosts/ozkprod/web/sites/all/modules/civicrm_extensions/`

| Bestand | Rol |
|---------|-----|
| `Civi/Token/TokenProcessor.php` | Centrale orchestratie, tokenEscapeSmarty aanroep, event dispatch |
| `Civi/Token/TokenCompatSubscriber.php` | Smarty render via `parseOneOffStringThroughSmarty` |
| `CRM/Core/SiteTokens.php` | Site token evaluatie, DB fetch, rowValues schrijven |
| `Civi/Token/AbstractTokenSubscriber.php` | Base class voor alle token-subscribers |
| `CRM/Utils/Token.php` | `tokenEscapeSmarty()` |
| `CRM/Utils/String.php` | `parseOneOffStringThroughSmarty()` |
| `CRM/Core/Smarty.php` | `pushScope()` / `popScope()`, `registerPlugin()`, `fetchWith()` |
| `CRM/Core/BAO/MessageTemplate.php` | `renderTemplateRaw()`, timing van `alterMailParams` |
| `nl.onvergetelijk.cssinliner/cssinliner.php` | OZK implementatie V3.0.0 |
