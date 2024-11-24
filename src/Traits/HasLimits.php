<?php

namespace NabilHassen\LaravelUsageLimiter\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use NabilHassen\LaravelUsageLimiter\Contracts\Limit as LimitContract;
use NabilHassen\LaravelUsageLimiter\Exceptions\LimitNotSetOnModel;
use NabilHassen\LaravelUsageLimiter\Exceptions\UsedAmountShouldBePositiveIntAndLessThanAllowedAmount;
use NabilHassen\LaravelUsageLimiter\LimitManager;

trait HasLimits
{
    /**
     * @return \Illuminate\Database\Eloquent\Relations\morphToMany
     */
    public function limits()
    {
        return $this->morphToMany(
                config('limit.models.limit'),
                'model',
                config('limit.tables.model_has_limits'),
                'model_id',
                config('limit.columns.limit_pivot_key'),
            )
            ->withPivot(['used_amount', 'extra_amount', 'extra_used_amount', 'last_reset', 'next_reset'])
            ->withTimestamps();
    }

    public function setLimit(/*string|LimitContract*/ $name, ?string $plan = null, /*float|int*/ $usedAmount = 0.0): bool
    {
        $limit = app(LimitContract::class)::findByName($name, $plan);

        if ($this->isLimitSet($limit)) {
            return true;
        }

        if ($usedAmount > $limit->allowed_amount) {
            throw new InvalidArgumentException('"used_amount" should always be less than or equal to the limit "allowed_amount"');
        }

        DB::transaction(function () use ($limit, $usedAmount) {
            $this->limitsRelationship()->attach([
                $limit->id => [
                    'used_amount' => $usedAmount,
                    'extra_amount' => 0,
                    'extra_used_amount' => 0,
                    'last_reset' => now(),
                ],
            ]);

            if ($limit->reset_frequency) {
                $this->limitsRelationship()->updateExistingPivot($limit->id, [
                    'next_reset' => app(LimitManager::class)->getNextReset($limit->reset_frequency, now()),
                ]);
            }
        });

        $this->unloadLimitsRelationship();

        return true;
    }

    public function isLimitSet(/*string|LimitContract*/ $name, ?string $plan = null): bool
    {
        $limit = app(LimitContract::class)::findByName($name, $plan);

        return ! $this->getModelLimits()->where('name', $limit->name)->isEmpty();
    }

    public function unsetLimit(/*string|LimitContract*/ $name, ?string $plan = null): bool
    {
        $limit = $this->getModelLimit($name, $plan);

        $this->limitsRelationship()->detach($limit->id);

        $this->unloadLimitsRelationship();

        return true;
    }

    public function useLimit(/*string|LimitContract*/ $name, ?string $plan = null, /*float|int*/ $amount = 1.0): bool
    {
        $limit = $this->getModelLimit($name, $plan);

        if ($limit->pivot->extra_amount > $limit->pivot->extra_used_amount) {
            // Use extra amount first
            $newUsedAmount = $limit->pivot->used_amount;
            $newExtraUsedAmount = $limit->pivot->extra_used_amount + $amount;

            if ($newExtraUsedAmount <= 0 || $limit->pivot->extra_amount < $newExtraUsedAmount) {
                throw new UsedAmountShouldBePositiveIntAndLessThanAllowedAmount;
            }
        } else {
            $newUsedAmount = $limit->pivot->used_amount + $amount;
            $newExtraUsedAmount = $limit->pivot->extra_used_amount;

            if ($newUsedAmount <= 0 || ! $this->ensureUsedAmountIsLessThanAllowedAmount($name, $plan, $newUsedAmount)) {
                throw new UsedAmountShouldBePositiveIntAndLessThanAllowedAmount;
            }
        }

        $this->limitsRelationship()->updateExistingPivot($limit->id, [
            'used_amount' => $newUsedAmount,
            'extra_used_amount' => $newExtraUsedAmount,
        ]);

        $this->unloadLimitsRelationship();

        return true;
    }

    public function unuseLimit(/*string|LimitContract*/ $name, ?string $plan = null, /*float|int*/ $amount = 1.0): bool
    {
        $limit = $this->getModelLimit($name, $plan);

        if ($limit->pivot->used_admount > 0) {
            $newUsedAmount = $limit->pivot->used_amount - $amount;
            $newExtraUsedAmount = $limit->pivot->extra_used_amount;

            if ($newUsedAmount <= 0 || ! $this->ensureUsedAmountIsLessThanAllowedAmount($name, $plan, $newUsedAmount)) {
                throw new UsedAmountShouldBePositiveIntAndLessThanAllowedAmount;
            }
        } else  {
            $newUsedAmount = $limit->pivot->used_amount;
            $newExtraUsedAmount = $limit->pivot->extra_used_amount - $amount;

            if ($newExtraUsedAmount <= 0 || $newExtraUsedAmount > $limit->pivot->extra_amount) {
                throw new UsedAmountShouldBePositiveIntAndLessThanAllowedAmount;
            }
        }

        $this->limitsRelationship()->updateExistingPivot($limit->id, [
            'used_amount' => $newUsedAmount,
            'extra_used_amount' => $newExtraUsedAmount,
        ]);

        $this->unloadLimitsRelationship();

        return true;
    }

    public function resetLimit(/*string|LimitContract*/ $name, ?string $plan = null): bool
    {
        $limit = $this->getModelLimit($name, $plan);

        $this->limitsRelationship()->updateExistingPivot($limit->id, [
            'used_amount' => 0,
        ]);

        $this->unloadLimitsRelationship();

        return true;
    }

    public function increaseExtraLimit(/*string|LimitContract*/ $name, ?string $plan = null, /*float|int*/ $amount): bool
    {
        $limit = $this->getModelLimit($name, $plan);

        $newExtraAmount = $limit->pivot->extra_amount + $amount;

        $this->limitsRelationship()->updateExistingPivot($limit->id, [
            'extra_amount' => $newExtraAmount,
        ]);

        $this->unloadLimitsRelationship();

        return true;
    }

    public function clearExtraLimit(/*string|LimitContract*/ $name, ?string $plan = null): bool
    {
        $limit = $this->getModelLimit($name, $plan);

        $this->limitsRelationship()->updateExistingPivot($limit->id, [
            'extra_amount' => 0,
            'extra_used_amount' => 0,
        ]);

        $this->unloadLimitsRelationship();

        return true;
    }

    public function hasEnoughLimit(/*string|LimitContract*/ $name, ?string $plan = null): bool
    {
        $limit = $this->getModelLimit($name, $plan);

        $allowedAmount = $limit->allowed_amount + $limit->pivot->extra_amount;
        $usedAmount = $limit->pivot->used_amount + $limit->pivot->extra_used_amount;

        return $allowedAmount > $usedAmount;
    }

    public function ensureUsedAmountIsLessThanAllowedAmount(/*string|LimitContract*/ $name, ?string $plan, /*float|int*/ $usedAmount): bool
    {
        $limit = $this->getModelLimit($name, $plan);

        return $usedAmount <= $limit->allowed_amount;
    }

    public function allowedLimit(/*string|LimitContract*/ $name, ?string $plan = null): float
    {
        $limit = $this->getModelLimit($name, $plan);

        return $limit->allowed_amount + $limit->pivot->extra_amount;
    }

    public function usedLimit(/*string|LimitContract*/ $name, ?string $plan = null): float
    {
        $limit = $this->getModelLimit($name, $plan);

        return $limit->pivot->used_amount + $limit->pivot->extra_used_amount;
    }

    public function remainingLimit(/*string|LimitContract*/ $name, ?string $plan = null): float
    {
        $limit = $this->getModelLimit($name, $plan);

        $allowedAmount = $limit->allowed_amount + $limit->pivot->extra_amount;
        $usedAmount = $limit->pivot->used_amount + $limit->pivot->extra_used_amount;

        return $allowedAmount - $usedAmount;
    }

    public function getModelLimit(/*string|LimitContract*/ $name, ?string $plan = null): LimitContract
    {
        $limit = app(LimitContract::class)::findByName($name, $plan);

        $modelLimit = $this->getModelLimits()->where('id', $limit->id)->first();

        if (! $modelLimit) {
            throw new LimitNotSetOnModel($name);
        }

        return $modelLimit;
    }

    public function getModelLimits(): Collection
    {
        $relationshipName = static::getLimitsRelationship();

        if (! $this->relationLoaded($relationshipName)) {
            $this->load($relationshipName);
        }

        return $this->$relationshipName;
    }

    public function limitsRelationship(): MorphToMany
    {
        $relationshipName = static::getLimitsRelationship();

        return $this->$relationshipName();
    }

    public function unloadLimitsRelationship(): void
    {
        $relationshipName = static::getLimitsRelationship();

        $this->unsetRelation($relationshipName);
    }

    private static function getLimitsRelationship(): string
    {
        return config('limit.relationship');
    }

    public function limitUsageReport(/*string|LimitContract|null*/ $name = null, ?string $plan = null): array
    {
        $modelLimits = ! is_null($name) ? collect([$this->getModelLimit($name, $plan)]) : $this->getModelLimits();

        return
        $modelLimits
            ->mapWithKeys(function (LimitContract $modelLimit) {
                $allowedAmount = $modelLimit->allowed_amount + $modelLimit->pivot->extra_amount;
                $usedAmount = $modelLimit->pivot->used_amount + $modelLimit->pivot->extra_used_amount;

                return [
                    $modelLimit->name => [
                        'allowed_amount' => $allowedAmount,
                        'used_amount' => $usedAmount,
                        'remaining_amount' => $allowedAmount - $usedAmount,
                    ],
                ];
            })->all();
    }
}
