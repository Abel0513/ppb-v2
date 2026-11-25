      <div class="ppb-shift-pagination" role="navigation" aria-label="分頁">
        <div class="ppb-shift-pagination-info">共 {{ $resultCount ?? $games->total() }} {{ $unit ?? '筆' }} / 第 {{ $games->currentPage() }} 頁</div>
        @if($games->hasPages())
          <div class="ppb-shift-pagination-links">
            @if($games->onFirstPage())
              <span aria-disabled="true" aria-label="上一頁">‹</span>
            @else
              <a href="{{ $games->previousPageUrl() }}" rel="prev" aria-label="上一頁">‹</a>
            @endif
            @php
              $firstPage = max(1, $games->currentPage()-2);
              $lastPage = min($games->lastPage(), $games->currentPage()+2);
            @endphp
            @if($firstPage > 1)
              <a href="{{ $games->url(1) }}" aria-label="第 1 頁">1</a>
              @if($firstPage > 2)<span class="ppb-shift-pagination-ellipsis">…</span>@endif
            @endif
            @foreach($games->getUrlRange($firstPage,$lastPage) as $number=>$url)
              @if($number == $games->currentPage())
                <span class="current" aria-current="page">{{ $number }}</span>
              @else
                <a href="{{ $url }}" aria-label="第 {{ $number }} 頁">{{ $number }}</a>
              @endif
            @endforeach
            @if($lastPage < $games->lastPage())
              @if($lastPage + 1 < $games->lastPage())<span class="ppb-shift-pagination-ellipsis">…</span>@endif
              <a href="{{ $games->url($games->lastPage()) }}" aria-label="第 {{ $games->lastPage() }} 頁">{{ $games->lastPage() }}</a>
            @endif
            @if($games->hasMorePages())
              <a href="{{ $games->nextPageUrl() }}" rel="next" aria-label="下一頁">›</a>
            @else
              <span aria-disabled="true" aria-label="下一頁">›</span>
            @endif
            @if($games->hasMorePages())
              <a href="{{ $games->url($games->lastPage()) }}" aria-label="最後一頁">»</a>
            @endif
          </div>
        @endif
      </div>
