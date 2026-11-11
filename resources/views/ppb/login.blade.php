<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#07142d">
    <title>管理後台登入｜皮皮寶</title>

    <!-- PPB Login L1 | 2026-11-03 | Approved dark-navy / blue visual direction. -->
    <!-- Standalone view: keeps A1/A2/A3 admin layouts, routes and permissions unchanged. -->
    <!-- PPB Login L2 | 2026-11-03 | Responsive proportion refinement only; no auth changes. -->
    <!-- PPB Login L3 | 2026-11-03 | Desktop card compact 510px; CSS-only change, no auth modifications. -->
    <!-- PPB Login L4 | 2026-11-03 | Compact desktop 400px, compact brand and balanced mobile. -->
    <style>
        :root { color-scheme: light; --navy:#06162f; --navy-deep:#041023; --blue:#0c55d8; --cyan:#38b5ff; --line:#d5dfee; }
        *, *::before, *::after { box-sizing: border-box; }
        html { min-height:100%; }
        body { margin:0; min-height:100vh; font-family:system-ui,-apple-system,"Noto Sans TC","Microsoft JhengHei",sans-serif; color:#132342; background:var(--navy-deep); }
        button,input { font:inherit; }
        a { color:inherit; }
        .login-page { position:relative; isolation:isolate; min-height:100vh; display:flex; flex-direction:column; justify-content:center; align-items:center; padding:34px 18px 28px; overflow:hidden; }
        .login-page::before { content:""; position:absolute; z-index:-2; inset:0; background:url('{{ asset('assets/ppb/stadium-dark.webp') }}') center / cover no-repeat; }
        .login-page::after { content:""; position:absolute; z-index:-1; inset:0; background:linear-gradient(90deg,rgba(2,10,24,.12),transparent 25%,rgba(2,10,24,.21) 50%,transparent 75%,rgba(2,10,24,.12)),linear-gradient(180deg,rgba(1,8,20,.12),rgba(1,8,20,.12) 66%,rgba(1,8,20,.47)); pointer-events:none; }
        .login-brand { width:min(320px,100%); display:flex; align-items:center; justify-content:center; gap:9px; margin:0 auto 11px; filter:drop-shadow(0 10px 12px rgba(0,0,0,.18)); }
        .login-brand img { width:105px; height:105px; flex:0 0 105px; object-fit:contain; }
        .brand-lines { min-width:0; margin-top:5px; }
        .brand-title { display:flex; align-items:baseline; white-space:nowrap; color:white; font-size:clamp(42px,3.4vw,53px); font-style:italic; font-weight:1000; letter-spacing:.06em; line-height:1.1; text-shadow:0 4px 0 rgba(0,0,0,.16),0 9px 19px rgba(0,0,0,.25); }
        .brand-title .accent { color:#32b7ff; background:linear-gradient(155deg,#71d2ff 0%,#34b2ff 48%,#0587d9 95%); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
        .brand-stroke { height:5px; border-radius:100%; width:100%; margin:1px auto 4px; background:linear-gradient(90deg,transparent,#08aaf0 20%,#66d5ff 75%,transparent); transform:skewX(-22deg); box-shadow:0 1px 13px rgba(44,177,250,.23); }
        .brand-subtitle { text-align:center; color:white; font-size:clamp(11px,1vw,13px); font-weight:750; letter-spacing:.45em; text-indent:.45em; text-shadow:0 2px 8px rgba(0,0,0,.7); white-space:nowrap; }
        .login-panel { width:min(400px,100%); overflow:hidden; border-radius:18px; background:#fff; border:1px solid rgba(179,214,255,.9); box-shadow:0 25px 90px rgba(0,9,35,.55),0 0 0 1px rgba(17,88,185,.12); }
        .panel-heading { min-height:52px; background:linear-gradient(117deg,#176bc2 0%,#083d85 48%,#062d70 100%); color:#fff; display:flex; align-items:center; justify-content:center; gap:15px; border-bottom:1px solid #2b69a7; }
        .panel-heading svg { width:22px; height:22px; fill:none; stroke:currentColor; stroke-linecap:round; stroke-linejoin:round; stroke-width:2.4; }
        .panel-heading h1 { font-size:20px; letter-spacing:.055em; margin:0; line-height:1.2; font-weight:850; }
        .panel-body { padding:15px 22px 17px; }
        .field-block { margin-bottom:9px; }
        .field-label { display:block; margin-bottom:4px; font-weight:750; font-size:13px; color:#1d2e48; }
        .input-wrap { display:flex; align-items:center; gap:9px; height:43px; border:1px solid #bfcce0; border-radius:10px; background:linear-gradient(180deg,#fff,#f7f9fd); padding:0 15px; transition:border-color .2s,box-shadow .2s; }
        .input-wrap:focus-within { border-color:#247ce1; box-shadow:0 0 0 3px rgba(37,133,231,.15); }
        .input-wrap .field-icon { display:grid; place-items:center; color:#8d9bb0; width:20px; flex:0 0 20px; }
        .field-icon svg,.password-toggle svg { display:block; width:20px; height:20px; fill:none; stroke:currentColor; stroke-width:1.9; stroke-linecap:round; stroke-linejoin:round; }
        .input-wrap input { width:100%; height:100%; background:transparent; min-width:0; color:#173356; outline:0; border:0; font-size:16px; }
        .input-wrap input::placeholder { color:#9aa6b7; opacity:1; }
        .input-wrap input:disabled { cursor:not-allowed; color:#8290a4; }
        .password-toggle { width:38px; height:38px; display:grid; place-items:center; flex:0 0 38px; color:#8293aa; cursor:pointer; border:0; background:transparent; border-radius:9px; }
        .password-toggle:focus-visible { outline:2px solid #418feb; outline-offset:1px; }
        .password-toggle:disabled { cursor:not-allowed; opacity:.55; }
        .password-toggle:hover:not(:disabled) { background:#eaf2fe; color:#0c53bc; }
        .remember-row { display:flex; align-items:center; justify-content:space-between; gap:10px; margin:1px 0 10px; }
        .remember { display:inline-flex; align-items:center; gap:8px; font-size:13px; color:#2b3851; cursor:pointer; }
        .remember input { appearance:none; -webkit-appearance:none; width:17px; height:17px; margin:0; border:1.5px solid #a1afc4; border-radius:5px; background:#fff; display:grid; place-content:center; }
        .remember input:checked { background:var(--blue); border-color:var(--blue); }
        .remember input:checked::before { content:""; display:block; width:10px; height:6px; border-left:2px solid #fff; border-bottom:2px solid #fff; transform:rotate(-45deg) translate(1px,-1px); }
        .remember input:focus-visible { outline:3px solid rgba(37,133,231,.24); outline-offset:2px; }
        .remember input:disabled { background:#f0f3fa; cursor:not-allowed; }
        .login-submit { width:100%; min-height:44px; border:0; border-radius:13px; background:linear-gradient(165deg,#139ff2 0%,#0760d9 55%,#07348f 100%); color:#fff; font-size:17px; font-weight:850; letter-spacing:.2em; text-indent:.2em; display:flex; align-items:center; justify-content:center; gap:14px; box-shadow:0 8px 18px rgba(19,84,170,.19); cursor:pointer; }
        .login-submit:hover:not(:disabled) { filter:brightness(1.07); }
        .login-submit:focus-visible { outline:3px solid #79bcff; outline-offset:3px; }
        .login-submit:disabled { cursor:not-allowed; opacity:.84; }
        .login-submit svg { width:21px; height:21px; fill:none; stroke:currentColor; stroke-width:2.7; stroke-linecap:round; stroke-linejoin:round; }
        .login-alert { padding:11px 15px; border:1px solid #ffd3d9; background:#fff0f2; color:#9d263d; border-radius:10px; margin-bottom:17px; font-size:13px; line-height:1.55; }
        .preview-alert { display:flex; align-items:center; gap:6px; font-size:10.5px; line-height:1.35; margin:9px 0 0; padding:7px 9px; background:#edf4ff; border:1px solid #d2e3fa; color:#255184; border-radius:10px; }
        .preview-alert svg { width:14px; height:14px; flex:0 0 14px; margin-top:0; fill:none; stroke:currentColor; stroke-width:2; }
        .login-footer { margin-top:11px; text-align:center; color:rgba(235,246,255,.9); font-size:13px; letter-spacing:.07em; text-shadow:0 2px 8px rgba(0,0,0,.72); }
        /* L4 | Logo and card both compact. Mobile retains accessible fields. */
        /* L2 | Medium screens: leave visibly more stadium around the form. */
        @media (max-width:900px) {
            .login-page { padding:24px 18px 22px; }
            .login-brand { width:min(310px,100%); margin-bottom:10px; gap:9px; }
            .login-brand img { width:98px; height:98px; flex-basis:98px; }
            .brand-title { font-size:clamp(42px,6vw,49px); }
            .brand-subtitle { font-size:12px; }
            .login-panel { width:min(396px,calc(100% - 16px)); border-radius:18px; }
            .panel-heading { min-height:52px; gap:9px; }
            .panel-heading h1 { font-size:18px; }
            .panel-heading svg { width:22px; height:22px; }
            .panel-body { padding:15px 22px 17px; }
            .field-block { margin-bottom:9px; }
            .field-label { margin-bottom:4px; font-size:13px; }
            .input-wrap { height:44px; }
            .remember-row { margin:0 0 10px; }
            .login-submit { min-height:44px; font-size:17px; }
            .preview-alert { font-size:10.5px; line-height:1.35; padding:7px 9px; margin-top:9px; }
            .login-footer { margin-top:11px; }
        }

        /* L2 | Phones: compact card without shrinking controls below usable size. */
        @media (max-width:470px) {
            .login-page { justify-content:center; min-height:100svh; padding:18px 10px 20px; }
            .login-page::before { background-position:center; }
            .login-brand { width:min(295px,100%); gap:6px; margin-bottom:11px; }
            .login-brand img { width:77px; height:79px; flex-basis:77px; }
            .brand-title { font-size:clamp(31px,8.6vw,38px); letter-spacing:.01em; }
            .brand-stroke { height:4px; margin:2px 0 4px; }
            .brand-subtitle { font-size:10px; letter-spacing:.26em; text-indent:.26em; }
            .login-panel { width:min(328px,calc(100% - 6px)); border-radius:16px; }
            .panel-heading { min-height:49px; gap:8px; }
            .panel-heading h1 { font-size:20px; }
            .panel-heading svg { width:20px; height:20px; }
            .panel-body { padding:15px 18px 16px; }
            .field-block { margin-bottom:9px; }
            .field-label { margin-bottom:4px; font-size:13px; }
            .input-wrap { height:44px; padding:0 12px; }
            .input-wrap input { font-size:15px; }
            .remember-row { margin:0 0 10px; }
            .remember { font-size:13px; }
            .remember input { width:18px; height:18px; }
            .login-submit { min-height:44px; font-size:16px; gap:9px; }
            .login-submit svg { width:20px; height:20px; }
            .preview-alert { font-size:10.5px; line-height:1.35; padding:7px 8px; margin-top:9px; }
            .login-footer { margin-top:12px; font-size:10.5px; }
        }

        @media (max-width:360px) {
            .login-brand img { width:69px; height:74px; flex-basis:69px; }
            .brand-title { font-size:29px; }
            .panel-body { padding-left:16px; padding-right:16px; }
        }

        @media(prefers-reduced-motion:reduce) { * { scroll-behavior:auto!important; transition:none!important; } }
    </style>
</head>
<body>
<main class="login-page">
    <header class="login-brand" aria-label="皮皮寶運動分析平台">
        <img src="{{ asset('assets/ppb/robot.webp') }}" alt="皮皮寶機器人" width="160" height="160">
        <div class="brand-lines">
            <div class="brand-title"><span>皮皮</span><span class="accent">寶</span></div>
            <div class="brand-stroke" aria-hidden="true"></div>
            <div class="brand-subtitle">運動分析平台</div>
        </div>
    </header>

    <section class="login-panel" aria-labelledby="login-title">
        <div class="panel-heading">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 15v2"/></svg>
            <h1 id="login-title">管理後台登入</h1>
        </div>

        <div class="panel-body">
            @if($errors->any())
                <div class="login-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="post" action="{{ route('ppb.login') }}" autocomplete="on">
                @csrf

                <div class="field-block">
                    <label class="field-label" for="username">管理員帳號</label>
                    <div class="input-wrap">
                        <span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2z"/></svg></span>
                        <input type="text" id="username" name="username" placeholder="請輸入管理員帳號" minlength="3" maxlength="64" pattern="[A-Za-z0-9_.-]{3,64}" autocomplete="username" autocapitalize="none" spellcheck="false" value="{{ old('username') }}" required @disabled(!$canLogin)>
                    </div>
                </div>

                <div class="field-block">
                    <label class="field-label" for="password">密碼</label>
                    <div class="input-wrap">
                        <span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>
                        <input type="password" id="password" name="password" placeholder="請輸入密碼" autocomplete="current-password" required @disabled(!$canLogin)>
                        <button class="password-toggle" type="button" id="toggle-password" aria-controls="password" aria-label="顯示密碼" title="顯示／隱藏密碼" @disabled(!$canLogin)>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.8-6 10-6 10 6 10 6-3.8 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="2.5"/><path d="M3 3l18 18"/></svg>
                        </button>
                    </div>
                </div>

                <div class="remember-row">
                    <label class="remember" for="remember">
                        <input type="checkbox" name="remember" id="remember" value="1" @disabled(!$canLogin)>
                        <span>記住登入狀態</span>
                    </label>
                </div>

                <button class="login-submit" type="submit" @disabled(!$canLogin)>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-8"/><path d="M4 12h12m-5-5 5 5-5 5"/></svg>
                    登入
                </button>
            </form>

            @if(!$canLogin)
                <p class="preview-alert" role="status">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7h.01"/></svg>
                    <span>HTTP 預覽模式｜登入需使用 HTTPS 網域。</span>
                </p>
            @endif
        </div>
    </section>

    <footer class="login-footer">© 皮皮寶 運動分析平台 管理後台</footer>
</main>
<script>
// PPB Login L1: interaction works only when the secure-host middleware permits login.
(function () {
    'use strict';
    const input = document.getElementById('password');
    const toggle = document.getElementById('toggle-password');
    if (!input || !toggle) return;
    toggle.addEventListener('click', function () {
        const revealed = input.type === 'password';
        input.type = revealed ? 'text' : 'password';
        toggle.setAttribute('aria-label', revealed ? '隱藏密碼' : '顯示密碼');
        toggle.setAttribute('aria-pressed', revealed ? 'true' : 'false');
    });
}());
</script>
</body>
</html>
