<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{0: string|int|float|null, 1: int}>
     */
    public static function amounts(): array
    {
        return [
            'two decimals' => ['12.34', 1234],
            'one decimal' => ['2.9', 290],
            'no decimals' => ['32', 3200],
            'extra precision truncated' => ['0.999', 99],
            'negative' => ['-1.50', -150],
            'zero' => ['0.00', 0],
            'empty string' => ['', 0],
            'null' => [null, 0],
            'integer number' => [10, 1000],
            'float number' => [5.1, 510],
        ];
    }

    #[DataProvider('amounts')]
    public function test_converts_decimal_amounts_to_cents_without_floats(string|int|float|null $amount, int $expectedCents): void
    {
        $this->assertSame($expectedCents, Money::toCents($amount));
    }

    public function test_rejects_unparseable_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents('12,50');
    }
}
