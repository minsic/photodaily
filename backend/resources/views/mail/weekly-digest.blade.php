<x-mail::message>
# {{ $name ? "La settimana di {$name}" : 'La settimana del diario' }}

**{{ $digest['pieni'] }} foto su {{ $digest['totali'] }} {{ $digest['totali'] === 1 ? 'giorno' : 'giorni' }}**@if ($digest['cuori'] > 0) · {{ $digest['cuori'] }} {{ $digest['cuori'] === 1 ? 'cuore' : 'cuori' }}@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 16px 0;">
@foreach (array_chunk($days, 4) as $row)
<tr>
@foreach ($row as $day)
<td width="25%" style="padding: 4px; vertical-align: top; text-align: center;">
<a href="{{ $day['url'] }}"><img src="{{ $day['thumbnail_url'] }}" alt="Foto di {{ $day['label'] }}" width="120" style="width: 100%; max-width: 120px; height: auto; border-radius: 8px; display: block; margin: 0 auto;"></a>
<span style="font-size: 12px; color: #5b6b78;">{{ $day['label'] }}@if ($day['speciale']) ★@endif</span>
</td>
@endforeach
@for ($i = count($row); $i < 4; $i++)
<td width="25%"></td>
@endfor
</tr>
@endforeach
</table>

@if (count($missing) > 0)
Senza foto: {{ implode(', ', $missing) }}. Si possono sempre recuperare dalla galleria.
@endif

<x-mail::button :url="$monthUrl">
Apri il diario
</x-mail::button>

<x-slot:subcopy>
Ricevi questo riepilogo ogni domenica sera. Per non riceverlo più spegnilo dal tuo profilo: [{{ $profileUrl }}]({{ $profileUrl }})
</x-slot:subcopy>
</x-mail::message>
