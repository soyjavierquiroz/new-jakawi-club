<meta name="description" data-inertia="description" content="{{ $landingSocial['short_description'] }}">
<meta name="robots" data-inertia="robots" content="{{ $landingSocial['robots'] }}">
<link rel="canonical" data-inertia="canonical" href="{{ $landingSocial['canonical'] }}">
<meta property="og:type" data-inertia="og:type" content="website">
<meta property="og:site_name" data-inertia="og:site_name" content="{{ $landingSocial['site_name'] }}">
<meta property="og:title" data-inertia="og:title" content="{{ $landingSocial['name'] }}">
<meta property="og:description" data-inertia="og:description" content="{{ $landingSocial['short_description'] }}">
<meta property="og:url" data-inertia="og:url" content="{{ $landingSocial['canonical'] }}">
@if($landingSocial['image_url'])
<meta property="og:image" data-inertia="og:image" content="{{ $landingSocial['image_url'] }}">
<meta property="og:image:secure_url" data-inertia="og:image:secure_url" content="{{ $landingSocial['image_url'] }}">
<meta property="og:image:type" data-inertia="og:image:type" content="image/jpeg">
<meta property="og:image:width" data-inertia="og:image:width" content="1200">
<meta property="og:image:height" data-inertia="og:image:height" content="630">
<meta property="og:image:alt" data-inertia="og:image:alt" content="{{ $landingSocial['name'] }}">
<meta name="twitter:card" data-inertia="twitter:card" content="summary_large_image">
<meta name="twitter:image" data-inertia="twitter:image" content="{{ $landingSocial['image_url'] }}">
@endif
<meta name="twitter:title" data-inertia="twitter:title" content="{{ $landingSocial['name'] }}">
<meta name="twitter:description" data-inertia="twitter:description" content="{{ $landingSocial['short_description'] }}">
