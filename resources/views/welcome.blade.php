<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="internals">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Theme vor dem ersten Paint setzen, damit nichts aufblitzt.
             Muss zu resources/js/admin/theme.js passen. --}}
        <script>
            (function () {
                var theme;
                try { theme = window.localStorage.getItem('pth-admin-theme'); } catch (e) {}
                if (theme !== 'dark' && theme !== 'light') {
                    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-theme', theme);
                if (theme === 'dark') document.documentElement.classList.add('dark');
            })();
        </script>
        @php($pth = \App\Support\ViteAssets::entry('resources/js/app.js', 'js/pth.js', ['css/pth.css']))
        @foreach ($pth['css'] as $css)
        <link href="{{ $css }}" rel="stylesheet">
        @endforeach
        <title>PokerTH Internal</title>
    </head>
    <body>
      <div id="vue1"><internals-component :authenticated="{{ json_encode(app(\App\Services\AdminAccess::class)->isAdmin(auth()->user())) }}"></internals-component></div>
    </body>
    <script type="module" src="{{ $pth['js'] }}"></script>
</html>
