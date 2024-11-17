<?php

namespace NabilHassen\LaravelUsageLimiter;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use NabilHassen\LaravelUsageLimiter\Contracts\Limit;
use NabilHassen\LaravelUsageLimiter\Exceptions\InvalidLimitResetFrequencyValue;

class LimitManager
{
    private $cache;

    private Limit $limitClass;

    /** @var \DateInterval|int */
    private $cacheExpirationTime;

    private string $cacheKey;

    private Collection $limits;

    public function __construct(Collection $limits, Limit $limitClass)
    {
        $this->limits = $limits;

        $this->limitClass = $limitClass;

        $this->initCache();
    }

    public function initCache(): void
    {
        $cacheStore = config('limit.cache.store');

        $this->cacheExpirationTime = config('limit.cache.expiration_time');

        $this->cacheKey = config('limit.cache.key');

        if ($cacheStore === 'default') {
            $this->cache = Cache::store();

            return;
        }

        if (! array_key_exists($cacheStore, config('cache.stores'))) {
            $cacheStore = 'array';
        }

        $this->cache = Cache::store($cacheStore);
    }

    public function getNextReset(string $limitResetFrequency, /*string|Carbon*/ $lastReset): Carbon
    {
        if (! $this->limitClass->getResetFrequencyOptions()->contains($limitResetFrequency)) {
            throw new InvalidLimitResetFrequencyValue;
        }

        $lastReset = Carbon::parse($lastReset);

        switch ($limitResetFrequency) {
            case 'every second':
                return $lastReset->addSecond();

            case 'every minute':
                return $lastReset->addMinute();

            case 'every hour':
                return $lastReset->addHour();

            case 'every day':
                return $lastReset->addDay();

            case 'every week':
                return $lastReset->addWeek();

            case 'every two weeks':
                return $lastReset->addWeeks(2);

            case 'every month':
                return $lastReset->addMonth();

            case 'every quarter':
                return $lastReset->addQuarter();

            case 'every six months':
                return $lastReset->addMonths(6);

            case 'every year':
                return $lastReset->addYear();
        }
    }

    public function loadLimits(): void
    {
        if (! $this->limits->isEmpty()) {
            return;
        }

        $this->limits = $this->cache->remember($this->cacheKey, $this->cacheExpirationTime, function () {
            return $this->limitClass::all([
                'id',
                'name',
                'plan',
                'allowed_amount',
                'reset_frequency',
            ]);
        });
    }

    public function getLimit(array $data)
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $plan = $data['plan'] ?? null;

        if (is_null($id) && is_null($name)) {
            throw new InvalidArgumentException('Either Limit id OR name parameters should be filled.');
        }

        $this->loadLimits();

        if ($id) {
            return $this->limits->where('id', $id)->first();
        }

        if ($plan) {
            return $this
                ->limits
                ->where('name', $name)
                ->where('plan', $plan)
                ->first();
        } else {
            return $this
                ->limits
                ->where('name', $name)
                ->first();
        }
    }

    public function getLimits(): Collection
    {
        $this->loadLimits();

        return $this->limits;
    }

    public function flushCache(): void
    {
        $this->limits = collect();

        $this->cache->forget($this->cacheKey);
    }

    public function getCacheStore()
    {
        return $this->cache->getStore();
    }
}
