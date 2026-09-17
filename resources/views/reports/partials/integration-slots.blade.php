<section class="block">
    <h2>INTEGRASI (Draf S1–S7) <span class="jp">統合ドラフト</span></h2>
    <p class="meta">Hanya untuk psikolog; diringkas menjadi Uraian sebelum tanda tangan.</p>
    <ol class="slots">
        @foreach ($integration_slots as $slot)
            <li value="{{ (int) substr($slot['code'], 1) }}">
                <strong>{{ $slot['title_id'] }}</strong>
                <span class="jp">{{ $slot['title_jp'] }}</span>
                <p>{{ $slot['text_id'] }}</p>
                @if ($slot['text_jp'] !== null)
                    <p class="jp">{{ $slot['text_jp'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
</section>
