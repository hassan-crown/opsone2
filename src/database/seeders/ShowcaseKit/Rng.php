<?php

namespace Database\Seeders\ShowcaseKit;

/**
 * Seeded generator so the dataset is identical on every run (mt_rand is deterministic for a given seed).
 */
final class Rng
{
    public function __construct(int $seed)
    {
        mt_srand($seed);
    }

    public function int(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    public function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  list<T>  $list
     * @return T
     */
    public function pick(array $list): mixed
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    /**
     * @param  array<int|string, int>  $weights
     */
    public function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_first($weights);
    }

    /**
     * @template T
     *
     * @param  list<T>  $list
     * @return list<T>
     */
    public function shuffle(array $list): array
    {
        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }

        return array_values($list);
    }
}
