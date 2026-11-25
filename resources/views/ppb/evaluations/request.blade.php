@csrf
<input type="hidden" name="month" value="{{ $report['month'] }}">
<input type="hidden" name="request_id" value="{{ (string)Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="revision" value="{{ $report['revision'] }}">
<input type="hidden" name="source_sha256" value="{{ $report['source_sha256'] }}">
