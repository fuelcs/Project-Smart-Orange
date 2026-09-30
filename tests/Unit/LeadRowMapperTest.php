<?php

namespace Tests\Unit;

use App\Services\LeadRowMapper;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LeadWorkbook;
use UnexpectedValueException;

class LeadRowMapperTest extends TestCase
{
    #[DataProvider('budgets')]
    public function test_budget_is_converted_to_integer_kopiykas(mixed $input, ?int $expected): void
    {
        $mapped = (new LeadRowMapper)->map(LeadWorkbook::row(['budget_uah' => $input]));

        $this->assertSame($expected, $mapped['budget_uah']);
    }

    public static function budgets(): array
    {
        return [
            'decimal amount' => ['123.45', 12345],
            'numeric amount' => [123.45, 12345],
            'whole amount' => [23700, 2370000],
            'zero' => [0, 0],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    public function test_dates_are_formatted_for_database_storage(): void
    {
        $mapped = (new LeadRowMapper)->map(LeadWorkbook::row([
            'created_at' => new DateTimeImmutable('2026-01-02 03:04:05'),
            'next_contact_at' => new DateTimeImmutable('2026-01-03 10:20:30'),
        ]));

        $this->assertSame('2026-01-02 03:04:05', $mapped['created_at']);
        $this->assertSame('2026-01-03 10:20:30', $mapped['next_contact_at']);
    }

    public function test_missing_contact_date_remains_null(): void
    {
        $mapped = (new LeadRowMapper)->map(LeadWorkbook::row(['next_contact_at' => null]));

        $this->assertNull($mapped['next_contact_at']);
    }

    #[DataProvider('dateColumns')]
    public function test_invalid_date_type_throws(string $column): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Некоректна дата в колонці {$column}.");

        (new LeadRowMapper)->map(LeadWorkbook::row([$column => 'invalid']));
    }

    public static function dateColumns(): array
    {
        return [['created_at'], ['next_contact_at']];
    }

    public function test_phone_formula_uses_its_cached_result(): void
    {
        $mapped = (new LeadRowMapper)->map(LeadWorkbook::row([
            'phone' => new FormulaCell('=380671234567', 380671234567),
        ]));

        $this->assertSame('380671234567', $mapped['phone']);
    }
}
