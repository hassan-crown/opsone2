{{-- ADR-0039: «request an appointment». The visitor only asks; staff call back to fix the time. Fields follow the settings. --}}
@php($sent = session('appointment_reference'))
@php($form = $appointmentForm)
@php($req = fn (string $field) => $form->mode($field) === 'required')
@php($shown = fn (string $field) => $form->mode($field) !== 'off')
<section id="appointment" class="bg-surface" aria-labelledby="{{ $sectionId }}-h">
    <div class="mx-auto grid max-w-content gap-8 px-4 py-12 md:grid-cols-2 md:px-8">
        <div>
            <h2 id="{{ $sectionId }}-h" class="text-title">{{ $data['heading'] }}</h2>
            @if ($data['text'] !== '')<p class="mt-2 text-body text-weak">{{ $data['text'] }}</p>@endif
            <p class="mt-4 text-body text-weak">{{ __('appointment_requests.public.how') }}</p>
        </div>
        @if (! $preview)
            <div>
                @if ($sent)
                    <div role="status" class="rounded-lg border border-stroke bg-success-bg p-6 text-success-text" data-test="appointment-sent">
                        <p class="text-subtitle font-semibold">{{ __('appointment_requests.public.sent_title') }}</p>
                        <p class="mt-1">{{ __('appointment_requests.public.sent', ['ref' => $sent]) }}</p>
                    </div>
                @else
                    <form method="post" action="{{ $appointmentAction }}" class="space-y-4 rounded-lg border border-stroke-weak bg-fill p-6" novalidate data-test="appointment-form">
                        @csrf
                        <input type="hidden" name="submission" value="{{ $submissionKey }}">
                        <input type="hidden" name="form_token" value="{{ $formToken }}">
                        <div class="absolute -start-[9999px]" aria-hidden="true">
                            <label>{{ __('site.public.lead.website') }}<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                        </div>
                        @if ($errors->has('form'))<p role="alert" class="text-danger-text">{{ $errors->first('form') }}</p>@endif

                        @foreach ([['name', 'text', 'name'], ['phone', 'tel', 'tel']] as [$field, $type, $auto])
                            <div>
                                <label for="appt-{{ $field }}" class="block text-label font-medium">{{ __('appointment_requests.field.'.$field) }} <span aria-hidden="true">*</span></label>
                                <input id="appt-{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $auto }}" value="{{ old($field) }}" required
                                    @if ($errors->has($field)) aria-invalid="true" aria-describedby="appt-{{ $field }}-e" @endif
                                    @if ($type !== 'text') dir="ltr" @endif
                                    class="field-control mt-1 w-full text-start">
                                @if ($errors->has($field))<p id="appt-{{ $field }}-e" class="mt-1 text-caption text-danger-text">{{ $errors->first($field) }}</p>@endif
                            </div>
                        @endforeach

                        <div>
                            <label for="appt-kind" class="block text-label font-medium">{{ __('appointment_requests.field.kind') }} <span aria-hidden="true">*</span></label>
                            <select id="appt-kind" name="kind" required class="field-control mt-1 w-full" @if ($errors->has('kind')) aria-invalid="true" aria-describedby="appt-kind-e" @endif>
                                @foreach ($form->kinds as $kind)
                                    <option value="{{ $kind }}" @selected(old('kind') === $kind)>{{ __('appointments.kind.'.$kind) }}</option>
                                @endforeach
                            </select>
                            @if ($errors->has('kind'))<p id="appt-kind-e" class="mt-1 text-caption text-danger-text">{{ $errors->first('kind') }}</p>@endif
                        </div>

                        @if ($shown('preferred_date'))
                            <fieldset class="grid gap-4 sm:grid-cols-2">
                                <legend class="mb-1 text-label font-medium">{{ __('appointment_requests.field.preferred_date') }}@if ($req('preferred_date')) <span aria-hidden="true">*</span>@endif</legend>
                                @foreach (['preferred_from', 'preferred_to'] as $field)
                                    <div>
                                        <label for="appt-{{ $field }}" class="block text-caption text-weak">{{ __('appointment_requests.field.'.$field) }}</label>
                                        <input id="appt-{{ $field }}" name="{{ $field }}" type="date" dir="ltr" value="{{ old($field) }}" min="{{ now()->format('Y-m-d') }}"
                                            @if ($field === 'preferred_from' && $req('preferred_date')) required @endif
                                            @if ($errors->has($field)) aria-invalid="true" aria-describedby="appt-{{ $field }}-e" @endif
                                            class="field-control mt-1 w-full text-start">
                                        @if ($errors->has($field))<p id="appt-{{ $field }}-e" class="mt-1 text-caption text-danger-text">{{ $errors->first($field) }}</p>@endif
                                    </div>
                                @endforeach
                            </fieldset>
                        @endif

                        @if ($shown('time_of_day'))
                            <div>
                                <label for="appt-time_of_day" class="block text-label font-medium">{{ __('appointment_requests.field.time_of_day') }}@if ($req('time_of_day')) <span aria-hidden="true">*</span>@endif</label>
                                <select id="appt-time_of_day" name="time_of_day" class="field-control mt-1 w-full" @if ($req('time_of_day')) required @endif>
                                    @unless ($req('time_of_day'))<option value="">—</option>@endunless
                                    @foreach (\App\Models\AppointmentRequest::TIMES_OF_DAY as $time)
                                        <option value="{{ $time }}" @selected(old('time_of_day') === $time)>{{ __('appointment_requests.time.'.$time) }}</option>
                                    @endforeach
                                </select>
                                @if ($errors->has('time_of_day'))<p class="mt-1 text-caption text-danger-text">{{ $errors->first('time_of_day') }}</p>@endif
                            </div>
                        @endif

                        @foreach (['city' => false, 'address' => true, 'note' => true] as $field => $multiline)
                            @if ($shown($field))
                                <div>
                                    <label for="appt-{{ $field }}" class="block text-label font-medium">{{ __('appointment_requests.field.'.$field) }}@if ($req($field)) <span aria-hidden="true">*</span>@endif</label>
                                    @if ($multiline)
                                        <textarea id="appt-{{ $field }}" name="{{ $field }}" rows="{{ $field === 'note' ? 3 : 2 }}" @if ($req($field)) required @endif
                                            @if ($errors->has($field)) aria-invalid="true" aria-describedby="appt-{{ $field }}-e" @endif
                                            class="field-control mt-1 w-full py-2">{{ old($field) }}</textarea>
                                    @else
                                        <input id="appt-{{ $field }}" name="{{ $field }}" type="text" autocomplete="address-level2" value="{{ old($field) }}" @if ($req($field)) required @endif
                                            @if ($errors->has($field)) aria-invalid="true" aria-describedby="appt-{{ $field }}-e" @endif
                                            class="field-control mt-1 w-full text-start">
                                    @endif
                                    @if ($errors->has($field))<p id="appt-{{ $field }}-e" class="mt-1 text-caption text-danger-text">{{ $errors->first($field) }}</p>@endif
                                </div>
                            @endif
                        @endforeach

                        <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-brand px-6 text-control font-medium text-on-brand hover:bg-brand-hover active:bg-brand-active">{{ __('appointment_requests.public.submit') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</section>
