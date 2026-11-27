<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">

    <!-- Open Graph / Facebook / WhatsApp -->
    <meta property="og:site_name" content="BANGO">
    <meta property="og:type" content="{{ $type === 'reel' ? 'video.other' : 'website' }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $url }}">
    @if(!empty($image))
        <meta property="og:image" content="{{ $image }}">
        <meta property="og:image:secure_url" content="{{ $image }}">
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ $title }}">
    @endif

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@bangoapp">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    @if(!empty($image))
        <meta name="twitter:image" content="{{ $image }}">
    @endif

    <!-- Mobile Deep Linking -->
    <link rel="alternate" href="{{ $deepLink }}">

    <!-- Google Fonts & Tailwind CDN -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            900: '#14532d',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-900 text-slate-100 font-sans antialiased min-h-screen flex flex-col justify-between selection:bg-brand-500 selection:text-white">

    <!-- Top Navigation Header -->
    <header class="bg-slate-900/80 backdrop-blur-md border-b border-slate-800 sticky top-0 z-30">
        <div class="max-w-2xl mx-auto px-4 h-16 flex items-center justify-between">
            <a href="/" class="flex items-center space-x-2.5 group">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-600 to-emerald-400 flex items-center justify-center text-white font-black text-lg shadow-lg shadow-brand-500/20 group-hover:scale-105 transition-transform">
                    B
                </div>
                <div class="flex flex-col">
                    <span class="font-extrabold text-lg tracking-tight text-white leading-none">BANGO</span>
                    <span class="text-[10px] text-slate-400 font-medium tracking-wide">SQUARE, RÉELS & MESSAGES</span>
                </div>
            </a>

            <a href="{{ $playStoreUrl }}" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700/60 transition-colors">
                <svg class="w-3.5 h-3.5 fill-current text-brand-500" viewBox="0 0 24 24">
                    <path d="M3.609 1.814L13.793 12 3.61 22.186a2.036 2.036 0 0 1-.61-.926V2.74c0-.348.217-.678.61-.926zm11.3 11.3l2.84 2.84-12.72 7.34 9.88-10.18zm0-2.228L5.03 0.706l12.72 7.34-2.84 2.84zm1.414 1.114l3.52-2.03a1.99 1.99 0 0 1 0 3.44l-3.52 2.03-2.02-2.02 2.02-1.42z"/>
                </svg>
                <span>Installer l'app</span>
            </a>
        </div>
    </header>

    <!-- Main Content Card -->
    <main class="flex-1 max-w-2xl w-full mx-auto px-4 py-8 flex flex-col justify-center">
        @if ($isAvailable)
            <div class="bg-slate-800/90 rounded-3xl border border-slate-700/60 shadow-2xl overflow-hidden backdrop-blur-xl">

                <!-- Content Type Pill Header -->
                <div class="px-6 pt-5 pb-3 flex items-center justify-between border-b border-slate-700/40">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold tracking-wider uppercase
                        {{ $type === 'post' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : '' }}
                        {{ $type === 'reel' ? 'bg-purple-500/10 text-purple-400 border border-purple-500/20' : '' }}
                        {{ $type === 'listing' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : '' }}
                        {{ $type === 'profile' ? 'bg-cyan-500/10 text-cyan-400 border border-cyan-500/20' : '' }}">
                        @if ($type === 'post') 💬 Square · Publication @endif
                        @if ($type === 'reel') 🎬 Reel Vidéo @endif
                        @if ($type === 'listing') 🏠 Annonce Immobilière @endif
                        @if ($type === 'profile') 👤 Profil Vérifié @endif
                    </span>

                    <span class="text-xs text-slate-400">
                        {{ $item['created_at'] ?? 'Sur BANGO' }}
                    </span>
                </div>

                <!-- Author Header (Posts, Reels, Profile) -->
                @if (in_array($type, ['post', 'reel', 'profile']) && !empty($item['author_name'] ?? $item['name']))
                    <div class="p-6 flex items-center space-x-3.5">
                        @php $avatar = $item['author_avatar'] ?? $item['avatar'] ?? null; @endphp
                        @if ($avatar)
                            <img src="{{ $avatar }}" alt="{{ $item['author_name'] ?? $item['name'] }}" class="w-12 h-12 rounded-full object-cover ring-2 ring-brand-500/30">
                        @else
                            <div class="w-12 h-12 rounded-full bg-slate-700 flex items-center justify-center text-slate-300 font-bold text-lg">
                                {{ strtoupper(substr($item['author_name'] ?? $item['name'] ?? 'B', 0, 1)) }}
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center space-x-1.5">
                                <h2 class="text-base font-bold text-white truncate">{{ $item['author_name'] ?? $item['name'] }}</h2>
                                @if (!empty($item['is_verified']) || (!empty($item['badge']) && $item['badge'] !== 'none'))
                                    <span class="inline-flex text-brand-400">
                                        <svg class="w-4 h-4 fill-current" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-slate-400 truncate">
                                {{ $item['author_handle'] ?? $item['handle'] ?? '@bango' }}
                                @if(!empty($item['city'])) · {{ $item['city'] }} @endif
                            </p>
                        </div>
                    </div>
                @endif

                <!-- Media Preview -->
                @if (!empty($image))
                    <div class="relative bg-slate-950 flex items-center justify-center max-h-[460px] overflow-hidden group">
                        <img src="{{ $image }}" alt="{{ $title }}" class="w-full h-full object-cover max-h-[460px] transition-transform duration-300 group-hover:scale-102">

                        @if ($type === 'reel')
                            <div class="absolute inset-0 bg-slate-900/40 flex items-center justify-center">
                                <div class="w-16 h-16 rounded-full bg-brand-500/90 text-white flex items-center justify-center shadow-xl shadow-brand-500/30 transform group-hover:scale-110 transition-transform">
                                    <svg class="w-8 h-8 fill-current ml-1" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z"/>
                                    </svg>
                                </div>
                            </div>
                        @endif

                        @if ($type === 'listing' && !empty($item['price_formatted']))
                            <div class="absolute bottom-4 left-4 bg-slate-900/90 backdrop-blur-md px-3.5 py-1.5 rounded-xl border border-slate-700/80 shadow-lg">
                                <span class="text-xs text-slate-400 block font-medium">Loyer / Prix</span>
                                <span class="text-base font-extrabold text-brand-400">{{ $item['price_formatted'] }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                <!-- Content Body -->
                <div class="p-6 space-y-4">
                    @if ($type === 'listing')
                        <div>
                            <h1 class="text-xl font-extrabold text-white leading-snug">{{ $item['title'] }}</h1>
                            @if (!empty($item['location']))
                                <p class="text-xs text-slate-400 flex items-center mt-1">
                                    <svg class="w-3.5 h-3.5 mr-1 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    {{ $item['location'] }}
                                </p>
                            @endif
                        </div>

                        <!-- Amenities tags -->
                        <div class="flex flex-wrap gap-2 text-xs text-slate-300">
                            @if(!empty($item['bedrooms']))
                                <span class="px-2.5 py-1 rounded-lg bg-slate-700/60 border border-slate-600/50">🛏️ {{ $item['bedrooms'] }} ch.</span>
                            @endif
                            @if(!empty($item['showers']))
                                <span class="px-2.5 py-1 rounded-lg bg-slate-700/60 border border-slate-600/50">🚿 {{ $item['showers'] }} d.</span>
                            @endif
                            @if(!empty($item['area']))
                                <span class="px-2.5 py-1 rounded-lg bg-slate-700/60 border border-slate-600/50">📐 {{ $item['area'] }} m²</span>
                            @endif
                        </div>
                    @endif

                    <!-- Text description / body -->
                    @php $text = $item['body'] ?? $item['caption'] ?? $item['description'] ?? $item['bio'] ?? null; @endphp
                    @if (!empty($text))
                        <p class="text-slate-300 text-sm leading-relaxed whitespace-pre-line">{{ $text }}</p>
                    @endif

                    <!-- Call To Action Buttons -->
                    <div class="pt-4 border-t border-slate-700/60 flex flex-col sm:flex-row gap-3">
                        <a href="{{ $deepLink }}" id="openAppBtn"
                           class="flex-1 inline-flex items-center justify-center space-x-2 px-5 py-3 rounded-2xl bg-gradient-to-r from-brand-600 to-emerald-500 hover:from-brand-500 hover:to-emerald-400 text-white font-bold text-sm shadow-lg shadow-brand-500/25 transition-all transform active:scale-98">
                            <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.5v-9l6 4.5-6 4.5z"/>
                            </svg>
                            <span>Ouvrir dans BANGO</span>
                        </a>

                        <a href="{{ $playStoreUrl }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center justify-center space-x-2 px-4 py-3 rounded-2xl bg-slate-700/80 hover:bg-slate-700 text-slate-200 font-semibold text-sm border border-slate-600/60 transition-colors">
                            <svg class="w-4 h-4 fill-current text-slate-300" viewBox="0 0 24 24">
                                <path d="M3.609 1.814L13.793 12 3.61 22.186a2.036 2.036 0 0 1-.61-.926V2.74c0-.348.217-.678.61-.926zm11.3 11.3l2.84 2.84-12.72 7.34 9.88-10.18zm0-2.228L5.03 0.706l12.72 7.34-2.84 2.84zm1.414 1.114l3.52-2.03a1.99 1.99 0 0 1 0 3.44l-3.52 2.03-2.02-2.02 2.02-1.42z"/>
                            </svg>
                            <span>Google Play</span>
                        </a>
                    </div>
                </div>
            </div>
        @else
            <!-- Unavailable / Private Card -->
            <div class="bg-slate-800/90 rounded-3xl border border-slate-700/60 p-8 text-center shadow-2xl backdrop-blur-xl">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-slate-700/50 flex items-center justify-center text-slate-400 mb-4">
                    <svg class="w-8 h-8 stroke-current" fill="none" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>

                <h1 class="text-xl font-extrabold text-white mb-2">{{ $title }}</h1>
                <p class="text-slate-400 text-sm max-w-md mx-auto mb-6">{{ $description }}</p>

                <div class="flex flex-col sm:flex-row justify-center gap-3">
                    <a href="{{ $playStoreUrl }}" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center justify-center space-x-2 px-6 py-3 rounded-2xl bg-brand-600 hover:bg-brand-500 text-white font-bold text-sm shadow-lg shadow-brand-500/25 transition-colors">
                        <span>Télécharger l'application BANGO</span>
                    </a>
                </div>
            </div>
        @endif
    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-800 py-6 text-center text-xs text-slate-400">
        <p>© {{ date('Y') }} BANGO. Square, Réels et Messages.</p>
    </footer>

    <!-- Smart Deep Link Redirection Script for Mobile Web -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var openBtn = document.getElementById('openAppBtn');
            if (openBtn) {
                openBtn.addEventListener('click', function (e) {
                    var deepLink = "{{ $deepLink }}";
                    var fallbackUrl = "{{ $playStoreUrl }}";
                    var clickedAt = +new Date();

                    setTimeout(function () {
                        if (+new Date() - clickedAt < 2000) {
                            window.location = fallbackUrl;
                        }
                    }, 1500);
                });
            }
        });
    </script>
</body>
</html>
