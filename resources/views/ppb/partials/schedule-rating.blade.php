<dialog class="ppb-schedule-dialog" id="ppbScheduleRating" aria-labelledby="ppbScheduleRatingTitle">
  <header class="ppb-dialog-header"><h2 id="ppbScheduleRatingTitle">分析師評鑑</h2><button type="button" class="admin-button admin-button-secondary ppb-dialog-icon" data-dialog-close aria-label="關閉">×</button></header>
  <div class="ppb-schedule-rating-summary">
    <span class="ppb-rating-game" data-rating-game></span>
    <strong data-rating-matchup></strong>
    <p data-rating-time></p>
    <strong data-rating-analyst></strong>
    <p>推薦：<span data-rating-recommendation></span></p>
    <p>平均分 <output class="ppb-rating-score" data-rating-total></output></p>
  </div>
  <p role="status" data-dialog-status></p>
  <form data-rating-form>
    @csrf
    <input type="hidden" name="operation" value="rate">
    <input type="hidden" name="schedule_scope" value="rating">
    <fieldset data-rating-fields disabled>
      <section class="ppb-rating-section" data-score-section="delivery">
        <h3>交稿時間 <output class="ppb-rating-score" data-section-score></output></h3>
        <input type="hidden" name="score_delivery_time" value="60">
      </section>
      @php
        $sections = [
            'compliance' => ['規範遵照程度', [
                'score_word_count' => ['是否符合文章字數規定', [60 => '是', 40 => '否']],
                'score_format' => ['是否符合文章格式規定', [60 => '是', 40 => '否']],
            ]],
            'professional' => ['分析師專業', [
                'score_typos' => ['是否有錯別字', [20 => '無', 10 => '少', 0 => '多']],
                'score_semantic_clarity' => ['語意是否表達不清', [20 => '清楚', 10 => '尚可', 0 => '不清']],
                'score_reference_accuracy' => ['引用資料是否有誤', [20 => '正確', 10 => '小誤', 0 => '有誤']],
                'score_logic_consistency' => ['邏輯是否互相牴觸', [20 => '無', 10 => '輕微', 0 => '有']],
                'score_language_appropriateness' => ['用字是否過於激進、偏激或語帶髒字', [10 => '無', 5 => '輕微', 0 => '有']],
            ]],
        ];
      @endphp
      @foreach($sections as $section => [$title, $fields])
        <section class="ppb-rating-section" data-score-section="{{ $section }}">
          <h3>{{ $title }} <output class="ppb-rating-score" data-section-score></output></h3>
          @foreach($fields as $name => [$label, $choices])
            <fieldset class="ppb-rating-options">
              <legend>{{ $label }}</legend>
              @foreach($choices as $score => $option)
                <label><input type="radio" name="{{ $name }}" value="{{ $score }}" @checked($loop->first)><span>{{ $option }} {{ $score }}</span></label>
              @endforeach
            </fieldset>
          @endforeach
        </section>
      @endforeach
      <section class="ppb-rating-bonus">
        <label for="ppbRatingBonus">額外加分項目（最高 +10 分）</label>
        <button type="button" class="admin-button admin-button-secondary ppb-dialog-icon" data-rating-step="-1" aria-label="減一分">−</button>
        <input class="field" id="ppbRatingBonus" type="number" name="score_professional_bonus" min="0" max="10" value="0" readonly>
        <button type="button" class="admin-button admin-button-secondary ppb-dialog-icon" data-rating-step="1" aria-label="加一分">+</button>
      </section>
    </fieldset>
    <footer><button class="action" type="submit" hidden>儲存評鑑</button><button class="admin-button admin-button-secondary" type="button" data-dialog-close>取消</button></footer>
  </form>
</dialog>
