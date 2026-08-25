<?php
/**
 * Standalone smoke test for \Sita\XmlFeed\FeedParser.
 *
 * No PHPUnit, no WordPress, no database - which is the whole point of having
 * split the parser out. Run it from the plugin root:
 *
 *     php tests/parser-smoke-test.php
 *
 * Exits 0 when everything passes, 1 on the first failure.
 * This directory is excluded from the WordPress.org build via .distignore.
 */

// The parser guards on ABSPATH like every other plugin file; define it so the
// class can be loaded outside WordPress.
define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-sita-xml-importer-feed-parser.php';

$failures = 0;
$checks   = 0;

function check( $label, $actual, $expected ) {
	global $failures, $checks;
	$checks++;

	if ( $actual === $expected ) {
		echo "  ok   $label\n";
		return;
	}

	$failures++;
	echo "  FAIL $label\n";
	echo "       expected: " . var_export( $expected, true ) . "\n";
	echo "       actual:   " . var_export( $actual, true ) . "\n";
}

/* ---------------------------------------------------------------------------
 * A feed with: one full article, one image-less article, one older-format
 * article without caption/credit elements.
 * ------------------------------------------------------------------------ */
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Spravy>
  <Sprava>
    <ID>12345</ID>
    <UnikatneID>abc-123</UnikatneID>
    <DatumVydania>2026-08-19</DatumVydania>
    <CasVydania>10:30:00</CasVydania>
    <DatumAktualizacie>2026-08-19</DatumAktualizacie>
    <CasAktualizacie>11:00:00</CasAktualizacie>
    <Nadpis>Titulok spravy</Nadpis>
    <Perex>Kratky perex.</Perex>
    <TextContent><![CDATA[<p>Odsek</p><strong>tucne</strong><script>evil()</script>]]></TextContent>
    <Sekcia><Rubrika>Ekonomika</Rubrika><Podrubrika>Financie</Podrubrika></Sekcia>
    <Lokality><Lokalita>Bratislava</Lokalita></Lokality>
    <Obrazok>
      <ObrazokLinka>//img.sita.sk/foto.jpg</ObrazokLinka>
      <VelkostSuboru>2048</VelkostSuboru>
      <ObrazokVyska>600</ObrazokVyska>
      <ObrazokSirka>800</ObrazokSirka>
      <ObrazokTitulok>  Popis obrazka  </ObrazokTitulok>
      <ObrazokZdroj>SITA/Jan Novak</ObrazokZdroj>
    </Obrazok>
  </Sprava>
  <Sprava>
    <ID>99999</ID>
    <Nadpis>Bez obrazka - musi sa preskocit</Nadpis>
    <Obrazok><ObrazokLinka></ObrazokLinka></Obrazok>
  </Sprava>
  <Sprava>
    <ID>67890</ID>
    <Nadpis>Stary format</Nadpis>
    <TextContent>Text</TextContent>
    <Obrazok><ObrazokLinka>https://img.sita.sk/stary.jpg</ObrazokLinka></Obrazok>
  </Sprava>
</Spravy>
XML;

echo "Parsing a normal feed\n";
$parser   = new \Sita\XmlFeed\FeedParser();
$articles = $parser->parse( $xml );

check( 'articles without an image are skipped', count( $articles ), 2 );
check( 'no parse errors', $parser->get_errors(), [] );

$a = $articles[0];
check( 'id maps to _w_id', $a['_w_id'], '12345' );
check( 'unique id', $a['u_id'], 'abc-123' );
check( 'title', $a['title'], 'Titulok spravy' );
check( 'perex', $a['perex'], 'Kratky perex.' );
check( 'publish date', $a['date_published'], '2026-08-19' );
check( 'modified time', $a['time_modified'], '11:00:00' );
check( 'category', $a['section']['category'], 'Ekonomika' );
check( 'subcategory', $a['section']['sub_category'], 'Financie' );
check( 'locations', $a['locations'], [ 'Bratislava' ] );

// <strong> is in the allow-list, <p>/<script> are not.
check( 'disallowed html stripped, allowed kept', $a['content'], 'Odsek<strong>tucne</strong>evil()' );

check( 'protocol-relative image url made absolute', $a['thumbnail']['url'], 'https://img.sita.sk/foto.jpg' );
check( 'image size', $a['thumbnail']['size'], '2048' );
check( 'image height', $a['thumbnail']['height'], '600' );
check( 'image caption trimmed', $a['thumbnail']['caption'], 'Popis obrazka' );
check( 'image credit', $a['thumbnail']['source'], 'SITA/Jan Novak' );

$old = $articles[1];
check( 'absolute url left alone', $old['thumbnail']['url'], 'https://img.sita.sk/stary.jpg' );
// null (not '') matters: it tells the sideloader to keep the file's own IPTC caption.
check( 'missing caption element yields null', $old['thumbnail']['caption'], null );
check( 'missing credit element yields null', $old['thumbnail']['source'], null );

echo "\nCustom allow-list\n";
$strict = new \Sita\XmlFeed\FeedParser( '<em>' );
$out    = $strict->parse( $xml );
check( 'strong stripped when not allowed', $out[0]['content'], 'Odsektucneevil()' );

echo "\nMalformed input\n";
$broken = new \Sita\XmlFeed\FeedParser();
check( 'broken xml returns no articles', $broken->parse( '<Spravy><Sprava>' ), [] );
check( 'broken xml records an error', count( $broken->get_errors() ) > 0, true );

$empty = new \Sita\XmlFeed\FeedParser();
check( 'empty body returns no articles', $empty->parse( '' ), [] );
check( 'empty body records an error', $empty->get_errors(), [ 'empty response body' ] );

$nosprava = new \Sita\XmlFeed\FeedParser();
$nosprava->parse( '<Spravy></Spravy>' );
check( 'valid xml with no articles is reported', $nosprava->get_errors(), [ 'no <Sprava> elements found' ] );

echo "\nURL normalisation\n";
check( 'bare host', \Sita\XmlFeed\FeedParser::normalize_image_url( 'img.sita.sk/a.jpg' ), 'https://img.sita.sk/a.jpg' );
check( 'http left alone', \Sita\XmlFeed\FeedParser::normalize_image_url( 'http://img.sita.sk/a.jpg' ), 'http://img.sita.sk/a.jpg' );
// A URL that merely *contains* http:// in a query string is not absolute.
check( 'http inside query string is not absolute', \Sita\XmlFeed\FeedParser::normalize_image_url( '/r?u=http://x.sk/a.jpg' ), 'https://r?u=http://x.sk/a.jpg' );

echo "\n" . ( $failures ? "FAILED: $failures of $checks checks\n" : "PASSED: all $checks checks\n" );
exit( $failures ? 1 : 0 );
