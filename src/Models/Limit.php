<?php

namespace NabilHassen\LaravelUsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use NabilHassen\LaravelUsageLimiter\Contracts\Limit as LimitContract;
use NabilHassen\LaravelUsageLimiter\Exceptions\InvalidLimitResetFrequencyValue;
use NabilHassen\LaravelUsageLimiter\Exceptions\LimitAlreadyExists;
use NabilHassen\LaravelUsageLimiter\Exceptions\LimitDoesNotExist;
use NabilHassen\LaravelUsageLimiter\LimitManager;
use NabilHassen\LaravelUsageLimiter\Traits\RefreshCache;

class Limit extends Model implements LimitContract
{
    use RefreshCache, SoftDeletes;

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    protected static array $resetFrequencyPossibleValues = [
        'every second',
        'every minute',
        'every hour',
        'every day',
        'every week',
        'every two weeks',
        'every month',
        'every quarter',
        'every six months',
        'every year',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('limit.tables.limits') ?: parent::getTable();
    }

    public static function create(array $data = [])
    {
        return static::findOrCreate($data, true);
    }

    public static function findOrCreate(array $data, bool $throw = false): LimitContract
    {
        $data = static::validateArgs($data);

        $limit = app(LimitManager::class)->getLimit($data);

        if (! $limit) {
            return tap(new static($data), fn ($model) => $model->save());
        }

        if ($throw) {
            throw new LimitAlreadyExists($data['name'], $data['plan'] ?? null);
        }

        return $limit;
    }

    protected static function validateArgs(array $data): array
    {
        if (! Arr::has($data, 'name') || ! Arr::has($data, 'allowed_amount')) {
            throw new InvalidArgumentException('"name" and "allowed_amount" keys do not exist on the array.');
        }

        if (! is_numeric($data['allowed_amount']) || $data['allowed_amount'] < 0) {
            throw new InvalidArgumentException('"allowed_amount" should be a float|int type and greater than or equal to 0.');
        }

        if (
            Arr::has($data, 'reset_frequency') &&
            array_search($data['reset_frequency'], static::$resetFrequencyPossibleValues) === false
        ) {
            throw new InvalidLimitResetFrequencyValue;
        }

        if (isset($data['plan']) && empty($data['plan'])) {
            unset($data['plan']);
        }

        return $data;
    }

    public static function findByName(/*string|LimitContract*/ $name, ?string $plan = null): LimitContract
    {
        if (is_object($name)) {
            return $name;
        }

        $limit = app(LimitManager::class)->getLimit(compact('name', 'plan'));

        if (! $limit) {
            throw new LimitDoesNotExist($name, $plan);
        }

        return $limit;
    }

    public static function findById(/*int|LimitContract*/ $id): LimitContract
    {
        if (is_object($id)) {
            return $id;
        }

        $limit = app(LimitManager::class)->getLimit(compact('id'));

        if (! $limit) {
            throw new LimitDoesNotExist($id);
        }

        return $limit;
    }

    public function incrementBy(/*float|int*/ $amount = 1.0): bool
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('"amount" should be greater than 0.');
        }

        $this->allowed_amount += $amount;

        return $this->save();
    }

    public function decrementBy(/*float|int*/ $amount = 1.0): bool
    {
        $this->allowed_amount -= $amount;

        if ($this->allowed_amount < 0) {
            throw new InvalidArgumentException('"allowed_amount" should be greater than or equal to 0.');
        }

        return $this->save();
    }

    public function getResetFrequencyOptions(): Collection
    {
        return collect(static::$resetFrequencyPossibleValues);
    }
}
