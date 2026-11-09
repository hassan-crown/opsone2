<?php

namespace App\Ai\Help;

use App\Models\Customer;
use App\Models\User;

/**
 * Names of the facility's own customers and staff that a question mentions, for the Redactor's `known` list: patterns
 * cannot recognise a person's name. Only names that share a word with the question are looked up (never the whole
 * customer list), and each part of a matched name is hidden too when it appears in the question.
 */
final class KnownNames
{
    private const MAX_WORDS = 12;

    private const MAX_MATCHES = 30;

    /**
     * @return array<string, list<string>> `['NAME' => [...]]`, or `[]` when the question mentions nobody
     */
    public static function in(string $question): array
    {
        $words = array_slice(array_values(array_unique(preg_split('/[^\p{L}\p{N}]+/u', $question, -1, PREG_SPLIT_NO_EMPTY) ?: [])), 0, self::MAX_WORDS);

        // A one-letter prefix is glued to the name in Arabic («لمحمد», «بالعتيبي»): search the word and its stem.
        $needles = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                $needles[] = $word;
                if (mb_strlen($word) >= 4) {
                    $needles[] = mb_substr($word, 1);
                }
            }
        }
        $needles = array_values(array_unique($needles));
        if ($needles === []) {
            return [];
        }

        $names = [];
        foreach ([Customer::class, User::class] as $model) {
            $names = [...$names, ...$model::query()->where(function ($q) use ($needles) {
                foreach ($needles as $needle) {
                    $q->orWhere('name', 'like', '%'.addcslashes($needle, '%_\\').'%');
                }
            })->limit(self::MAX_MATCHES)->pluck('name')->all()];
        }

        $known = [];
        foreach (array_unique($names) as $name) {
            $known[] = (string) $name;
            foreach (preg_split('/\s+/u', (string) $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (mb_strlen($part) >= 3 && mb_stripos($question, $part) !== false) {
                    $known[] = $part;
                }
            }
        }

        return $known === [] ? [] : ['NAME' => array_values(array_unique($known))];
    }
}
