<x-mail::message>
<div dir="{{ $dir }}" style="text-align: {{ $dir === 'rtl' ? 'right' : 'left' }}">
@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($action)
<x-mail::button :url="$action['url']">
{{ $action['label'] }}
</x-mail::button>
@endif
</div>
</x-mail::message>
