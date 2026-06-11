<?php

namespace Civi\Cssinliner;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test voor cssinliner functies.
 *
 * @group e2e
 *
 * Scenario's:
 * 1. _cssinliner_cleanup_html(): produceert geldige HTML5-schil (DOCTYPE, html, head, body)
 * 2. _cssinliner_cleanup_html(): <title> wordt correct ingevuld
 * 3. _cssinliner_cleanup_html(): standaard titel als geen argument
 * 4. _cssinliner_cleanup_html(): img zonder alt krijgt alt="" toegevoegd
 * 5. _cssinliner_cleanup_html(): img met bestaand alt blijft ongewijzigd
 * 6. _cssinliner_cleanup_html(): lege <p>-tags worden verwijderd
 * 7. _cssinliner_cleanup_html(): <link>-tags worden gestript
 * 8. cssinliner_civicrm_alterMailParams(): lege html wordt niet aangepast
 * 9. _cssinliner_fetch_external_css(): niet-bereikbare URL geeft lege string
 * 10. _cssinliner_cleanup_html(): naakt block-token krijgt <div>-wrapper
 * 11. _cssinliner_cleanup_html(): <p>-wrapped block-token wordt <div>
 * 12. _cssinliner_cleanup_html(): <br> vóór block-token wordt <div>
 * 13. _cssinliner_cleanup_html(): al gewrapped block-token krijgt geen dubbele <div>
 * 14. _cssinliner_cleanup_html(): twee aaneengesloten block-tokens elk eigen <div>
 */
class CssInlinerTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('_cssinliner_cleanup_html')) {
      $this->markTestSkipped('_cssinliner_cleanup_html() niet beschikbaar; is nl.onvergetelijk.cssinliner geïnstalleerd?');
    }
  }

  // ########################################################################
  // ### SCENARIO 1: HTML5-SCHIL
  // ########################################################################

  /**
   * Output bevat een geldige HTML5 DOCTYPE en html/head/body structuur.
   */
  public function testHtml5SchilAanwezig() {
    $result = _cssinliner_cleanup_html('<p>Testinhoud</p>', 'Test Titel');

    $this->assertStringContainsString('<!DOCTYPE html>', $result, 'Output moet starten met HTML5 DOCTYPE.');
    $this->assertStringContainsString('<html lang="nl">', $result, 'Output moet <html lang="nl"> bevatten.');
    $this->assertStringContainsString('<head>',           $result, 'Output moet <head> bevatten.');
    $this->assertStringContainsString('<body',            $result, 'Output moet <body> bevatten.');
    $this->assertStringContainsString('</html>',          $result, 'Output moet afsluiten met </html>.');
  }

  // ########################################################################
  // ### SCENARIO 2 & 3: TITEL
  // ########################################################################

  /**
   * <title>-tag bevat de meegegeven titel.
   */
  public function testTitelWordtIngevuld() {
    $result = _cssinliner_cleanup_html('<p>inhoud</p>', 'Zomerkamp 2026');
    $this->assertStringContainsString('<title>Zomerkamp 2026</title>', $result, '<title> moet de meegegeven titel bevatten.');
  }

  /**
   * Standaard titel als geen argument wordt meegegeven.
   */
  public function testStandaardTitelBijGeenArgument() {
    $result = _cssinliner_cleanup_html('<p>test</p>');
    $this->assertStringContainsString('<title>Onvergetelijke Zomerkampen</title>', $result, 'Standaard titel moet worden gebruikt als er geen is meegegeven.');
  }

  // ########################################################################
  // ### SCENARIO 4 & 5: ALT-TEKST OP AFBEELDINGEN
  // ########################################################################

  /**
   * img-tag zonder alt-attribuut krijgt alt="" toegevoegd.
   */
  public function testImgZonderAltKrijgtAltLeeg() {
    $html   = '<p><img src="https://example.com/foto.jpg"></p>';
    $result = _cssinliner_cleanup_html($html);
    $this->assertStringContainsString('alt=""', $result, 'img zonder alt moet alt="" toegevoegd krijgen.');
  }

  /**
   * img-tag met bestaand alt-attribuut blijft ongewijzigd (geen duplicaat).
   */
  public function testImgMetBestaandAltBlijftOngewijzigd() {
    $html   = '<p><img src="https://example.com/foto.jpg" alt="Zomerkamp foto"></p>';
    $result = _cssinliner_cleanup_html($html);
    $this->assertStringContainsString('alt="Zomerkamp foto"', $result, 'Bestaand alt-attribuut mag niet overschreven worden.');
    $this->assertEquals(1, substr_count($result, 'alt='), 'Er mag maar één alt-attribuut zijn per img-tag.');
  }

  // ########################################################################
  // ### SCENARIO 6: LEGE TAGS
  // ########################################################################

  /**
   * Lege <p>-tags worden verwijderd.
   */
  public function testLegeParagraafWordtVerwijderd() {
    $html   = '<div><p></p><p>Inhoud</p></div>';
    $result = _cssinliner_cleanup_html($html);
    $this->assertStringNotContainsString('<p></p>', $result, 'Lege <p>-tags moeten verwijderd worden.');
    $this->assertStringContainsString('Inhoud',     $result, 'Niet-lege tags mogen niet verwijderd worden.');
  }

  // ########################################################################
  // ### SCENARIO 7: LINK-TAGS GESTRIPT
  // ########################################################################

  /**
   * <link>-tags worden verwijderd uit de cleanup-output.
   */
  public function testLinkTagsWordenVerwijderd() {
    $html   = '<link rel="stylesheet" href="/style.css"><p>inhoud</p>';
    // Link-tags worden alleen gestript bij finale verzending (is_final_send=TRUE)
    $result = _cssinliner_cleanup_html($html, 'Test', TRUE);
    $this->assertStringNotContainsString('<link', $result, '<link>-tags moeten verwijderd worden uit de cleanup-output bij finale verzending.');
  }

  // ########################################################################
  // ### SCENARIO 8: ALTERMAIL PARAMS — LEGE HTML
  // ########################################################################

  /**
   * cssinliner_civicrm_alterMailParams() doet niets als html leeg is.
   */
  public function testAlterMailParamsNegeertLegeHtml() {
    if (!function_exists('cssinliner_civicrm_alterMailParams')) {
      $this->markTestSkipped('cssinliner_civicrm_alterMailParams() niet beschikbaar.');
    }
    $params = ['html' => '', 'subject' => 'Test'];
    cssinliner_civicrm_alterMailParams($params);
    $this->assertEquals('', $params['html'], 'Lege html mag niet worden aangepast door de hook.');
  }

  // ########################################################################
  // ### SCENARIO 9: FETCH EXTERNAL CSS — NIET-BEREIKBARE URL
  // ########################################################################

  /**
   * _cssinliner_fetch_external_css() geeft altijd een string terug (geen exception).
   * Retourneert lege string of gecachede CSS — geen exception bij onbereikbare URL.
   */
  public function testFetchGeeftAltijdStringZonderException() {
    if (!function_exists('_cssinliner_fetch_external_css')) {
      $this->markTestSkipped('_cssinliner_fetch_external_css() niet beschikbaar.');
    }
    $result = _cssinliner_fetch_external_css('http://192.0.2.1/nonexistent.css', 'cssinliner');
    $this->assertIsString($result, 'Functie moet altijd een string teruggeven, ook bij onbereikbare URL.');
  }

  // ########################################################################
  // ### SCENARIO 10-14: BLOCK-TOKEN <div>-WRAPPER (v3.5.0)
  // ########################################################################

  /**
   * Naakt block-token (geen wrapper) krijgt automatisch <div>-wrapper.
   */
  public function testNaaktBlockTokenKrijgtDivWrapper() {
    $html   = '<p>Tekst</p>{site.smarty_logo}<p>Meer tekst</p>';
    $result = _cssinliner_cleanup_html($html);
    $this->assertStringContainsString('<div>{site.smarty_logo}</div>', $result, 'Naakt block-token moet in <div> gewrapped worden.');
  }

  /**
   * Block-token in <p>-wrapper wordt omgezet naar <div>.
   */
  public function testBlockTokenInPWrapperWordtDiv() {
    $html   = '<p>{site.smarty_logo}</p>';
    $result = _cssinliner_cleanup_html($html);
    $this->assertStringContainsString('<div>{site.smarty_logo}</div>', $result, 'Block-token in <p> moet naar <div> omgezet worden.');
    $this->assertStringNotContainsString('<p>{site.smarty_logo}</p>', $result, '<p>-wrapper mag niet meer aanwezig zijn.');
  }

  /**
   * <br> direct vóór block-token wordt vervangen door <div>-wrapper.
   */
  public function testBrVoorBlockTokenWordtDiv() {
    $html   = '<p>Groet</p><br>{site.smarty_logo}';
    $result = _cssinliner_cleanup_html($html);
    $this->assertStringContainsString('<div>{site.smarty_logo}</div>', $result, 'Block-token na <br> moet in <div> gewrapped worden.');
    $this->assertStringNotContainsString('<br>{site.smarty_logo}', $result, '<br> vóór block-token mag niet meer aanwezig zijn.');
  }

  /**
   * Al correct gewrapped block-token krijgt geen dubbele <div>.
   */
  public function testAlGewraptBlockTokenGeenDubbeleDiv() {
    $html   = '<p>Tekst</p><div>{site.smarty_logo}</div><p>Daarna</p>';
    $result = _cssinliner_cleanup_html($html);
    $this->assertEquals(1, substr_count($result, '<div>{site.smarty_logo}</div>'), 'Al gewrapped token mag geen dubbele <div> krijgen.');
    $this->assertStringNotContainsString('<div><div>{site.smarty_logo}</div></div>', $result, 'Dubbel nesten is niet toegestaan.');
  }

  /**
   * Twee aaneengesloten block-tokens krijgen elk een eigen <div>.
   */
  public function testTweeBlockTokensElkEigenDiv() {
    $html   = '<div>{site.smarty_logo}</div><div>{site.smarty_intake_tips}</div>';
    $result = _cssinliner_cleanup_html($html);
    $this->assertStringContainsString('<div>{site.smarty_logo}</div>',       $result, 'smarty_logo moet in eigen <div> staan.');
    $this->assertStringContainsString('<div>{site.smarty_intake_tips}</div>', $result, 'smarty_intake_tips moet in eigen <div> staan.');
    $this->assertEquals(1, substr_count($result, '{site.smarty_logo}'),       'smarty_logo mag maar één keer voorkomen.');
    $this->assertEquals(1, substr_count($result, '{site.smarty_intake_tips}'),'smarty_intake_tips mag maar één keer voorkomen.');
  }
}
