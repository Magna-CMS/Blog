<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

/**
 * The full set of ISO 639-1 languages, code => English name. Used to populate the
 * language picker in the post builder and the default-language setting, and to
 * resolve a display name for a stored locale code.
 */
final class Locales
{
    /**
     * @var array<string, string>
     */
    private const NAMES = [
        'ab' => 'Abkhazian', 'aa' => 'Afar', 'af' => 'Afrikaans', 'ak' => 'Akan',
        'sq' => 'Albanian', 'am' => 'Amharic', 'ar' => 'Arabic', 'an' => 'Aragonese',
        'hy' => 'Armenian', 'as' => 'Assamese', 'av' => 'Avaric', 'ae' => 'Avestan',
        'ay' => 'Aymara', 'az' => 'Azerbaijani', 'bm' => 'Bambara', 'ba' => 'Bashkir',
        'eu' => 'Basque', 'be' => 'Belarusian', 'bn' => 'Bengali', 'bh' => 'Bihari',
        'bi' => 'Bislama', 'bs' => 'Bosnian', 'br' => 'Breton', 'bg' => 'Bulgarian',
        'my' => 'Burmese', 'ca' => 'Catalan', 'ch' => 'Chamorro', 'ce' => 'Chechen',
        'ny' => 'Chichewa', 'zh' => 'Chinese', 'cv' => 'Chuvash', 'kw' => 'Cornish',
        'co' => 'Corsican', 'cr' => 'Cree', 'hr' => 'Croatian', 'cs' => 'Czech',
        'da' => 'Danish', 'dv' => 'Divehi', 'nl' => 'Dutch', 'dz' => 'Dzongkha',
        'en' => 'English', 'eo' => 'Esperanto', 'et' => 'Estonian', 'ee' => 'Ewe',
        'fo' => 'Faroese', 'fj' => 'Fijian', 'fi' => 'Finnish', 'fr' => 'French',
        'ff' => 'Fulah', 'gl' => 'Galician', 'ka' => 'Georgian', 'de' => 'German',
        'el' => 'Greek', 'gn' => 'Guarani', 'gu' => 'Gujarati', 'ht' => 'Haitian Creole',
        'ha' => 'Hausa', 'he' => 'Hebrew', 'hz' => 'Herero', 'hi' => 'Hindi',
        'ho' => 'Hiri Motu', 'hu' => 'Hungarian', 'ia' => 'Interlingua', 'id' => 'Indonesian',
        'ie' => 'Interlingue', 'ga' => 'Irish', 'ig' => 'Igbo', 'ik' => 'Inupiaq',
        'io' => 'Ido', 'is' => 'Icelandic', 'it' => 'Italian', 'iu' => 'Inuktitut',
        'ja' => 'Japanese', 'jv' => 'Javanese', 'kl' => 'Kalaallisut', 'kn' => 'Kannada',
        'kr' => 'Kanuri', 'ks' => 'Kashmiri', 'kk' => 'Kazakh', 'km' => 'Khmer',
        'ki' => 'Kikuyu', 'rw' => 'Kinyarwanda', 'ky' => 'Kyrgyz', 'kv' => 'Komi',
        'kg' => 'Kongo', 'ko' => 'Korean', 'ku' => 'Kurdish', 'kj' => 'Kwanyama',
        'la' => 'Latin', 'lb' => 'Luxembourgish', 'lg' => 'Ganda', 'li' => 'Limburgish',
        'ln' => 'Lingala', 'lo' => 'Lao', 'lt' => 'Lithuanian', 'lu' => 'Luba-Katanga',
        'lv' => 'Latvian', 'gv' => 'Manx', 'mk' => 'Macedonian', 'mg' => 'Malagasy',
        'ms' => 'Malay', 'ml' => 'Malayalam', 'mt' => 'Maltese', 'mi' => 'Maori',
        'mr' => 'Marathi', 'mh' => 'Marshallese', 'mn' => 'Mongolian', 'na' => 'Nauru',
        'nv' => 'Navajo', 'nd' => 'North Ndebele', 'ne' => 'Nepali', 'ng' => 'Ndonga',
        'nb' => 'Norwegian Bokmal', 'nn' => 'Norwegian Nynorsk', 'no' => 'Norwegian',
        'ii' => 'Nuosu', 'nr' => 'South Ndebele', 'oc' => 'Occitan', 'oj' => 'Ojibwe',
        'cu' => 'Church Slavonic', 'om' => 'Oromo', 'or' => 'Odia', 'os' => 'Ossetian',
        'pa' => 'Punjabi', 'pi' => 'Pali', 'fa' => 'Persian', 'pl' => 'Polish',
        'ps' => 'Pashto', 'pt' => 'Portuguese', 'qu' => 'Quechua', 'rm' => 'Romansh',
        'rn' => 'Rundi', 'ro' => 'Romanian', 'ru' => 'Russian', 'sa' => 'Sanskrit',
        'sc' => 'Sardinian', 'sd' => 'Sindhi', 'se' => 'Northern Sami', 'sm' => 'Samoan',
        'sg' => 'Sango', 'sr' => 'Serbian', 'gd' => 'Scottish Gaelic', 'sn' => 'Shona',
        'si' => 'Sinhala', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'so' => 'Somali',
        'st' => 'Southern Sotho', 'es' => 'Spanish', 'su' => 'Sundanese', 'sw' => 'Swahili',
        'ss' => 'Swati', 'sv' => 'Swedish', 'ta' => 'Tamil', 'te' => 'Telugu',
        'tg' => 'Tajik', 'th' => 'Thai', 'ti' => 'Tigrinya', 'bo' => 'Tibetan',
        'tk' => 'Turkmen', 'tl' => 'Tagalog', 'tn' => 'Tswana', 'to' => 'Tongan',
        'tr' => 'Turkish', 'ts' => 'Tsonga', 'tt' => 'Tatar', 'tw' => 'Twi',
        'ty' => 'Tahitian', 'ug' => 'Uyghur', 'uk' => 'Ukrainian', 'ur' => 'Urdu',
        'uz' => 'Uzbek', 've' => 'Venda', 'vi' => 'Vietnamese', 'vo' => 'Volapuk',
        'wa' => 'Walloon', 'cy' => 'Welsh', 'wo' => 'Wolof', 'fy' => 'Western Frisian',
        'xh' => 'Xhosa', 'yi' => 'Yiddish', 'yo' => 'Yoruba', 'za' => 'Zhuang',
        'zu' => 'Zulu',
    ];

    /**
     * Code => name for every language, sorted by name for the picker.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $names = self::NAMES;
        asort($names);

        return $names;
    }

    /** The English name for a locale code, or the code itself when unknown. */
    public static function name(string $code): string
    {
        return self::NAMES[$code] ?? $code;
    }

    public static function has(string $code): bool
    {
        return isset(self::NAMES[$code]);
    }
}
