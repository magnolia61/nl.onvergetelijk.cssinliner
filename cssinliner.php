<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: cssinliner.php
 * =======================================================================================
 * cssinliner_civicrm_pre()             Hook: pre (MessageTemplates opschonen voor DB)
 * cssinliner_civicrm_alterMailParams() Hook: alterMailParams (Uitgaande mail inlinen & cleanen)
 * _cssinliner_cleanup_html()           HTML Sanitisatie, token stripper & whitespace fix
 * _cssinliner_fetch_external_css()     Fetcher voor externe CSS met statische cache (cURL)
 * _cssinliner_analyze_templates()      Vergelijkt in/out HTML voor diff logging
 * cssinliner_civicrm_config()          Configuratie inladen + event listeners registratie
 * _cssinliner_on_token_eval()          civi.token.eval listener: site tokens met Smarty → placeholder
 * _cssinliner_on_token_render()        civi.token.render listener: placeholder → raw Smarty body
 * =======================================================================================
 * CHANGELOG:
 * V3.4.0 - Smarty-reparaties bij opslaan: ISOLATIE (crmScope), LOGICA ({/if}), SYNTAX ({ /if }), ENTITEIT (&gt;/&lt;/&amp;&amp;), OPERATOR (and/or→AND/OR).
 * V3.3.0 - Dubbele witregels (<br><br>, <p><br></p><br>, etc.) worden bij render gecollapseerd tot één.
 * V3.7.0 - CiviCRM tokens ({entity.field}) in site token Smarty-bodies worden bij civi.token.eval gesubs­titueerd met reeds opgeloste waarden → $kampstart_ts/$kampeinde_ts/$dayssince werken correct.
 * V3.6.0 - Geen cssinliner-injectie meer nodig voor datum-override: smarty_header overschrijft {$smartynow} direct voor Testdeel/Testleid indien m61sched_ts > 0. Alle afgeleide tijdvariabelen ($smartynow_time, $weeksuntil etc.) volgen automatisch.
 * V3.5.0 - Block-tokens (smarty_logo, checkleid/deel/top, loginrequest, intake_tips) krijgen automatisch <div>-wrapper zodat ze op eigen regel staan in editor én email.
 * V3.2.0 - <p>-tags in site token bodies worden bij render gestript (CKEditor-vriendelijk).
 * V3.1.0 - {ozk_checkdeel} Smarty plugin verwijderd (vervangen door {site.smarty_checkdeel}).
 * V3.0.0 - Native site tokens met Smarty-variabelen: placeholder-techniek via civi.token.eval/render.
 * V2.9.0 - Smarty plugin {ozk_checkdeel} toegevoegd; stap-0 debug-code verwijderd.
 * V2.8.1 - Gedetailleerde sub-logs (niveau 4) hersteld in cleanup-functie.
 * V2.8.0 - Geavanceerde Civi::paths() en dirname() fallback toegevoegd voor Cron/API CSS fetch.
 * V2.7.1 - Uniforme sectiekopjes met vaste functie-prefix (bijv. [ALTERMAIL] - 1.0).
 * V2.7.0 - Nummering per functie onafhankelijk herstart, slimme sectiekopjes per functie.
 * V2.6.1 - Log-drempelwaarde max 4, subsectie-kopjes toegevoegd.
 * =======================================================================================
 */

$extRoot    = __DIR__ . DIRECTORY_SEPARATOR;
if (file_exists($extRoot . 'vendor/autoload.php')) {
    require_once $extRoot . 'vendor/autoload.php';
}
if (file_exists($extRoot . 'cssinliner.civix.php')) {
    require_once 'cssinliner.civix.php';
}
require_once __DIR__ . '/cssinliner.repairs.php';

use Pelago\Emogrifier\CssInliner;

/**
 * Hook: pre
 * Wordt getriggerd voordat een MessageTemplate wordt opgeslagen in de database.
 */
function cssinliner_civicrm_pre($op, $objectName, $id, &$params) {
    $extdebug   = 'cssinliner';

    if ($objectName !== 'MessageTemplate' || !in_array($op, ['create', 'edit'])) {
        return;
    }
    if (empty($params['msg_html'])) {
        return;
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [PRE] - 1.0 TEMPLATE CLEANUP VOOR DB",         "[DB-SAVE]");
    wachthond($extdebug, 2, "########################################################################");

    $template_subj  = $params['msg_subject']    ?? 'Onvergetelijke Zomerkampen';

    try {
        $params_clean   = [
            'html'      => strlen($params['msg_html']) . ' bytes',
            'subject'   => $template_subj,
            'is_final'  => FALSE
        ];

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CSSINLINER [PRE] - 1.1 START PRE-SAVE CLEANUP",         "[PRE-CLEAN]");
        wachthond($extdebug, 2, "########################################################################");
        
        wachthond($extdebug, 4, "Parameters voor opschoning",                       $params_clean);

        $params['msg_html'] = _cssinliner_cleanup_html($params['msg_html'], $template_subj, FALSE);
        
        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CSSINLINER [PRE] - 1.2 TEMPLATE HTML OPGESCHOOND",         "[SUCCES]");
        wachthond($extdebug, 2, "########################################################################");

    } catch (\Exception $e) {
        wachthond($extdebug, 1, "CRITICAL ERROR TIJDENS TEMPLATE DB CLEANUP: " . $e->getMessage(),  "[ERROR]");
    }
}

/**
 * Hook: alterMailParams
 * Onderschept de mail net voor verzending om CSS in te linen (UI & API/Cron).
 *
 * Verwante alterMailParams-hooks elders (géén overlap in verantwoordelijkheid):
 * - nl.onvergetelijk.batchreminders: CLI-only throttling/logging van batch-reminders.
 * - nl.onvergetelijk.event: registreert de token {event.gcalendar_link} (agenda-link
 *   voor "Add to calendar"-knoppen) via civi.token.list/eval — GEEN alterMailParams,
 *   maar wel gerelateerd aan mail-verrijking rond hetzelfde send-moment.
 * Deze hook (cssinliner) doet uitsluitend HTML/CSS-opmaak; geen van de bovenstaande
 * vult hier iets in.
 */
function cssinliner_civicrm_alterMailParams(&$params, $context = NULL) {
    $extdebug   = 'cssinliner';

    // Zorg dat date_format (strftime) altijd Nederlandse dag/maandnamen geeft
    setlocale(LC_TIME, 'nl_NL.utf8', 'nl_NL', 'Dutch');

    if (empty($params['html'])) {
        return;
    }

    $log_params = [
        'context'   => $context ?? 'ONBEKEND',
        'html_lengte'   => strlen($params['html'])
    ];

    // --- OZK & API FALLBACK DETECTIE MET CONTEXT LOGGING ---
    $is_ozk_mail    = FALSE;
    $detectie_reden = 'Geen match';

    if (strpos($params['html'], 'custom_civicrm_email.css') !== FALSE) {
        $is_ozk_mail    = TRUE;
        $detectie_reden = 'Stylesheet link gevonden in HTML';
    } elseif (strpos($params['html'], 'nl.onvergetelijk.cssinliner') !== FALSE) {
        $is_ozk_mail    = TRUE;
        $detectie_reden = 'Generator meta-tag (nl.onvergetelijk.cssinliner) gevonden';
    } elseif (strpos($params['html'], 'onvergetelijk.nl') !== FALSE && in_array($context, ['api', 'messageTemplate'])) {
        $is_ozk_mail    = TRUE;
        $detectie_reden = 'Brede fallback geactiveerd voor context: ' . $context;
    }

    if (!$is_ozk_mail) {
        wachthond($extdebug, 4, "GEEN OZK MAIL GEDETECTEERD, INLINER GESTOPT",  ['context_ontvangen' => $context]);
        return;
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [ALTERMAIL] - 1.0 UITGAANDE MAIL DETECTIE",     "[MAIL-OUT]");
    wachthond($extdebug, 2, "########################################################################");
    
    wachthond($extdebug, 4, "Hook Getriggerd (alterMailParams)",                        $log_params);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [ALTERMAIL] - 1.1 OZK MAIL GEDETECTEERD",           "[DETECTED]");
    wachthond($extdebug, 2, "########################################################################");
    
    wachthond($extdebug, 4, "Detectie details",                                 ['reden' => $detectie_reden, 'context_ontvangen' => $context]);

    // --- OZK-mails zijn HTML-only ---
    // msg_text is in alle templates bewust leeg (conventie). CiviCRM genereert dan zelf
    // een text/plain-deel via html2text, maar de footer (checklist/HL-foto's, M61-blok)
    // is op dat moment nog niet gerenderd → die belandt als rauwe Smarty/{if}-code in de
    // tekstversie. Omdat we de tekstversie toch niet zelf onderhouden, verwijderen we 'm:
    // de mail gaat HTML-only (wat vrijwel elke client toont). Alleen voor OZK-mail.
    if (!empty($params['text'])) {
        wachthond($extdebug, 4, "Tekst-deel verwijderd → HTML-only",            ['text_lengte_was' => strlen($params['text'])]);
        $params['text'] = '';
    }

    $original_html  = $params['html']   ?? '';
    $all_css    = '';

    // Vang het template-ID op dat headersync bovenin de template-body zette
    // (<meta name="msg-template-id">) VOORDAT de meta-strip hieronder 'm wist.
    // We zetten 'm straks opnieuw in de verse <head>, zodat je aan de bron van
    // een verzonden mail direct ziet om welk MessageTemplate het gaat.
    $tpl_id     = NULL;
    if (preg_match('/<meta\s+name=["\']msg-template-id["\']\s+content=["\'](\d+)["\']/i', $original_html, $mtid)) {
        $tpl_id = $mtid[1];
    }
    wachthond($extdebug, 4, "Template-ID uit bron geëxtraheerd",                ['msg_template_id' => $tpl_id ?? 'ONBEKEND']);

    try {
        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CSSINLINER [ALTERMAIL] - 1.2 START CSS OPHALEN & FALLBACKS",   "[FETCHING]");
        wachthond($extdebug, 2, "########################################################################");

        // Zoek naar externe stylesheets in de brontemplate
        preg_match_all('/<link [^>]*href=["\']([^"\']+\.css[^"\']*)["\'][^>]*>/i', $original_html, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $css_url) {
                if (strpos($css_url, '//') === FALSE) {
                    $css_url    = CRM_Utils_System::basePath() . ltrim($css_url, '/');
                }
                $css_content    = _cssinliner_fetch_external_css($css_url);

                if (!empty($css_content)) {
                    $all_css    .= $css_content . "\n";
                    wachthond($extdebug, 4, "--- 1.2.1 CSS OPGEHAALD VIA LINK TAG ---",     ['url' => $css_url, 'bytes' => strlen($css_content)]);
                }
            }
        }

        // --- FALLBACK ALS API/CRON DE LINK HEEFT GESTRIPT OF GEMIST ---
        if (empty(trim($all_css))) {
            wachthond($extdebug, 4, "--- 1.2.2 GEEN CSS IN BRON, START FALLBACK ---",       ['actie' => 'FALLBACK-INIT']);

            $fallback_url   = 'https://www.onvergetelijk.nl/sites/all/modules/civicrm_extensions/custom_civicrm_email.css';
            $fallback_css   = _cssinliner_fetch_external_css($fallback_url);

            if (!empty($fallback_css)) {
                $all_css    .= $fallback_css . "\n";
                wachthond($extdebug, 4, "--- 1.2.3 FALLBACK CSS SUCCESVOL GEBRUIKT ---",    ['bytes' => strlen($fallback_css)]);
            } else {
                wachthond($extdebug, 1, "--- 1.2.4 FALLBACK CSS OPHALEN MISLUKT! ---",      "[FOUT]");
            }
        }

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CSSINLINER [ALTERMAIL] - 1.3 DOM PREPARATIE VOOR API",     "[DOM-WRAP]");
        wachthond($extdebug, 2, "########################################################################");

        // --- HTML DOM WRAPPER & SANITISATIE VOOR API ---
        $safe_html  = $original_html;
        $is_wrapped = false;
        
        wachthond($extdebug, 4, "SAFE_HTML PRE-SANITATION",                     ['bytes' => strlen($safe_html)]);

        // 1. Strip alle corrupte of verdwaalde headers weg voordat we wrappen.
        $safe_html  = preg_replace('/<head[^>]*>.*?<\/head>/is',    '', $safe_html);
        $safe_html  = preg_replace('/<meta[^>]*>/i',        '', $safe_html);
        $safe_html  = preg_replace('/<title[^>]*>.*?<\/title>/is',  '', $safe_html);
        $safe_html  = preg_replace('/<(\/)?(html|head|body)[^>]*>/i','',    $safe_html);
        $safe_html  = preg_replace('/<!DOCTYPE[^>]*>/i',        '', $safe_html);
        
        $safe_html  = trim($safe_html);
        
        // 2. Bouw een waterdicht HTML5 document op voor de parser
        $safe_html  = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n</head>\n<body>\n" . $safe_html . "\n</body>\n</html>";
        $is_wrapped = true;
        
        wachthond($extdebug, 4, "--- 1.3.1 TIJDELIJKE HTML SCHIL GEBOUWD ---",  ['actie' => 'WRAP_AND_CLEAN', 'bytes' => strlen($safe_html)]);
        
        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CSSINLINER [ALTERMAIL] - 1.4 START EMOGRIFIER RENDER",     "[RENDER]");
        wachthond($extdebug, 2, "########################################################################");
        
        wachthond($extdebug, 4, "CSS en HTML ingeladen in parser",              ['css_bytes' => strlen($all_css)]);
        
        $visual_inliner = CssInliner::fromHtml($safe_html);

        if (!empty($all_css)) {
            $visual_inliner->inlineCss($all_css);
        }
        
        // Render het volledige document
        $rendered_html  = $visual_inliner->render();
        wachthond($extdebug, 4, "--- 1.4.1 HTML GERENDERD DOOR EMOGRIFIER ---", ['bytes_out' => strlen($rendered_html)]);

        // Sloop de tijdelijke schil er weer netjes af
        if ($is_wrapped) {
            // Behoud een eventueel <style>-blok dat Emogrifier in de <head> achterliet:
            // dat bevat de ON-inlinebare regels (m.n. @media voor responsive opmaak, bv.
            // desktop-only line-height). Email-clients lezen <style> ook in de body, dus we
            // zetten het vóór de body-inhoud terug — anders gooit de body-extractie hieronder
            // die responsive regels weg.
            $preserve_style = '';
            if (preg_match('/<style\b[^>]*>(.*?)<\/style>/is', $rendered_html, $style_matches)) {
                $style_inner = trim($style_matches[1]);
                if ($style_inner !== '') {
                    $preserve_style = "<style type=\"text/css\">" . $style_inner . "</style>\n";
                    wachthond($extdebug, 4, "--- 1.4.1b RESPONSIVE <style> BEHOUDEN ---",   ['bytes' => strlen($style_inner)]);
                }
            }
            preg_match("/<body[^>]*>(.*?)<\/body>/is", $rendered_html, $body_matches);
            if (!empty($body_matches[1])) {
                $rendered_html  = $preserve_style . trim($body_matches[1]);
                wachthond($extdebug, 4, "--- 1.4.2 TIJDELIJKE HTML SCHIL WEER VERWIJDERD ---",  ['actie' => 'UNWRAP', 'bytes' => strlen($rendered_html)]);
            } else {
                wachthond($extdebug, 3, "--- 1.4.3 FOUT BIJ UNWRAPPEN VAN BODY TAG ---",    "[ERROR]");
            }
        }

        // Strip witruimte-artefacten vóór een site-logo div.
        // 1. Lege <p><br></p> of kale <br> direct voor de logo-div verwijderen.
        // 2. margin-bottom van de <p> direct vóór de logo-div op 0 zetten (anders geeft die p
        //    alsnog 15px ruimte boven het logo).
        $logo_div = '(<div\b[^>]*\bclass="[^"]*\bsite-logo\b[^"]*")';
        // Trailing <br> aan het einde van de groet-div vóór logo → weghalen (geeft anders extra hoogte)
        $rendered_html = preg_replace('/<br\s*\/?>\s*(<\/div>\s*)' . $logo_div . '/i', '$1$2', $rendered_html);
        $rendered_html = preg_replace('/<p[^>]*>\s*<br\s*\/?>\s*<\/p>\s*' . $logo_div . '/i', '$1', $rendered_html);
        $rendered_html = preg_replace('/<br\s*\/?>\s*'                     . $logo_div . '/i', '$1', $rendered_html);

        $logo_pos = strpos($rendered_html, '<div class="site-logo"');
        if ($logo_pos !== false) {
            $before    = substr($rendered_html, 0, $logo_pos);
            $p_end     = strrpos($before, '</p>');
            if ($p_end !== false) {
                $p_start = strrpos(substr($before, 0, $p_end), '<p ');
                if ($p_start !== false) {
                    $p_html  = substr($rendered_html, $p_start, $p_end - $p_start + 4);
                    $p_fixed = preg_replace('/\bmargin-bottom:\s*\d+px\b/i', 'margin-bottom: 0px', $p_html);
                    $rendered_html = substr($rendered_html, 0, $p_start) . $p_fixed . substr($rendered_html, $p_start + strlen($p_html));
                }
            }
        }

        _cssinliner_analyze_templates($original_html, $rendered_html);

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CSSINLINER [ALTERMAIL] - 1.5 START FINALE CLEANUP",        "[CLEANUP-INIT]");
        wachthond($extdebug, 2, "########################################################################");
        
        $params['html'] = _cssinliner_cleanup_html($rendered_html, $params['subject'] ?? 'Onvergetelijke Zomerkampen', TRUE, $tpl_id);

        // Stap 1.6: geen embedding — afbeeldingen blijven als externe URLs staan.
        // base64 data-URI's worden door Gmail geblokkeerd; CID vereist een core-patch.
        // Externe URLs op www.onvergetelijk.nl werken correct in alle grote mail-clients.

    } catch (\Exception $e) {
        wachthond($extdebug, 1, "CRITICAL ERROR TIJDENS MAIL PARSING: " . $e->getMessage(),     "[ERROR]");
    }
}

/**
 * HTML opschonen en CiviCRM-vervuiling verwijderen
 */
function _cssinliner_cleanup_html($html, $title = 'Onvergetelijke Zomerkampen', $is_final_send = FALSE, $tpl_id = NULL) {
    $extdebug   = 'cssinliner';
    $stats      = [];

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [CLEANUP] - 1.0 HTML OPSCHONING EN FIXES START",        "[CLEANUP]");
    wachthond($extdebug, 2, "########################################################################");

    $log_params = [
        'is_final_send' => $is_final_send   ? 'JA' : 'NEE',
        'bytes_in'  => strlen($html)
    ];
    
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [CLEANUP] - 1.1 START REGEX STRIPPEN & FIXES",      "[REGEX-FIX]");
    wachthond($extdebug, 2, "########################################################################");
    
    wachthond($extdebug, 4, "Parameters voor cleanup",                          $log_params);

    // 0. Strip PHP-foutmeldingen (Deprecated/Warning/Notice) die door display_errors in de HTML zijn
    //    terechtgekomen, bijv. uit de Smarty Math.php plugin bij null-waarden zonder |default:0.
    $html = preg_replace(
        '/<br\s*\/?>\s*\n?<b>(?:Deprecated|Warning|Notice|Fatal error|Parse error)<\/b>\s*:.*?<br\s*\/?>/s',
        '',
        $html,
        -1,
        $stats['php_foutmeldingen_gestript']
    );

    // 1. Verwijder HTML comments volledig
    $html       = preg_replace('//is', '', $html, -1, $stats['html_comments_verwijderd']);

    // 2. Ruim stray tekst op van eerdere foutieve pogingen
    $html       = str_replace('> alt=""', '>', $html, $stats['stray_alt_tekst_verwijderd']);

    wachthond($extdebug, 4, "--- 1.1.1 COMMENTS & STRAY TEKST VERWIJDERD ---",  ['acties' => ($stats['html_comments_verwijderd'] ?? 0) + ($stats['stray_alt_tekst_verwijderd'] ?? 0)]);

    // 2b. Smarty-template reparaties (alleen bij opslaan — bij render zijn Smarty-tags al verwerkt).
    // Gedeeld via cssinliner.repairs.php → ozk_repair_save_smarty() is de canonieke implementatie.
    if (!$is_final_send) {
        $html = ozk_repair_save_smarty($html);
    }

    $config     = CRM_Core_Config::singleton();
    $base_url   = rtrim($config->userFrameworkBaseURL, '/');

    // 3a. URL-prefix stripper — gedeeld via cssinliner.repairs.php.
    $html       = ozk_repair_url_prefix($html);

    // 3b. Block-token normalisatie — gedeeld via cssinliner.repairs.php.
    $html = ozk_repair_block_tokens($html);

    // 3c. Witruimte ROND {site.smarty_logo} — gedeeld via cssinliner.repairs.php.
    $html = ozk_repair_logo_whitespace($html);

    $html       = preg_replace('/<img[^>]+src=["\']([^"\']+\/ozkimages\/)["\']([^>]*)>/i', '', $html, -1, $stats['loze_img_tags_verwijderd']);

    $html       = preg_replace_callback('/<img([^>]+)src=["\'](https?:\/\/[^\'"]+)(https?:\/\/[^\'"]+)["\']([^>]*)>/i', function($matches) {
        return "<img{$matches[1]}src=\"{$matches[3]}\"{$matches[4]}>";
    }, $html, -1, $stats['dubbele_url_prefixes_hersteld']);

    $html       = preg_replace_callback('/<img([^>]+)src=["\']([^"\']+)["\']([^>]*)>/i', function($matches) use ($base_url) {
        $src    = $matches[2];
        if (strpos($src, '{$') !== false) {
            return $matches[0];
        }
        if (!preg_match('/^https?:\/\//i', $src)) {
            $src    = $base_url . '/' . ltrim($src, '/');
        }
        return "<img{$matches[1]}src=\"{$src}\"{$matches[3]}>";
    }, $html, -1, $stats['img_paden_absoluut_gemaakt']);

    wachthond($extdebug, 4, "--- 1.1.2 URLS & AFBEELDINGSPADEN HERSTELD ---",   ['absoluut_gemaakt' => $stats['img_paden_absoluut_gemaakt'] ?? 0]);

    // 4. Voeg alt="" toe BINNEN de img tag
    $html       = preg_replace_callback('/<img(?![^>]*\balt=)([^>]+)>/i', function($m) {
        return '<img alt="" ' . $m[1] . '>';
    }, $html, -1, $stats['ontbrekende_alt_attributen_geinjecteerd']);

    // 5. Verwijder stray head-tags uit de body (Alleen bij finale verzending)
    if ($is_final_send) {
        $html   = preg_replace('/<link[^>]*>/i',            '', $html, -1, $cnt_link);
        $html   = preg_replace('/<meta http-equiv="Content-Type"[^>]*>/i','', $html, -1, $cnt_meta);
        
        if ($cnt_link > 0 || $cnt_meta > 0) {
            wachthond($extdebug, 4, "--- 1.1.3 STRAY HEAD TAGS VERWIJDERD ---", ['links' => $cnt_link, 'metas' => $cnt_meta]);
        }
    }
    
    // 5a. Voeg witruimte (lege regel) toe boven "Met vriendelijke groet" als die direct na </p> staat.
    //     Zonder deze regel plakt de aanhef visueel vast aan de voorgaande paragraaf.
    $html = preg_replace(
        '/(<\/p>)(<div[^>]*>(?:met|Met) vriendelijke groet)/i',
        '$1<br>$2',
        $html
    );

    // 5b. Verwijder <big>-tags — gedeeld via cssinliner.repairs.php.
    $html = ozk_repair_big($html);

    // 5c-pre. Testbox class → inline style — gedeeld via cssinliner.repairs.php.
    $html = ozk_repair_testbox($html);

    // 5d. Collapseer dubbele witregels — gedeeld via cssinliner.repairs.php.
    $html = ozk_repair_spacing($html);

    // 5e. Normaliseer groetsectie naar <div class="ozk-groet"> — gedeeld via cssinliner.repairs.php.
    $html = ozk_repair_greeting($html);
    // 5c. Verwijder <strong> direct binnen <h3>-<h6> — gedeeld via cssinliner.repairs.php.
    $html = ozk_repair_strong_in_heading($html);

    // 6. Verwijder lege tags — gedeeld via cssinliner.repairs.php.
    $html = ozk_repair_empty_tags($html);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [CLEANUP] - 1.2 TOPELEMENT & SCHIL HERSTEL",            "[STRUCTURE]");
    wachthond($extdebug, 2, "########################################################################");

    // 7. Forceer de valide HTML5 schil
    $html       = preg_replace('/<(\/)?(html|head|body)[^>]*>/i',   '', $html);
    $html       = preg_replace('/<!DOCTYPE[^>]*>/i',            '', $html);
    // 7a. Strip orphaned head-content (meta/title/viewport) die achterblijven na vorige runs
    $html       = preg_replace('/<meta\s+charset="utf-8">\s*<meta\s+name="generator"\s+content="nl\.onvergetelijk\.cssinliner">\s*<title>.*?<\/title>\s*<meta\s+name="viewport"[^>]*>/is', '', $html);

    // 7b. TOPELEMENT REGEX FIX: Wist elke lege paragraaf die zich vóór de aanhef bevindt
    $html       = trim($html);
    
    if (preg_match('/(Hallo|Beste|Geachte|Hoi|Dear)/i', $html, $anker_matches, PREG_OFFSET_CAPTURE)) {
        $anker_pos  = $anker_matches[0][1];
        $header_part    = substr($html, 0, $anker_pos);
        $body_part  = substr($html, $anker_pos);
        
        $header_part    = preg_replace('/<p[^>]*>\s*(?:&nbsp;|\s)*\s*<\/p>/i', '', $header_part, -1, $stats['top_spook_paragrafen_gestript']);
        $html       = $header_part . $body_part;
        
        wachthond($extdebug, 4, "--- 1.2.1 SPOOKPARAGRAFEN VOOR AANHEF GEWIST ---",         ['aantal' => $stats['top_spook_paragrafen_gestript'] ?? 0]);
    }

    $clean_html = "<!DOCTYPE html>\n";
    $clean_html .= "<html lang=\"nl\">\n";
    $clean_html .= "<head>\n";
    $clean_html .= "    <meta charset=\"utf-8\">\n";
    $clean_html .= "    <meta name=\"generator\" content=\"nl.onvergetelijk.cssinliner\">\n";
    // Herplaats het template-ID (opgevangen in alterMailParams vóór de meta-strip).
    // Hierdoor blijft in de bron van een verzonden mail zichtbaar welk MessageTemplate
    // de basis was — handig om snel de juiste template terug te vinden.
    if (!empty($tpl_id)) {
        $clean_html .= "    <meta name=\"msg-template-id\" content=\"" . (int) $tpl_id . "\">\n";
    }
    $safe_title = preg_replace('/\{[^}]*\}/', '', $title); // strip Smarty-tags zodat {$smarty.now|date_format:&quot;...&quot;} niet crasht
    $clean_html .= "    <title>" . htmlspecialchars($safe_title) . "</title>\n";
    $clean_html .= "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
    $clean_html .= "</head>\n";
    $clean_html .= "<body style=\"margin:0;padding:0;font-family:Arial,sans-serif;\">\n";
    $clean_html .= trim($html) . "\n";
    $clean_html .= "</body>\n";
    $clean_html .= "</html>";

    // 8. Trek de HTML visueel strak
    $clean_html = preg_replace('/>[\s\n\r]+</', '><', $clean_html, -1, $stats['witregels_tussen_tags_geminimaliseerd']);

    $stats      = array_filter($stats, function($val) { return $val > 0; });
    
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [CLEANUP] - 1.3 CLEANUP AFGEROND (STATISTIEKEN)",       "[STATS]");
    wachthond($extdebug, 2, "########################################################################");

    if (!empty($stats)) {
        wachthond($extdebug, 4, "Resultaten (Aantal acties)",                       $stats);
    }

    return $clean_html;
}

/**
 * Fetcher voor externe CSS (CLI / CRON COMPATIBEL)
 */
function _cssinliner_fetch_external_css($url) {
    static $css_cache   = [];
    $extdebug       = 'cssinliner';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [FETCHCSS] - 1.0 EXTERNE CSS OPHALEN",          "[FETCH]");
    wachthond($extdebug, 2, "########################################################################");

    if (isset($css_cache[$url])) {
        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CSSINLINER [FETCHCSS] - 1.1 CSS CACHE CHECK",          "[CACHE]");
        wachthond($extdebug, 2, "########################################################################");
        
        wachthond($extdebug, 4, "CSS gevonden in statische cache",                  ['url' => $url]);
        return $css_cache[$url];
    }
    
    $content    = FALSE;
    $parsed_url = parse_url($url);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [FETCHCSS] - 1.2 LOKALE BESTANDEN CHECK",           "[LOCAL]");
    wachthond($extdebug, 2, "########################################################################");

    // Bepaal op een CLI-veilige manier wat de document root is
    $doc_root   = $_SERVER['DOCUMENT_ROOT'] ?? '';
    
    if (empty($doc_root) && defined('DRUPAL_ROOT')) {
        $doc_root   = DRUPAL_ROOT;
    }
    if (empty($doc_root) && class_exists('Civi')) {
        $doc_root   = rtrim(\Civi::paths()->get('[cms.root]/.'), '/');
    }

    if (isset($parsed_url['path']) && !empty($doc_root)) {
        $local_path = rtrim($doc_root, '/') . '/' . ltrim($parsed_url['path'], '/');
        $local_path = preg_replace('#/+#', '/', $local_path);
        
        if (file_exists($local_path)) {
            $content    = @file_get_contents($local_path);
            wachthond($extdebug, 4, "--- 1.2.1 LOKAAL BESTAND OPGEHAALD ---",        ['pad' => $local_path, 'bytes' => strlen((string)$content)]);
        } else {
            wachthond($extdebug, 4, "--- 1.2.2 LOKAAL BESTAND NIET GEVONDEN ---",    ['pad' => $local_path]);
        }
    }
    
    // EXTRA FALLBACK: Zoek direct via de extensie directory fallback
    // (custom_civicrm_email.css woont sinds 1 jul 2026 IN deze extensiemap, niet meer los in civicrm_extensions/)
    // Alleen toepassen als de gevraagde URL ook echt custom_civicrm_email.css betreft,
    // anders krijgen andere externe CSS-URL's (bv. Google Fonts) ten onrechte onze eigen CSS terug.
    $url_basename = isset($parsed_url['path']) ? basename($parsed_url['path']) : basename($url);
    if (($content === FALSE || trim($content) === '') && $url_basename === 'custom_civicrm_email.css') {
        $ext_dir_path = __DIR__ . '/custom_civicrm_email.css';
        if (file_exists($ext_dir_path)) {
            $content = @file_get_contents($ext_dir_path);
            wachthond($extdebug, 4, "--- 1.2.3 LOKAAL BESTAND VIA EXTENSIEMAP OPGEHAALD ---",   ['pad' => $ext_dir_path, 'bytes' => strlen((string)$content)]);
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [FETCHCSS] - 1.3 START CURL FALLBACK",          "[CURL]");
    wachthond($extdebug, 2, "########################################################################");

    if ($content === FALSE || trim($content) === '') {
        wachthond($extdebug, 4, "cURL fallback geactiveerd",                        ['url' => $url]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,       $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
        curl_setopt($ch, CURLOPT_TIMEOUT,   5);
        
        $content    = curl_exec($ch);
        $curl_info  = curl_getinfo($ch);
        curl_close($ch);

        wachthond($extdebug, 4, "--- 1.3.1 CURL RESPONSE ONTVANGEN ---",          ['http_code' => $curl_info['http_code'] ?? 'N/A']);
    }
    
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [FETCHCSS] - 1.4 CSS OPHALEN AFGEROND",         "[FETCH-DONE]");
    wachthond($extdebug, 2, "########################################################################");

    if ($content === FALSE || trim($content) === '') {
        wachthond($extdebug, 3, "CSS Content blijft leeg na alle pogingen",       "[WARN]");
        return '';
    }
    
    $css_cache[$url]    = strip_tags($content);
    return $css_cache[$url];
}

/**
 * Template Analyse
 */
function _cssinliner_analyze_templates($html_voor, $html_na) {
    $extdebug   = 'cssinliner';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [ANALYZE] - 1.0 TEMPLATE VERGELIJKING",         "[ANALYZE]");
    wachthond($extdebug, 2, "########################################################################");
    
    $count_voor = preg_match_all('/style\s*=\s*["\']([^"\']+)["\']/i', $html_voor, $matches_voor);
    $count_na   = preg_match_all('/style\s*=\s*["\']([^"\']+)["\']/i', $html_na,   $matches_na);
    
    $unieke_styles_voor = array_unique(array_map('trim', $matches_voor[1] ?? []));
    $unieke_styles_na   = array_unique(array_map('trim', $matches_na[1]   ?? []));
    $toegevoegde_styles = array_values(array_diff($unieke_styles_na, $unieke_styles_voor));

    $rapport    = [
        'aantal_styles_in_brontemplate' => $count_voor,
        'aantal_styles_na_emogrifier'   => $count_na,
        'verschil_aantal_elementen' => ($count_na - $count_voor),
        'nieuw_geinjecteerde_regels'    => $toegevoegde_styles,
    ];
    
    wachthond($extdebug, 4, "--- 1.1 TEMPLATE ANALYSE (EMOGRIFIER DIFF RESULTAAT) ---",         $rapport);
}

/**
 * civi.token.eval listener (prioriteit -10, vuurt NA SiteTokens bij prioriteit 0).
 *
 * Stript ook omringende <p>-tags uit alle site token waarden (CKEditor voegt die toe voor
 * leesbaarheid; in email-HTML zijn ze ongewenst).
 * Detecteert site tokens waarvan de body Smarty-variabelen bevat ({$...}, {if}, {foreach} etc.).
 * Slaat de raw body op in een statische cache en vervangt de token-waarde door een placeholder
 * die tokenEscapeSmarty() niet aanraakt: ##OZK_SMARTY:naam##
 */
function _cssinliner_on_token_eval(\Civi\Token\Event\TokenValueEvent $e): void {
    $extdebug = 'cssinliner';

    foreach ($e->getRows() as $row) {
        $rowIdx  = $row->tokenRow;
        if (empty($row->tokenProcessor->rowValues[$rowIdx]['text/html']['site'])) {
            continue;
        }
        $htmlVals = &$row->tokenProcessor->rowValues[$rowIdx]['text/html']['site'];

        foreach ($htmlVals as $field => $value) {
            if (!is_string($value)) {
                continue;
            }
            // Strip omringende <p>-tags die CKEditor toevoegt voor leesbaarheid.
            // Alleen de tags worden verwijderd, de inhoud blijft intact.
            $stripped = preg_replace('/<p\b[^>]*>(.*?)<\/p>/is', '$1', $value);
            if ($stripped !== $value) {
                $value = trim($stripped);
                $row->tokenProcessor->rowValues[$rowIdx]['text/html']['site'][$field] = $value;
                $htmlVals[$field] = $value;
            }
            // Bevat de body Smarty-code? Check op {$ of {if of {foreach of {assign
            if (preg_match('/\{\$|\{if\b|\{foreach\b|\{assign\b|\{capture\b/i', $value)) {
                // Sla raw body op in statische cache (key = veldnaam).
                // NB: CiviCRM tokens ({participant.x} etc.) in deze body worden NIET hier
                // opgelost — dat gebeurt in _cssinliner_on_token_render via een mini-
                // TokenProcessor nadat de Smarty body is geïnjecteerd.
                $cache = &_cssinliner_smarty_token_cache();
                $cache[$field] = $value;
                // Vervang door placeholder zonder accolades → tokenEscapeSmarty laat hem met rust
                $row->tokenProcessor->rowValues[$rowIdx]['text/html']['site'][$field]
                    = '##OZK_SMARTY:' . $field . '##';
                wachthond($extdebug, 3,
                    "### CSSINLINER [TOKEN-EVAL] - site.$field bevat Smarty → placeholder gezet",
                    ['bytes' => strlen($value)]
                );
            }
        }
    }
}

/**
 * civi.token.render listener (prioriteit 10, vuurt VOOR TokenCompatSubscriber bij prioriteit 0).
 *
 * Stap 1: Vervangt ##OZK_SMARTY:naam## placeholders door de raw Smarty body uit de cache.
 * Stap 2: Verwerkt CiviCRM tokens ({participant.x}, {contact.x}, {event.x}) die in de
 *         Smarty bodies staan maar NIET door de standaard evaluators zijn gezien (die scannen
 *         alleen de originele template-tekst, niet de site-token bodies). Hiervoor wordt een
 *         tijdelijke TokenProcessor aangemaakt met dezelfde context.
 *         Recursie-guard voorkomt dat dit zichzelf herhaaldelijk triggert.
 */
/**
 * Curated capture-blok dat vóór een text/plain-onderwerp wordt geplakt zodat de meest
 * gebruikte camp-variabelen ({$user_kampkort} etc.) ook in het EMAIL-ONDERWERP bruikbaar
 * zijn — net als in de body via de smarty_header. Bevat uitsluitend {capture}/{assign}
 * (geen output). De CiviCRM-tokens hierin worden door Stap 2 in _cssinliner_on_token_render()
 * geresolved; daarna draait Smarty (prio 0) en zet de {$...}-vars.
 *
 * ANTI-DRIFT: de keten-vars (kampkort/kampnaam/kamptype/kampjaar/voornaam) worden RUNTIME
 * 1-op-1 uit de smarty_header geëxtraheerd (zie _cssinliner_extract_header_vars), zodat ze
 * automatisch meebewegen als de header-keten wijzigt. De overige vars staan niet 1-op-1 in
 * de header (groep* zit in een {if}-blok; displayname/eventstart/smartynow bestaan niet als
 * header-var) en worden hier expliciet gedefinieerd. Faalt de extractie, dan valt 't blok
 * terug op de volledige hardcoded versie (_cssinliner_subjectvars_fallback) zodat onderwerpen
 * nóóit breken. Resultaat wordt per request gecachet.
 */
function _cssinliner_subjectvars(): string {
    static $block = NULL;
    if ($block !== NULL) {
        return $block;
    }

    // Auto-sync uit de header: deze targets + hun {capture}-deps worden geëxtraheerd.
    $targets = ['user_kampkort', 'user_kampnaam', 'user_kamptype', 'user_kampjaar', 'user_voornaam'];

    // Expliciet (niet 1-op-1 uit de header te halen):
    //  - groep* staan in de header in een {if}/{elseif}-blok -> hier met dezelfde part->jaar fallback;
    //  - user_displayname / user_eventstart(_ts) / user_smartynow bestaan niet als header-var.
    $extra =
        '{capture assign="part_groepskleur"}{participant.custom_1766}{/capture}' .
        '{capture assign="jaar_groepskleur"}{contact.custom_1228}{/capture}' .
        '{assign var="user_groepskleur" value=$part_groepskleur|default:$jaar_groepskleur|default:""}' .
        '{capture assign="part_groepsletter"}{participant.custom_1765}{/capture}' .
        '{capture assign="jaar_groepsletter"}{contact.custom_2062}{/capture}' .
        '{assign var="user_groepsletter" value=$part_groepsletter|default:$jaar_groepsletter|default:""}' .
        '{capture assign="part_groepsnaam"}{participant.custom_1803}{/capture}' .
        '{capture assign="jaar_groepsnaam"}{contact.custom_2063}{/capture}' .
        '{assign var="user_groepsnaam" value=$part_groepsnaam|default:$jaar_groepsnaam|default:""}' .
        '{capture assign="user_displayname"}{contact.display_name}{/capture}' .
        '{capture assign="user_eventstart"}{event.start_date}{/capture}' .
        '{assign var="user_eventstart_ts" value=$user_eventstart|date_format:"%s"|default:0}' .
        '{assign var="user_smartynow" value=$smarty.now|date_format:"%Y%m%d_%H%M%S"}' .
        '{capture assign="user_image_url"}{contact.image_URL}{/capture}' .
        '{assign var="user_image_basename" value=$user_image_url|regex_replace:"/^.*\//":""|regex_replace:"/\?.*$/":""}' .
        '{assign var="user_polaroid_url" value="https://www.onvergetelijk.nl/sites/default/files/styles/square_0260_naam/public/profielfotos/"|cat:$user_image_basename}' .
        '{assign var="user_polaroid_plain_url" value="https://www.onvergetelijk.nl/sites/default/files/styles/square_0260/public/profielfotos/"|cat:$user_image_basename}';

    try {
        $hdr = (string) \CRM_Core_DAO::singleValueQuery("SELECT body_html FROM civicrm_site_token WHERE name = 'smarty_header'");
        $chain = _cssinliner_extract_header_vars($hdr, $targets);
        if (trim($chain) === '') {
            throw new \RuntimeException('geen keten-vars uit smarty_header geëxtraheerd');
        }
        $block = '{*OZKSV*}' . $chain . $extra;
        wachthond('cssinliner', 3, '### CSSINLINER [SUBJECTVARS] - keten-vars uit smarty_header geëxtraheerd', ['bytes' => strlen($block)]);
    } catch (\Throwable $ex) {
        wachthond('cssinliner', 1, '### CSSINLINER [SUBJECTVARS] - header-extractie faalde, hardcoded fallback gebruikt: ' . $ex->getMessage());
        $block = _cssinliner_subjectvars_fallback();
    }
    return $block;
}

/**
 * Extraheert uit de smarty_header-body de {assign}/{capture}-statements voor de gevraagde
 * target-vars PLUS hun transitieve {capture}-afhankelijkheden ($vars die in hun waarde/body
 * staan). De statements worden teruggegeven in originele header-volgorde (zodat captures vóór
 * hun gebruik staan; de header draait immers top-down). Werkt alleen voor 1-op-1 op top-niveau
 * gedefinieerde vars — vars die uitsluitend binnen een {if}-blok worden gezet (zoals groep*)
 * komen hier NIET uit (die definieer je expliciet in de aanroeper).
 */
function _cssinliner_extract_header_vars(string $hdr, array $targets): string {
    $defs = [];  // naam => ['stmt'=>..., 'pos'=>int, 'deps'=>[...]]

    // 1) {assign var="NAAM" ...}  — eerste definitie per naam wint
    if (preg_match_all('/\{assign\s+var="([a-zA-Z0-9_]+)"[^}]*\}/', $hdr, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $name = $mm[1][0];
            if (isset($defs[$name])) {
                continue;
            }
            preg_match_all('/\$([a-zA-Z0-9_]+)/', $mm[0][0], $d);
            $defs[$name] = ['stmt' => $mm[0][0], 'pos' => $mm[0][1], 'deps' => array_values(array_unique($d[1]))];
        }
    }
    // 2) {capture assign="NAAM"}BODY{/capture}  — niet overschrijven (assign uit stap 1 wint)
    if (preg_match_all('/\{capture\s+assign="([a-zA-Z0-9_]+)"\}(.*?)\{\/capture\}/s', $hdr, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $name = $mm[1][0];
            if (isset($defs[$name])) {
                continue;
            }
            preg_match_all('/\$([a-zA-Z0-9_]+)/', $mm[2][0], $d);
            $defs[$name] = ['stmt' => $mm[0][0], 'pos' => $mm[0][1], 'deps' => array_values(array_unique($d[1]))];
        }
    }

    // Transitieve closure vanaf de targets
    $need  = [];
    $stack = $targets;
    while ($stack) {
        $n = array_pop($stack);
        if (isset($need[$n]) || !isset($defs[$n])) {
            continue;
        }
        $need[$n] = $defs[$n];
        foreach ($defs[$n]['deps'] as $dep) {
            if (!isset($need[$dep]) && isset($defs[$dep])) {
                $stack[] = $dep;
            }
        }
    }

    // Sorteer op originele header-positie zodat captures vóór hun gebruik staan
    uasort($need, function ($a, $b) {
        return $a['pos'] <=> $b['pos'];
    });

    $out = '';
    foreach ($need as $d) {
        $out .= $d['stmt'];
    }
    return $out;
}

/**
 * Volledige hardcoded versie van het subjectvars-blok. Wordt alleen gebruikt als de
 * runtime-extractie uit de smarty_header faalt (zie _cssinliner_subjectvars). Houd 'm
 * gelijk aan de header-ketens als veiligheidsnet.
 */
function _cssinliner_subjectvars_fallback(): string {
    return
        '{*OZKSV*}' .
        // kampkort: participant -> contributie -> jaar(contact) -> activiteit
        '{capture assign="part_kampkort"}{participant.custom_950}{/capture}' .
        '{capture assign="jaar_kampkort"}{contact.custom_901}{/capture}' .
        '{capture assign="geld_kampkort"}{contribution.custom_1744:name}{/capture}' .
        '{capture assign="act_kampkort"}{activity.custom_1565}{/capture}' .
        '{assign var="user_kampkort" value=$part_kampkort|default:$geld_kampkort|default:$jaar_kampkort|default:$act_kampkort|default:""}' .
        // kampnaam
        '{capture assign="part_kampnaam"}{participant.custom_949}{/capture}' .
        '{capture assign="jaar_kampnaam"}{contact.custom_900}{/capture}' .
        '{capture assign="geld_kampnaam"}{contribution.custom_1743:name}{/capture}' .
        '{capture assign="act_kampnaam"}{activity.custom_1703}{/capture}' .
        '{assign var="user_kampnaam" value=$part_kampnaam|default:$geld_kampnaam|default:$jaar_kampnaam|default:$act_kampnaam|default:""}' .
        // kamptype
        '{capture assign="part_kamptype"}{participant.custom_992}{/capture}' .
        '{capture assign="jaar_kamptype"}{contact.custom_993}{/capture}' .
        '{assign var="user_kamptype" value=$part_kamptype|default:$jaar_kamptype|default:""}' .
        // groepskleur — canonieke header-naam is user_groepskleur (part wint, anders jaar)
        '{capture assign="part_groepskleur"}{participant.custom_1766}{/capture}' .
        '{capture assign="jaar_groepskleur"}{contact.custom_1228}{/capture}' .
        '{assign var="user_groepskleur" value=$part_groepskleur|default:$jaar_groepskleur|default:""}' .
        // groepsletter — canonieke header-naam is user_groepsletter
        '{capture assign="part_groepsletter"}{participant.custom_1765}{/capture}' .
        '{capture assign="jaar_groepsletter"}{contact.custom_2062}{/capture}' .
        '{assign var="user_groepsletter" value=$part_groepsletter|default:$jaar_groepsletter|default:""}' .
        // groepsnaam — canonieke header-naam is user_groepsnaam
        '{capture assign="part_groepsnaam"}{participant.custom_1803}{/capture}' .
        '{capture assign="jaar_groepsnaam"}{contact.custom_2063}{/capture}' .
        '{assign var="user_groepsnaam" value=$part_groepsnaam|default:$jaar_groepsnaam|default:""}' .
        // voornaam / displayname — werken óók native als {contact.first_name}/{contact.display_name}
        // in een onderwerp; hier ook als {$user_*} voor een consistente vocabulaire (voornaam
        // verbatim uit header; displayname spiegelt diezelfde vorm).
        '{capture assign="user_voornaam"}{contact.first_name}{/capture}' .
        '{capture assign="user_displayname"}{contact.display_name}{/capture}' .
        // kampjaar — Smarty-afgeleid (jaartal uit kampstart). Hele kampstart-keten verbatim uit
        // de header overgenomen, want user_kampjaar = $user_kampstart|date_format:"%Y".
        '{capture assign="part_kampstart"}{participant.custom_1780}{/capture}' .
        '{capture assign="jaar_kampstart"}{contact.custom_1155}{/capture}' .
        '{capture assign="event_kampstart"}{event.start_date}{/capture}' .
        '{capture assign="act_kampstart"}{activity.custom_1570}{/capture}' .
        '{assign var="user_kampstart" value=$part_kampstart|default:$jaar_kampstart|default:$event_kampstart|default:$act_kampstart|default:""}' .
        '{assign var="user_kampjaar" value=$user_kampstart|date_format:"%Y"|default:0}' .
        // eventstart — START-datum van het EVENT ZELF (bv. trainingsdag / meeting kampstaf dat
        // bij de leiding-registratie hoort), NIET het kampjaar/kampstart. Exposed als unix-ts
        // (date_format:"%s" parset de date-string via strtotime) zodat het onderwerp zelf kan
        // formatteren, bv {$user_eventstart_ts|date_format:"%d-%m"}.
        '{capture assign="user_eventstart"}{event.start_date}{/capture}' .
        '{assign var="user_eventstart_ts" value=$user_eventstart|date_format:"%s"|default:0}' .
        // datum vandaag in Ymd_His (bv. 20260622_143015) — Smarty-builtin $smarty.now, geen token
        '{assign var="user_smartynow" value=$smarty.now|date_format:"%Y%m%d_%H%M%S"}';
}

function _cssinliner_on_token_render(\Civi\Token\Event\TokenRenderEvent $e): void {
    $extdebug = 'cssinliner';
    $cache = _cssinliner_smarty_token_cache();

    // Stap 0: SUBJECT-Smarty-variabelen. Het onderwerp wordt als losse text/plain-message
    // gerenderd; de body-header (smarty_header) lekt NIET naar die scope, dus {$user_kampkort}
    // e.d. zouden in het onderwerp leeg blijven. We prependen daarom een compact capture-blok
    // (alleen {assign}s, geen output) met de meest gebruikte camp-variabelen. De {*OZKSV*}
    // Smarty-comment dient als guard én wordt door Smarty (prio 0) verwijderd, dus het
    // onderwerp blijft schoon. De CiviCRM-tokens in dit blok worden in Stap 2 hieronder
    // alsnog geresolved (de standaard eval-fase scant site-token/subject-bodies niet).
    $isPlain = (($e->message['format'] ?? '') === 'text/plain');
    if ($isPlain && strpos($e->string, '{$') !== FALSE && strpos($e->string, '{*OZKSV*}') === FALSE) {
        $e->string = _cssinliner_subjectvars() . $e->string;
        wachthond($extdebug, 3,
            "### CSSINLINER [TOKEN-RENDER] - subjectvars-blok geprepend aan text/plain-onderwerp",
            ['bytes' => strlen($e->string)]
        );
    }

    // Stap 1: Injecteer alle Smarty bodies (site-token placeholders in de body)
    if (!empty($cache) && strpos($e->string, '##OZK_SMARTY:') !== FALSE) {
        foreach ($cache as $field => $rawBody) {
            $placeholder = '##OZK_SMARTY:' . $field . '##';
            if (strpos($e->string, $placeholder) !== FALSE) {
                $e->string = str_replace($placeholder, $rawBody, $e->string);
                wachthond($extdebug, 3,
                    "### CSSINLINER [TOKEN-RENDER] - placeholder site.$field vervangen door raw Smarty body",
                    ['bytes' => strlen($rawBody)]
                );
            }
        }
    }

    // Stap 2: Los nu de CiviCRM tokens op die in de geïnjecteerde bodies stonden.
    // De originele eval-fase heeft die tokens overgeslagen (ze stonden niet in getMessageTokens).
    // Gebruik een tijdelijke TokenProcessor met dezelfde context en smarty=FALSE (Smarty draait
    // daarna op prioriteit 0 via TokenCompatSubscriber).
    static $reprocessing = FALSE;
    if ($reprocessing) {
        return;  // Recursie-guard
    }

    // Check of er nog CiviCRM tokens in de string staan die verwerkt moeten worden
    $stap2_nodig = preg_match('/\{(?:participant|contact|event|activity)\.[a-zA-Z_0-9.:]+\}/', $e->string);
    if (!$stap2_nodig) {
        return;
    }

    $reprocessing = TRUE;
    try {
        // $e->row->context is een TokenRowContext-OBJECT (ArrayAccess), géén platte array.
        // Een (array)-cast levert alleen de protected props op (* tokenProcessor / * tokenRow),
        // NIET de entity-id's — daardoor kon de mini-processor {participant.x} niet resolven en
        // maakte hij ze leeg. We lezen de id's daarom via ArrayAccess uit de rij-context en
        // bouwen een platte context voor de tijdelijke TokenProcessor.
        $src = $e->row->context;
        $ctx = ['smarty' => FALSE]; // Smarty hier NIET draaien; dat doet TokenCompatSubscriber (prio 0)
        foreach (['contactId', 'participantId', 'eventId', 'activityId', 'contributionId', 'membershipId', 'caseId', 'contact'] as $k) {
            if (isset($src[$k])) {
                $ctx[$k] = $src[$k];
            }
        }

        $tp = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), $ctx);
        // Format-bewust: een text/plain-onderwerp mag NIET HTML-encoded worden, anders
        // verschijnt bv. "Loïs" in het onderwerp als "Lo&iuml;s" (de entity is in platte
        // tekst letterlijk zichtbaar). HTML-bodies blijven text/html (entities zijn daar oké).
        $tp->addMessage('body', $e->string, $isPlain ? 'text/plain' : 'text/html');
        $tp->addRow()->context($ctx);
        $tp->evaluate();
        foreach ($tp->getRows() as $tpRow) {
            $e->string = $tp->render('body', $tpRow);
        }
        wachthond($extdebug, 3,
            "### CSSINLINER [TOKEN-RENDER] - CiviCRM tokens in Smarty bodies verwerkt via mini-TokenProcessor"
        );
    } catch (\Throwable $ex) {
        wachthond($extdebug, 1,
            "### CSSINLINER [TOKEN-RENDER] - FOUT bij token-herverwerking: " . $ex->getMessage()
        );
    } finally {
        $reprocessing = FALSE;
    }
}

/**
 * Statische cache voor raw Smarty-bodies van site tokens.
 * Retourneert een referentie zodat aanroepers kunnen schrijven.
 */
function &_cssinliner_smarty_token_cache(): array {
    static $cache = [];
    return $cache;
}

function cssinliner_civicrm_config(&$config) {
    if (function_exists('_cssinliner_civix_civicrm_config')) {
        _cssinliner_civix_civicrm_config($config);
    }

    // Eénmalig registreren (config-hook kan meerdere keren vuren).
    static $registered = FALSE;
    if ($registered) {
        return;
    }
    $registered = TRUE;

    // Registreer de twee token-event listeners voor native site token Smarty-rendering.
    // eval prioriteit -10: vuurt NA SiteTokens (0), zodat hun waarden al gezet zijn.
    // render prioriteit 10: vuurt VOOR TokenCompatSubscriber (0), zodat placeholders
    //   vervangen worden vóór parseOneOffStringThroughSmarty() draait.
    \Civi::dispatcher()->addListener('civi.token.eval',   '_cssinliner_on_token_eval',   -10);
    \Civi::dispatcher()->addListener('civi.token.render', '_cssinliner_on_token_render',  10);
}