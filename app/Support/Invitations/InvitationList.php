<?php

declare(strict_types=1);

namespace App\Support\Invitations;

/**
 * Turns the bulk invitation input into rows (§6.6, CD-6): pasted text (addresses one per line, or separated by
 * commas or semicolons) and a CSV file (an email column and an optional locale column, with or without a header
 * row, comma- or semicolon-separated). A UTF-8 BOM is stripped, blank lines and cells are skipped, and every value
 * is trimmed and lower-cased. The content is only read as text, never included or evaluated. Nothing is validated
 * here: the page checks each row, and refuses a list longer than MAX_ROWS.
 *
 * @phpstan-type InvitationRow array{email: string, locale: string|null}
 */
final class InvitationList
{
    /** The most rows one batch may hold (§6.6, CD-6). */
    public const int MAX_ROWS = 100;

    private const string BOM = "\u{FEFF}";

    /** Header cells that name the locale column; the email column is the first header cell that contains "mail" and no ".". */
    private const array LOCALE_HEADERS = ['locale', 'language', 'lang', 'taal'];

    /**
     * Pasted addresses; a pasted list carries no locale.
     *
     * @return list<InvitationRow>
     */
    public static function fromText(string $text): array
    {
        $rows = [];

        foreach (preg_split('/[\r\n,;]+/', self::withoutBom($text)) ?: [] as $value) {
            $email = self::clean($value);

            if ($email !== '') {
                $rows[] = ['email' => $email, 'locale' => null];
            }
        }

        return $rows;
    }

    /**
     * A CSV file's contents. The delimiter is the one the first line uses most (a semicolon, as Excel writes in
     * Dutch, or a comma). The first line is a header when no cell of it holds an "@" and one names a column (see
     * header()); the header then decides which columns hold the address and the locale. Without a header, the first
     * column is the address and the second the locale, so a first row with a mistyped address is kept and refused
     * by the page instead of dropped as a header.
     *
     * @return list<InvitationRow>
     */
    public static function fromCsv(string $contents): array
    {
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', self::withoutBom($contents)) ?: [],
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return [];
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $records = array_map(fn (string $line): array => str_getcsv($line, $delimiter, '"', ''), $lines);
        $header = self::header($records[0]);

        if ($header !== null) {
            array_shift($records);
        }

        [$emailColumn, $localeColumn] = $header ?? [0, 1];

        $rows = [];

        foreach ($records as $record) {
            $email = self::clean((string) ($record[$emailColumn] ?? ''));

            if ($email === '') {
                continue;
            }

            $locale = $localeColumn === null ? '' : self::clean((string) ($record[$localeColumn] ?? ''));
            $rows[] = ['email' => $email, 'locale' => $locale === '' ? null : $locale];
        }

        return $rows;
    }

    /**
     * The columns a header row names, or null when the record is not a header: it holds an "@", or none of its cells
     * names a column. The email column is the first cell that contains "mail" and no "." (email, e-mail, e-mailadres …;
     * an address that lost its "@", such as ada.gmail.com, keeps its domain dot and stays a row) and
     * defaults to the first column; a header that names no locale column means the file has none.
     *
     * @param  array<int, string|null>  $record
     * @return array{0: int, 1: int|null}|null
     */
    private static function header(array $record): ?array
    {
        $names = array_map(fn (?string $cell): string => self::clean((string) $cell), $record);
        $email = null;
        $locale = null;

        foreach ($names as $index => $name) {
            if (str_contains($name, '@')) {
                return null;
            }

            if ($email === null && str_contains($name, 'mail') && ! str_contains($name, '.')) {
                $email = $index;
            } elseif ($locale === null && in_array($name, self::LOCALE_HEADERS, true)) {
                $locale = $index;
            }
        }

        return $email === null && $locale === null ? null : [$email ?? 0, $locale];
    }

    private static function clean(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function withoutBom(string $text): string
    {
        return str_starts_with($text, self::BOM) ? substr($text, strlen(self::BOM)) : $text;
    }
}
