<nav class="pager ppb-list-pager" aria-label="分頁">
  <div class="ppb-page-count">共 {{ $resultCount ?? $paginator->total() }} {{ $unit ?? '筆' }} / 第 {{ $paginator->currentPage() }} 頁</div>
  @if($paginator->hasPages())
    <div class="ppb-segments">
      @if($paginator->onFirstPage())
        <span aria-disabled="true">上一頁</span>
      @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">上一頁</a>
      @endif
      @php
        $firstPage = max(1, $paginator->currentPage()-2);
        $lastPage = min($paginator->lastPage(), $paginator->currentPage()+2);
      @endphp
      @if($firstPage > 1)
        <a href="{{ $paginator->url(1) }}" aria-label="第 1 頁">1</a>
        @if($firstPage > 2)<span class="ppb-page-ellipsis">…</span>@endif
      @endif
      @foreach($paginator->getUrlRange($firstPage,$lastPage) as $number=>$url)
        @if($number == $paginator->currentPage())
          <span aria-current="page">{{ $number }}</span>
        @else
          <a href="{{ $url }}" aria-label="第 {{ $number }} 頁">{{ $number }}</a>
        @endif
      @endforeach
      @if($lastPage < $paginator->lastPage())
        @if($lastPage + 1 < $paginator->lastPage())<span class="ppb-page-ellipsis">…</span>@endif
        <a href="{{ $paginator->url($paginator->lastPage()) }}" aria-label="最後一頁">{{ $paginator->lastPage() }}</a>
      @endif
      @if($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next">下一頁</a>
      @else
        <span aria-disabled="true">下一頁</span>
      @endif
    </div>
  @endif
</nav>
