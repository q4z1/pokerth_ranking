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
        <link href="/pthranking/css/pth.css" rel="stylesheet">
        <title>PokerTH Internal</title>
    </head>
    <body>
      <div id="vue1"><internals-component :authenticated="{{ json_encode(!is_null(auth()->user()), true) }}"></internals-component></div>
    </body>
    <script type="module" src="/pthranking/js/pth.js"></script>
</html>
