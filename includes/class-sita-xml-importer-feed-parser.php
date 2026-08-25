<?php
/**
 * Parser for the SITA XML feed format.
 *
 * DELIBERATELY FRAMEWORK-FREE. This class must never call WordPress functions,
 * touch globals, perform HTTP requests or do any I/O. It takes an XML string and
 * returns plain arrays.
 *
 * THIS FILE IS MIRRORED. The identical file is published as the standalone
 * Composer package `sita/xml-feed-parser` (src/FeedParser.php) for SITA
 * subscribers who do not use WordPress. The release pipeline copies it there and
 * strips only the ABSPATH guard below. Keep it dependency-free and keep the
 * public API stable — other people's code depends on it.
 *
 * Everything WordPress-specific — fetching the feed, deduplication, creating
 * posts, sideloading images — lives in functions.php and stays there.
 *
 * @package sita-xml-importer
 * @see https://github.com/PayteR/sita-xml-feed-parser
 */

namespace Sita\XmlFeed;

defined( 'ABSPATH' ) || exit;

final class FeedParser {

	/**
	 * HTML kept in article bodies when the caller does not specify its own list.
	 */
	const DEFAULT_ALLOWABLE_TAGS = '<a><strong><em><i><b><br><h1><h2><h3><h4><h5><table><tbody><thead><tfoot><tr><td><th><blockquote>';

	/** @var string */
	private $allowable_tags;

	/** @var string Scheme prefixed to protocol-relative or bare image URLs. */
	private $image_protocol;

	/** @var string[] Human-readable problems from the last parse() call. */
	private $errors = [];

	/**
	 * @param string|null $allowable_tags Tags to keep in the body, strip_tags() format.
	 *                                    Null uses DEFAULT_ALLOWABLE_TAGS.
	 * @param string      $image_protocol Scheme to prefix onto non-absolute image URLs.
	 */
	public function __construct( $allowable_tags = null, $image_protocol = 'https://' ) {
		$this->allowable_tags = ( null === $allowable_tags ) ? self::DEFAULT_ALLOWABLE_TAGS : (string) $allowable_tags;
		$this->image_protocol = (string) $image_protocol;
	}

	/**
	 * Parse one feed document into normalised article arrays.
	 *
	 * Articles with no image URL are skipped by design — the importer requires a
	 * featured image. On a malformed document this returns an empty array and
	 * records why in get_errors(); it never throws, so one bad feed cannot abort
	 * a run over several feeds.
	 *
	 * @param string $xml Raw feed body.
	 * @return array<int,array<string,mixed>>
	 */
	public function parse( $xml ) {
		$this->errors = [];
		$articles     = [];

		if ( ! is_string( $xml ) || '' === trim( $xml ) ) {
			$this->errors[] = 'empty response body';
			return $articles;
		}

		// LIBXML_NONET blocks external entity fetches (XXE); NOCDATA folds CDATA
		// sections into plain strings so TextContent behaves like the other nodes.
		$previous   = libxml_use_internal_errors( true );
		$data       = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		$xml_errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $data || ! isset( $data->Sprava ) ) {
			$this->errors[] = ! empty( $xml_errors )
				? trim( $xml_errors[0]->message )
				: 'no <Sprava> elements found';
			return $articles;
		}

		foreach ( $data->Sprava as $n ) {
			$article = $this->map_article( $n );

			if ( null !== $article ) {
				$articles[] = $article;
			}
		}

		return $articles;
	}

	/**
	 * Map a single <Sprava> element. Returns null when the article must be skipped.
	 *
	 * @param \SimpleXMLElement $n
	 * @return array<string,mixed>|null
	 */
	private function map_article( $n ) {
		$thumbnail_url = (string) $n->Obrazok->ObrazokLinka;

		// No image, no import — the importer requires a featured image.
		if ( '' === $thumbnail_url ) {
			return null;
		}

		return [
			'_w_id'          => (string) $n->ID,
			'u_id'           => (string) $n->UnikatneID,
			'date_published' => (string) $n->DatumVydania,
			'date_modified'  => (string) $n->DatumAktualizacie,
			'time_publish'   => (string) $n->CasVydania,
			'time_modified'  => (string) $n->CasAktualizacie,
			'title'          => (string) $n->Nadpis,
			'perex'          => (string) $n->Perex,
			'section'        => [
				'category'     => (string) $n->Sekcia->Rubrika,
				'sub_category' => (string) $n->Sekcia->Podrubrika,
			],
			'locations'      => (array) $n->Lokality->Lokalita,
			'content'        => trim( strip_tags( (string) $n->TextContent, $this->allowable_tags ) ),
			'thumbnail'      => [
				'url'     => self::normalize_image_url( $thumbnail_url, $this->image_protocol ),
				'size'    => (string) $n->Obrazok->VelkostSuboru,
				'height'  => (string) $n->Obrazok->ObrazokVyska,
				'width'   => (string) $n->Obrazok->ObrazokSirka,
				// Image caption + credit (SITA feed 2026-08+). null when the element is
				// absent (older feed) so the sideload step leaves the file's IPTC-derived
				// caption alone; a present-but-empty element ('') overrides that IPTC.
				'caption' => isset( $n->Obrazok->ObrazokTitulok ) ? trim( (string) $n->Obrazok->ObrazokTitulok ) : null,
				'source'  => isset( $n->Obrazok->ObrazokZdroj ) ? trim( (string) $n->Obrazok->ObrazokZdroj ) : null,
			],
		];
	}

	/**
	 * Make an image URL absolute.
	 *
	 * Prefix check only: a URL that merely contains "http://" somewhere in a query
	 * string is not absolute.
	 *
	 * @param string $url
	 * @param string $protocol
	 * @return string
	 */
	public static function normalize_image_url( $url, $protocol = 'https://' ) {
		if ( stripos( $url, 'http://' ) !== 0 && stripos( $url, 'https://' ) !== 0 ) {
			$url = $protocol . ltrim( $url, '/' );
		}

		return $url;
	}

	/**
	 * Problems recorded by the last parse() call.
	 *
	 * @return string[]
	 */
	public function get_errors() {
		return $this->errors;
	}
}
