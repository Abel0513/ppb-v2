<span class="legacy-name-pill"
  @if($colors['background'] ?? null) style="background-color:{{ $colors['background'] }};color:{{ $colors['color'] ?? '#26334a' }}"
  @elseif($colors['color'] ?? null) style="color:{{ $colors['color'] }}"
  @endif>{{ $name }}</span>
