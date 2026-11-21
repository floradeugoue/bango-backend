<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $locale === 'en' ? 'Delete your BANGO Account & Data' : 'Suppression de votre compte et de vos données — BANGO' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                    colors: {
                        bango: {
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
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen flex flex-col justify-between">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white font-black text-lg shadow-sm">
                    B
                </div>
                <span class="font-extrabold text-xl tracking-tight text-slate-900">BANGO</span>
            </div>

            <!-- Language Switcher -->
            <div class="flex items-center bg-slate-100 rounded-lg p-1 text-xs font-semibold">
                <a href="/supprimer-mon-compte" class="px-2.5 py-1 rounded-md {{ $locale === 'fr' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800' }}">FR</a>
                <a href="/en/delete-account" class="px-2.5 py-1 rounded-md {{ $locale === 'en' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800' }}">EN</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow py-8 sm:py-12 px-4 sm:px-6">
        <div class="max-w-3xl mx-auto">

            <!-- Title & Subtitle -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-rose-50 text-rose-700 text-xs font-semibold mb-3 border border-rose-200">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    {{ $locale === 'en' ? 'Google Play Data Deletion Request' : 'Demande de suppression des données (Google Play)' }}
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                    {{ $locale === 'en' ? 'Delete your account & personal data' : 'Supprimer votre compte et vos données personnelles' }}
                </h1>
                <p class="mt-2 text-sm sm:text-base text-slate-600 max-w-xl mx-auto">
                    {{ $locale === 'en' 
                        ? 'You can request the deletion of your BANGO account even without the mobile app installed.' 
                        : 'Vous pouvez demander la suppression de votre compte BANGO directement ici, même si vous n\'avez plus l\'application.' }}
                </p>
            </div>

            <!-- Alerts -->
            @if (session('error'))
                <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-start gap-3">
                    <svg class="w-5 h-5 text-rose-600 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>
                    <div>{{ session('error') }}</div>
                </div>
            @endif

            @if (session('status') === 'scheduled_for_deletion')
                <div class="mb-6 p-5 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm">
                    <div class="flex items-center gap-2 font-bold text-base mb-1 text-emerald-800">
                        <svg class="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                        {{ $locale === 'en' ? 'Deletion request registered' : 'Demande de suppression enregistrée avec succès' }}
                    </div>
                    <p class="text-emerald-800">{{ session('message') }}</p>
                    <div class="mt-3 pt-3 border-t border-emerald-200/60 text-xs text-emerald-700">
                        {{ $locale === 'en' ? 'A confirmation email has also been sent to your registered address.' : 'Un e-mail de confirmation vous a également été envoyé.' }}
                    </div>
                </div>
            @elseif (session('status') === 'already_scheduled')
                <div class="mb-6 p-5 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-sm">
                    <div class="flex items-center gap-2 font-bold text-base mb-1 text-amber-800">
                        <svg class="w-5 h-5 text-amber-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                        {{ $locale === 'en' ? 'Account already scheduled for deletion' : 'Compte déjà en attente de suppression' }}
                    </div>
                    <p class="text-amber-800">{{ session('message') }}</p>
                </div>
            @elseif (session('status') === 'partial_data_deleted')
                <div class="mb-6 p-5 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm">
                    <div class="flex items-center gap-2 font-bold text-base mb-1 text-emerald-800">
                        <svg class="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                        {{ $locale === 'en' ? 'Data deleted' : 'Données supprimées' }}
                    </div>
                    <p class="text-emerald-800">{{ session('message') }}</p>
                </div>
            @elseif (session('status') === 'otp_sent')
                <div class="mb-6 p-4 rounded-xl bg-blue-50 border border-blue-200 text-blue-900 text-sm flex items-start gap-3">
                    <svg class="w-5 h-5 text-blue-600 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>
                    <div>{{ session('message') }}</div>
                </div>
            @endif

            <!-- Informative Cards (Data Transparency / Google Disclosures) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
                <!-- Data Deleted -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                    <div class="flex items-center gap-2 text-rose-700 font-bold text-sm mb-2.5">
                        <div class="w-6 h-6 rounded-lg bg-rose-100 flex items-center justify-center text-rose-600">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
                        </div>
                        {{ $locale === 'en' ? 'Data permanently deleted (in 30 days)' : 'Données effacées (dans 30 jours)' }}
                    </div>
                    <ul class="text-xs sm:text-sm text-slate-600 space-y-2">
                        <li class="flex items-start gap-2">
                            <span class="text-slate-400">•</span>
                            <span>{{ $locale === 'en' ? 'Public profile, username, bio, avatar, and badges' : 'Profil public, pseudonyme, biographie, photo et badges' }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-slate-400">•</span>
                            <span>{{ $locale === 'en' ? 'Posts, reels, stories, photos, and video media' : 'Publications Square, reels, stories, photos et vidéos' }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-slate-400">•</span>
                            <span>{{ $locale === 'en' ? 'Private messages, voice notes, and attachments' : 'Messages privés, notes vocales et pièces jointes' }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-slate-400">•</span>
                            <span>{{ $locale === 'en' ? 'Compared address book hashes & search history' : 'Empreintes de contacts comparés et historiques de recherche' }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Data Retained for Legal Compliance -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                    <div class="flex items-center gap-2 text-amber-800 font-bold text-sm mb-2.5">
                        <div class="w-6 h-6 rounded-lg bg-amber-100 flex items-center justify-center text-amber-700">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                        </div>
                        {{ $locale === 'en' ? 'Data retained per statutory obligations' : 'Données conservées selon la loi' }}
                    </div>
                    <ul class="text-xs sm:text-sm text-slate-600 space-y-2">
                        <li class="flex items-start gap-2">
                            <span class="text-amber-500 font-bold font-mono shrink-0">{{ $locale === 'en' ? '10 yrs' : '10 ans' }}</span>
                            <span>{{ $locale === 'en' ? 'Transactions, payment receipts & accounting invoices (mandatory tax/commercial law)' : 'Transactions, factures & pièces comptables de paiement (obligation fiscale/commerciale)' }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-amber-500 font-bold font-mono shrink-0">{{ $locale === 'en' ? '5 yrs' : '5 ans' }}</span>
                            <span>{{ $locale === 'en' ? 'Verified KYC documents & identity checks (anti-fraud compliance)' : 'Vérifications KYC & pièces d\'identité (lutte contre la fraude)' }}</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-amber-500 font-bold font-mono shrink-0">{{ $locale === 'en' ? '2 yrs' : '2 ans' }}</span>
                            <span>{{ $locale === 'en' ? 'Moderation sanction records for appeals processing' : 'Historique des décisions de modération pour recours' }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- 30-Day Window Banner -->
            <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-4 mb-8 flex items-start gap-3">
                <svg class="w-5 h-5 text-indigo-600 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <div class="text-xs sm:text-sm text-indigo-900">
                    <span class="font-bold">{{ $locale === 'en' ? '30-day grace period:' : 'Délai de rétractation de 30 jours :' }}</span>
                    {{ $locale === 'en' 
                        ? 'Your account is deactivated immediately upon submission. If you sign back into the BANGO app within 30 days, your deletion request will be canceled automatically and your data restored.'
                        : 'Votre compte est immédiatement désactivé dès validation. Si vous vous reconnectez à l\'application BANGO dans les 30 jours, votre demande sera annulée et toutes vos données restaurées.' }}
                </div>
            </div>

            <!-- Tab Switcher -->
            <div class="flex border-b border-slate-200 mb-6 gap-6 text-sm font-semibold">
                <button type="button" id="tab-full-btn" onclick="switchTab('full')" class="pb-3 border-b-2 border-emerald-600 text-emerald-700">
                    {{ $locale === 'en' ? '1. Delete Full Account' : '1. Supprimer mon compte complet' }}
                </button>
                <button type="button" id="tab-partial-btn" onclick="switchTab('partial')" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800">
                    {{ $locale === 'en' ? '2. Delete Specific Data Only' : '2. Supprimer seulement certaines données' }}
                </button>
            </div>

            <!-- TAB 1: FULL ACCOUNT DELETION FORM -->
            <div id="tab-full" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
                <form action="/supprimer-mon-compte" method="POST" class="space-y-6">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale }}">

                    <!-- Step 1: Identifier -->
                    <div>
                        <label for="identifier" class="block text-sm font-semibold text-slate-800 mb-1">
                            {{ $locale === 'en' ? 'Email address or Phone number' : 'Adresse e-mail ou Numéro de téléphone' }}
                        </label>
                        <input type="text" name="identifier" id="identifier" required value="{{ old('identifier', $identifier) }}"
                            placeholder="{{ $locale === 'en' ? 'e.g. john@example.com or 237690000000' : 'Ex: jean@example.com ou 690000000' }}"
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                    </div>

                    <!-- Step 2: Auth Method Choice -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-800 mb-2">
                            {{ $locale === 'en' ? 'Verification method' : 'Méthode de vérification' }}
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center gap-2.5 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/40">
                                <input type="radio" name="auth_method" value="password" checked onclick="toggleAuthMethod('password')" class="text-emerald-600 focus:ring-emerald-500">
                                <span class="text-xs sm:text-sm font-medium text-slate-700">{{ $locale === 'en' ? 'Password' : 'Mot de passe' }}</span>
                            </label>
                            <label class="flex items-center gap-2.5 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/40">
                                <input type="radio" name="auth_method" value="otp" onclick="toggleAuthMethod('otp')" class="text-emerald-600 focus:ring-emerald-500">
                                <span class="text-xs sm:text-sm font-medium text-slate-700">{{ $locale === 'en' ? 'Code via SMS / Email' : 'Code reçu par SMS / Email' }}</span>
                            </label>
                        </div>
                    </div>

                    <!-- Password Input Field -->
                    <div id="password-field" class="space-y-1">
                        <label for="password" class="block text-sm font-semibold text-slate-800">
                            {{ $locale === 'en' ? 'Your current password' : 'Votre mot de passe actuel' }}
                        </label>
                        <input type="password" name="password" id="password"
                            placeholder="••••••••••••"
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                    </div>

                    <!-- OTP Code Input Field (Hidden by default) -->
                    <div id="otp-field" class="hidden space-y-2">
                        <div class="flex items-center justify-between">
                            <label for="otp_code" class="block text-sm font-semibold text-slate-800">
                                {{ $locale === 'en' ? '6-digit verification code' : 'Code de confirmation à 6 chiffres' }}
                            </label>
                            <button type="button" onclick="sendOtpRequest()" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 underline">
                                {{ $locale === 'en' ? 'Send code now' : 'Envoyer le code maintenant' }}
                            </button>
                        </div>
                        <input type="text" name="otp_code" id="otp_code" maxlength="6"
                            placeholder="123456"
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm tracking-widest font-mono text-center text-lg">
                        <p id="otp-status-msg" class="text-xs text-slate-500"></p>
                    </div>

                    <!-- Confirmation Checkbox -->
                    <div class="pt-2 border-t border-slate-100">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="confirm_delete" value="1" required class="mt-1 rounded text-rose-600 focus:ring-rose-500">
                            <span class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                                {{ $locale === 'en'
                                    ? 'I understand that my account will be permanently deleted after 30 days, and that my profile, posts, reels, and messages will be unrecoverable.'
                                    : 'Je comprends que mon compte sera définitivement supprimé dans 30 jours, et que mon profil, mes annonces, messages et médias seront irrécupérables.' }}
                            </span>
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="w-full py-3 px-5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-sm shadow-sm transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        {{ $locale === 'en' ? 'Confirm and Schedule Account Deletion' : 'Confirmer et programmer la suppression de mon compte' }}
                    </button>
                </form>
            </div>

            <!-- TAB 2: PARTIAL DATA DELETION FORM (GOOGLE RECOMMENDED) -->
            <div id="tab-partial" class="hidden bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
                <form action="/supprimer-mon-compte/partial-data" method="POST" class="space-y-6">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale }}">

                    <div>
                        <h2 class="text-base font-bold text-slate-900 mb-1">
                            {{ $locale === 'en' ? 'Delete specific data without deleting your account' : 'Supprimer certaines données sans fermer votre compte' }}
                        </h2>
                        <p class="text-xs sm:text-sm text-slate-500">
                            {{ $locale === 'en' 
                                ? 'Select the categories of data you wish to remove from your BANGO profile.' 
                                : 'Sélectionnez les catégories de données que vous souhaitez effacer de votre profil BANGO.' }}
                        </p>
                    </div>

                    <div>
                        <label for="identifier_partial" class="block text-sm font-semibold text-slate-800 mb-1">
                            {{ $locale === 'en' ? 'Email address or Phone number' : 'Adresse e-mail ou Numéro de téléphone' }}
                        </label>
                        <input type="text" name="identifier" id="identifier_partial" required value="{{ old('identifier', $identifier) }}"
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                    </div>

                    <!-- Categories Checkboxes -->
                    <div class="space-y-2.5 bg-slate-50 p-4 rounded-xl border border-slate-200/80">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="categories[]" value="location_history" checked class="mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <div class="text-sm font-semibold text-slate-800">{{ $locale === 'en' ? 'Location & IP History' : 'Historique de localisation et adresse IP' }}</div>
                                <div class="text-xs text-slate-500">{{ $locale === 'en' ? 'Clears your recorded location coords and last known network IP' : 'Efface vos coordonnées de localisation enregistrées et la dernière IP' }}</div>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="categories[]" value="devices" class="mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <div class="text-sm font-semibold text-slate-800">{{ $locale === 'en' ? 'Push Notification Device Tokens' : 'Jetons d\'appareils et notifications push' }}</div>
                                <div class="text-xs text-slate-500">{{ $locale === 'en' ? 'Revokes push notifications on all your previously connected mobile devices' : 'Révoque les notifications push sur vos anciens téléphones' }}</div>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="categories[]" value="search_history" class="mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <div class="text-sm font-semibold text-slate-800">{{ $locale === 'en' ? 'Search History & Recommendations' : 'Historique de recherche et préférences' }}</div>
                                <div class="text-xs text-slate-500">{{ $locale === 'en' ? 'Clears your saved search filters and discovery history' : 'Efface vos recherches récentes et filtres mémorisés' }}</div>
                            </div>
                        </label>
                    </div>

                    <!-- Auth Method -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-800 mb-2">
                            {{ $locale === 'en' ? 'Verification method' : 'Méthode de vérification' }}
                        </label>
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <label class="flex items-center gap-2.5 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/40">
                                <input type="radio" name="auth_method" value="password" checked onclick="togglePartialAuthMethod('password')" class="text-emerald-600 focus:ring-emerald-500">
                                <span class="text-xs sm:text-sm font-medium text-slate-700">{{ $locale === 'en' ? 'Password' : 'Mot de passe' }}</span>
                            </label>
                            <label class="flex items-center gap-2.5 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/40">
                                <input type="radio" name="auth_method" value="otp" onclick="togglePartialAuthMethod('otp')" class="text-emerald-600 focus:ring-emerald-500">
                                <span class="text-xs sm:text-sm font-medium text-slate-700">{{ $locale === 'en' ? 'Code via SMS / Email' : 'Code reçu par SMS / Email' }}</span>
                            </label>
                        </div>

                        <div id="partial-password-field">
                            <label for="password_partial" class="block text-sm font-semibold text-slate-800 mb-1">
                                {{ $locale === 'en' ? 'Password' : 'Mot de passe' }}
                            </label>
                            <input type="password" name="password" id="password_partial" required
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                        </div>

                        <div id="partial-otp-field" class="hidden space-y-2">
                            <div class="flex items-center justify-between">
                                <label for="otp_code_partial" class="block text-sm font-semibold text-slate-800">
                                    {{ $locale === 'en' ? '6-digit verification code' : 'Code de confirmation à 6 chiffres' }}
                                </label>
                                <button type="button" onclick="sendOtpRequest('identifier_partial', 'partial-otp-status-msg')" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 underline">
                                    {{ $locale === 'en' ? 'Send code now' : 'Envoyer le code maintenant' }}
                                </button>
                            </div>
                            <input type="text" name="otp_code" id="otp_code_partial" maxlength="6"
                                placeholder="123456"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm tracking-widest font-mono text-center text-lg">
                            <p id="partial-otp-status-msg" class="text-xs text-slate-500"></p>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 px-5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-sm shadow-sm transition">
                        {{ $locale === 'en' ? 'Delete Selected Data' : 'Supprimer les données sélectionnées' }}
                    </button>
                </form>
            </div>

            <!-- Legal Links Footer -->
            <div class="mt-8 text-center text-xs text-slate-500 space-x-4">
                <a href="/settings/legal" class="hover:underline">{{ $locale === 'en' ? 'Privacy Policy' : 'Politique de confidentialité' }}</a>
                <span>•</span>
                <a href="/settings/legal" class="hover:underline">{{ $locale === 'en' ? 'Terms of Service' : 'Conditions Générales' }}</a>
                <span>•</span>
                <a href="mailto:confidentialite@bango.app" class="hover:underline">confidentialite@bango.app</a>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-400">
        &copy; {{ date('Y') }} BANGO — CCEDA Finance. {{ $locale === 'en' ? 'All rights reserved.' : 'Tous droits réservés.' }}
    </footer>

    <!-- Interactive Scripts -->
    <script>
        function switchTab(tab) {
            const tabFull = document.getElementById('tab-full');
            const tabPartial = document.getElementById('tab-partial');
            const btnFull = document.getElementById('tab-full-btn');
            const btnPartial = document.getElementById('tab-partial-btn');

            if (tab === 'full') {
                tabFull.classList.remove('hidden');
                tabPartial.classList.add('hidden');
                btnFull.className = "pb-3 border-b-2 border-emerald-600 text-emerald-700 font-semibold";
                btnPartial.className = "pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 font-semibold";
            } else {
                tabFull.classList.add('hidden');
                tabPartial.classList.remove('hidden');
                btnFull.className = "pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 font-semibold";
                btnPartial.className = "pb-3 border-b-2 border-emerald-600 text-emerald-700 font-semibold";
            }
        }

        function toggleAuthMethod(method) {
            const pwField = document.getElementById('password-field');
            const otpField = document.getElementById('otp-field');
            const pwInput = document.getElementById('password');
            const otpInput = document.getElementById('otp_code');

            if (method === 'password') {
                pwField.classList.remove('hidden');
                otpField.classList.add('hidden');
                pwInput.setAttribute('required', 'required');
                otpInput.removeAttribute('required');
            } else {
                pwField.classList.add('hidden');
                otpField.classList.remove('hidden');
                pwInput.removeAttribute('required');
                otpInput.setAttribute('required', 'required');
            }
        }

        function togglePartialAuthMethod(method) {
            const pwField = document.getElementById('partial-password-field');
            const otpField = document.getElementById('partial-otp-field');
            const pwInput = document.getElementById('password_partial');
            const otpInput = document.getElementById('otp_code_partial');

            if (method === 'password') {
                pwField.classList.remove('hidden');
                otpField.classList.add('hidden');
                pwInput.setAttribute('required', 'required');
                otpInput.removeAttribute('required');
            } else {
                pwField.classList.add('hidden');
                otpField.classList.remove('hidden');
                pwInput.removeAttribute('required');
                otpInput.setAttribute('required', 'required');
            }
        }

        async function sendOtpRequest(inputId = 'identifier', msgId = 'otp-status-msg') {
            const identifierEl = document.getElementById(inputId);
            const identifier = identifierEl.value.trim();
            const statusMsg = document.getElementById(msgId);

            if (!identifier) {
                alert("{{ $locale === 'en' ? 'Please enter your email or phone number first.' : 'Veuillez d\'abord saisir votre e-mail ou votre numéro de téléphone.' }}");
                identifierEl.focus();
                return;
            }

            statusMsg.textContent = "{{ $locale === 'en' ? 'Sending code...' : 'Envoi du code en cours...' }}";
            statusMsg.className = "text-xs text-blue-600 font-medium";

            try {
                const response = await fetch('/supprimer-mon-compte/request-otp', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        identifier: identifier,
                        locale: '{{ $locale }}'
                    })
                });

                const data = await response.json();
                if (response.ok) {
                    statusMsg.textContent = data.message || "{{ $locale === 'en' ? 'Code sent! Check your inbox or SMS.' : 'Code envoyé ! Vérifiez votre boîte de réception ou vos SMS.' }}";
                    statusMsg.className = "text-xs text-emerald-600 font-medium";
                } else {
                    statusMsg.textContent = data.message || "{{ $locale === 'en' ? 'Unable to send code.' : 'Impossible d\'envoyer le code.' }}";
                    statusMsg.className = "text-xs text-rose-600 font-medium";
                }
            } catch (err) {
                statusMsg.textContent = "{{ $locale === 'en' ? 'Network error. Please try again.' : 'Erreur réseau. Veuillez réessayer.' }}";
                statusMsg.className = "text-xs text-rose-600 font-medium";
            }
        }
    </script>
</body>
</html>

