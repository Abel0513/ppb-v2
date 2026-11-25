@php
  $selectedDate = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Taipei');
  $latest = \Carbon\CarbonImmutable::now('Asia/Taipei')->startOfMonth();
  if ($ratingTabs ?? false) $latest = $latest->subMonth();
  $top = ($ratingTabs ?? false) ? $latest : max($selectedDate, $latest);
  if ($selectedDate->lessThan($top->subMonths(5))) $top = $selectedDate;
@endphp
<nav class="legacy-month-tabs" aria-label="月份">
  @if($top->lessThan($latest))
    <a class="link" href="{{ request()->fullUrlWithQuery(['month' => $top->addMonth()->format('Y-m')]) }}">‹</a>
  @endif
  @foreach(range(0, 5) as $offset)
    @php
      $tab = $top->subMonths($offset);
    @endphp
    <a class="link" href="{{ request()->fullUrlWithQuery(['month' => $tab->format('Y-m')]) }}"
      @if($tab->format('Y-m') === $month) aria-current="page" @endif>{{ ($ratingTabs ?? false) ? $tab->format('Y年n月') : $tab->format('Y/n月') }}</a>
  @endforeach
  <a class="link" href="{{ request()->fullUrlWithQuery(['month' => $top->subMonths(6)->format('Y-m')]) }}">›</a>
</nav>
