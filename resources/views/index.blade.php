{{-- Rendered by "php artisan api-docs:generate" into public/docs/index.html; never served through Blade at request time. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · Docs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ $assets['css'] }}">
</head>
<body>
    <div id="api-docs" class="app">
        <noscript>The API docs need JavaScript. The raw OpenAPI documents are in the specs/ folder next to this page.</noscript>
    </div>

    <script type="application/json" id="api-docs-config">@json($config)</script>
    <script src="{{ $assets['js'] }}"></script>
</body>
</html>
