<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Gravit' }}</title>
    @vite('resources/css/app.css')
    @livewireStyles
</head>
<body class="min-h-full bg-slate-100 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    {{ $slot }}
    @livewireScripts
</body>
</html>
