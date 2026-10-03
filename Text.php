<?php

namespace Omnibank\Files;

/** What the readers and the writer share: amounts, dates, encodings, IBANs, the SEPA character set. */
final class Text
{
    /** The content as UTF-8: as it is when it is UTF-8 already (its BOM dropped), from Windows-1252 otherwise. */
    public static function utf8(string $content, ?string $encoding = null): string
    {
        if (null !== $encoding && !\in_array(strtoupper($encoding), ['UTF-8', 'UTF8'], true)) {
            $content = self::convert($content, $encoding);
        } elseif (!preg_match('//u', $content)) {
            $content = self::convert($content, 'Windows-1252');
        }

        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /**
     * A bank's amount as a plain decimal ("-1234.56"): spaces, non-breaking
     * spaces and apostrophes between thousands dropped, the decimal mark
     * found - the last of "," and "." when both are there, "," alone when it
     * is followed by one or two digits, or as $decimal says. A trailing or
     * parenthesized minus counts.
     */
    public static function decimal(string $amount, ?string $decimal = null): ?string
    {
        $amount = trim(str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", ' ', "'", '’', '€', '$', '£'], '', $amount));
        $amount = str_replace(['−', '–'], '-', $amount);
        if ('' === $amount) {
            return null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $amount, $m)) {
            [$negative, $amount] = [true, $m[1]];
        }
        if (str_ends_with($amount, '-')) {
            [$negative, $amount] = [true, substr($amount, 0, -1)];
        }
        if (str_starts_with($amount, '-')) {
            [$negative, $amount] = [!$negative, substr($amount, 1)];
        } elseif (str_starts_with($amount, '+')) {
            $amount = substr($amount, 1);
        }
        $decimal ??= match (true) {
            str_contains($amount, ',') && str_contains($amount, '.') => strrpos($amount, ',') > strrpos($amount, '.') ? ',' : '.',
            str_contains($amount, ',') => 1 === substr_count($amount, ',') && preg_match('/,\d{1,2}$/', $amount) ? ',' : '.',
            default => '.',
        };
        $thousands = ',' === $decimal ? '.' : ',';
        $amount = str_replace([$thousands, $decimal], ['', '.'], $amount);
        if (!preg_match('/^\d*(\.\d*)?$/', $amount) || '' === trim($amount, '.')) {
            return null;
        }

        return ($negative ? '-' : '').$amount;
    }

    /**
     * A date in the first of $formats it matches, at midnight.
     *
     * @param list<string> $formats
     */
    public static function date(string $value, array $formats): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $date && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count']))) {
                return $date;
            }
        }

        return null;
    }

    /** An ISO date ("2026-09-03") or date-time ("2026-09-03T10:15:00+02:00") from an XML file. */
    public static function isoDate(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** An IBAN as it is written in files: upper case, no spaces. */
    public static function iban(?string $iban): ?string
    {
        $iban = strtoupper(preg_replace('/[\s\-.]+/', '', (string) $iban));

        return '' === $iban ? null : $iban;
    }

    /** Whether an IBAN holds: its shape and its check digits (ISO 13616, modulo 97). */
    public static function isIban(?string $iban): bool
    {
        $iban = self::iban($iban);
        if (null === $iban || !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $iban)) {
            return false;
        }
        $digits = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (\ord($char) - 55) : $char;
        }
        $rest = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $rest = (int) ($rest.$chunk) % 97;
        }

        return 1 === $rest;
    }

    /**
     * Text in the character set every SEPA bank takes (EPC: a-z A-Z 0-9
     * / - ? : ( ) . , ' + and space), accents transliterated, the rest made
     * spaces, cut at $length.
     */
    public static function sepa(string $text, int $length): string
    {
        // Dashes and quotes first: iconv (without intl) writes an em dash "--".
        $text = strtr($text, ['—' => '-', '–' => '-', '‑' => '-', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '«' => '"', '»' => '"', "\u{00A0}" => ' ', "\u{202F}" => ' ']);
        if (class_exists(\Transliterator::class) && null !== ($t = \Transliterator::create('Any-Latin; Latin-ASCII'))) {
            $text = (string) $t->transliterate($text);
        } elseif (\function_exists('iconv')) {
            // Letter by letter: iconv writes "?" for what it cannot transliterate (n° → n?), a SEPA character.
            $text = preg_replace_callback('/[^\x00-\x7F]/u', function ($m) {
                $ascii = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $m[0]);

                return str_contains($ascii, '?') ? ' ' : $ascii;
            }, $text);
        }
        $text = preg_replace('/[^A-Za-z0-9\/\-?:().,\'+ ]/', ' ', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return rtrim(substr($text, 0, $length));
    }

    /** Lower case, accents and punctuation gone: how a CSV header is compared. */
    public static function key(string $text): string
    {
        $text = self::utf8($text);
        if (class_exists(\Transliterator::class) && null !== ($t = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()'))) {
            $text = (string) $t->transliterate($text);
        } else {
            $text = strtolower(strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'î' => 'i', 'ï' => 'i', 'ç' => 'c']));
        }

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    private static function convert(string $content, string $from): string
    {
        if (\function_exists('iconv') && false !== ($converted = @iconv($from, 'UTF-8//IGNORE', $content))) {
            return $converted;
        }
        if (\function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($content, 'UTF-8', $from);
        }

        return $content;
    }
}
