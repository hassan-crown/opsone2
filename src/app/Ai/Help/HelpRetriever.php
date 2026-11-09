<?php

namespace App\Ai\Help;

use App\Help\HelpReader;
use App\Models\HelpArticle;
use Illuminate\Support\Collection;

/**
 * Finds the articles that may answer a question. It only ever looks inside `HelpReader::query()` (audience,
 * permissions, license; ADR-0048), so an article the user cannot read is never a candidate, and therefore never
 * reaches the model or the answer. Articles locked by the plan are skipped too: the assistant does not explain a
 * feature the facility does not have. Plain keyword scoring (title > summary > body): the knowledge base is dozens of
 * articles, no vector store is needed (plan §2, axis 7).
 */
final class HelpRetriever
{
    public const LIMIT = 4;

    private const STOPWORDS = ['كيف', 'ماذا', 'هل', 'ما', 'من', 'في', 'على', 'الى', 'عن', 'هذا', 'هذه', 'هو', 'هي', 'لي', 'اريد', 'ابي', 'يمكن', 'اقدر', 'how', 'the', 'can', 'what', 'and', 'for', 'to', 'do', 'i'];

    /**
     * @return Collection<int, HelpArticle> best match first, at most LIMIT
     */
    public function find(HelpReader $reader, string $question): Collection
    {
        $tokens = self::tokens($question);
        if ($tokens === []) {
            return collect();
        }

        return $reader->query()->orderBy('sort')->orderBy('id')->get()
            ->reject(fn (HelpArticle $a) => $reader->isLocked($a))
            ->map(fn (HelpArticle $a) => ['article' => $a, 'score' => $this->score($a, $tokens)])
            ->filter(fn (array $row) => $row['score'] >= 2)
            ->sortByDesc('score')
            ->take(self::LIMIT)
            ->map(fn (array $row) => $row['article'])
            ->values();
    }

    /**
     * @param  list<string>  $tokens
     */
    private function score(HelpArticle $article, array $tokens): int
    {
        $title = self::normalize($article->title());
        $summary = self::normalize((string) $article->summary());
        $body = self::normalize($article->body());

        $score = 0;
        foreach ($tokens as $token) {
            $score += (str_contains($title, $token) ? 3 : 0) + (str_contains($summary, $token) ? 2 : 0) + (str_contains($body, $token) ? 1 : 0);
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($words as $word) {
            $word = (string) preg_replace('/^(وال|بال|لل|ال)/u', '', $word);
            if (mb_strlen($word) >= 2 && ! in_array($word, self::STOPWORDS, true)) {
                $tokens[] = $word;
            }
        }

        return array_values(array_unique($tokens));
    }

    /** Lower case; no diacritics or tatweel; alef, ya and ta marbuta folded; Eastern digits to ASCII. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $text);

        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }
}
