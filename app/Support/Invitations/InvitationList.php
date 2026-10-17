<?php

declare(strict_types=1);

namespace App\Support\Invitations;

use Generator;

/**
 * Turns the bulk invitation input into rows (§6.6, CD-6): pasted text (addresses one per line, or separated by
 * commas or semicolons) and a CSV file (an email column and an optional locale column, with or without a header
 * row, comma- or semicolon-separated). A UTF-8 BOM is stripped, blank lines and cells are skipped, and every value
 * is trimmed and lower-cased. The content is only read as text, never included or evaluated. Nothing is validated
 * here: the page checks each row, and refuses a list longer than MAX_ROWS. The input is read one line or value at
 * a time and reading stops at a limit (one row past MAX_ROWS by default, enough to tell a list is too long), so a
 * large input never costs more memory than that many rows.
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
     * @param  positive-int  $limit  the most rows to read
     * @return list<InvitationRow>
     */
    public static function fromText(string $text, int $limit = self::MAX_ROWS + 1): array
    {
        $rows = [];

        foreach (self::pieces(self::withoutBom($text), "\r\n,;") as $value) {
            $email = self::clean($value);

            if ($email === '') {
                continue;
            }

            $rows[] = ['email' => $email, 'locale' => null];

            if (count($rows) >= $limit) {
                break;
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
     * @param  positive-int  $limit  the most rows to read
     * @return list<InvitationRow>
     */
    public static function fromCsv(string $contents, int $limit = self::MAX_ROWS + 1): array
    {
        $rows = [];
        $delimiter = ',';
        $columns = null;

        foreach (self::pieces(self::withoutBom($contents), "\r\n") as $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($columns === null) {
                $delimiter = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
                $columns = self::header(str_getcsv($line, $delimiter, '"', ''));

                if ($columns !== null) {
                    continue;
                }

                $columns = [0, 1];
            }

            [$emailColumn, $localeColumn] = $columns;
            $record = str_getcsv($line, $delimiter, '"', '');
            $email = self::clean($record[$emailColumn] ?? '');

            if ($email === '') {
                continue;
            }

            $locale = $localeColumn === null ? '' : self::clean($record[$localeColumn] ?? '');
            $rows[] = ['email' => $email, 'locale' => $locale === '' ? null : $locale];

            if (count($rows) >= $limit) {
                break;
            }
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

    /**
     * The pieces of the text between any of the separator characters, one at a time, so the text is never split
     * into one array; a run of separators yields empty pieces, which the callers skip.
     *
     * @return Generator<int, string>
     */
    private static function pieces(string $text, string $separators): Generator
    {
        $length = strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $end = strcspn($text, $separators, $offset);

            yield substr($text, $offset, $end);

            $offset += $end + 1;
        }
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
