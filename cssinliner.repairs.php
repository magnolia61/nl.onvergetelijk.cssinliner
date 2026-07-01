<?php
/**
 * OZK Template Repair Functions — gedeelde source-of-truth
 *
 * Bevat alle HTML/Smarty-reparaties die bij het OPSLAAN van een template
 * worden toegepast. Zowel cssinliner (pre-hook) als de repair-scripts
 * (repair_w3c.sh, repair_css.sh) roepen deze functies aan — geen kopieën meer.
 *
 * Beschikbaar in elk cv php:script-context zodra de cssinliner-extensie is
 * geladen (cssinliner.php doet require_once van dit bestand).
 *
 * Alle functies ontvangen een HTML-string en retourneren de (eventueel
 * gewijzigde) HTML-string. Geen zijeffecten, geen globale state.
 */

/**
 * ISOLATIE — strip <p> rondom {crmScope}-blocktags.
 * CKEditor wikkelt scope-tags soms in een <p>, wat rendering breekt.
 */
function ozk_repair_isolatie(string $html): string {
    $html = preg_replace('/<p>\s*\{crmScope\s+extensionKey=""\}\s*<\/p>/i', '{crmScope extensionKey=""}', $html);
    $html = preg_replace('/<p>\s*\{\/crmScope\}\s*<\/p>/i', '{/crmScope}', $html);
    return $html;
}

/**
 * LOGICA — strip <p>-wrapper rondom losse {/if}-sluittags.
 *
 * LET OP: de {/if} zelf wordt NOOIT verwijderd — alleen de <p>-wrapper.
 * {/if}<p> is geldige Smarty en wordt niet aangeraakt (anders breekt de
 * if-balans → onrenderbare mail; bug cssinliner-ifstrip-regressie jun 2026).
 */
function ozk_repair_logica(string $html): string {
    // Eén {/if} in een <p>: strip de wrapper, bewaar de {/if}
    $html = preg_replace('/<p>\s*\{\/if\s*\}\s*<\/p>/i', '{/if}', $html);
    $html = preg_replace('/<p>\s*\{\/if\}\s*<\/p>/i',    '{/if}', $html);
    $html = str_replace('<p>{/if}</p>', '{/if}', $html);
    // Meerdere opeenvolgende {/if}'s in een <p>: strip wrapper, bewaar alle {/if}'s
    $html = preg_replace('/<p>\s*((?:\{\/if\}\s*){2,})<\/p>/i', '$1', $html);
    // { /if }<p> / { /if }\s*<p> — syntaxis-artefact in logica-context normaliseren
    $html = preg_replace('/\{\s*\/if\s*\}\s*(<p)/i', '{/if}$1', $html);
    return $html;
}

/**
 * SYNTAX — herstel spaties binnen Smarty-sluit-tags en bekende typo's.
 * CKEditor voegt soms spaties toe in { /if }, { /capture } etc.
 */
function ozk_repair_syntax(string $html): string {
    $html = preg_replace('/\{\s*\/if\s*\}/i',      '{/if}',      $html);
    $html = preg_replace('/\{\s*\/capture\s*\}/i', '{/capture}', $html);
    $html = preg_replace('/\{\s*\/assign\s*\}/i',  '{/assign}',  $html);
    $html = preg_replace('/var="user_fietsevent"\s*\}\s*value=/i', 'var="user_fietsevent" value=', $html);
    return $html;
}

/**
 * ENTITEIT — vertaal HTML-entiteiten terug naar logische operatoren in Smarty-context.
 * CKEditor HTML-escapet > en && in {if}-condities.
 */
function ozk_repair_entiteit(string $html): string {
    $html = str_replace(' &gt; ',      ' > ',  $html);
    $html = str_replace(' &lt; ',      ' < ',  $html);
    $html = str_replace(' &amp;&amp; ', ' && ', $html);
    return $html;
}

/**
 * OPERATOR — forceer UPPERCASE logische operatoren binnen {if}-condities.
 * Smarty eist AND/OR (uppercase); lowercase and/or werkt niet of is ambigue.
 */
function ozk_repair_operator(string $html): string {
    return preg_replace_callback('/\{if\s+(.*?)\}/i', function($m) {
        $c = preg_replace('/\b(and)\b/i', 'AND', $m[1]);
        $c = preg_replace('/\b(or)\b/i',  'OR',  $c);
        return '{if ' . $c . '}';
    }, $html);
}

/**
 * URL-PREFIX — verwijder domein-prefix vóór Smarty URL-variabelen in attributen.
 * CKEditor plakt soms het huidige domein voor een {$loginlink}-achtige variabele.
 * Patroon stopt aan de attribuutgrens zodat nooit buiten de href/src gesprongen wordt.
 */
function ozk_repair_url_prefix(string $html): string {
    static $smarty_url_vars = 'ozkweburl|ozkimgurl|loginrequest|loginlink|ozkstyles|ozkaccount|hlfimgurl';
    return preg_replace(
        '/(["\'\(])[^\'"\(\)\{\}]*(\{\$(?:' . $smarty_url_vars . ')[^}]*\})/i',
        '$1$2',
        $html
    );
}

/**
 * BLOCK-TOKENS — zorg dat block-level site-tokens in een eigen <div> staan.
 * Editor en verzending renderen {site.smarty_logo} etc. correct als ze op
 * eigen regel in een <div> staan in plaats van in een <p> of naakt.
 */
function ozk_repair_block_tokens(string $html): string {
    $block_tokens = 'site\.smarty_logo'
        . '|site\.smarty_intake_tips'
        . '|site\.smarty_checkleid'
        . '|site\.smarty_checkdeel'
        . '|site\.smarty_checktopkamp'
        . '|site\.smarty_checkintake'
        . '|site\.smarty_loginrequest_deel'
        . '|site\.smarty_loginrequest_leid'
        . '|site\.smarty_inloglink_request'
        . '|site\.smarty_fietshuur'
        . '|site\.smarty_fotos_hl'
        . '|site\.smarty_fotos_hl_tel';
    // <p>{token}</p> → <div>{token}</div>
    $html = preg_replace('/<p[^>]*>\s*(\{(?:' . $block_tokens . ')\})\s*<\/p>/i', '<div>$1</div>', $html);
    // <br>{token} → <div>{token}</div>
    $html = preg_replace('/<br\s*\/?>\s*(\{(?:' . $block_tokens . ')\})/i', '<div>$1</div>', $html);
    // Naakte token (niet al in <div>) → <div>{token}</div>
    $html = preg_replace('/(?<!<div>)(\{(?:' . $block_tokens . ')\})(?!\s*<\/div>)/i', '<div>$1</div>', $html);
    // Dedup: voorkom <div><div>{token}</div></div>
    $html = preg_replace('/<div>\s*<div>(\{(?:' . $block_tokens . ')\})<\/div>\s*<\/div>/i', '<div>$1</div>', $html);
    return $html;
}

/**
 * LOGO-WITRUIMTE — verwijder overbodige witruimte vóór en ná {site.smarty_logo}.
 * Geldt alleen op opgeslagen templates (Smarty-token nog letterlijk aanwezig).
 */
function ozk_repair_logo_whitespace(string $html): string {
    // Vóór logo: trailing <br> aan het einde van de groet-div
    $html = preg_replace('/(<br\s*\/?>\s*)(<\/div>\s*<div>\{site\.smarty_logo\}<\/div>)/si', '$2', $html);
    // Vóór logo: losse <br> of <p><br/></p>
    $html = preg_replace('/(<br\s*\/?>\s*)(<div>\{site\.smarty_logo\}<\/div>)/si',              '$2', $html);
    $html = preg_replace('/(<p>\s*<br\s*\/?>\s*<\/p>\s*)(<div>\{site\.smarty_logo\}<\/div>)/si', '$2', $html);
    // Na logo: stray <br>, lege elementen, of </p>
    $html = preg_replace('/(<div>\{site\.smarty_logo\}<\/div>)\s*<br\s*\/?>\r?\n?/i',             '$1', $html);
    $html = preg_replace('/(<div>\{site\.smarty_logo\}<\/div>)\s*<(?:p|div)>\s*(?:<br\s*\/?>)?\s*<\/(?:p|div)>/si', '$1', $html);
    $html = str_replace('<div>{site.smarty_logo}</div></p>', '<div>{site.smarty_logo}</div>', $html);
    // Oud patroon (zonder div-wrapper, fallback)
    $html = preg_replace('/(\{site\.smarty_logo\})(\s*<p[^>]*>)\s*<br\s*\/>/i', '$1$2', $html);
    $html = preg_replace('/(\{site\.smarty_logo\})\s*<p>\s*(<\/div>)/i',         '$1$2', $html);
    return $html;
}

/**
 * BIG — verwijder <big>-tags; de inhoud blijft behouden.
 * <big> vergroot tekst tov de omgeving — ongewenst in e-mail.
 */
function ozk_repair_big(string $html): string {
    return preg_replace('/<big>(.*?)<\/big>/is', '$1', $html);
}

/**
 * STRONG-IN-HEADING — verwijder <strong> direct binnen <h3>-<h6>.
 * Een heading is al intrinsiek bold; extra <strong> geeft dubbel nadruk.
 * Andere opmaak (bv. <u>) binnen de <strong> wordt bewaard.
 */
function ozk_repair_strong_in_heading(string $html): string {
    return preg_replace_callback('/<(h[3-6])([^>]*)>(.*?)<\/\1>/is', function($m) {
        $inner = preg_replace('/<strong>(.*?)<\/strong>/is', '$1', $m[3]);
        return '<' . $m[1] . $m[2] . '>' . $inner . '</' . $m[1] . '>';
    }, $html);
}

/**
 * SPACING — collapseer dubbele witregels: <br>, <p>, &nbsp;, lege divs, \n.
 * Iteratief (max 5 passes) zodat gecreëerde combinaties zelf ook worden opgelost.
 */
function ozk_repair_spacing(string $html): string {
    for ($pass = 0; $pass < 5; $pass++) {
        $before = $html;
        // Lege <p>: alleen whitespace, &nbsp; of één <br>
        $html = preg_replace('/<p[^>]*>\s*(<br\s*\/?>)?\s*<\/p>/i',       '',        $html);
        $html = preg_replace('/<p[^>]*>\s*(?:&nbsp;|\xc2\xa0)+\s*<\/p>/i', '',        $html);
        // Lege <div> (ook met attributen): alleen whitespace of &nbsp;
        $html = preg_replace('/<div[^>]*>\s*(?:&nbsp;|\xc2\xa0| )?\s*<\/div>/i', '', $html);
        // Opeenvolgende <br> (2+) → één <br />
        $html = preg_replace('/(?:<br\s*\/?>\s*){2,}/i', '<br />', $html);
        // <br> direct na </p> of voor <p> → overbodig bij block-elementen
        $html = preg_replace('/<\/p>\s*<br\s*\/?>/i', '</p>', $html);
        $html = preg_replace('/<br\s*\/?>\s*<p/i',    '<p',   $html);
        // Meerdere lege regels in tekst → max één
        $html = preg_replace('/\n{3,}/', "\n\n", $html);
        if ($html === $before) {
            break;
        }
    }
    return $html;
}

/**
 * GREETING — normaliseer de groetsectie naar <div class="ozk-groet">.
 * Behandelt vier patronen: meerdere <p>-regels, losse <div>, <div> met HTML erin,
 * en bare tekst direct vóór een M61BODY-marker.
 */
function ozk_repair_greeting(string $html): string {
    // A: eerste groet-<p> + eventuele vervolg-<p>'s → <div class="ozk-groet">
    $html = preg_replace_callback(
        '/(<p[^>]*>)((?:met\s+(?:een\s+)?(?:vriendelijke|hartelijke)|[Hh]artelijke\s+groet)[^<]*(?:<br\s*\/?>.*?)?<\/p>)((?:\s*<p[^>]*>[^<]*<\/p>)*)/is',
        function($m) {
            $inner = preg_replace('/<\/?p[^>]*>/i', '', $m[2]);
            $rest  = preg_replace('/<p[^>]*>(.*?)<\/p>/is', '<br />$1', $m[3]);
            return '<div class="ozk-groet">' . trim($inner) . $rest . '</div>';
        },
        $html
    );
    // B: losse <div> (of met oud margin-top style) die direct met een groetformule begint
    $html = preg_replace(
        '/<div(?:\s+style="[^"]*margin-top[^"]*")?>(?=\s*(?:met\s+(?:een\s+)?(?:vriendelijke|hartelijke)|[Hh]artelijke\s+groet|with\s+kind|regards|groet))/i',
        '<div class="ozk-groet">',
        $html
    );
    // D: <div> die een groetformule bevat NA willekeurige inline HTML
    //    Tempered greedy: (?!<\/?div\b) stopt bij geneste <div>-grenzen
    $html = preg_replace(
        '/<div>(?=(?:(?!<\/?div\b).)*(?:met\s+(?:een\s+)?(?:vriendelijke|hartelijke)|[Hh]artelijke\s+groet))/is',
        '<div class="ozk-groet">',
        $html
    );
    // E: bare tekst direct vóór {assign var="M61BODY"} of </body>
    $html = preg_replace_callback(
        '/(<br\s*\/?>\s*)((?:[^<]|<br\s*\/?>)*?(?:met\s+(?:een\s+)?(?:vriendelijke|hartelijke)|[Hh]artelijke\s+groet)(?:[^<]|<br\s*\/?>)*?)(\s*<div[^>]*>\{assign\s+var="M61BODY"|<\/body>)/is',
        function($m) {
            return $m[1] . '<div class="ozk-groet">' . trim($m[2]) . '</div>' . $m[3];
        },
        $html
    );
    // F: leidende <br> direct in .ozk-groet vóór de eigenlijke tekst strippen — de CSS
    // (margin-top: 1.5em op .ozk-groet) zorgt al voor de bovenmarge; een handmatige <br>
    // erbovenop gaf dubbele witruimte boven de aanhef (zie template 162/600, jul 2026).
    $html = preg_replace('/(<div class="ozk-groet">)\s*<br\s*\/?>\s*/i', '$1', $html);
    return $html;
}

/**
 * EMPTY-TAGS — verwijder lege structuurlabels (div, p, span, b, i zonder inhoud).
 * Iteratief (3 passes) om geneste lege tags te verwijderen.
 */
function ozk_repair_empty_tags(string $html): string {
    $html    = preg_replace('/<div[^>]*>\s*(?:&nbsp;|\xc2\xa0| )?\s*<\/div>/i', '', $html);
    $pattern = '/<(p|div|span|b|i)>\s*(?:&nbsp;|\xc2\xa0|\s)*\s*<\/\1>/i';
    for ($i = 0; $i < 3; $i++) {
        $html = preg_replace($pattern, '', $html);
    }
    return $html;
}

/**
 * TESTBOX — vervang class="testbox" door inline style.
 * De .testbox CSS-definitie is verwijderd uit custom_civicrm_email.css;
 * de stijl wordt nu direct meegestuurd zodat hij ook zonder extern stylesheet werkt.
 */
function ozk_repair_testbox(string $html): string {
    return preg_replace_callback(
        '/<(p|div|span)([^>]*)\bclass="([^"]*\btestbox\b[^"]*)"([^>]*)>/i',
        function($m) {
            $otherClasses = trim(preg_replace('/\btestbox\b/', '', $m[3]));
            $classAttr    = $otherClasses ? ' class="' . $otherClasses . '"' : '';
            return '<' . $m[1] . $m[2] . $classAttr
                . ' style="background-color:rgb(239,239,239);border:1px solid;padding:5px;"'
                . $m[4] . '>';
        },
        $html
    );
}

/**
 * Alle Smarty-syntaxreparaties in volgorde: isolatie→logica→syntax→entiteit→operator.
 * Alleen aanroepen op ruwe template-HTML (Smarty-tags nog letterlijk aanwezig),
 * NIET op gerenderde HTML.
 */
function ozk_repair_save_smarty(string $html): string {
    $html = ozk_repair_isolatie($html);
    $html = ozk_repair_logica($html);
    $html = ozk_repair_syntax($html);
    $html = ozk_repair_entiteit($html);
    $html = ozk_repair_operator($html);
    return $html;
}

/**
 * Alle markup-reparaties die zowel bij opslaan als na rendering relevant zijn:
 * spacing, groetsectie, big-tags, strong-in-heading, lege tags.
 */
function ozk_repair_markup(string $html): string {
    $html = ozk_repair_spacing($html);
    $html = ozk_repair_greeting($html);
    $html = ozk_repair_big($html);
    $html = ozk_repair_strong_in_heading($html);
    $html = ozk_repair_empty_tags($html);
    return $html;
}

/**
 * Alle save-time reparaties in volgorde.
 * Aanroepen bij het opslaan van een template (Smarty-tags nog rauw).
 */
function ozk_repair_save_all(string $html): string {
    $html = ozk_repair_save_smarty($html);
    $html = ozk_repair_url_prefix($html);
    $html = ozk_repair_block_tokens($html);
    $html = ozk_repair_logo_whitespace($html);
    $html = ozk_repair_markup($html);
    $html = ozk_repair_testbox($html);
    return $html;
}
