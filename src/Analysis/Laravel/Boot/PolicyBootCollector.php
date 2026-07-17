<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Application;

final class PolicyBootCollector implements BootCollector
{
    public function name(): string
    {
        return 'policies';
    }

    public function collect(Application $application): BootCollectionResult
    {
        $gate = $application->make(GateContract::class);

        if (! $gate instanceof Gate) {
            return new BootCollectionResult();
        }

        $policies = $gate->policies();
        $facts = [];
        ksort($policies, SORT_STRING);

        foreach ($policies as $model => $policy) {
            if (! is_string($model) || ! is_string($policy)) {
                continue;
            }

            $facts[] = new BootFact('policy', $this->name(), [
                'model' => ltrim($model, '\\'),
                'policy' => ltrim($policy, '\\'),
            ]);
        }

        return new BootCollectionResult($facts);
    }
}
