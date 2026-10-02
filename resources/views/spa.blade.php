<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('clinic.name', 'Clinic Suite') }}</title>
    @vite('src/main.tsx')
</head>
<body class="bg-clinical-slate-50">
    <div id="root"></div>
</body>
</html>