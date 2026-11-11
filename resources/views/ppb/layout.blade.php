{{-- PPB U2 | 2026-11-03 | CSDA original head/sidebar/topbar CSS & behavior port. --}}
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="color-scheme" content="light">
<title>@yield('title', '管理後台')｜皮皮寶</title>
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-admin-csda-source.css') }}?v=pg10-20261105">
@stack('styles')
</head>
<body>
@yield('body')
<script src="{{ asset('assets/ppb/ppb-admin-csda-source.js') }}?v=u2-20261103" defer></script>
</body>
</html>
