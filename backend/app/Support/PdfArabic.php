<?php

namespace App\Support;

use ArPHP\I18N\Arabic;

/**
 * dompdf has no bidi or contextual-shaping engine: it draws glyphs left to right
 * exactly as given, so raw Arabic comes out reversed and with the letters
 * disconnected. Pre-shaping to presentation forms and reversing the run here
 * makes it render correctly in the generated PDFs.
 */
class PdfArabic
{
    private static ?Arabic $arabic = null;

    public static function shape(?string $text): string
    {
        $text = (string) $text;

        if ($text === '' || ! preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        return (self::$arabic ??= new Arabic())->utf8Glyphs($text);
    }
}
