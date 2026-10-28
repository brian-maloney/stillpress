<?php
/**
 * Accent folding without the intl extension.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

/**
 * Removes accents the way the search client does in the browser: decompose (NFD), drop combining diacritical marks
 * (U+0300 to U+036F), recompose (NFC).
 *
 * With intl, PHP does exactly that through Normalizer. Without it, a precomposed letter in Latin-1 Supplement, Latin
 * Extended-A/B, Greek, Cyrillic or Latin Extended Additional maps to the letter NFD would leave; the table was generated
 * from Unicode 16.0.0 data and FoldTest checks it against Normalizer. WordPress's remove_accents() isn't used because it
 * maps some letters differently (ø to o, ß to ss), which the browser would not match.
 */
final class Fold {

	/** Precomposed letters, in the same order as TO. */
	const FROM = 'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝàáâãäåçèéêëìíî'
		. 'ïñòóôõöùúûüýÿĀāĂăĄąĆćĈĉĊċČčĎďĒēĔĕĖėĘęĚěĜ'
		. 'ĝĞğĠġĢģĤĥĨĩĪīĬĭĮįİĴĵĶķĹĺĻļĽľŃńŅņŇňŌōŎŏŐő'
		. 'ŔŕŖŗŘřŚśŜŝŞşŠšŢţŤťŨũŪūŬŭŮůŰűŲųŴŵŶŷŸŹźŻżŽ'
		. 'žƠơƯưǍǎǏǐǑǒǓǔǕǖǗǘǙǚǛǜǞǟǠǡǢǣǦǧǨǩǪǫǬǭǮǯǰǴǵ'
		. 'ǸǹǺǻǼǽǾǿȀȁȂȃȄȅȆȇȈȉȊȋȌȍȎȏȐȑȒȓȔȕȖȗȘșȚțȞȟȦȧ'
		. 'ȨȩȪȫȬȭȮȯȰȱȲȳʹΆΈΉΊΌΎΏΐΪΫάέήίΰϊϋόύώϓϔЀЁЃЇЌ'
		. 'ЍЎЙйѐёѓїќѝўѶѷӁӂӐӑӒӓӖӗӚӛӜӝӞӟӢӣӤӥӦӧӪӫӬӭӮӯӰ'
		. 'ӱӲӳӴӵӸӹḀḁḂḃḄḅḆḇḈḉḊḋḌḍḎḏḐḑḒḓḔḕḖḗḘḙḚḛḜḝḞḟḠ'
		. 'ḡḢḣḤḥḦḧḨḩḪḫḬḭḮḯḰḱḲḳḴḵḶḷḸḹḺḻḼḽḾḿṀṁṂṃṄṅṆṇṈ'
		. 'ṉṊṋṌṍṎṏṐṑṒṓṔṕṖṗṘṙṚṛṜṝṞṟṠṡṢṣṤṥṦṧṨṩṪṫṬṭṮṯṰ'
		. 'ṱṲṳṴṵṶṷṸṹṺṻṼṽṾṿẀẁẂẃẄẅẆẇẈẉẊẋẌẍẎẏẐẑẒẓẔẕẖẗẘ'
		. 'ẙẛẠạẢảẤấẦầẨẩẪẫẬậẮắẰằẲẳẴẵẶặẸẹẺẻẼẽẾếỀềỂểỄễ'
		. 'ỆệỈỉỊịỌọỎỏỐốỒồỔổỖỗỘộỚớỜờỞởỠỡỢợỤụỦủỨứỪừỬử'
		. 'ỮữỰựỲỳỴỵỶỷỸỹἀἁἂἃἄἅἆἇἈἉἊἋἌἍἎἏἐἑἒἓἔἕἘἙἚἛἜἝ'
		. 'ἠἡἢἣἤἥἦἧἨἩἪἫἬἭἮἯἰἱἲἳἴἵἶἷἸἹἺἻἼἽἾἿὀὁὂὃὄὅὈὉ'
		. 'ὊὋὌὍὐὑὒὓὔὕὖὗὙὛὝὟὠὡὢὣὤὥὦὧὨὩὪὫὬὭὮὯὰάὲέὴήὶί'
		. 'ὸόὺύὼώᾀᾁᾂᾃᾄᾅᾆᾇᾈᾉᾊᾋᾌᾍᾎᾏᾐᾑᾒᾓᾔᾕᾖᾗᾘᾙᾚᾛᾜᾝᾞᾟᾠᾡ'
		. 'ᾢᾣᾤᾥᾦᾧᾨᾩᾪᾫᾬᾭᾮᾯᾰᾱᾲᾳᾴᾶᾷᾸᾹᾺΆᾼιῂῃῄῆῇῈΈῊΉῌῐῑῒ'
		. 'ΐῖῗῘῙῚΊῠῡῢΰῤῥῦῧῨῩῪΎῬῲῳῴῶῷῸΌῺΏῼ';

	/** The letter each one folds to. */
	const TO = 'AAAAAACEEEEIIIINOOOOOUUUUYaaaaaaceeeeiii'
		. 'inooooouuuuyyAaAaAaCcCcCcCcDdEeEeEeEeEeG'
		. 'gGgGgGgHhIiIiIiIiIJjKkLlLlLlNnNnNnOoOoOo'
		. 'RrRrRrSsSsSsSsTtTtUuUuUuUuUuUuWwYyYZzZzZ'
		. 'zOoUuAaIiOoUuUuUuUuUuAaAaÆæGgKkOoOoƷʒjGg'
		. 'NnAaÆæØøAaAaEeEeIiIiOoOoRrRrUuUuSsTtHhAa'
		. 'EeOoOoOoOoYyʹΑΕΗΙΟΥΩιΙΥαεηιυιυουωϒϒЕЕГІК'
		. 'ИУИиеегікиуѴѵЖжАаАаЕеӘәЖжЗзИиИиОоӨөЭэУуУ'
		. 'уУуЧчЫыAaBbBbBbCcDdDdDdDdDdEeEeEeEeEeFfG'
		. 'gHhHhHhHhHhIiIiKkKkKkLlLlLlLlMmMmMmNnNnN'
		. 'nNnOoOoOoOoPpPpRrRrRrRrSsSsSsSsSsTtTtTtT'
		. 'tUuUuUuUuUuVvVvWwWwWwWwWwXxXxYyZzZzZzhtw'
		. 'yſAaAaAaAaAaAaAaAaAaAaAaAaEeEeEeEeEeEeEe'
		. 'EeIiIiOoOoOoOoOoOoOoOoOoOoOoOoUuUuUuUuUu'
		. 'UuUuYyYyYyYyααααααααΑΑΑΑΑΑΑΑεεεεεεΕΕΕΕΕΕ'
		. 'ηηηηηηηηΗΗΗΗΗΗΗΗιιιιιιιιΙΙΙΙΙΙΙΙοοοοοοΟΟ'
		. 'ΟΟΟΟυυυυυυυυΥΥΥΥωωωωωωωωΩΩΩΩΩΩΩΩααεεηηιι'
		. 'οουυωωααααααααΑΑΑΑΑΑΑΑηηηηηηηηΗΗΗΗΗΗΗΗωω'
		. 'ωωωωωωΩΩΩΩΩΩΩΩαααααααΑΑΑΑΑιηηηηηΕΕΗΗΗιιι'
		. 'ιιιΙΙΙΙυυυυρρυυΥΥΥΥΡωωωωωΟΟΩΩΩ';

	/**
	 * Lookup table built from FROM and TO.
	 *
	 * @var array<string,string>|null
	 */
	private static $table;

	/**
	 * Folds accents in lowercase or mixed-case text.
	 *
	 * @param string $text UTF-8 text.
	 * @param bool   $intl Use Normalizer when the extension is loaded; false forces the table (tests).
	 * @return string
	 */
	public static function fold( string $text, bool $intl = true ): string {
		if ( $intl && class_exists( '\\Normalizer' ) ) {
			$decomposed = \Normalizer::normalize( $text, \Normalizer::FORM_D );
			if ( false !== $decomposed ) {
				$stripped = (string) preg_replace( '/[\x{0300}-\x{036F}]+/u', '', $decomposed );
				return (string) \Normalizer::normalize( $stripped, \Normalizer::FORM_C );
			}
		}
		if ( null === self::$table ) {
			self::$table = array_combine( self::chars( self::FROM ), self::chars( self::TO ) );
		}
		return (string) preg_replace( '/[\x{0300}-\x{036F}]+/u', '', strtr( $text, self::$table ) );
	}

	/**
	 * Splits a UTF-8 string into characters.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function chars( string $text ): array {
		return (array) preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	}
}
