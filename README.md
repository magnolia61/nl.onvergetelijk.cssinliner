# Onvergetelijk CSS Inliner (`nl.onvergetelijk.cssinliner`)

Deze CiviCRM-extensie zorgt ervoor dat CSS-stijlen uit `<style>`-blokken automatisch worden omgezet naar inline `style`-attributen in de HTML-body van uitgaande e-mails. Dit is essentieel voor een consistente weergave in e-mailclients zoals Outlook en Gmail.

## 🛠 Technische Werking

De extensie haakt in op het e-mailproces van CiviCRM via de `alterMailParams` hook.

### 1. Hook Interceptie
Zodra CiviCRM een e-mail voorbereidt, wordt de functie `cssinline_civicrm_alterMailParams` aangeroepen.
* **Filter:** De extensie controleert of er daadwerkelijk HTML-inhoud aanwezig is.
* **Encoding:** Om compatibiliteit met **PHP 8.4** en Nederlandse speciale tekens te garanderen, wordt de HTML eerst omgezet naar UTF-8 HTML-entities via `mb_convert_encoding`.

### 2. Library Gebruik (Emogrifier)
In plaats van verouderde regex-gebaseerde vervangingen, gebruikt deze extensie de moderne **Pelago/Emogrifier** library.
* **DOM-gebaseerd:** De library laadt de HTML in een DOMDocument.
* **Inlining:** De library berekent welke CSS-selectors van toepassing zijn op welke HTML-elementen en voegt de stijlen direct toe.
* **Opschoning:** Na het inlinen worden de oorspronkelijke `<style>`-blokken verwijderd.

### 3. Logging & Debugging
De extensie maakt gebruik van de interne `wachthond` functie voor uitgebreide logging:
* **Niveau 1:** Kritieke fouten (try-catch blok).
* **Niveau 7:** Technische parameters (oorspronkelijke grootte vs. nieuwe grootte).
* **Niveau 9:** Succesvolle afronding met ontvangerinformatie.

---

## 🔄 Vergelijking: Onvergetelijk vs. Fuzion (cssinline)

| Kenmerk | Fuzion (`nz.co.fuzion.cssinliner`) | Onvergetelijk (`nl.onvergetelijk.cssinliner`) |
| :--- | :--- | :--- |
| **PHP Compatibiliteit** | Tot PHP 8.1 | **Fully PHP 8.4 Proof** |
| **Core Library** | `cssin` (verouderd) | **Emogrifier v7+** (actueel) |
| **Encoding Handling** | Beperkt (UTF-8 issues) | Strikt via `HTML-ENTITIES` |
| **Dependencies** | Hardcoded in `packages/` | Beheerd via **Composer** (`vendor/`) |

---

## 🚀 Installatie & Gebruik

### Vereisten
* CiviCRM 6.x
* PHP 8.2, 8.3 of 8.4
* Composer

### Installatie stappen
1. Kopieer de extensie naar je `ext/` map.
2. Voer composer uit in de extensie-map:
   ```bash
   composer install --no-dev