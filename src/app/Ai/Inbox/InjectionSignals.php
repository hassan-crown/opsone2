<?php

namespace App\Ai\Inbox;

use App\Ai\Redaction\Redactor;

/**
 * A local, model-free first line for the suspicion flag (plan §3.3 point 5): phrases that try to steer an assistant
 * («تجاهل التعليمات», «ignore previous instructions», «system prompt», role-play, tag injection). It works even when
 * the classifier is off or fails, and its answer never depends on what the model says. A false positive only shows a
 * warning to staff and keeps automatic replies off for that conversation.
 */
final class InjectionSignals
{
    private const PATTERNS = [
        // English
        '/\b(ignore|disregard|forget|override)\b.{0,40}\b(previous|prior|above|earlier|all|your|the)\b.{0,30}\b(instructions?|rules?|prompts?|guidelines?|messages?)\b/iu',
        '/\b(system|developer|hidden)\s*(prompt|message|instructions?)\b/iu',
        '/\byou\s+are\s+now\b|\bact\s+as\b|\bpretend\s+(to\s+be|you)\b|\bjailbreak\b|\bDAN\b/iu',
        '/\b(new|updated)\s+instructions?\s*:/iu',
        '/\bas\s+an?\s+(ai|assistant|language\s+model)\b/iu',
        // Arabic
        '/(تجاهل|انس|إنس|اترك|تخط[ىي])\s*.{0,25}(التعليمات|الأوامر|الاوامر|القواعد|التوجيهات|ما\s+سبق)/u',
        '/(التعليمات|الأوامر|الاوامر)\s+(السابقة|الأصلية|الاصلية|الجديدة)/u',
        '/(أنت|انت)\s+(الآن|الان)\s/u',
        '/(تصرف|تظاهر)\s+(ك|بأنك|انك|أنك)/u',
        '/(موجه|برومبت|تعليمات)\s+(النظام|المطور)/u',
        // Markup that imitates the prompt structure
        '~</?\s*(system|instruction|facts|untrusted_data|item|assistant|user)\s*>~iu',
    ];

    public static function suspicious(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $text = Redactor::foldDigits($text);

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
