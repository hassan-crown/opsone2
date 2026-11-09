<?php

namespace App\Ai\Help;

use App\Ai\Redaction\Redactor;
use App\Models\Ai\HelpUnansweredQuestion;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The content team's list of questions the guide could not answer (plan §2, axis 7). Never stores who asked: the text
 * goes through the Redactor first (phones, e-mails, IBANs, ids), is cut short, and the same question is counted once.
 */
final class UnansweredQuestions
{
    public const MAX_LENGTH = 300;

    public function record(string $question, string $reason): void
    {
        $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (new Redactor(KnownNames::in($question)))->redact($question))), 0, self::MAX_LENGTH);
        if ($text === '') {
            return;
        }

        $hash = sha1(HelpRetriever::normalize($text));
        try {
            $row = HelpUnansweredQuestion::query()->firstOrCreate(
                ['question_hash' => $hash],
                ['question' => $text, 'reason' => $reason, 'times' => 0, 'last_asked_at' => now()],
            );
        } catch (UniqueConstraintViolationException) {
            // Two first asks of the same question at once: the other request created the row.
            $row = HelpUnansweredQuestion::query()->where('question_hash', $hash)->firstOrFail();
        }

        $row->increment('times', 1, ['reason' => $reason, 'last_asked_at' => now()]);
    }
}
