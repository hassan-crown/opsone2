<?php

namespace App\Ai\Help;

use App\Ai\Redaction\Redactor;
use App\Models\Ai\HelpUnansweredQuestion;

/**
 * The content team's list of questions the guide could not answer (plan §2, axis 7). Never stores who asked: the text
 * goes through the Redactor first (phones, e-mails, IBANs, ids), is cut short, and the same question is counted once.
 */
final class UnansweredQuestions
{
    public const MAX_LENGTH = 300;

    public function record(string $question, string $reason): void
    {
        $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (new Redactor)->redact($question))), 0, self::MAX_LENGTH);
        if ($text === '') {
            return;
        }

        $hash = sha1(HelpRetriever::normalize($text));
        $row = HelpUnansweredQuestion::query()->firstOrCreate(
            ['question_hash' => $hash],
            ['question' => $text, 'reason' => $reason, 'times' => 0, 'last_asked_at' => now()],
        );
        $row->forceFill(['reason' => $reason, 'last_asked_at' => now()])->increment('times');
    }
}
