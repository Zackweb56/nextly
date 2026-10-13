<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Nextly') : config('app.name', 'Nextly') }}
</title>

<link rel="icon" href="{{ asset('assets/logo_branding/nextly_favicon_primary.jpg') }}" type="image/jpeg">
<link rel="apple-touch-icon" href="{{ asset('assets/logo_branding/nextly_favicon_primary.jpg') }}">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
