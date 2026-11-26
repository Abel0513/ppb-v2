<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<title>賽事排班總覽</title>
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-overview-legacy.css') }}?v=20261126-3">
<style>
*{box-sizing:border-box}
body{margin:0;padding:20px;background:#f0f0f0;font-family:"Microsoft JhengHei","Noto Sans TC",sans-serif}
.ppb-ov .ov-panel{background:none;box-shadow:none;padding:0;border-radius:0}
@media(max-width:640px){body{padding:10px}.ppb-ov .search-card{display:none}}
</style>
</head>
<body>
@include('ppb.partials.overview-content', ['public' => true])
</body>
</html>
