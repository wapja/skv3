<?php

declare(strict_types=1);

/** covers §6.6 */

use App\Support\Invitations\InvitationList;

it('reads pasted addresses one per line or separated by commas or semicolons (CD-6)', function (string $text, array $expected): void {
    expect(InvitationList::fromText($text))->toBe($expected);
})->with([
    'one per line' => ["ada@example.com\nbob@example.com", [
        ['email' => 'ada@example.com', 'locale' => null],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'commas, semicolons and Windows line ends' => ["ada@example.com, bob@example.com;cleo@example.com\r\ndan@example.com", [
        ['email' => 'ada@example.com', 'locale' => null],
        ['email' => 'bob@example.com', 'locale' => null],
        ['email' => 'cleo@example.com', 'locale' => null],
        ['email' => 'dan@example.com', 'locale' => null],
    ]],
    'blank lines, spaces, capitals and a BOM' => ["\u{FEFF}  Ada@Example.COM  \n\n \n;,\nBOB@example.com\n", [
        ['email' => 'ada@example.com', 'locale' => null],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'nothing but separators' => [" \n,;\r\n", []],
    'an invalid address is kept for the page to refuse' => ['not-an-address', [['email' => 'not-an-address', 'locale' => null]]],
]);

it('reads a CSV file with or without a header, comma- or semicolon-separated (CD-6)', function (string $contents, array $expected): void {
    expect(InvitationList::fromCsv($contents))->toBe($expected);
})->with([
    'addresses only, no header' => ["ada@example.com\nbob@example.com\n", [
        ['email' => 'ada@example.com', 'locale' => null],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'address and locale, no header' => ["ada@example.com,nl\nbob@example.com,EN\ncleo@example.com,\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
        ['email' => 'bob@example.com', 'locale' => 'en'],
        ['email' => 'cleo@example.com', 'locale' => null],
    ]],
    'a header, semicolons and a BOM, as Excel writes it' => ["\u{FEFF}E-mailadres;Taal\r\nAda@Example.com;nl\r\n\r\nbob@example.com;en\r\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
        ['email' => 'bob@example.com', 'locale' => 'en'],
    ]],
    'a header that puts the columns elsewhere' => ["name,language,email\nAda,nl,ada@example.com\nBob,,bob@example.com\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'a header without a locale column' => ["email,name\nada@example.com,Ada\n", [
        ['email' => 'ada@example.com', 'locale' => null],
    ]],
    'quoted cells with spaces' => ["\"email\",\"locale\"\n\" ada@example.com \",\" nl \"\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
    ]],
    'rows without an address' => ["email;locale\n;nl\n   \nbob@example.com;en\n", [
        ['email' => 'bob@example.com', 'locale' => 'en'],
    ]],
    'a header that names only the locale column' => ["address;taal\nada@example.com;nl\n", [
        ['email' => 'ada@example.com', 'locale' => 'nl'],
    ]],
    'a mistyped first address is a row, not a header' => ["ada.example.com,nl\nbob@example.com\n", [
        ['email' => 'ada.example.com', 'locale' => 'nl'],
        ['email' => 'bob@example.com', 'locale' => null],
    ]],
    'a first address without an @ that contains "mail" is a row' => ["ada.gmail.com,nl\nbob@example.com,en\n", [
        ['email' => 'ada.gmail.com', 'locale' => 'nl'],
        ['email' => 'bob@example.com', 'locale' => 'en'],
    ]],
    'only a header' => ["email,locale\n", []],
    'empty' => ['', []],
]);

it('keeps every row, so the page can refuse a list longer than the maximum (CD-6)', function (): void {
    $text = implode("\n", array_map(fn (int $index): string => "person{$index}@example.com", range(1, InvitationList::MAX_ROWS + 1)));

    expect(InvitationList::fromText($text))->toHaveCount(101)
        ->and(InvitationList::fromCsv($text))->toHaveCount(101)
        ->and(InvitationList::MAX_ROWS)->toBe(100);
});
