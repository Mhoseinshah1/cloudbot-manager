<?php

namespace App\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent builder for ledgers whose monetary columns are integer toman.
 *
 * PostgreSQL returns SUM(bigint) through PDO as a numeric string while
 * SQLite commonly returns an int. Normalizing aggregate sums here keeps the
 * domain contract (integer toman) consistent across database drivers.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class IntegerMoneyBuilder extends Builder
{
    /**
     * @param  string  $column
     */
    public function sum($column): int
    {
        return (int) $this->toBase()->sum($column);
    }
}
