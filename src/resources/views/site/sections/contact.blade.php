@php($sent = session('lead_reference'))
<section id="contact" class="bg-fill" aria-labelledby="{{ $sectionId }}-h">
    <div class="mx-auto grid max-w-content gap-8 px-4 py-12 md:grid-cols-2 md:px-8">
        <div>
            <h2 id="{{ $sectionId }}-h" class="text-title">{{ $data['heading'] }}</h2>
            @if ($data['text'] !== '')<p class="mt-2 text-body text-weak">{{ $data['text'] }}</p>@endif
            <dl class="mt-6 space-y-2 text-body">
                @if ($contact['phone'])<div><dt class="sr-only">{{ __('site.public.lead.mobile') }}</dt><dd dir="ltr" class="text-start">{{ $contact['phone'] }}</dd></div>@endif
                @if ($contact['email'])<div><dt class="sr-only">{{ __('site.public.lead.email') }}</dt><dd>{{ $contact['email'] }}</dd></div>@endif
                @if ($contact['address'])<div><dt class="sr-only">{{ __('site.public.address') }}</dt><dd>{{ $contact['address'] }}</dd></div>@endif
            </dl>
        </div>
        @if ($data['show_form'] && ! $preview)
            <div>
                @if ($sent)
                    <div role="status" class="rounded-lg border border-stroke bg-success-bg p-6 text-success-text">
                        <p class="text-subtitle font-semibold">{{ __('site.public.lead.sent_title') }}</p>
                        <p class="mt-1">{{ __('site.public.lead.sent', ['ref' => $sent]) }}</p>
                    </div>
                @else
                    <form method="post" action="{{ $leadAction }}" class="space-y-4 rounded-lg border border-stroke-weak bg-surface p-6" novalidate>
                        @csrf
                        <input type="hidden" name="submission" value="{{ $submissionKey }}">
                        <input type="hidden" name="form_token" value="{{ $formToken }}">
                        <div class="absolute -start-[9999px]" aria-hidden="true">
                            <label>{{ __('site.public.lead.website') }}<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                        </div>
                        @if ($errors->has('form'))<p role="alert" class="text-danger-text">{{ $errors->first('form') }}</p>@endif
                        @foreach ([['name', 'text', 'name', true], ['mobile', 'tel', 'tel', false], ['email', 'email', 'email', false]] as [$field, $type, $auto, $req])
                            <div>
                                <label for="lead-{{ $field }}" class="block text-label font-medium">{{ __('site.public.lead.'.$field) }}@if ($req) <span aria-hidden="true">*</span>@endif</label>
                                <input id="lead-{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $auto }}" value="{{ old($field) }}" @if ($req) required @endif
                                    @if ($errors->has($field)) aria-invalid="true" aria-describedby="lead-{{ $field }}-e" @endif
                                    @if ($type !== 'text') dir="ltr" @endif
                                    class="field-control mt-1 w-full text-start">
                                @if ($errors->has($field))<p id="lead-{{ $field }}-e" class="mt-1 text-caption text-danger-text">{{ $errors->first($field) }}</p>@endif
                            </div>
                        @endforeach
                        <p class="text-caption text-weak">{{ __('site.public.lead.reach_hint') }}</p>
                        <div>
                            <label for="lead-need" class="block text-label font-medium">{{ __('site.public.lead.need') }} <span aria-hidden="true">*</span></label>
                            <textarea id="lead-need" name="need" rows="4" required @if ($errors->has('need')) aria-invalid="true" aria-describedby="lead-need-e" @endif class="field-control mt-1 w-full py-2">{{ old('need') }}</textarea>
                            @if ($errors->has('need'))<p id="lead-need-e" class="mt-1 text-caption text-danger-text">{{ $errors->first('need') }}</p>@endif
                        </div>
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-brand px-6 text-control font-medium text-on-brand hover:bg-brand-hover active:bg-brand-active">{{ __('site.public.lead.submit') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</section>
