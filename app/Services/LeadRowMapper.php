<?php

namespace App\Services;

use Brick\Money\Money;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use UnexpectedValueException;

class LeadRowMapper
{
    // Header order maps spreadsheet cells to lead fields.
    public const array COLUMNS = [
        'external_id', 'created_at', 'first_name', 'last_name', 'phone',
        'email', 'city', 'source', 'utm_campaign', 'product',
        'budget_uah', 'status', 'manager', 'comment', 'next_contact_at',
    ];

    /**
     * Convert XLSX cells into values for a batch INSERT.
     *
     * @return array<string, int|string|null>
     */
    public function map(Row $row): array
    {
        $values = [];

        foreach (self::COLUMNS as $index => $column) {
            $cell = $row->cells[$index] ?? null;
            // Use cached formula results stored in the XLSX file.
            $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell?->getValue();

            // Store missing values as NULL while preserving numeric zero.
            if ($value === null || $value === '') {
                $values[$column] = null;
            } elseif ($column === 'budget_uah') {
                // Batch INSERT bypasses model setters, so convert UAH to kopiykas here.
                $values[$column] = Money::of((string) $value, 'UAH')->getMinorAmount()->toInt();
            } elseif ($column === 'created_at' || $column === 'next_contact_at') {
                // Require a date recognized by OpenSpout before formatting it for the database.
                if (! $value instanceof DateTimeInterface) {
                    throw new UnexpectedValueException("Некоректна дата в колонці {$column}.");
                }

                $values[$column] = $value->format('Y-m-d H:i:s');
            } else {
                // Store text fields, including phone numbers and identifiers, as strings.
                $values[$column] = (string) $value;
            }
        }

        return $values;
    }
}
