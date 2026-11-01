<?php

namespace Database\Seeders\ShowcaseKit;

use App\Agreements\Signing\DocumentPdfs;
use App\Agreements\Signing\ESignature;
use App\Agreements\Signing\SignLinks;
use App\Jobs\DeliverOutboxMessage;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DocumentSignLink;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Portal\CustomerOtp;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Electronic signatures of the showcase (ADR-0029), through the real path: staff send a signing link, the customer
 * asks for the step-up code (read back from the outbox, as the customer would read their SMS/email), and signs the
 * exact PDF with a drawn signature. Pending documents keep a live link.
 */
final class Signatures
{
    private const USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    public function sendLink(User $staff, AgreementDocument $document): DocumentSignLink
    {
        return app(SignLinks::class)->send($staff, $document->refresh())['link'];
    }

    public function sign(AgreementDocument $document, string $signer): void
    {
        $document = $document->refresh();
        $customer = $document->order->customer;
        $hash = CustomerOtp::identifierHash($customer->mobile);
        // The frozen clock replays months in seconds: the per-window caps are not what the showcase demonstrates.
        foreach (["portal-otp:cooldown:{$hash}:".ESignature::subject($document), "portal-otp:send:{$hash}", 'portal-otp:send-ip:none'] as $key) {
            RateLimiter::clear($key);
        }

        $esign = app(ESignature::class);
        $pdf = app(DocumentPdfs::class)->current($document, 'ar');
        // The outbox wipes a message's text once delivered; hold delivery while the code is read, then deliver it.
        $queue = Queue::getFacadeRoot();
        Queue::fake();
        try {
            if ($esign->requestCode($customer, $document, null) === null) {
                throw new RuntimeException('The customer has no channel for a step-up code.');
            }
            $message = OutboxMessage::query()->where('idempotency_key', 'like', 'portal-otp:%')->latest('id')->firstOrFail();
        } finally {
            Queue::swap($queue);
        }
        $text = (string) ($message->payload['text'] ?? implode(' ', $message->payload['lines'] ?? []));
        if (preg_match('/\b(\d{6})\b/', $text, $m) !== 1) {
            throw new RuntimeException('No step-up code in the outbox.');
        }
        DeliverOutboxMessage::dispatch($message->id);

        // Its own seeded numbers: the shared generator's sequence (and so the rest of the dataset) stays as it was.
        $rng = new Rng((int) crc32($document->number));
        $esign->sign($customer, $document, [
            'signer_name' => $signer,
            'signature' => self::drawn($rng),
            'consent' => true,
            'code' => $m[1],
            'content_hash' => (string) $document->content_hash,
            'pdf_sha256' => $pdf->sha256,
            'locale' => 'ar',
        ], '10.20.'.$rng->int(1, 250).'.'.$rng->int(1, 250), self::USER_AGENT, DocumentSignLink::query()
            ->where('agreement_document_id', $document->id)->whereNull('used_at')->whereNull('revoked_at')->latest('id')->first());
    }

    /**
     * A handwritten-looking signature: a few smooth strokes drawn from the showcase's seeded random numbers.
     */
    private static function drawn(Rng $rng): string
    {
        $w = 520;
        $h = 170;
        $image = imagecreatetruecolor($w, $h);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 255, 255, 127));
        $ink = (int) imagecolorallocate($image, 20, 32, 60);
        imagesetthickness($image, 3);
        $x = 30;
        $y = 110;
        for ($stroke = 0; $stroke < 3; $stroke++) {
            $amp = $rng->int(18, 40);
            $freq = $rng->int(6, 12) / 100;
            $len = $rng->int(110, 160);
            $prev = [$x, $y];
            for ($i = 1; $i <= $len; $i += 2) {
                $px = $x + $i;
                $py = (int) ($y - $amp * sin($i * $freq) - ($i / $len) * 25);
                imageline($image, $prev[0], $prev[1], $px, $py, $ink);
                $prev = [$px, $py];
            }
            $x += $len + $rng->int(5, 20);
            $y = $rng->int(95, 120);
        }
        imageline($image, 40, 140, $w - 60, 128, $ink);
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
