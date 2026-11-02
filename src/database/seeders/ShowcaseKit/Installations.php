<?php

namespace Database\Seeders\ShowcaseKit;

use App\Installation\FieldVisits;
use App\Installation\InstallationRemarks;
use App\Models\Appointment;
use App\Models\Installation\InstallationRemark;
use App\Models\Installation\InstallationVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Installation visits walked through the field app (ADR-0036): the booked technician arrives (location shared most of
 * the time), takes before and after photos from the showcase kitchens, records a remark now and then (a missing part,
 * a scratched panel, extra work — each blocks the order's closure until resolved), has the customer sign on the phone
 * and finishes — the finish runs the engine exactly as the screen does. Most remarks are resolved by the branch manager
 * a day or two later; a few stay open so the order page and the closure card show them.
 */
final class Installations
{
    private const REMARKS = [
        ['missing_part', 'ينقص مفصلان للباب العلوي الأيسر، سيُحضران في زيارة قصيرة.'],
        ['damage', 'خدش خفيف في لوح الرخام عند الزاوية، يحتاج استبدال القطعة.'],
        ['extra_work', 'طلب العميل رفًا إضافيًا فوق الثلاجة بعد التركيب.'],
        ['other', 'لون مقبض الدرج يختلف قليلًا عن العينة؛ يُعرض البديل على العميل.'],
    ];

    private const RESOLUTIONS = [
        'missing_part' => 'رُكّبت المفصلات في زيارة متابعة.',
        'damage' => 'استُبدل لوح الرخام دون تكلفة على العميل.',
        'extra_work' => 'سُعّر الرف كإضافة واعتمدها العميل.',
        'other' => 'وافق العميل على المقبض الحالي بعد عرض البديل.',
    ];

    public function __construct(private readonly Showcase $s) {}

    /**
     * Schedules the visit's steps around its start; steps after the showcase's end simply do not happen, so
     * upcoming visits stay «not started» and one running at the end stays «in progress».
     */
    public function schedule(Story $st, int $appointmentId, User $tech, CarbonImmutable $start): void
    {
        $rng = $this->s->rng;
        $arrive = $start->addMinutes($rng->int(-10, 25));
        $finish = $arrive->addMinutes($rng->int(95, 260));
        $remark = $rng->chance(30) ? self::REMARKS[$rng->int(0, count(self::REMARKS) - 1)] : null;
        $located = $rng->chance(85);

        $this->s->timeline->at($arrive, $st, 'field-arrive', function () use ($appointmentId, $tech, $located) {
            $appointment = Appointment::query()->findOrFail($appointmentId);
            app(FieldVisits::class)->arrive($tech, $appointment,
                $located ? 24.6 + $this->s->rng->int(0, 400) / 1000 : null,
                $located ? 46.5 + $this->s->rng->int(0, 400) / 1000 : null,
                $located ? $this->s->rng->int(5, 40) : null);
            $this->photos($tech, $appointmentId, 'before', $this->s->rng->int(1, 2));
        });

        if ($remark !== null) {
            $this->s->timeline->at($arrive->addMinutes(50), $st, 'field-remark', function () use ($appointmentId, $tech, $remark) {
                app(InstallationRemarks::class)->record($tech, $this->visit($appointmentId), $remark[0], $remark[1]);
            });
        }

        $this->s->timeline->at($finish->subMinutes(10), $st, 'field-after', function () use ($appointmentId, $tech) {
            $this->photos($tech, $appointmentId, 'after', $this->s->rng->int(1, 3));
            $signer = (string) Appointment::query()->with('order.customer')->findOrFail($appointmentId)->order->customer->name;
            app(FieldVisits::class)->signOnDevice($tech, $this->visit($appointmentId), [
                'signer_name' => $signer, 'signature' => Signatures::drawn($this->s->rng), 'consent' => true,
            ], '10.30.'.$this->s->rng->int(1, 250).'.'.$this->s->rng->int(1, 250), Signatures::USER_AGENT);
        });

        $this->s->timeline->at($finish, $st, 'field-finish', function () use ($appointmentId, $tech) {
            app(FieldVisits::class)->finish($tech, $this->visit($appointmentId), 'تم التركيب وسُلّم الموقع نظيفًا.');
        });

        if ($remark !== null && $rng->chance(80)) {
            $this->s->timeline->at($finish->addDays($rng->int(1, 3))->setTime(11, 0), $st, 'field-resolve', function () use ($appointmentId, $st) {
                $remark = InstallationRemark::query()->where('installation_visit_id', $this->visit($appointmentId)->id)->where('status', InstallationRemark::OPEN)->first();
                $manager = $this->s->users[['RUH' => 'gm', 'JED' => 'mgr_jed', 'DMM' => 'mgr_dmm'][$st->data['branch']]];
                if ($remark !== null) {
                    app(InstallationRemarks::class)->resolve($manager, $remark, self::RESOLUTIONS[$remark->kind] ?? 'عولجت مع العميل.');
                }
            });
        }
    }

    private function photos(User $tech, int $appointmentId, string $phase, int $count): void
    {
        $pool = array_merge(...array_values(Assets::KITCHENS));
        for ($i = 0; $i < $count; $i++) {
            $file = $this->s->assets->image($this->s->rng->pick($pool)) ?? self::plainPhoto();
            app(FieldVisits::class)->addPhoto($tech, $this->visit($appointmentId), $phase, $file, (string) Str::uuid());
        }
    }

    /** A small generated JPEG when the showcase images are off (tests): the photos rule still holds. */
    private static function plainPhoto(): UploadedFile
    {
        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 190, 170));
        $path = (string) tempnam(sys_get_temp_dir(), 'ops');
        imagejpeg($image, $path, 70);

        return new UploadedFile($path, 'site.jpg', 'image/jpeg', null, true);
    }

    private function visit(int $appointmentId): InstallationVisit
    {
        return InstallationVisit::query()->where('appointment_id', $appointmentId)->firstOrFail();
    }
}
