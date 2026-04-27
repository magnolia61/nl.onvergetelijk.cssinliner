<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: cssinliner.php
 * =======================================================================================
 *   cssinliner_civicrm_alterMailParams()  Hook: alterMailParams
 *   _cssinliner_cleanup_html()            V2.4.7: Garandeert 100% W3C compliance en Outlook-compatibiliteit.
 *   _cssinliner_fetch_external_css()      Fetcher voor externe CSS bestanden met statische cache.
 *   cssinliner_civicrm_config()
 * =======================================================================================
 */

/*
 * nl.onvergetelijk.cssinliner
 * V2.3.6: Emogrifier + Valid HTML5 Wrapper + Alt-tag Fix + Metadata injection.
 */

/**
 * 1. Laad de composer autoloader
 */
$extRoot = __DIR__ . DIRECTORY_SEPARATOR;
if (file_exists($extRoot . 'vendor/autoload.php')) {
    require_once $extRoot . 'vendor/autoload.php';
}

/**
 * 2. Laad de civix hulpfuncties
 */
if (file_exists($extRoot . 'cssinliner.civix.php')) {
    require_once 'cssinliner.civix.php';
}

use Pelago\Emogrifier\CssInliner;

/**
 * Hook: alterMailParams
 */
function cssinliner_civicrm_alterMailParams(&$params, $context = NULL) {

    $extdebug = 0;

    if (empty($params['html'])) {
        return;
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CSSINLINER [START] V2.3.6 - HTML5 COMPLIANT MODE",         "[MAIL]");
    wachthond($extdebug, 2, "########################################################################");

    $original_html = $params['html'];
    $all_css       = '';

    try {
        // Stap A: Externe CSS ophalen
        preg_match_all('/<link [^>]*href=["\']([^"\']+\.css[^"\']*)["\'][^>]*>/i', $original_html, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $css_url) {
                if (strpos($css_url, '//') === FALSE) {
                    $css_url = CRM_Utils_System::basePath() . ltrim($css_url, '/');
                }
                $css_content = _cssinliner_fetch_external_css($css_url, $extdebug);
                if (!empty($css_content)) {
                    $all_css .= $css_content . "\n";
                    wachthond($extdebug, 3, "CSS_loaded_from", $css_url);
                }
            }
        }

        // Stap B: Inlining proces
        $html_utf8      = mb_encode_numericentity($original_html, [0x80, 0x10ffff, 0, 0x1fffff], 'UTF-8');
        $visual_inliner = CssInliner::fromHtml($html_utf8);

        if (!empty($all_css)) {
            $visual_inliner->inlineCss($all_css);
        }

        $visual_inliner->inlineCss();
        $rendered_html = $visual_inliner->render();

        // Stap C: Validatie & Cleanup
        // We geven het onderwerp mee voor de <title> tag
        $params['html'] = _cssinliner_cleanup_html($rendered_html, $params['subject'] ?? 'Onvergetelijke Zomerkampen');

        $summary = [
            'ontvanger'    => $params['to_email']   ?? 'onbekend',
            'grootte_voor' => strlen($original_html) . ' bytes',
            'grootte_na'   => strlen($params['html']) . ' bytes',
        ];
        wachthond($extdebug, 1, 'VERWERKING VOLTOOID', $summary);

    } catch (\Exception $e) {
        wachthond($extdebug, 1, "CRITICAL ERROR: " . $e->getMessage(), "[ERROR]");
    }

    wachthond($extdebug, 1, "### CSSINLINER",                                                "[EINDE]");
    wachthond($extdebug, 2, "########################################################################");
}

/**
 * V2.4.7: Garandeert 100% W3C compliance en Outlook-compatibiliteit.
 * - Herstelt gebroken /ozkimages/ paden met volledige host-URL.
 * - Dicht het 'alt-tekst lek' door attributen strikt binnen tags te plaatsen.
 * - Forceert een schone HTML5 schil met taal- en meta-data.
 */
function _cssinliner_cleanup_html($html, $title = 'Onvergetelijke Zomerkampen') {

    // 1. Verwijder HTML comments volledig
    $html = preg_replace('//', '', $html);

    // 2. Ruim stray tekst op van eerdere foutieve pogingen (zoals "> alt=""")
    $html = str_replace('> alt=""', '>', $html);

    // 3. FIX: Herstel image-paden en verwijder lege mappen
    $config = CRM_Core_Config::singleton();
    $base_url = rtrim($config->userFrameworkBaseURL, '/'); 
    
    // Verwijder img tags die alleen naar de map /ozkimages/ wijzen zonder bestand
    $html = preg_replace('/<img[^>]+src=["\']([^"\']+\/ozkimages\/)["\']([^>]*)>/i', '', $html);

    // Herstel overgebleven relatieve paden naar absolute URL's
    $html = preg_replace_callback('/<img([^>]+)src=["\']([^"\']+)["\']([^>]*)>/i', function($matches) use ($base_url) {
        $src = $matches[2];
        if (!preg_match('/^https?:\/\//i', $src)) {
            $src = $base_url . '/' . ltrim($src, '/');
        }
        return "<img{$matches[1]}src=\"{$src}\"{$matches[3]}>";
    }, $html);

    // 4. FIX: Voeg alt="" toe BINNEN de img tag (callback voorkomt lekken)
    $html = preg_replace_callback('/<img(?![^>]*\balt=)([^>]+)>/i', function($m) {
        return '<img alt="" ' . $m[1] . '>';
    }, $html);

    // 5. Verwijder stray tags uit de body (link, meta)
    $html = preg_replace('/<link[^>]*>/i', '', $html);
    $html = preg_replace('/<meta http-equiv="Content-Type"[^>]*>/i', '', $html);
    
    // 6. Verwijder lege tags (3 iteraties voor nesting)
    $pattern = '/<(p|div|span|b|i)>\s*(&nbsp;|\s)*\s*<\/\1>/i';
    for ($i = 0; $i < 3; $i++) {
        $html = preg_replace($pattern, '', $html);
    }

    // 7. Forceer de valide HTML5 schil
    $html = preg_replace('/<(\/)?(html|head|body)[^>]*>/i', '', $html);
    $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);

    $clean_html = "<!DOCTYPE html>\n";
    $clean_html .= "<html lang=\"nl\">\n";
    $clean_html .= "<head>\n";
    $clean_html .= "    <meta charset=\"utf-8\">\n";
    $clean_html .= "    <title>" . htmlspecialchars($title) . "</title>\n";
    $clean_html .= "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
    $clean_html .= "</head>\n";
    $clean_html .= "<body style=\"margin:0;padding:0;font-family:Arial,sans-serif;\">\n";
    $clean_html .= trim($html) . "\n";
    $clean_html .= "</body>\n";
    $clean_html .= "</html>";

    // 8. Trek de HTML visueel strak (geen loze enters tussen tags)
    $clean_html = preg_replace('/>[\s\n\r]+</', '><', $clean_html);

    return $clean_html;
}

/**
 * Fetcher voor externe CSS bestanden met statische cache.
 */
function _cssinliner_fetch_external_css($url, $extdebug) {
    
    static $css_cache = [];
    if (isset($css_cache[$url])) return $css_cache[$url];

    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $content = @file_get_contents($url, FALSE, $ctx);

    if ($content === FALSE) {
        return '';
    }

    $css_cache[$url] = strip_tags($content);
    return $css_cache[$url];
}

function cssinliner_civicrm_config(&$config) {
    if (function_exists('_cssinliner_civix_civicrm_config')) {
        _cssinliner_civix_civicrm_config($config);
    }
}