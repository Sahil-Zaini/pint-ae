<?php

declare(strict_types=1);

namespace PintAE;

/**
 * One-line entry points.
 *
 *   $xml    = PintAE::generate($invoiceArray);
 *   $result = PintAE::validate($xml);          // ValidationResult
 *   $json   = PintAE::toJson($xml);
 */
final class PintAE
{
    public const VERSION = '1.0.0';

    /** @param array<string,mixed> $invoice  @param array<string,mixed> $options */
    public static function generate(array $invoice, array $options = []): string
    {
        return (new Generator($options))->generate($invoice);
    }

    public static function validate(string $xml): ValidationResult
    {
        return (new Validator())->validateXml($xml);
    }

    /** Generate and validate in one go. @return array{xml:string,result:ValidationResult} */
    public static function build(array $invoice, array $options = []): array
    {
        $xml = self::generate($invoice, $options);
        return ['xml' => $xml, 'result' => self::validate($xml)];
    }

    /** @return array<string,mixed> */
    public static function toArray(string $xml): array
    {
        return Reader::toArray($xml);
    }

    public static function toJson(string $xml, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        return (string) json_encode(Reader::toArray($xml), $flags);
    }

    /** @return array<string,mixed> totals, VAT breakdown and line amounts without building XML */
    public static function calculate(array $invoice): array
    {
        return Calculator::calculate($invoice);
    }
}
