<?php

namespace App\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'external_id',
    'created_at',
    'first_name',
    'last_name',
    'phone',
    'email',
    'city',
    'source',
    'utm_campaign',
    'product',
    'budget_uah',
    'status',
    'manager',
    'comment',
    'next_contact_at',
])]
class Lead extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'next_contact_at' => 'immutable_datetime',
        ];
    }

    /**
     * Expose UAH as Money while storing integer kopiykas.
     *
     * @return Attribute<Money|null, Money|null>
     */
    protected function budgetUah(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?Money => $value === null ? null : Money::ofMinor($value, 'UAH'),
            set: fn (?Money $value): ?int => $value?->getMinorAmount()->toInt(),
        );
    }
}
