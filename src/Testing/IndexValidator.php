<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport\Testing;

use DOMDocument;
use LibXMLError;
use Pushery\TaxAuditExport\Dtd;

/**
 * Validates an `index.xml` against the document type definition of the description standard, for a
 * test suite.
 *
 * libxml refuses the content model the definition gives `Media`, `(Name, Command*, Table*, Command*,
 * AcceptNoTables?)`, as not deterministic: after a `Command` it cannot tell which of the two it is in.
 * The check therefore reads the definition with the equivalent model `(Name, Command*, (Table+,
 * Command*)?, AcceptNoTables?)`, which accepts the same documents. The export always ships the
 * definition unchanged ({@see Dtd}).
 */
final class IndexValidator
{
    private const string MEDIA = '<!ELEMENT Media (Name, Command*, Table*, Command*, AcceptNoTables?)>';

    private const string DETERMINISTIC_MEDIA = '<!ELEMENT Media (Name, Command*, (Table+, Command*)?, AcceptNoTables?)>';

    /**
     * The messages of libxml for every place the document departs from the definition; none for a
     * valid one.
     *
     * @return list<string>
     */
    public static function errors(string $xml): array
    {
        if (preg_match('/<!DOCTYPE\s+DataSet\s+SYSTEM\s+"[^"]*"\s*>/', $xml, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return ['the document declares no document type DataSet with an external definition'];
        }

        $inline = substr_replace($xml, '<!DOCTYPE DataSet ['.self::definition().']>', $match[0][1], strlen($match[0][0]));
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if ($document->loadXML($inline, LIBXML_NONET)) {
                $document->validate();
            }

            return array_map(
                static fn (LibXMLError $error): string => sprintf('line %d: %s', $error->line, trim($error->message)),
                libxml_get_errors(),
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function isValid(string $xml): bool
    {
        return self::errors($xml) === [];
    }

    /**
     * The definition as an internal subset: without its text declaration, with the deterministic
     * model. The definition is the constant of Dtd, whose content model of Media is the one MEDIA
     * names: left unreplaced, it would refuse a valid index.
     */
    private static function definition(): string
    {
        $definition = (string) preg_replace('/^\s*<\?xml[^>]*\?>/', '', Dtd::contents());

        return str_replace(self::MEDIA, self::DETERMINISTIC_MEDIA, $definition);
    }
}
